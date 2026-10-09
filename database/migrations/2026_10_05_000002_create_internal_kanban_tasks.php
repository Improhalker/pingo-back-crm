<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Internal project tool: intentionally has no organization_id.
    public function up(): void
    {
        Schema::create('internal_kanban_tasks', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->enum('status', ['TODO', 'IN_PROGRESS', 'IN_REVIEW', 'DONE'])->default('TODO');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps(6);
            $table->index(['status', 'position', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_kanban_tasks');
    }
};
