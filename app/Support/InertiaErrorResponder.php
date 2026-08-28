<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Central place deciding how an uncaught exception becomes an HTTP response
 * for browser/Inertia traffic — see bootstrap/app.php's withExceptions().
 *
 * Two concerns, kept separate from ordinary controller code so every
 * existing abort()/abort_unless() call across the app (confirmation claim
 * gates, department permissions, Ozon/Sendit dispatch, ...) gets consistent
 * treatment without individually wrapping each one in a try/catch:
 *
 *  - A genuine Inertia ACTION (a button click — confirm/cancel/claim/assign/
 *    dispatch/...) rejected with 401/403/419 must never blow the user off
 *    the page they were on. Detected via the X-Inertia request header (set
 *    by every router.post/put/patch/delete call once the SPA has loaded),
 *    so this NEVER changes the response for a plain HTTP request — a Pest
 *    test asserting ->assertForbidden() on the very same route keeps
 *    getting the exact original 403 response, byte for byte, unchanged.
 *  - A full-page GET request that hits 403/404/419/500 gets the branded
 *    Error page instead of the bare framework default — status code always
 *    preserved, never silently turned into a 200 "success".
 */
class InertiaErrorResponder
{
    /** @var array<int, int> */
    private const ACTION_STATUSES = [401, 403, 419];

    /** @var array<int, int> */
    private const PAGE_STATUSES = [403, 404, 419, 500];

    public static function respond(Response $response, Throwable $e, Request $request): Response
    {
        // Real JSON API consumers (webhooks, any /api/* route) are never
        // touched — this is exclusively about the browser/Inertia experience.
        if ($request->is('api/*') || $request->expectsJson()) {
            return $response;
        }

        $status = $response->getStatusCode();

        if (self::isInertiaAction($request) && in_array($status, self::ACTION_STATUSES, true)) {
            return self::backWithFlash($e, $status);
        }

        if ($request->isMethod('GET') && in_array($status, self::PAGE_STATUSES, true)) {
            return self::brandedErrorPage($request, $e, $status);
        }

        return $response;
    }

    private static function isInertiaAction(Request $request): bool
    {
        return (bool) $request->header('X-Inertia') && ! $request->isMethod('GET');
    }

    private static function backWithFlash(Throwable $e, int $status): RedirectResponse
    {
        return back()->with('error', self::messageFor($e, $status));
    }

    private static function brandedErrorPage(Request $request, Throwable $e, int $status): Response
    {
        return Inertia::render('Error', [
            'status' => $status,
            // Never leak an internal exception message for a genuine server
            // error — 403/404/419 messages are always our own, safe,
            // user-facing text set at the abort()/abort_unless() call site.
            'message' => $status === 500 ? null : self::messageFor($e, $status),
        ])->toResponse($request)->setStatusCode($status);
    }

    private static function messageFor(Throwable $e, int $status): string
    {
        $message = $e->getMessage();

        if ($message !== '') {
            return $message;
        }

        return match ($status) {
            401 => 'You need to sign in again to do that.',
            403 => 'You are not allowed to do that.',
            404 => 'That page could not be found.',
            419 => 'Your session expired — please try again.',
            default => 'Something went wrong.',
        };
    }
}
