<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            // スナップショットを保存するたびに増える。記録済みの差分が古くなったかの判定に使う。
            $table->unsignedInteger('snapshot_revision')->default(0);
            // symbol_changes をどのスナップショット同士の比較で記録したか。
            $table->string('changes_basis')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn(['snapshot_revision', 'changes_basis']);
        });
    }
};
