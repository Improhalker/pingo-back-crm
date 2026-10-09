<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Card 09 (ADR-031): a settlement is never edited. A correction inserts a replacement row that points to
     * the original (`corrects_id`, unique: one correction per row) and marks the original `superseded_at`.
     * Only rows without `superseded_at` count in totals; the original stays as evidence.
     *
     * Card 10 (ADR-032): obligations already allow several rows per entry (`sequence`). Each one now has an
     * optional internal reminder date, independent of its due date, and may be the down payment. Existing
     * entries become one-instalment schedules without touching amounts or settlements.
     */
    public function up(): void
    {
        foreach (['receipts' => 'receivable_id', 'payments' => 'payable_id'] as $table => $parent) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->unsignedBigInteger('corrects_id')->nullable()->after('created_by');
                $blueprint->string('correction_reason', 500)->nullable()->after('corrects_id');
                $blueprint->dateTime('superseded_at', 6)->nullable()->after('correction_reason');
                $blueprint->unique('corrects_id');
                $blueprint->unique(['organization_id', 'id'], "{$table}_organization_id_id_unique");
                $blueprint->foreign(['organization_id', 'corrects_id'])->references(['organization_id', 'id'])->on($table)->restrictOnDelete();
            });
        }
        // Payables were limited to one row per expense; give them the same (entry, sequence) key as receivables.
        Schema::table('payables', function (Blueprint $blueprint) {
            $blueprint->unsignedSmallInteger('sequence')->default(1)->after('expense_id');
            $blueprint->unique(['expense_id', 'sequence']);
        });
        Schema::table('payables', fn (Blueprint $blueprint) => $blueprint->dropUnique(['expense_id']));
        foreach (['receivables', 'payables'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->date('reminder_date')->nullable()->after('due_date');
                $blueprint->boolean('is_down_payment')->default(false)->after('reminder_date');
                $blueprint->index(['organization_id', 'reminder_date']);
            });
        }
    }

    public function down(): void
    {
        foreach (['receivables', 'payables'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['organization_id', 'reminder_date']);
                $blueprint->dropColumn(['reminder_date', 'is_down_payment']);
            });
        }
        // Only reversible while every expense still has a single obligation.
        Schema::table('payables', fn (Blueprint $blueprint) => $blueprint->unique(['expense_id']));
        Schema::table('payables', function (Blueprint $blueprint) {
            $blueprint->dropUnique(['expense_id', 'sequence']);
            $blueprint->dropColumn('sequence');
        });
        foreach (['receipts', 'payments'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign(['organization_id', 'corrects_id']);
                $blueprint->dropUnique("{$table}_organization_id_id_unique");
                $blueprint->dropUnique(['corrects_id']);
                $blueprint->dropColumn(['corrects_id', 'correction_reason', 'superseded_at']);
            });
        }
    }
};
