<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_grades', function (Blueprint $t) {
            if (!Schema::hasColumn('final_grades', 'nisn')) {
                $t->string('nisn')->nullable()->index();
            }
            if (!Schema::hasColumn('final_grades', 'd1')) { $t->tinyInteger('d1')->nullable(); }
            if (!Schema::hasColumn('final_grades', 'd2')) { $t->tinyInteger('d2')->nullable(); }
            if (!Schema::hasColumn('final_grades', 'd3')) { $t->tinyInteger('d3')->nullable(); }
            if (!Schema::hasColumn('final_grades', 'd4')) { $t->tinyInteger('d4')->nullable(); }
            if (!Schema::hasColumn('final_grades', 'd5')) { $t->tinyInteger('d5')->nullable(); }
            if (!Schema::hasColumn('final_grades', 'd6')) { $t->tinyInteger('d6')->nullable(); }
            if (!Schema::hasColumn('final_grades', 'd7')) { $t->tinyInteger('d7')->nullable(); }
            if (!Schema::hasColumn('final_grades', 'd8')) { $t->tinyInteger('d8')->nullable(); }
            if (!Schema::hasColumn('final_grades', 'mapel')) { $t->string('mapel')->nullable(); }
            if (!Schema::hasColumn('final_grades', 'kelas')) { $t->string('kelas')->nullable(); }
            if (!Schema::hasColumn('final_grades', 'teacher_name')) { $t->string('teacher_name')->nullable(); }
        });
    }

    public function down(): void
    {
        // Sengaja tidak drop — kolom dipakai sistem aktif.
    }
};