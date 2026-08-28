<?php

declare(strict_types=1);

use App\Connectors\Delivery\SenditConnector;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Store;
use App\Models\User;
use App\Services\Delivery\SenditDistrictMappingService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Root-cause regression test: Sendit's GET /districts is paginated
| (page/last_page/next_page_url/total), and the OLD sync fetched only page
| 1 and stopped — silently dropping every city that only appeared on a
| later page (Marrakech, Rabat, Meknès, Safi, ...). listDistricts() must
| now walk every page.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);
    $this->store->ensureDefaultRoles();

    $this->connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    Http::fake(['app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok_page'], 200)]);
});

/** A Laravel-style flat paginator page: pagination meta alongside a flat "data" row list. */
function senditDistrictsPage(array $rows, int $currentPage, int $lastPage, int $total): array
{
    return [
        'data' => $rows,
        'current_page' => $currentPage,
        'per_page' => count($rows) > 0 ? count($rows) : 1,
        'last_page' => $lastPage,
        'total' => $total,
        'next_page_url' => $currentPage < $lastPage ? "https://app.sendit.ma/api/v1/districts?page=" . ($currentPage + 1) : null,
        'prev_page_url' => $currentPage > 1 ? "https://app.sendit.ma/api/v1/districts?page=" . ($currentPage - 1) : null,
    ];
}

it('fetches page 1 and page 2', function () {
    $calls = [];
    Http::fake([
        'app.sendit.ma/api/v1/districts?*' => function ($request) use (&$calls) {
            $calls[] = $request->url();
            preg_match('/page=(\d+)/', $request->url(), $m);
            $page = (int) ($m[1] ?? 1);

            $rows = $page === 1
                ? [['id' => 1, 'ville' => 'Casablanca', 'name' => 'Casablanca']]
                : [['id' => 2, 'ville' => 'Rabat', 'name' => 'Rabat']];

            return Http::response(senditDistrictsPage($rows, $page, 2, 2), 200);
        },
    ]);

    $connector = new SenditConnector($this->connection);
    $result = $connector->listDistricts();

    expect($result['ok'])->toBeTrue()
        ->and($result['pages_fetched'])->toBe(2)
        ->and(count($calls))->toBe(2)
        ->and($calls[0])->toContain('page=1')
        ->and($calls[1])->toContain('page=2');
});

it('continues fetching until current_page reaches last_page', function () {
    Http::fake([
        'app.sendit.ma/api/v1/districts?*' => function ($request) {
            preg_match('/page=(\d+)/', $request->url(), $m);
            $page = (int) ($m[1] ?? 1);

            return Http::response(senditDistrictsPage(
                [['id' => $page, 'ville' => "City{$page}", 'name' => "City{$page}"]],
                $page,
                5,
                5,
            ), 200);
        },
    ]);

    $connector = new SenditConnector($this->connection);
    $result = $connector->listDistricts();

    expect($result['ok'])->toBeTrue()
        ->and($result['pages_fetched'])->toBe(5)
        ->and(count($result['cities']))->toBe(5)
        ->and($result['total_reported'])->toBe(5);
});

it('follows next_page_url presence to decide whether to continue, even without a reliable last_page', function () {
    $sequence = [
        // Page 1: next_page_url present, no last_page field at all.
        ['data' => [['id' => 1, 'ville' => 'Fes', 'name' => 'Fes']], 'current_page' => 1, 'next_page_url' => 'https://app.sendit.ma/api/v1/districts?page=2'],
        // Page 2: next_page_url is null -> stop here.
        ['data' => [['id' => 2, 'ville' => 'Meknes', 'name' => 'Meknes']], 'current_page' => 2, 'next_page_url' => null],
    ];
    $call = 0;

    Http::fake([
        'app.sendit.ma/api/v1/districts?*' => function () use (&$sequence, &$call) {
            $body = $sequence[$call] ?? $sequence[count($sequence) - 1];
            $call++;

            return Http::response($body, 200);
        },
    ]);

    $connector = new SenditConnector($this->connection);
    $result = $connector->listDistricts();

    expect($result['pages_fetched'])->toBe(2)
        ->and(collect($result['cities'])->pluck('city_name')->all())->toBe(['Fes', 'Meknes']);
});

it('stores all districts across every page, not only the first', function () {
    Http::fake([
        'app.sendit.ma/api/v1/districts?*' => function ($request) {
            preg_match('/page=(\d+)/', $request->url(), $m);
            $page = (int) ($m[1] ?? 1);

            $rowsByPage = [
                1 => [['id' => 1, 'ville' => 'Casablanca', 'name' => 'Casablanca']],
                2 => [
                    ['id' => 2, 'ville' => 'Marrakech', 'name' => 'Marrakech'],
                    ['id' => 3, 'ville' => 'Rabat', 'name' => 'Rabat'],
                ],
                3 => [
                    ['id' => 4, 'ville' => 'Meknes', 'name' => 'Meknes'],
                    ['id' => 5, 'ville' => 'Safi', 'name' => 'Safi'],
                ],
            ];

            return Http::response(senditDistrictsPage($rowsByPage[$page], $page, 3, 5), 200);
        },
    ]);

    app(SenditDistrictMappingService::class)->syncDistricts($this->connection);

    $storedNames = DeliveryProviderCity::where('store_id', $this->store->id)
        ->where('provider_code', 'sendit')
        ->pluck('city_name')
        ->sort()
        ->values()
        ->all();

    expect($storedNames)->toBe(['Casablanca', 'Marrakech', 'Meknes', 'Rabat', 'Safi']);
});

it('stores a major city that only appears on page 2', function () {
    Http::fake([
        'app.sendit.ma/api/v1/districts?*' => function ($request) {
            preg_match('/page=(\d+)/', $request->url(), $m);
            $page = (int) ($m[1] ?? 1);

            $rows = $page === 1
                ? [['id' => 1, 'ville' => 'Casablanca', 'name' => 'Casablanca']]
                : [['id' => 2, 'ville' => 'Marrakech', 'name' => 'Marrakech']];

            return Http::response(senditDistrictsPage($rows, $page, 2, 2), 200);
        },
    ]);

    app(SenditDistrictMappingService::class)->syncDistricts($this->connection);

    expect(DeliveryProviderCity::where('store_id', $this->store->id)->where('provider_code', 'sendit')->where('city_name', 'Marrakech')->exists())->toBeTrue();
});

it('stops after MAX_PAGES as a safety net against a runaway/misbehaving paginator', function () {
    Http::fake([
        'app.sendit.ma/api/v1/districts?*' => function ($request) {
            preg_match('/page=(\d+)/', $request->url(), $m);
            $page = (int) ($m[1] ?? 1);

            // last_page always reports absurdly high — a real pagination
            // bug this safety net exists to survive.
            return Http::response(senditDistrictsPage(
                [['id' => $page, 'ville' => "City{$page}", 'name' => "City{$page}"]],
                $page,
                999999,
                999999,
            ), 200);
        },
    ]);

    $connector = new SenditConnector($this->connection);
    $result = $connector->listDistricts();

    expect($result['pages_fetched'])->toBeLessThanOrEqual(50);
});

it('a genuinely single-page response (no pagination metadata) still syncs correctly in one call', function () {
    Http::fake([
        'app.sendit.ma/api/v1/districts?*' => Http::response(['data' => [
            ['id' => 1, 'ville' => 'Casablanca', 'name' => 'Casablanca'],
        ]], 200),
    ]);

    $connector = new SenditConnector($this->connection);
    $result = $connector->listDistricts();

    expect($result['ok'])->toBeTrue()
        ->and($result['pages_fetched'])->toBe(1)
        ->and(count($result['cities']))->toBe(1);
});

it('does not report ok when a later page fails, and keeps the pages already fetched', function () {
    Http::fake([
        'app.sendit.ma/api/v1/districts?*' => function ($request) {
            preg_match('/page=(\d+)/', $request->url(), $m);
            $page = (int) ($m[1] ?? 1);

            if ($page === 1) {
                return Http::response(senditDistrictsPage([['id' => 1, 'ville' => 'Casablanca', 'name' => 'Casablanca']], 1, 3, 3), 200);
            }

            return Http::response(['message' => 'Server error'], 500);
        },
    ]);

    $connector = new SenditConnector($this->connection);
    $result = $connector->listDistricts();

    expect($result['ok'])->toBeFalse()
        ->and($result['pages_fetched'])->toBe(1);
});
