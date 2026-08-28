<?php

use App\Http\Middleware\CheckUserStatus;
use App\Support\InertiaErrorResponder;
use App\Http\Middleware\ConfineDeliveryAgent;
use App\Http\Middleware\EnsureCanAccessDashboard;
use App\Http\Middleware\EnsureCanAccessPos;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureStoreAdmin;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\IsCashier;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\RedirectIfCashier;
use App\Http\Middleware\RedirectIfNotCashier;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'check.status'        => CheckUserStatus::class,
            'is.cashier'          => IsCashier::class,
            'pos.auth'            => RedirectIfNotCashier::class,
            'pos.guest'           => RedirectIfCashier::class,
            'onboarding_complete' => EnsureOnboardingComplete::class,
            'super_admin'         => EnsureSuperAdmin::class,
            'can_dashboard'       => EnsureCanAccessDashboard::class,
            'confine_driver'      => ConfineDeliveryAgent::class,
            'store_admin_only'    => EnsureStoreAdmin::class,
            'can_pos'             => EnsureCanAccessPos::class,
            'perm'                => EnsurePermission::class,
            'tenant'              => ResolveTenant::class,
        ]);

        // Runs after session/auth so it can read the active store, scoping every
        // tenant-model query made inside controllers. Direct route-model-binding
        // access is guarded separately by the Order/Facture Policies.
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            ResolveTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // See App\Support\InertiaErrorResponder for the full rationale —
        // keeps a rejected Inertia action on the current page (flash toast)
        // while a full-page GET error gets the branded Error page, without
        // touching the status code either way.
        $exceptions->respond(
            fn (Response $response, Throwable $e, Request $request) => InertiaErrorResponder::respond($response, $e, $request),
        );
    })->create();
