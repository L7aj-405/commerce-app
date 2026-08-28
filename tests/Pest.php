<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * add-parcel returning a tracking number is never trusted alone —
 * OzonExpressConnector::verifyShipment() always calls parcel-info (and, if
 * that doesn't confirm it, tracking) right after. Any test that expects an
 * Ozon send to end up VERIFIED (Shipment::STATUS_SENT_TO_CARRIER, a
 * "success" flash, dispatch->assign() called) must merge this into its
 * Http::fake([...]) array alongside its add-parcel fake, or the shipment
 * will end up STATUS_PROVIDER_UNVERIFIED instead (an unfaked parcel-info/
 * tracking call gets Laravel's default empty response, which this project
 * treats as "could not confirm").
 *
 * @return array<string, \Illuminate\Http\Client\Response>
 */
function ozonVerifiedFakes(): array
{
    return [
        'api.ozonexpress.ma/*/parcel-info' => \Illuminate\Support\Facades\Http::response(
            ['PARCEL-INFO' => ['RESULT' => 'SUCCESS']], 200
        ),
    ];
}
