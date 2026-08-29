<?php

namespace App\Providers;

use App\Events\OrderCreated;
use App\Listeners\CreateNewOrderNotifications;
use App\Models\Facture;
use App\Models\User;
use App\Policies\FacturePolicy;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Support\TenantContext::class);
        $this->app->scoped(\App\Services\SupportAccess::class);

        $this->app->singleton(\Laravel\Fortify\Contracts\LoginResponse::class, function () {
            return new class implements \Laravel\Fortify\Contracts\LoginResponse {
                public function toResponse($request)
                {
                    $user = $request->user();

                    if ($user === null) {
                        return redirect('/login');
                    }

                    if ($user->isSuperAdmin()) {
                        return redirect()->intended('/admin');
                    }

                    if (! $user->hasCompletedOnboarding()) {
                        return redirect('/onboarding');
                    }

                    if ($user->managedAgencyOrganizations()->isNotEmpty() && ! $request->session()->has('store_id')) {
                        return redirect()->intended('/agency/clients');
                    }

                    $store = $user->getActiveStore();

                    // Routing is contextual to the active store. A user may be a
                    // manager in one store and a cashier in another, so users.role
                    // must never decide the destination.
                    if ($user->isDeliveryOnlyAgent($store)) {
                        return redirect('/dashboard/my-deliveries');
                    }

                    if ($user->canAccessDashboard($store)) {
                        return redirect()->intended('/dashboard');
                    }

                    if ($user->canAccessPos($store)) {
                        return redirect()->intended('/pos');
                    }

                    return redirect('/dashboard');
                }
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
   public function boot(): void
{
    if ($this->app->environment('production')) {
        URL::forceScheme('https');
        URL::forceRootUrl(config('app.url'));
    }

    Blade::anonymousComponentNamespace('layouts', 'layouts');

    Event::listen(Login::class, function (Login $event): void {
        if ($event->user instanceof User) {
            $event->user->recordLogin();
        }
    });

    Event::listen(Logout::class, function (Logout $event): void {
        if ($event->user instanceof User && $event->user->isSuperAdmin()) {
            app(\App\Services\SupportAccess::class)->end($event->user, 'logout');
        }
    });

    Event::listen(OrderCreated::class, CreateNewOrderNotifications::class);

    $this->bridgePermissionsToGate();

    Gate::policy(Facture::class, FacturePolicy::class);

    $this->configureDefaults();
}

    /**
     * Bridge the store-scoped PermissionCatalog into Laravel's native Gate so
     * `$user->can('orders.manage')`, `@can(...)` and `$this->authorize(...)`
     * all work against the custom RBAC — no spatie/laravel-permission needed.
     *
     * IMPORTANT: this only short-circuits dotted *catalog* abilities. Model
     * abilities (view/amend/void on a Facture) are plain verbs, so this returns
     * null for them and the corresponding Policy method runs — that's where the
     * invoice immutability rules live and must not be bypassed.
     */
    protected function bridgePermissionsToGate(): void
    {
        Gate::before(function (User $user, string $ability) {
            if (! PermissionCatalog::isValid($ability)) {
                return null; // defer to a Policy (or default-deny) for model abilities
            }

            return $user->hasStorePermission($user->getActiveStore(), $ability) ? true : null;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
