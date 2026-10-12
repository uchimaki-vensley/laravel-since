<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('type_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('visibility');
            $table->boolean('is_static')->default(false);
            $table->text('return_type')->nullable();
            $table->text('docblock_return_type')->nullable();
            $table->string('signature_hash')->index();
            $table->unique(['type_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('methods');
    }
};
