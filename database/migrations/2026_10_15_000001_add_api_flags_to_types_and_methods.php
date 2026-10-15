<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false);
            $table->boolean('is_deprecated')->default(false);
        });

        Schema::table('methods', function (Blueprint $table) {
            $table->string('source')->default('declared');
            $table->boolean('is_internal')->default(false);
            $table->boolean('is_deprecated')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('methods', function (Blueprint $table) {
            $table->dropColumn(['source', 'is_internal', 'is_deprecated']);
        });

        Schema::table('types', function (Blueprint $table) {
            $table->dropColumn(['is_internal', 'is_deprecated']);
        });
    }
};
