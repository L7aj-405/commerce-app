<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal justification / owner-review workflow for expenses paid without
 * an official invoice — see FinanceExpenseService's docblock for the concept
 * (official invoice = external legal proof; internal cash voucher = internal
 * justification for why company money left the business).
 *
 * Default `justification_type` is 'official_document' (both at the DB level
 * and in FinanceExpenseService::create()) — deliberately, so every expense
 * created before this feature, and every expense created by a caller that
 * doesn't yet know about it (the recurring-expense generator inserts
 * FinanceExpense rows directly, bypassing the service — see
 * FinanceRecurringExpenseService::generateExpenseFor()), is treated as
 * normally-documented rather than suddenly flagged for owner review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_expenses', function (Blueprint $table) {
            // What the user declared at creation time — the historical
            // record of which path was chosen. Never rewritten by adding/
            // removing documents later (see justification_status for the
            // live-derived counterpart).
            $table->string('justification_type', 30)->default('official_document')->after('attachment_path');

            // Live snapshot, recomputed by FinanceExpenseService on create,
            // update, and whenever a document is attached/removed — see
            // FinanceExpenseService::syncJustificationStatus(). documented |
            // internal_only | needs_review.
            $table->string('justification_status', 20)->default('documented')->after('justification_type');

            // Only ever set for a non-official-document expense — see
            // FinanceExpenseService::create()/approve()/reject()/
            // requestMoreInfo(). Null for an official-document expense: no
            // owner-review workflow applies to it.
            $table->string('owner_review_status', 20)->nullable()->after('justification_status');

            // Internal cash voucher / no-invoice justification fields —
            // required (by FinanceExpenseRequest) whenever justification_type
            // isn't 'official_document'. Free text, not a user FK: the
            // beneficiary/payer is very often not a system user at all (a
            // driver, a supplier's employee paid in person, etc.).
            $table->string('beneficiary_name')->nullable()->after('owner_review_status');
            $table->text('justification_reason')->nullable()->after('beneficiary_name');
            $table->string('paid_by')->nullable()->after('justification_reason');
            $table->text('justification_notes')->nullable()->after('paid_by');

            // Owner-review audit trail — who reviewed it, when, and any note
            // (especially for a rejection or a "needs more info" request).
            $table->ulid('owner_reviewed_by')->nullable()->after('justification_notes');
            $table->timestamp('owner_reviewed_at')->nullable()->after('owner_reviewed_by');
            $table->text('owner_review_note')->nullable()->after('owner_reviewed_at');

            $table->foreign('owner_reviewed_by', 'fe_owner_reviewed_by_fk')->references('id')->on('users')->nullOnDelete();

            $table->index(['organization_id', 'justification_type']);
            $table->index(['organization_id', 'owner_review_status']);
        });
    }

    public function down(): void
    {
        Schema::table('finance_expenses', function (Blueprint $table) {
            $table->dropForeign('fe_owner_reviewed_by_fk');
            $table->dropIndex(['organization_id', 'justification_type']);
            $table->dropIndex(['organization_id', 'owner_review_status']);
            $table->dropColumn([
                'justification_type', 'justification_status', 'owner_review_status',
                'beneficiary_name', 'justification_reason', 'paid_by', 'justification_notes',
                'owner_reviewed_by', 'owner_reviewed_at', 'owner_review_note',
            ]);
        });
    }
};
