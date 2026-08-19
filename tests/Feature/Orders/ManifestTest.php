<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Orders\DispatchService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Manifest handover sheet
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Queue::fake();

    $this->owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);
    $this->store->ensureDefaultRoles();

    $this->dispatcher = (function () {
        $role   = $this->store->roles()->where('name', 'Dispatcher')->firstOrFail();
        $member = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
        StoreMember::create([
            'store_id' => $this->store->id, 'user_id' => $member->id, 'role' => 'manager',
            'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
        ]);
        return $member;
    })();

    // Two packed orders dispatched under one manifest reference.
    $workflow = app(OrderWorkflowService::class);
    $dispatch = app(DispatchService::class);
    $this->manifestRef = 'MAN-AMANA-20260725';

    foreach ([['ORD-1', 'Sara', 200], ['ORD-2', 'Youssef', 350]] as [$num, $name, $total]) {
        $order = Order::factory()->create([
            'store_id' => $this->store->id, 'order_number' => $num,
            'fulfillment_status' => FulfillmentStatus::Pending,
            'customer_name' => $name, 'customer_phone' => '06'.rand(10000000, 99999999),
            'total' => $total, 'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => $total, 'line_total' => $total]],
        ]);
        foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
            $order = $workflow->transition($order, $s, $this->owner);
        }
        $dispatch->assign($order, [
            'carrier_type' => 'courier', 'carrier_name' => 'Amana',
            'tracking_number' => 'AM-'.$num, 'manifest_reference' => $this->manifestRef,
        ], $this->dispatcher);
    }
});

it('gathers a manifest with every parcel and the batch total', function () {
    $payload = app(DispatchService::class)->gatherManifest($this->store, $this->manifestRef);

    expect($payload['reference'])->toBe($this->manifestRef)
        ->and($payload['carrier'])->toBe('Amana')
        ->and($payload['total_parcels'])->toBe(2)
        ->and($payload['total_value'])->toBe(550.0)
        ->and($payload['parcels'])->toHaveCount(2)
        ->and($payload['parcels'][0]['order_reference'])->toBe('ORD-1')
        ->and($payload['parcels'][0]['tracking_number'])->toBe('AM-ORD-1');
});

it('lists the store’s manifests with parcel counts', function () {
    $manifests = app(DispatchService::class)->manifests($this->store);

    expect($manifests)->toHaveCount(1)
        ->and($manifests[0]['reference'])->toBe($this->manifestRef)
        ->and($manifests[0]['parcels'])->toBe(2)
        ->and($manifests[0]['pending'])->toBe(2);
});

it('throws for a manifest that does not exist', function () {
    expect(fn () => app(DispatchService::class)->gatherManifest($this->store, 'MAN-NOPE-20260101'))
        ->toThrow(ValidationException::class);
});

it('streams a real PDF handover sheet to the dispatcher', function () {
    $response = $this->actingAs($this->dispatcher)
        ->get('/dashboard/departments/manifests/'.$this->manifestRef);

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->getContent())->toStartWith('%PDF');
});

it('404s an unknown manifest over HTTP', function () {
    $this->actingAs($this->dispatcher)
        ->get('/dashboard/departments/manifests/MAN-GHOST-20260101')
        ->assertNotFound();
});

it('keeps the warehouse out of manifest printing', function () {
    $role   = $this->store->roles()->where('name', 'Warehouse')->firstOrFail();
    $packer = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $this->store->id, 'user_id' => $packer->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    $this->actingAs($packer)
        ->get('/dashboard/departments/manifests/'.$this->manifestRef)
        ->assertForbidden();
});

it('does not expose another store’s manifest', function () {
    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    Store::factory()->create(['user_id' => $otherOwner->id])->ensureDefaultRoles();

    // Same reference string, different tenant → not found.
    $this->actingAs($otherOwner)
        ->get('/dashboard/departments/manifests/'.$this->manifestRef)
        ->assertNotFound();
});
