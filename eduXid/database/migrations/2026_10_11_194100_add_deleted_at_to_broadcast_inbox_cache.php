<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('broadcast_inbox_cache') &&
            !Schema::hasColumn('broadcast_inbox_cache', 'deleted_at')) {
            Schema::table('broadcast_inbox_cache', function (Blueprint $table) {
                $table->timestamp('deleted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('broadcast_inbox_cache') &&
            Schema::hasColumn('broadcast_inbox_cache', 'deleted_at')) {
            Schema::table('broadcast_inbox_cache', function (Blueprint $table) {
                $table->dropColumn('deleted_at');
            });
        }
    }
};
