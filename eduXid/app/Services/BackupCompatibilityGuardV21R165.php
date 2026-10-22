<?php

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Pemeriksaan pra-restore untuk backup SQLite eduxid generasi lama.
 * Baca-saja; tabel migrations/versi/updater pada target tidak dipulihkan.
 * Kegagalan validasi menghentikan restore sebelum database aktif diubah.
 */
final class BackupCompatibilityGuardV21R165
{
    private const IGNORE_TABLES = [
        'sessions', 'cache', 'cache_locks', 'migrations',
        'app_versions', 'patches', 'jobs', 'job_batches', 'failed_jobs',
    ];

    public function inspect(string $backupPath, string $activePath): array
    {
        $backup = $this->connect($backupPath);
        $active = $this->connect($activePath);
        $sourceTables = $this->tables($backup);
        $targetTables = $this->tables($active);
        if (!isset($sourceTables['users']) || !isset($targetTables['users'])) {
            throw new RuntimeException('Database restore harus memiliki tabel users pada sumber dan tujuan.');
        }

        $this->assertHealthy($backup, 'backup');
        $this->assertHealthy($active, 'database aktif');
        $this->assertSameSchool($backup, $active, $sourceTables, $targetTables);
        $this->assertSameDevice($backup, $active, $sourceTables, $targetTables);
        $this->assertMigrationDirection($backup, $active, $sourceTables, $targetTables);

        $tables = [];
        $skipped = [];
        foreach ($sourceTables as $table => $_) {
            if (in_array($table, self::IGNORE_TABLES, true)) {
                $skipped[] = $table;
                continue;
            }
            if (!isset($targetTables[$table])) {
                throw new RuntimeException('Restore ditolak: tabel backup tidak ada di versi baru: '.$table);
            }
            $oldColumns = $this->columns($backup, $table);
            $newColumns = $this->columns($active, $table);
            foreach ($oldColumns as $name => $column) {
                if (!isset($newColumns[$name])) {
                    throw new RuntimeException('Restore ditolak agar data tidak hilang: kolom lama tidak dikenali: '.$table.'.'.$name);
                }
                if ($column['pk'] > 0 && $newColumns[$name]['pk'] <= 0) {
                    throw new RuntimeException('Restore ditolak: perubahan primary key '.$table.'.'.$name);
                }
            }
            $oldHasRows = (bool) $backup->query('SELECT 1 FROM '.$this->qid($table).' LIMIT 1')->fetchColumn();
            if ($oldHasRows) {
                foreach ($newColumns as $name => $column) {
                    if (isset($oldColumns[$name])) {
                        continue;
                    }
                    if ($column['required'] && !$column['pk'] && !$column['default']) {
                        throw new RuntimeException('Restore ditolak: kolom wajib baru belum tersedia pada backup: '.$table.'.'.$name);
                    }
                }
            }
            $tables[] = $table;
        }
        return [
            'compatible' => true,
            'restore_table_count' => count($tables),
            'preserved_metadata_tables' => $skipped,
            'source_table_count' => count($sourceTables),
            'target_table_count' => count($targetTables),
        ];
    }

    private function connect(string $path): PDO
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('File database tidak ditemukan atau tidak dapat dibaca.');
        }
        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->query('PRAGMA query_only = ON');
        return $pdo;
    }

    private function assertHealthy(PDO $db, string $label): void
    {
        if (strtolower(trim((string) $db->query('PRAGMA quick_check')->fetchColumn())) !== 'ok') {
            throw new RuntimeException('Pemeriksaan integritas '.$label.' tidak lulus.');
        }
        if ($db->query('PRAGMA foreign_key_check')->fetch(PDO::FETCH_NUM) !== false) {
            throw new RuntimeException('Referensi foreign key '.$label.' tidak konsisten.');
        }
    }

    private function tables(PDO $db): array
    {
        $names = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys($names, true);
    }

    private function columns(PDO $db, string $table): array
    {
        $rows = $db->query('PRAGMA table_info('.$this->qid($table).')')->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $r) {
            $result[$r['name']] = [
                'required' => (int) $r['notnull'] === 1,
                'pk' => (int) $r['pk'],
                'default' => $r['dflt_value'] !== null,
            ];
        }
        return $result;
    }

    private function assertMigrationDirection(PDO $backup, PDO $active, array $old, array $new): void
    {
        if (!isset($old['migrations'], $new['migrations'])) {
            throw new RuntimeException('Tabel migrasi tidak tersedia untuk validasi versi database.');
        }
        $installed = array_fill_keys($active->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN), true);
        foreach ($backup->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN) as $migration) {
            if (!isset($installed[$migration])) {
                throw new RuntimeException('Restore ditolak: versi target belum mempunyai migrasi dalam backup. Perbarui aplikasi terlebih dahulu.');
            }
        }
    }

    private function assertSameSchool(PDO $backup, PDO $active, array $old, array $new): void
    {
        if (!isset($old['schools'], $new['schools'])) return;
        $a = $this->nonemptyValues($backup, 'schools', 'npsn');
        $b = $this->nonemptyValues($active, 'schools', 'npsn');
        if ($a && $b && !array_intersect($a, $b)) {
            throw new RuntimeException('Restore ditolak: identitas sekolah backup berbeda dari instalasi tujuan.');
        }
    }

    private function assertSameDevice(PDO $backup, PDO $active, array $old, array $new): void
    {
        if (!isset($old['sync_devices'], $new['sync_devices'])) return;
        $a = $this->nonemptyValues($backup, 'sync_devices', 'device_id');
        $b = $this->nonemptyValues($active, 'sync_devices', 'device_id');
        // Kedua database sudah memiliki pairing yang berbeda: jangan kloning identitas perangkat.
        if ($a && $b && !array_intersect($a, $b)) {
            throw new RuntimeException('Restore ditolak: pairing berasal dari perangkat berbeda. Gunakan prosedur pemindahan perangkat resmi.');
        }
    }

    private function nonemptyValues(PDO $db, string $table, string $column): array
    {
        if (!isset($this->columns($db, $table)[$column])) return [];
        $stmt = $db->query('SELECT DISTINCT '.$this->qid($column).' FROM '.$this->qid($table)
            .' WHERE '.$this->qid($column).' IS NOT NULL AND TRIM('.$this->qid($column).') != \'\' LIMIT 50');
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function qid(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
