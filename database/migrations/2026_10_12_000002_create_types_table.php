<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->string('fqcn');
            $table->string('kind');
            $table->text('file_path');
            $table->unique(['release_id', 'fqcn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('types');
    }
};
