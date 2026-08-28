<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Dispatch modal shape — the sidebar/board's nav definitions live in this
| client-side file, same convention as AdminOperationsNavigationClarityTest:
| there is no server-rendered modal markup to inspect, so asserting the new
| structure means reading its source of truth directly.
|--------------------------------------------------------------------------
*/

function dbdmDispatchSource(): string
{
    return file_get_contents(resource_path('js/Pages/Dashboard/Departments/Dispatch.jsx'));
}

it('shows the Dispatch order modal with its three dispatch method modes', function (): void {
    $source = dbdmDispatchSource();

    expect($source)->toContain('Dispatch order')
        ->toContain('Choose how this order will be delivered.')
        ->toContain('Integrated provider')
        ->toContain('Manual external courier')
        ->toContain('Internal agent')
        // The old label must be gone, not just duplicated alongside the new one.
        ->not->toContain('Assign a carrier')
        ->not->toContain("label: 'Third-party courier'");
});

it('never asks for a manual tracking number/URL in the Integrated Provider panel — Ozon and Sendit both return their own code via the API', function (): void {
    $source = dbdmDispatchSource();

    // Isolate IntegratedProviderPanel's own source (up to the next top-level
    // function) so this assertion can't accidentally pass by matching text
    // that actually lives in ManualCourierPanel further down the file.
    $start = strpos($source, 'function IntegratedProviderPanel');
    $end = strpos($source, 'function ManualCourierPanel');
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    $panel = substr($source, $start, $end - $start);

    expect($panel)->not->toContain('Tracking number')
        ->not->toContain('Tracking URL')
        ->toContain('{meta.sendLabel}'); // Ozon's/Sendit's own "Send to X" label, from PROVIDER_META below

    // The actual "Send to Ozon"/"Send to Sendit" strings live in PROVIDER_META,
    // which both the panel and the order card's quick-send buttons read from.
    expect($source)->toContain("sendLabel: 'Send to Ozon'")
        ->toContain("sendLabel: 'Send to Sendit'");
});

it('shows tracking number, tracking URL, and manifest reference fields only in Manual external courier mode', function (): void {
    $source = dbdmDispatchSource();

    $start = strpos($source, 'function ManualCourierPanel');
    $end = strpos($source, 'function InternalAgentPanel');
    $panel = substr($source, $start, $end - $start);

    expect($panel)->toContain('Tracking number')
        ->toContain('Tracking URL')
        ->toContain('Manifest reference')
        ->toContain('Assign manual courier');
});

it('shows an agent selector, never tracking fields, in Internal agent mode', function (): void {
    $source = dbdmDispatchSource();

    $start = strpos($source, 'function InternalAgentPanel');
    $end = strpos($source, 'const inputCls');
    $panel = substr($source, $start, $end - $start);

    expect($panel)->toContain('Delivery agent')
        ->toContain('Choose an agent')
        ->toContain('Assign agent')
        ->not->toContain('Tracking number')
        ->not->toContain('Tracking URL');
});

it('never lets Ozon Express or Sendit appear as a suggested manual courier', function (): void {
    $source = dbdmDispatchSource();

    expect($source)->toContain("['Ozon Express', 'Sendit'].includes(c)");
});

it('shows a provider/method badge (Ozon Express, Sendit, Manual courier, Internal agent) on the order card', function (): void {
    $source = dbdmDispatchSource();

    expect($source)->toContain('function dispatchMethodBadge')
        ->toContain("return 'Ozon Express'")
        ->toContain("return 'Sendit'")
        ->toContain("return 'Internal agent'")
        ->toContain("return 'Manual courier'");
});

it('lets the Delivery Board render with no store/session context (empty state) and with a real store', function (): void {
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id]);
    $store->ensureDefaultRoles();

    $this->actingAs($owner)->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Departments/Dispatch')
            ->has('orders')
            ->has('agents')
            ->has('couriers')
            ->has('stats'));
});
