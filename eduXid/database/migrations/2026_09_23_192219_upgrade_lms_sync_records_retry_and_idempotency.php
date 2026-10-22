<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Kolom retry (fair rotation + dead-letter)
        if (!Schema::hasColumn('lms_sync_records', 'attempts')) {
            Schema::table('lms_sync_records', function ($t) {
                $t->unsignedInteger('attempts')->default(0);
            });
        }
        if (!Schema::hasColumn('lms_sync_records', 'last_attempt_at')) {
            Schema::table('lms_sync_records', function ($t) {
                $t->dateTime('last_attempt_at')->nullable();
            });
        }

        /*
         * 2. Dedupe sebelum index unik dibuat — sisakan baris TERBARU
         *    per kunci komposit event.
         */
        DB::statement(<<<'SQL'
DELETE FROM lms_sync_records
WHERE id NOT IN (
    SELECT MAX(id) FROM lms_sync_records
    GROUP BY node_id, entity_type, entity_id, operation, version,
             COALESCE(target_device_id, ''), COALESCE(target_homeroom_id, -1)
)
SQL);

        /*
         * 3. Unique index idempotensi (expression index — SQLite ≥ 3.9).
         *    COALESCE menetralkan NULL pada target agar dua push ke
         *    target berbeda tidak bertabrakan, dan dua push ke target
         *    sama TIDAK bisa terduplikasi oleh race.
         */
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS uq_lms_sync_records_event
ON lms_sync_records (
    node_id, entity_type, entity_id, operation, version,
    COALESCE(target_device_id, ''),
    COALESCE(target_homeroom_id, -1)
)
SQL);
    }

    public function down(): void
    {
        // Forward-only.
    }
};