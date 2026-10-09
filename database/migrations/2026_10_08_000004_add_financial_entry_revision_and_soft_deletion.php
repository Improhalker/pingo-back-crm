<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['revenues', 'expenses'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedInteger('version')->default(1);
                $table->softDeletes('deleted_at', 6);
            });
        }
    }

    public function down(): void
    {
        foreach (['revenues', 'expenses'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['version', 'deleted_at']));
        }
    }
};
