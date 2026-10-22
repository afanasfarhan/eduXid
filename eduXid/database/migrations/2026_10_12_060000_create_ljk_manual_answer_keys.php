<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ljk_manual_answer_keys')) {
            Schema::create('ljk_manual_answer_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('exam_id')->unique();
                $table->unsignedBigInteger('teacher_id')->index();
                $table->longText('keys_json');
                $table->string('questions_fingerprint', 64);
                $table->boolean('is_active')->default(false);
                $table->unsignedBigInteger('version')->default(1);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('ljk_scan_results')) {
            $source = !Schema::hasColumn('ljk_scan_results', 'key_source');
            $snapshot = !Schema::hasColumn('ljk_scan_results', 'manual_keys_json');
            if ($source || $snapshot) {
                Schema::table('ljk_scan_results', function (Blueprint $table) use ($source, $snapshot) {
                    if ($source) $table->string('key_source', 16)->default('package');
                    if ($snapshot) $table->longText('manual_keys_json')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ljk_scan_results')) {
            if (Schema::hasColumn('ljk_scan_results', 'manual_keys_json')) {
                Schema::table('ljk_scan_results', fn (Blueprint $table) => $table->dropColumn('manual_keys_json'));
            }
            if (Schema::hasColumn('ljk_scan_results', 'key_source')) {
                Schema::table('ljk_scan_results', fn (Blueprint $table) => $table->dropColumn('key_source'));
            }
        }
        Schema::dropIfExists('ljk_manual_answer_keys');
    }
};
