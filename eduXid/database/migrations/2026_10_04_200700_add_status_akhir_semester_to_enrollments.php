<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('enrollments', 'status_akhir_semester')) {
            Schema::table('enrollments', function (Blueprint $table) {
                $table
                    ->string('status_akhir_semester', 30)
                    ->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('enrollments', 'status_akhir_semester')) {
            Schema::table('enrollments', function (Blueprint $table) {
                $table->dropColumn('status_akhir_semester');
            });
        }
    }
};
