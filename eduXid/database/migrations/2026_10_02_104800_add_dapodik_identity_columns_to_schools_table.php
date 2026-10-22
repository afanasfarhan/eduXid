<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            if (!Schema::hasColumn('schools', 'nss')) {
                $table->string('nss')->nullable()->after('npsn');
            }

            if (!Schema::hasColumn('schools', 'desa_kelurahan')) {
                $table->string('desa_kelurahan')->nullable()->after('alamat_jalan');
            }
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('schools', 'nss')) {
                $columns[] = 'nss';
            }

            if (Schema::hasColumn('schools', 'desa_kelurahan')) {
                $columns[] = 'desa_kelurahan';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
