<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Route;

/**
 * Admin Operations Navigation Clarity — an admin (privileged store owner)
 * must see EVERY operations page (Workboards, Supervisor Queues, Inventory
 * exceptions), never a hidden one, but the sidebar/page copy must make each
 * page's purpose distinct: a "workboard" is where the hands-on work happens,
 * a "queue" is a supervisor's status-monitoring view over the same orders —
 * not a duplicate of the workboard.
 */

/** @return array{0: User, 1: Store} */
function adminNavWorkspace(string $name = 'Admin Nav Clarity Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

/** The sidebar's nav definitions live in this client-side file — there is no
 * server-rendered nav markup to inspect (React renders it after hydration),
 * so asserting the exact label text means reading the source of truth for
 * those labels directly. This is a regression guard against the ambiguous
 * "Pick & Pack" / "Picking" / "Packing" / "Ready for delivery" labels the
 * admin originally reported as confusing duplicates. */
function adminNavSidebarSource(): string
{
    return file_get_contents(resource_path('js/Layouts/SaasLayout.jsx'));
}

it('gives the admin (privileged owner) permissions covering both Fulfillment Workboards and Supervisor Queues', function (): void {
    [$owner] = adminNavWorkspace();

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', function ($perms) {
            $perms = collect($perms);
            $has = fn (string $p) => $perms->contains($p);

            // Fulfillment Workboards
            return $has('orders.fulfil') && $has('orders.dispatch') && $has('orders.deliver') && $has('orders.inspect')
                // Orders section
                && $has('orders.view') && $has('orders.confirm')
                // Supervisor Queues — the new nav-visibility permission
                && $has('operations.supervise')
                // Inventory
                && $has('warehouses.manage') && $has('stock.view');
        }));
});

it('labels the sidebar sections and items with Workbench/Queue/Desk/Board wording, never the old ambiguous duplicates', function (): void {
    $source = adminNavSidebarSource();

    // Required section headings.
    expect($source)->toContain('Fulfillment Workboards')
        ->toContain('Supervisor Queues');

    // Required, disambiguated item labels.
    expect($source)->toContain('Pick & Pack Workbench')
        ->toContain('Delivery Board')
        ->toContain('Returns Desk')
        ->toContain('Confirmation Desk')
        ->toContain('All orders')
        ->toContain('Picking Queue')
        ->toContain('Packing Queue')
        ->toContain('Ready for Dispatch')
        ->toContain('Transfer Receiving')
        ->toContain('Waiting for stock')
        ->toContain('Transfers'); // Inventory section item, distinct from "Transfer Receiving"

    // The old bare labels that caused the reported confusion must not remain
    // as their own nav entries (a plain "'Picking',", "'Packing',", or
    // "'Pick & Pack',", exact old array-literal form) — the fix isn't
    // complete if they were only duplicated rather than replaced.
    expect($source)->not->toContain("label: 'Pick & Pack',")
        ->not->toContain("label: 'Picking',")
        ->not->toContain("label: 'Packing',")
        ->not->toContain("label: 'Ready for delivery',");
});

it('keeps the Pick & Pack Workbench page reachable and carrying its explanatory subtitle', function (): void {
    [$owner] = adminNavWorkspace();

    $this->actingAs($owner)->get('/dashboard/departments/packing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Departments/Packing'));

    $source = file_get_contents(resource_path('js/Pages/Dashboard/Departments/Packing.jsx'));
    expect($source)->toContain('Pick & Pack Workbench')
        ->toContain('Worker screen for picking, packing, and moving orders through warehouse steps.');
});

it('keeps Picking Queue and Packing Queue reachable and carrying their supervisor-view subtitles', function (): void {
    [$owner] = adminNavWorkspace();

    $this->actingAs($owner)->get('/dashboard/operations/picking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Operations/Picking'));

    $this->actingAs($owner)->get('/dashboard/operations/packing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Operations/Packing'));

    $picking = file_get_contents(resource_path('js/Pages/Dashboard/Operations/Picking.jsx'));
    $packing = file_get_contents(resource_path('js/Pages/Dashboard/Operations/Packing.jsx'));

    expect($picking)->toContain('Picking Queue')->toContain('Supervisor view of all orders currently in picking status.');
    expect($packing)->toContain('Packing Queue')->toContain('Supervisor view of all orders currently in packing status.');
});

it('does not hide any operations route from the admin — every workboard, queue and inventory-exception page still resolves', function (): void {
    foreach ([
        'dashboard.orders.manage',
        'dashboard.departments.confirmation',
        'dashboard.departments.packing',
        'dashboard.departments.dispatch',
        'dashboard.deliveries.index',
        'dashboard.orders.returns.index',
        'dashboard.operations.waiting-stock',
        'dashboard.operations.picking',
        'dashboard.operations.packing',
        'dashboard.operations.ready-delivery',
        'dashboard.operations.transfers.index',
        'dashboard.warehouses.index',
        'dashboard.stock.index',
        'dashboard.stock.transfers.index',
    ] as $name) {
        expect(Route::has($name))->toBeTrue("Expected route [{$name}] to exist.");
    }
});

it('lets the admin actually load every one of those pages (nothing hidden behind a 403/404)', function (): void {
    [$owner] = adminNavWorkspace();

    foreach ([
        '/dashboard/orders/manage',
        '/dashboard/departments/confirmation',
        '/dashboard/departments/packing',
        '/dashboard/departments/dispatch',
        '/dashboard/orders/returns',
        '/dashboard/operations/waiting-stock',
        '/dashboard/operations/picking',
        '/dashboard/operations/packing',
        '/dashboard/operations/ready-delivery',
        '/dashboard/operations/transfers',
        '/dashboard/warehouses',
        '/dashboard/stock',
        '/dashboard/stock/transfers',
    ] as $url) {
        $this->actingAs($owner)->get($url)->assertOk();
    }
});
