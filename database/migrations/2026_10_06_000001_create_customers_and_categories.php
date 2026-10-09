<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tenant-owned registries. Archiving (archived_at) replaces deletion so history keeps its references.
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('document', 30)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('archived_at', 6)->nullable();
            $table->timestamps(6);
            // Target of composite foreign keys: children must share the organization of the customer.
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'archived_at', 'name']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->enum('type', ['REVENUE', 'EXPENSE']);
            $table->dateTime('archived_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['organization_id', 'id']);
            // The default collation is case/accent-insensitive, so "Aulas" and "aulas" collide per type.
            $table->unique(['organization_id', 'type', 'name']);
            $table->index(['organization_id', 'type', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
        Schema::dropIfExists('customers');
    }
};
