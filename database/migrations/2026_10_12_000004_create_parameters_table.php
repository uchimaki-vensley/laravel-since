<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('method_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('name');
            $table->text('type')->nullable();
            $table->text('default_value')->nullable();
            $table->boolean('is_variadic')->default(false);
            $table->boolean('by_ref')->default(false);
            $table->text('docblock_type')->nullable();
            $table->unique(['method_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parameters');
    }
};
