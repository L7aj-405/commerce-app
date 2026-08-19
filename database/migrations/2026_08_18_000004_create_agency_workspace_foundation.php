<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('type', 24)->default('merchant')->after('owner_user_id');
            $table->index(['type', 'status'], 'org_type_status_idx');

            // A client workspace created/fully managed by an agency may not have
            // a client login yet. Organization membership/agency relationships,
            // not this nullable shortcut, are the security boundary.
            $table->ulid('owner_user_id')->nullable()->change();
        });

        Schema::create('agency_client_organizations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('agency_organization_id');
            $table->ulid('client_organization_id');
            $table->string('status', 24)->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->foreign('agency_organization_id', 'aco_agency_fk')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('client_organization_id', 'aco_client_fk')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['agency_organization_id', 'client_organization_id'], 'aco_pair_uq');
            $table->index(['client_organization_id', 'status'], 'aco_client_status_idx');
        });

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->ulid('owner_organization_id')->nullable()->after('user_id');
            $table->ulid('operator_organization_id')->nullable()->after('owner_organization_id');

            $table->foreign('owner_organization_id', 'wh_owner_org_fk')
                ->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('operator_organization_id', 'wh_operator_org_fk')
                ->references('id')->on('organizations')->nullOnDelete();
            $table->index(['owner_organization_id', 'is_active'], 'wh_owner_active_idx');
            $table->index(['operator_organization_id', 'is_active'], 'wh_operator_active_idx');
        });

        Schema::create('warehouse_organization_access', function (Blueprint $table): void {
            // Pure pivot table: the warehouse/organization pair is the identity.
            // No surrogate ULID is needed; BelongsToMany inserts do not generate one.
            $table->ulid('warehouse_id');
            $table->ulid('organization_id');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->foreign('warehouse_id', 'woa_warehouse_fk')
                ->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('organization_id', 'woa_org_fk')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['warehouse_id', 'organization_id'], 'woa_pair_uq');
            $table->index(['organization_id', 'is_active'], 'woa_org_active_idx');
        });

        Schema::create('organization_service_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('client_organization_id');
            $table->string('service_code', 48);
            $table->ulid('operator_organization_id');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->foreign('client_organization_id', 'osa_client_fk')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('operator_organization_id', 'osa_operator_fk')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['client_organization_id', 'service_code'], 'osa_client_service_uq');
            $table->index(['operator_organization_id', 'service_code', 'is_active'], 'osa_operator_service_idx');
        });

        $this->backfillWarehouseOrganizations();
    }

    private function backfillWarehouseOrganizations(): void
    {
        DB::table('warehouses')->orderBy('id')->get()->each(function ($warehouse): void {
            $organizationId = DB::table('warehouse_store')
                ->join('stores', 'stores.id', '=', 'warehouse_store.store_id')
                ->where('warehouse_store.warehouse_id', $warehouse->id)
                ->whereNotNull('stores.organization_id')
                ->value('stores.organization_id');

            if ($organizationId === null) {
                $organizationId = DB::table('organizations')
                    ->where('owner_user_id', $warehouse->user_id)
                    ->where('type', '!=', 'client')
                    ->orderBy('created_at')
                    ->value('id');
            }

            if ($organizationId === null) {
                return;
            }

            DB::table('warehouses')->where('id', $warehouse->id)->update([
                'owner_organization_id' => $organizationId,
                'operator_organization_id' => $organizationId,
            ]);

            DB::table('warehouse_organization_access')->updateOrInsert(
                ['warehouse_id' => $warehouse->id, 'organization_id' => $organizationId],
                [
                    'is_active' => true,
                    'settings' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_service_assignments');
        Schema::dropIfExists('warehouse_organization_access');

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropForeign('wh_owner_org_fk');
            $table->dropForeign('wh_operator_org_fk');
            $table->dropIndex('wh_owner_active_idx');
            $table->dropIndex('wh_operator_active_idx');
            $table->dropColumn(['owner_organization_id', 'operator_organization_id']);
        });

        Schema::dropIfExists('agency_client_organizations');

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex('org_type_status_idx');
            $table->dropColumn('type');
            $table->ulid('owner_user_id')->nullable(false)->change();
        });
    }
};
