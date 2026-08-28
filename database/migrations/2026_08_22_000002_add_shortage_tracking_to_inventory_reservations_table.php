<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Waiting Stock Reallocation phase — `inventory_reservations` is ALREADY the
 * line-level shortage record the task asks for: it has organization_id (via
 * BelongsToOrganization), allocation_id (-> the order via
 * InventoryAllocation.source), inventory_item_id, warehouse_id (target),
 * requested_quantity, reserved_quantity, shortage_quantity (missing_quantity),
 * status, and inventory_transfer_id — see App\Models\InventoryReservation.
 * Only three genuinely missing, small fields are added here rather than a
 * whole new `order_stock_shortages` table:
 *   - restock_requested_at: a supervisor manually flagged "no warehouse can
 *     fill this — restock/reclamation needed", with no transfer possible.
 *   - resolved_at: when shortage_quantity last reached 0 for this row.
 *   - notes: free-text context for the restock/shortage decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->timestamp('restock_requested_at')->nullable()->after('inventory_transfer_id');
            $table->timestamp('resolved_at')->nullable()->after('restock_requested_at');
            $table->text('notes')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropColumn(['restock_requested_at', 'resolved_at', 'notes']);
        });
    }
};
