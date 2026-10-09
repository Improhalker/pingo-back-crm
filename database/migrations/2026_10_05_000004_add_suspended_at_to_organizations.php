<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Platform-managed suspension (ADR-026): a suspended organization keeps its data but its members cannot open its context.
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dateTime('suspended_at', 6)->nullable()->after('timezone');
            $table->index(['suspended_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['suspended_at', 'created_at']);
            $table->dropColumn('suspended_at');
        });
    }
};
