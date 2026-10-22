<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {

            $table->uuid('sync_id')
                ->nullable()
                ->unique()
                ->after('id');

            $table->unsignedInteger('sync_version')
                ->default(1)
                ->after('sync_id');

            $table->string('sync_status', 20)
                ->nullable()
                ->after('sync_version');

            $table->timestamp('last_synced_at')
                ->nullable()
                ->after('sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn([
                'sync_id',
                'sync_version',
                'sync_status',
                'last_synced_at',
            ]);
        });
    }
};