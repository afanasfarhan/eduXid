<?php

declare(strict_types=1);

namespace App\Services\GtkBundle;

use App\Services\Sync\CloudDirectoryAliasV21;
use PDO;
use RuntimeException;

/**
 * Generates *only* GTK-local mapping digests for the owner's authorized roster.
 * No Admin APP_KEY, school HMAC key or personal ID is added to the Cloud envelope.
 */
final class GtkBundleAliasRoutesV21
{
    public static function appKeyFromEnv(string $env): string
    {
        if (!preg_match('/^APP_KEY\s*=\s*(.*?)\s*$/m', str_replace("\r", '', $env), $match)) {
            throw new RuntimeException('BLOCKED V21: APP_KEY Paket GTK belum ada.');
        }
        $value = trim($match[1], " \t\n\r\0\x0B\"'");
        $key = str_starts_with($value, 'base64:')
            ? base64_decode(substr($value, 7), true)
            : $value;
        if (!is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('BLOCKED V21: APP_KEY Paket GTK tidak valid.');
        }
        return $key;
    }

    public static function digest(string $key, string $school, string $role, string $localId): string
    {
        if (!preg_match('/^[0-9]{8}$/D', $school) || !in_array($role, ['teacher','student'], true)
            || trim($localId) === '' || strlen($localId) > 128) {
            throw new RuntimeException('BLOCKED V21: parameter registry GTK tidak valid.');
        }
        // Byte-for-byte same as OpaqueRouteRegistryV21::lookupDigest().
        return hash_hmac('sha256', "eduxid-v21-local-map:v1\0{$school}\0{$role}\0" . trim($localId), $key);
    }

    public static function seed(PDO $pdo, string $school, int $userId, ?string $key, CloudDirectoryAliasV21 $generator): int
    {
        if (!is_string($key) || strlen($key) < 32) throw new RuntimeException('BLOCKED V21: APP_KEY GTK tidak valid.');
        $hasTable = static function (string $table) use ($pdo): bool {
            $q = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
            $q->execute([$table]);
            return (bool) $q->fetchColumn();
        };
        foreach (['teachers','teaching_assignments','homerooms','students','enrollments','cloud_identity_alias_routes'] as $table) {
            if (!$hasTable($table)) throw new RuntimeException('BLOCKED V21: tabel roster/alias Paket GTK belum tersedia.');
        }
        // V21R46: users.id belongs to the login account, NOT to teachers.
        // Neither the Dapodik teachers schema nor a restored pre-V21 schema
        // guarantees teachers.user_id. Resolve through the same local login
        // identity used by Provisioner::profile() (nip/nik/nuptk).
        $owner = $pdo->prepare('SELECT login_id FROM users WHERE id = ? LIMIT 1');
        $owner->execute([$userId]);
        $login = $owner->fetchColumn();
        $login = $login === false ? '' : trim((string) $login);
        if ($login === '') {
            throw new RuntimeException('BLOCKED V21: akun pemilik GTK tidak mempunyai login_id lokal.');
        }

        $teacherColumns = array_column(
            $pdo->query('PRAGMA table_info("teachers")')->fetchAll(PDO::FETCH_ASSOC), 'name'
        );
        $identityColumns = array_values(array_intersect(['nip', 'nik', 'nuptk'], $teacherColumns));
        if ($identityColumns === []) {
            throw new RuntimeException('BLOCKED V21: kolom identitas guru lokal tidak ditemukan pada skema Paket GTK.');
        }
        $conditions = array_map(
            static fn (string $column): string => 'TRIM("' . $column . '") = ?',
            $identityColumns
        );
        $q = $pdo->prepare(
            'SELECT id FROM teachers WHERE (' . implode(' OR ', $conditions) . ') ORDER BY id LIMIT 2'
        );
        $q->execute(array_fill(0, count($identityColumns), $login));
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        // Never guess an owner, never widen the roster to a different teacher.
        if (count($ids) !== 1) {
            throw new RuntimeException('BLOCKED V21: identitas login GTK tidak cocok tepat satu baris teachers.');
        }
        $teacherId = (int) $ids[0];
        $q = $pdo->prepare(<<<'SQL'

            SELECT DISTINCT TRIM(s.nisn) AS nisn
            FROM students s
            JOIN enrollments e ON e.student_id = s.id
            JOIN homerooms h ON h.id = e.homeroom_id
            WHERE s.nisn IS NOT NULL AND TRIM(s.nisn) <> ''
              AND (h.homeroom_teacher_id = :wali OR EXISTS (
                  SELECT 1 FROM teaching_assignments ta
                  WHERE ta.teacher_id = :teacher AND ta.homeroom_id = h.id
              ))
            ORDER BY nisn
SQL
        );
        $q->execute(['wali' => $teacherId, 'teacher' => $teacherId]);
        $now = gmdate('Y-m-d H:i:s');
        $insert = $pdo->prepare(<<<'SQL'

            INSERT INTO cloud_identity_alias_routes (school_id, role, local_digest, cloud_alias, created_at, updated_at)
            VALUES (?, 'student', ?, ?, ?, ?)
SQL
        );
        $lookup = $pdo->prepare(<<<'SQL'

            SELECT cloud_alias FROM cloud_identity_alias_routes
            WHERE school_id = ? AND role = 'student' AND local_digest = ? LIMIT 1
SQL
        );
        $count = 0;
        $pdo->beginTransaction();
        try {
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $nisn = (string) $row['nisn'];
                $alias = $generator->studentAliasForBundle($school, $nisn);
                $digest = self::digest($key, $school, 'student', $nisn);
                $lookup->execute([$school, $digest]);
                $existing = $lookup->fetchColumn();
                if ($existing !== false) {
                    if (!hash_equals((string) $existing, $alias)) {
                        throw new RuntimeException('BLOCKED V21: konflik mapping siswa pada Paket GTK.');
                    }
                    continue;
                }
                $insert->execute([$school, $digest, $alias, $now, $now]);
                $count++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $count;
    }
}
