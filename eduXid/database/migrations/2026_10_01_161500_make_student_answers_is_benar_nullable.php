<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_answers', function (Blueprint $table) {
            $table->boolean('is_benar')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('student_answers', function (Blueprint $table) {
            $table->boolean('is_benar')->default(false)->nullable(false)->change();
        });
    }
};
