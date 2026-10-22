<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('question_banks')) return;

        $missing = [];
        foreach (['tka_group_code', 'tka_stimulus', 'tka_category', 'tka_statement_order'] as $name) {
            if (!Schema::hasColumn('question_banks', $name)) $missing[$name] = true;
        }
        if (!$missing) return;

        Schema::table('question_banks', function (Blueprint $table) use ($missing) {
            if (isset($missing['tka_group_code'])) $table->string('tka_group_code', 96)->nullable();
            if (isset($missing['tka_stimulus'])) $table->longText('tka_stimulus')->nullable();
            if (isset($missing['tka_category'])) $table->boolean('tka_category')->default(false);
            if (isset($missing['tka_statement_order'])) $table->unsignedSmallInteger('tka_statement_order')->nullable();
        });
    }

    public function down(): void
    {
        // Rollback sengaja tidak menghapus metadata dan soal TKA yang sudah dibuat.
    }
};
