<?php

declare(strict_types=1);

namespace App\Services\Sync;

use RuntimeException;

/**
 * V21R40: Cloud-only projection of local Dapodik master data.
 * The original full snapshot is retained for local/LAN/m2g flows. Never send
 * NIP, NIK, NISN, NUPTK, Dapodik person UUID, addresses or contact fields
 * to the store-and-forward Hub in a master snapshot.
 *
 * Fail-closed: explicit per-table allowlists; newly added Dapodik columns
 * must be reviewed before they can be published to Cloud.
 */
final class CloudMasterOpaqueProjectionV21
{
    /** @var array<string, list<string>> */
    private const SAFE_FIELDS = [
        'sekolah' => ['id','npsn','nama','bentuk_pendidikan','status_sekolah','kecamatan','kabupaten_kota','provinsi','created_at','updated_at'],
        'gurus' => ['id','nama','nama_lengkap','jenis_kelamin','jenis_ptk','status_kepegawaian','jabatan_ptk','deleted_at','created_at','updated_at'],
        'mapels' => ['id','nama_mata_pelajaran','urutan','created_at','updated_at'],
        'siswas' => ['id','nama','jenis_kelamin','created_at','updated_at'],
        'rombels' => ['id','nama','tingkat_pendidikan','semester_id','jenis_rombel','kurikulum','homeroom_teacher_id','jurusan','created_at','updated_at'],
        'enrollments' => ['id','student_id','homeroom_id','jenis_pendaftaran','pos_x','pos_y','status_akhir_semester','created_at','updated_at'],
        'pembelajaran' => ['id','teacher_id','subject_id','homeroom_id','jam_mengajar_per_minggu','status_di_kurikulum','created_at','updated_at'],
        'kokurikulers' => ['id','tema','nama_kegiatan','koordinator_id','homeroom_id','created_at','updated_at'],
    ];

    public static function project(array $local, string $schoolId, CloudDirectoryAliasV21 $aliaser): array
    {
        if (!preg_match('/^[0-9]{8}$/D', $schoolId)) {
            throw new RuntimeException('BLOCKED V21: identitas sekolah Cloud belum valid.');
        }
        $tables = [];
        foreach (MasterSnapshotService::TABLE_MAP as $category => $unused) {
            $rows = $local[$category] ?? null;
            if (!is_array($rows) || !isset(self::SAFE_FIELDS[$category])) {
                throw new RuntimeException('BLOCKED V21: kategori master tidak dikenal atau belum diaudit.');
            }
            $allowed = array_flip(self::SAFE_FIELDS[$category]);
            $out = [];
            foreach ($rows as $value) {
                if (!is_array($value) || !isset($value['id']) || !is_numeric($value['id']) || (int)$value['id'] <= 0) {
                    throw new RuntimeException('BLOCKED V21: baris master tidak mempunyai ID lokal sah.');
                }
                $safe = array_intersect_key($value, $allowed);
                if ($category === 'gurus') {
                    $login = '';
                    foreach (['nip','nik','nuptk'] as $field) {
                        $candidate = trim((string)($value[$field] ?? ''));
                        if ($candidate !== '') { $login = $candidate; break; }
                    }
                    if ($login !== '') {
                        $safe['cloud_alias'] = $aliaser->teacherAliasForAdmin($schoolId, $login);
                    }
                } elseif ($category === 'siswas') {
                    $nisn = trim((string)($value['nisn'] ?? ''));
                    if ($nisn !== '') {
                        $safe['cloud_alias'] = $aliaser->studentAliasForBundle($schoolId, $nisn);
                    }
                }
                $out[] = $safe;
            }
            $tables[$category] = $out;
        }

        $metadata = is_array($local['metadata'] ?? null) ? $local['metadata'] : [];
        // Hash the transport representation, not the raw local Dapodik data.
        // MasterApplier independently verifies this hash before any database write.
        $metadata['content_hash'] = MasterSnapshotService::canonicalHash($tables);
        $metadata['counts'] = array_map('count', $tables);
        $metadata['cloud_projection'] = 'v21-opaque';
        $metadata['requested_by'] = null;
        $metadata['tipe_file'] = 'm2g_master';
        $result = ['metadata' => $metadata, ...$tables];
        self::assertOpaque($result);
        return $result;
    }
    /** Reject any private or unreviewed field in a purported Cloud master. */
    public static function assertOpaque(array $master): void
    {
        if (($master['metadata']['cloud_projection'] ?? '') !== 'v21-opaque'
            || ($master['metadata']['requested_by'] ?? null) !== null) {
            throw new RuntimeException('BLOCKED V21: metadata snapshot Cloud anonim tidak sah.');
        }
        foreach (MasterSnapshotService::TABLE_MAP as $category => $unused) {
            $rows = $master[$category] ?? null;
            if (!is_array($rows) || !isset(self::SAFE_FIELDS[$category])) {
                throw new RuntimeException('BLOCKED V21: daftar master Cloud anonim tidak lengkap.');
            }
            $allow = array_flip(self::SAFE_FIELDS[$category]);
            if ($category === 'gurus' || $category === 'siswas') $allow['cloud_alias'] = true;
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['id']) || !is_numeric($row['id']) || (int)$row['id'] <= 0) {
                    throw new RuntimeException('BLOCKED V21: referensi master Cloud tidak sah.');
                }
                foreach ($row as $key => $value) {
                    if (!isset($allow[$key])) {
                        throw new RuntimeException('BLOCKED V21: snapshot Cloud berisi kolom pribadi/tidak dikenal.');
                    }
                    if ($key === 'cloud_alias' && !OpaqueIdentityV21::isAlias(
                        (string)$value, $category === 'gurus' ? 'teacher' : 'student'
                    )) {
                        throw new RuntimeException('BLOCKED V21: alias di snapshot Cloud tidak valid.');
                    }
                }
            }
        }
    }

}
