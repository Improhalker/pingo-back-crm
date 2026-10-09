<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Obligation and effective payments are separate. Composite FKs prevent cross-company references. */
    public function up(): void
    {
        $methods = ['CASH', 'PIX', 'BOLETO', 'CARD', 'TRANSFER', 'OTHER'];

        Schema::create('expenses', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('category_id');
            $table->string('description', 160);
            $table->decimal('total_amount', 19, 2);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'created_at', 'id']);
            $table->foreign(['organization_id', 'category_id'])->references(['organization_id', 'id'])->on('categories')->restrictOnDelete();
        });

        Schema::create('payables', function (Blueprint $table) use ($methods) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('expense_id');
            // One obligation per expense in this version; partial payments do not create instalments.
            $table->unique('expense_id');
            $table->decimal('amount', 19, 2);
            $table->date('due_date');
            $table->enum('expected_method', $methods);
            $table->timestamps(6);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'due_date', 'id']);
            $table->foreign(['organization_id', 'expense_id'])->references(['organization_id', 'id'])->on('expenses')->restrictOnDelete();
        });

        Schema::create('payments', function (Blueprint $table) use ($methods) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('payable_id');
            $table->decimal('amount', 19, 2);
            $table->enum('method', $methods);
            $table->dateTime('paid_at', 6);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
            $table->index(['organization_id', 'paid_at']);
            $table->index(['payable_id', 'id']);
            $table->foreign(['organization_id', 'payable_id'])->references(['organization_id', 'id'])->on('payables')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payables');
        Schema::dropIfExists('expenses');
    }
};
