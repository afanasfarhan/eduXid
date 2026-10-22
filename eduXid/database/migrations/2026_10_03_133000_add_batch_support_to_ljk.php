<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ljk_results')) {
            $addBatchUuid = !Schema::hasColumn('ljk_results', 'batch_uuid');
            $addSourceRef = !Schema::hasColumn('ljk_results', 'source_ref');

            if ($addBatchUuid || $addSourceRef) {
                Schema::table('ljk_results', function (Blueprint $table) use ($addBatchUuid, $addSourceRef) {
                    if ($addBatchUuid) {
                        $table->string('batch_uuid', 64)->nullable()->index();
                    }
                    if ($addSourceRef) {
                        $table->string('source_ref', 255)->nullable();
                    }
                });
            }
        }

        if (!Schema::hasTable('ljk_batches')) {
            Schema::create('ljk_batches', function (Blueprint $table) {
                $table->id();
                $table->string('batch_uuid', 64)->unique();
                $table->unsignedBigInteger('exam_id');
                $table->unsignedBigInteger('teacher_id');
                $table->date('exam_date');
                $table->unsignedInteger('total_items')->default(0);
                $table->unsignedInteger('finalized_items')->default(0);
                $table->string('status', 20)->default('partial');
                $table->timestamp('finalized_at')->nullable();
                $table->timestamps();

                $table->index(['teacher_id', 'exam_date']);
                $table->index(['exam_id', 'exam_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ljk_batches');

        if (Schema::hasTable('ljk_results')) {
            $dropBatchUuid = Schema::hasColumn('ljk_results', 'batch_uuid');
            $dropSourceRef = Schema::hasColumn('ljk_results', 'source_ref');

            if ($dropBatchUuid || $dropSourceRef) {
                Schema::table('ljk_results', function (Blueprint $table) use ($dropBatchUuid, $dropSourceRef) {
                    if ($dropBatchUuid) {
                        $table->dropColumn('batch_uuid');
                    }
                    if ($dropSourceRef) {
                        $table->dropColumn('source_ref');
                    }
                });
            }
        }
    }
};
