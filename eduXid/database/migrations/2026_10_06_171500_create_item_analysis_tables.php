<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('item_analysis_runs')) {
            Schema::create('item_analysis_runs', function (Blueprint $table) {
                $table->id();
                $table->uuid('run_id')->unique();
                $table->unsignedBigInteger('exam_id')->index();
                $table->unsignedBigInteger('teacher_id')->index();
                $table->string('source_scope', 20)->default('combined')->index();
                $table->unsignedInteger('participant_count')->default(0);
                $table->unsignedInteger('cbt_count')->default(0);
                $table->unsignedInteger('ljk_count')->default(0);
                $table->unsignedInteger('deduped_count')->default(0);
                $table->decimal('mean_score', 8, 3)->nullable();
                $table->decimal('median_score', 8, 3)->nullable();
                $table->decimal('min_score', 8, 3)->nullable();
                $table->decimal('max_score', 8, 3)->nullable();
                $table->decimal('stddev_score', 8, 3)->nullable();
                $table->decimal('reliability_alpha', 8, 4)->nullable();
                $table->text('sample_note')->nullable();
                $table->longText('metadata_json')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('item_analysis_results')) {
            Schema::create('item_analysis_results', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('item_analysis_run_id')->index();
                $table->unsignedBigInteger('question_bank_id')->index();
                $table->unsignedInteger('item_order')->default(0);
                $table->decimal('difficulty_index', 8, 4)->nullable();
                $table->decimal('discrimination_index', 8, 4)->nullable();
                $table->decimal('point_biserial', 8, 4)->nullable();
                $table->unsignedInteger('correct_count')->default(0);
                $table->unsignedInteger('blank_count')->default(0);
                $table->decimal('alpha_if_deleted', 8, 4)->nullable();
                $table->text('indicator')->nullable();
                $table->string('cognitive_level', 20)->nullable();
                $table->string('status', 40)->default('Tinjau');
                $table->text('recommendation')->nullable();
                $table->longText('distractor_json')->nullable();
                $table->timestamps();

                $table->unique(
                    ['item_analysis_run_id', 'question_bank_id'],
                    'item_analysis_run_question_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_analysis_results');
        Schema::dropIfExists('item_analysis_runs');
    }
};
