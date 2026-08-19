<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Throwable;

/**
 * Request-scoped tenant identity.
 *
 * During the migration to workspace-level tenancy we intentionally keep both
 * identifiers: store_id still drives legacy global scopes, while
 * organization_id is the durable SaaS boundary new modules will use.
 */
class TenantContext
{
    private ?string $storeId = null;

    private ?string $organizationId = null;

    private bool $disabled = false;

    public function set(?string $storeId, ?string $organizationId = null): void
    {
        $this->storeId = $storeId;
        $this->organizationId = $organizationId;
        $this->disabled = false;
    }

    public function id(): ?string
    {
        return $this->storeId;
    }

    public function storeId(): ?string
    {
        return $this->storeId;
    }

    public function organizationId(): ?string
    {
        return $this->organizationId;
    }

    public function has(): bool
    {
        return $this->storeId !== null;
    }

    public function hasOrganization(): bool
    {
        return $this->organizationId !== null;
    }

    /**
     * Store id to use for tenant query scopes.
     *
     * Route model binding can run before ResolveTenant. In an authenticated web
     * request we therefore fall back to the user's active store. Console/jobs
     * with no authenticated user remain unscoped unless a context was set.
     */
    public function queryStoreId(): ?string
    {
        if ($this->disabled) {
            return null;
        }

        if ($this->storeId !== null) {
            return $this->storeId;
        }

        try {
            $user = auth()->user();

            return $user?->getActiveStore()?->id;
        } catch (Throwable) {
            return null;
        }
    }

    public function queryOrganizationId(): ?string
    {
        if ($this->disabled) {
            return null;
        }

        if ($this->organizationId !== null) {
            return $this->organizationId;
        }

        try {
            return auth()->user()?->getActiveStore()?->organization_id;
        } catch (Throwable) {
            return null;
        }
    }

    public function denyOrganizationQueriesWhenUnresolved(): bool
    {
        if ($this->disabled) {
            return false;
        }

        try {
            return auth()->check() && $this->queryOrganizationId() === null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * An authenticated user with no permitted active Store must never cause a
     * tenant model query to become globally unscoped.
     */
    public function denyTenantQueriesWhenUnresolved(): bool
    {
        if ($this->disabled) {
            return false;
        }

        try {
            return auth()->check() && $this->queryStoreId() === null;
        } catch (Throwable) {
            return false;
        }
    }

    public function forget(): void
    {
        $this->storeId = null;
        $this->organizationId = null;
        $this->disabled = false;
    }

    /** Run a callback with tenancy temporarily disabled, then restore it. */
    public function runWithout(Closure $callback): mixed
    {
        $previousStore = $this->storeId;
        $previousOrganization = $this->organizationId;
        $previousDisabled = $this->disabled;

        $this->storeId = null;
        $this->organizationId = null;
        $this->disabled = true;

        try {
            return $callback();
        } finally {
            $this->storeId = $previousStore;
            $this->organizationId = $previousOrganization;
            $this->disabled = $previousDisabled;
        }
    }
}
