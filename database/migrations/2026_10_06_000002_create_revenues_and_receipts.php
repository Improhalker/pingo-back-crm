<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Revenue (sale) → receivable (expected obligation) → receipts (each effective settlement).
     * Money is DECIMAL(19,2); civil dates are DATE in the organization timezone; instants are UTC DATETIME(6).
     * Every parent reference is a composite (organization_id, id) foreign key so rows cannot cross tenants.
     */
    public function up(): void
    {
        $methods = ['CASH', 'PIX', 'BOLETO', 'CARD', 'TRANSFER', 'OTHER'];

        Schema::create('revenues', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('category_id');
            $table->string('description', 160);
            $table->decimal('total_amount', 19, 2);
            $table->date('sale_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'sale_date', 'id']);
            $table->foreign(['organization_id', 'customer_id'])->references(['organization_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['organization_id', 'category_id'])->references(['organization_id', 'id'])->on('categories')->restrictOnDelete();
        });

        Schema::create('receivables', function (Blueprint $table) use ($methods) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('revenue_id');
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->decimal('amount', 19, 2);
            $table->date('due_date');
            $table->enum('expected_method', $methods);
            $table->timestamps(6);
            $table->unique(['organization_id', 'id']);
            // This version allows a single obligation per revenue; the sequence keeps room for instalments.
            $table->unique(['revenue_id', 'sequence']);
            $table->index(['organization_id', 'due_date']);
            $table->foreign(['organization_id', 'revenue_id'])->references(['organization_id', 'id'])->on('revenues')->restrictOnDelete();
        });

        Schema::create('receipts', function (Blueprint $table) use ($methods) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('receivable_id');
            $table->decimal('amount', 19, 2);
            $table->enum('method', $methods);
            $table->dateTime('received_at', 6);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
            $table->index(['organization_id', 'received_at']);
            $table->index(['receivable_id', 'id']);
            $table->foreign(['organization_id', 'receivable_id'])->references(['organization_id', 'id'])->on('receivables')->restrictOnDelete();
        });

        // Financial audit trail, written in the same transaction as the change. Append-only for the app.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 60);
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->json('previous_data')->nullable();
            $table->json('new_data')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['organization_id', 'entity_type', 'entity_id']);
            $table->index(['organization_id', 'created_at']);
        });

        // Idempotency for financial writes: one row per (organization, user, operation, key), claimed in the
        // operation's transaction. The stored response is replayed; another payload with the key is a conflict.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('operation', 60);
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            // Exact bytes of the first response (a JSON column would reorder keys on replay).
            $table->longText('response_body')->nullable();
            $table->timestamps(6);
            $table->unique(['organization_id', 'user_id', 'operation', 'idempotency_key'], 'idempotency_keys_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('receivables');
        Schema::dropIfExists('revenues');
    }
};
