<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_recurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->enum('kind', ['REVENUE', 'EXPENSE']);
            $table->enum('status', ['ACTIVE', 'PAUSED', 'ENDED'])->default('ACTIVE');
            $table->string('description', 160);
            $table->text('notes')->nullable();
            $table->string('expected_method', 20);
            $table->unsignedTinyInteger('due_day');
            $table->unsignedTinyInteger('reminder_days')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('next_month');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'recurrence_org_id_unique');
            $table->index(['status', 'next_month', 'id']);
            $table->foreign(['organization_id', 'category_id'], 'recurrence_category_fk')
                ->references(['organization_id', 'id'])->on('categories')->restrictOnDelete();
            $table->foreign(['organization_id', 'customer_id'], 'recurrence_customer_fk')
                ->references(['organization_id', 'id'])->on('customers')->restrictOnDelete();
        });

        Schema::create('financial_recurrence_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_recurrence_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('effective_month');
            $table->decimal('amount', 11, 2);
            $table->timestamps();
            $table->index(['financial_recurrence_id', 'effective_month', 'id'], 'recurrence_effective_rates');
        });

        foreach (['revenues', 'expenses'] as $entries) {
            Schema::table($entries, function (Blueprint $table) use ($entries) {
                $table->unsignedBigInteger('financial_recurrence_id')->nullable();
                $table->date('recurrence_month')->nullable();
                $table->boolean('recurrence_amount_overridden')->default(false);
                $table->unique(['financial_recurrence_id', 'recurrence_month'], "{$entries}_recurrence_month_unique");
                $table->foreign(['organization_id', 'financial_recurrence_id'], "{$entries}_recurrence_org_fk")
                    ->references(['organization_id', 'id'])->on('financial_recurrences')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['revenues', 'expenses'] as $entries) {
            Schema::table($entries, function (Blueprint $table) use ($entries) {
                $table->dropForeign("{$entries}_recurrence_org_fk");
                $table->dropUnique("{$entries}_recurrence_month_unique");
                $table->dropColumn(['financial_recurrence_id', 'recurrence_month', 'recurrence_amount_overridden']);
            });
        }

        Schema::dropIfExists('financial_recurrence_rates');
        Schema::dropIfExists('financial_recurrences');
    }
};
