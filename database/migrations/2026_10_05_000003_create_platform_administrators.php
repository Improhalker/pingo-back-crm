<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Platform authorization, independent of organization_user roles (ADR-026). Intentionally has no organization_id.
    public function up(): void
    {
        Schema::create('platform_administrators', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_administrators');
    }
};
