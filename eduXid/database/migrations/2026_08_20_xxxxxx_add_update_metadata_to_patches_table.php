<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan metadata untuk sistem update.
     */
    public function up(): void
    {
        Schema::table('patches', function (Blueprint $table) {
            /*
             * Versi yang harus sedang aktif sebelum
             * patch ini boleh dijalankan.
             *
             * Contoh:
             * Patch 1.0.1 membutuhkan 1.0.0.
             */
            $table->string('requires_version', 50)
                ->nullable()
                ->after('version');

            /*
             * Catatan rilis patch.
             */
            $table->text('release_notes')
                ->nullable()
                ->after('description');

            $table->index('requires_version');
        });
    }

    /**
     * Menghapus metadata update.
     */
    public function down(): void
    {
        Schema::table('patches', function (Blueprint $table) {
            $table->dropIndex([
                'patches_requires_version_index',
            ]);

            $table->dropColumn([
                'requires_version',
                'release_notes',
            ]);
        });
    }
};