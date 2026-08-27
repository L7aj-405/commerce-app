<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/*
|--------------------------------------------------------------------------
| Orders page de-duplication: the old Department-tabs row, 2x4 status-tiles
| grid, and separate Source-tabs-+-search row are gone, replaced by one
| compact summary strip + one SearchFilterBar. Same file-source-inspection
| pattern as AdminOperationsNavigationClarityTest — no JS test runner here.
|--------------------------------------------------------------------------
*/

/** @return array{0: User, 1: Store} */
function opdWorkspace(string $name = 'Orders Dedup Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function opdManageSource(): string
{
    return file_get_contents(resource_path('js/Pages/Dashboard/Orders/Manage.jsx'));
}

it('removes the old Department-tabs row and 2x4 status-tiles grid', function (): void {
    $source = opdManageSource();

    expect($source)->not->toContain('const DEPARTMENTS =')
        ->not->toContain('Status overview tiles');

    // The old bare "All orders" source-tab entry (with its own icon/tab row)
    // is gone — source is now a SearchFilterBar filter option, not a tab.
    $sourcesBlock = substr($source, strpos($source, 'const SOURCES ='), 200);
    expect($sourcesBlock)->not->toContain("value: 'all'");
});

it('uses exactly one SearchFilterBar for search/source/status/assigned/date filters', function (): void {
    $source = opdManageSource();

    expect(substr_count($source, '<SearchFilterBar'))->toBe(1);
    expect($source)->toContain('dateRange=');
});

it('replaces the status grid with a compact summary strip driven by real counts', function (): void {
    $source = opdManageSource();

    expect($source)->toContain('counts.byStatus')
        ->toContain('PRIMARY_FLOW');
});

it('never invents a fulfillment status — every board/summary status is a real FulfillmentStatus value', function (): void {
    $source = opdManageSource();
    $start = strpos($source, 'const COLUMNS = [');
    $columnsBlock = substr($source, $start, strpos($source, '];', $start) - $start);

    preg_match_all("/value: '([a-z_]+)',\s+label:/", $columnsBlock, $matches);
    $sourceStatuses = array_unique($matches[1]);

    $realStatuses = array_map(fn (FulfillmentStatus $s) => $s->value, FulfillmentStatus::cases());

    expect($sourceStatuses)->not->toBeEmpty();
    foreach ($sourceStatuses as $status) {
        expect($realStatuses)->toContain($status, "Status [{$status}] in Manage.jsx is not a real FulfillmentStatus value.");
    }
});

it('keeps the board/table view toggle and real order data working end to end', function (): void {
    [$owner] = opdWorkspace();

    $this->actingAs($owner)->get('/dashboard/orders/manage')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Orders/Manage')
            ->has('orders')
            ->has('store'));

    $source = opdManageSource();
    expect($source)->toContain("{ value: 'board', icon: LayoutGrid, label: 'Board' }")
        ->toContain("{ value: 'table', icon: Table2,     label: 'Table' }");
});

it('exposes assignee data read-only, mirroring DepartmentController::queueFor()\'s existing pattern', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/Dashboard/OrderController.php'));

    expect($controller)->toContain('assignee_name')
        ->toContain("User::whereIn(");
});
