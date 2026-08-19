<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('owner_user_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active');
            $table->json('settings')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('owner_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['owner_user_id', 'status']);
        });

        Schema::create('organization_members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('user_id');
            $table->string('role')->default('member');
            $table->boolean('is_active')->default(true);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::table('stores', function (Blueprint $table) {
            // Nullable during the transition so deployments with legacy/custom rows
            // remain safe. Application code assigns it for every new store.
            $table->ulid('organization_id')->nullable()->after('id');
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->index('organization_id');
        });

        $this->backfillLegacyStores();
    }

    private function backfillLegacyStores(): void
    {
        DB::table('stores')
            ->whereNull('organization_id')
            ->orderBy('id')
            ->chunkById(100, function ($stores): void {
                foreach ($stores as $store) {
                    $organizationId = (string) Str::ulid();
                    $now = now();

                    DB::table('organizations')->insert([
                        'id'            => $organizationId,
                        'owner_user_id' => $store->user_id,
                        'name'          => $store->name,
                        'slug'          => $this->uniqueOrganizationSlug((string) $store->slug, $organizationId),
                        'status'        => 'active',
                        'settings'      => null,
                        'metadata'      => json_encode(['migrated_from_store_id' => $store->id], JSON_THROW_ON_ERROR),
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);

                    DB::table('organization_members')->insert([
                        'id'              => (string) Str::ulid(),
                        'organization_id' => $organizationId,
                        'user_id'         => $store->user_id,
                        'role'            => 'owner',
                        'is_active'       => true,
                        'joined_at'       => $now,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]);

                    // Mirror existing active store members at workspace level. Store
                    // roles remain the operational permission source for now.
                    DB::table('store_members')
                        ->where('store_id', $store->id)
                        ->where('user_id', '!=', $store->user_id)
                        ->where('is_active', true)
                        ->get()
                        ->each(function ($member) use ($organizationId, $now): void {
                            DB::table('organization_members')->updateOrInsert(
                                [
                                    'organization_id' => $organizationId,
                                    'user_id' => $member->user_id,
                                ],
                                [
                                    'id'         => (string) Str::ulid(),
                                    'role'       => $member->role === 'store_admin' ? 'admin' : 'member',
                                    'is_active'  => true,
                                    'joined_at'  => $member->joined_at ?? $now,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ],
                            );
                        });

                    DB::table('stores')
                        ->where('id', $store->id)
                        ->update(['organization_id' => $organizationId]);
                }
            }, 'id');
    }

    private function uniqueOrganizationSlug(string $storeSlug, string $organizationId): string
    {
        $base = Str::slug($storeSlug !== '' ? $storeSlug : 'organization');
        $candidate = $base;

        if (! DB::table('organizations')->where('slug', $candidate)->exists()) {
            return $candidate;
        }

        return $base . '-' . Str::lower(substr($organizationId, -6));
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::dropIfExists('organization_members');
        Schema::dropIfExists('organizations');
    }
};
