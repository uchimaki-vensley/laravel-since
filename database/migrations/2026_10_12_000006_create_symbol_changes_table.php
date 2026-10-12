<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('symbol_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('symbol_id')->constrained()->cascadeOnDelete();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->index('release_id');
            $table->unique(['symbol_id', 'release_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('symbol_changes');
    }
};
