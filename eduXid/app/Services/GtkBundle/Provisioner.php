<?php

namespace App\Services\GtkBundle;

use App\Services\Sync\{CloudDirectoryAliasV21, CloudMasterDataService, EduxidCloudConfig, LmsSyncService, OpaqueRouteRegistryV21, SchoolIdentity, SyncClient};
use Illuminate\Support\Facades\{Crypt, DB, Http, Schema};
use RuntimeException;

class Provisioner
{
    public function catalog(bool $provision = false): array
    {
        $query = DB::table('teachers')->orderBy('nama');
        if (Schema::hasColumn('teachers','deleted_at')) $query->whereNull('deleted_at');
        $profiles=[]; $seen=[];
        foreach ($query->get() as $teacher) {
            $p=$this->profile((int)$teacher->id, $provision);
            if (isset($seen[$p['login_id']])) throw new RuntimeException('Identitas login GTK ganda; perbaiki Data Pokok sebelum membuat paket.');
            $seen[$p['login_id']]=true; $profiles[]=$p;
        }
        return $profiles;
    }

    public function profile(int $teacherId, bool $provision = false): array
    {
        $query=DB::table('teachers')->where('id',$teacherId);
        if (Schema::hasColumn('teachers','deleted_at')) $query->whereNull('deleted_at');
        $t=$query->first();
        if (!$t) throw new RuntimeException('GTK tidak ditemukan pada Data Pokok aktif sekolah ini.');
        $ids=array_values(array_unique(array_filter(array_map(static fn($v)=>trim((string)$v),[$t->nip??'', $t->nik??'', $t->nuptk??'']))));
        if ($ids===[]) throw new RuntimeException('GTK #'.$teacherId.' belum mempunyai NIP/NIK/NUPTK untuk login.');
        $users=DB::table('users')->whereIn('login_id',$ids)->get();
        if ($users->count()>1) throw new RuntimeException('GTK #'.$teacherId.' mempunyai lebih dari satu akun login; pilih satu identitas akun di Data Pokok.');
        $user=$users->first(); $login=trim((string)($user->login_id??$ids[0]));
        if (!preg_match('/^[A-Za-z0-9._]{1,100}$/D',$login)) throw new RuntimeException('Identitas login GTK tidak sesuai format perangkat; tanda hubung tidak diperbolehkan.');
        $role=strtolower(trim((string)($user->role??'')));
        if ($user&&!in_array($role,['guru','wali_kelas','guru_bk','kepala_sekolah','tendik'],true)) {
            throw new RuntimeException('Identitas GTK #'.$teacherId.' dipakai akun non-GTK. Perbaiki identitas login sebelum membuat paket.');
        }
        if (!in_array($role,['guru','wali_kelas','guru_bk','kepala_sekolah','tendik'],true)) {
            $kind=mb_strtolower(($t->jenis_ptk??'').' '.($t->jabatan_ptk??''));
            $role=str_contains($kind,'kepala sekolah')?'kepala_sekolah':
                ((str_contains($kind,'bk')||str_contains($kind,'konselor')||str_contains($kind,'konseling'))?'guru_bk':
                ((str_contains($kind,'tendik')||str_contains($kind,'kependidikan')||str_contains($kind,'tata usaha')||str_contains($kind,'staf'))?'tendik':'guru'));
        }
        $identity=app(SchoolIdentity::class);
        $school=$identity->schoolId();
        // Admin Sekolah owns the alias secret. Provision the deterministic teacher
        // route before SchoolIdentity tries to resolve its V21 device_id.
        // Never allow a GTK installation or an unauthenticated caller to mint aliases.
        $cloudLogin = $this->authorizedCloudLogin($school, $login, $provision);
        return ['teacher_id'=>$teacherId,'name'=>(string)$t->nama,'login_id'=>$login,
            'cloud_login_id'=>$cloudLogin,
            'role'=>$role,'school_id'=>$school,'device_id'=>OpaqueRouteRegistryV21::enabled()
                ? $school.'-'.$cloudLogin.'-PC' : $identity->teacherPcDeviceId($login),
            'source_user_id'=>$user?->id];
    }

    private function authorizedCloudLogin(string $schoolId, string $localLogin, bool $provision): string
    {
        if (!OpaqueRouteRegistryV21::enabled()) return $localLogin;
        $user = auth()->user();
        if (!$user || strtolower(trim((string) $user->role)) !== 'admin' || Installation::owner() !== null) {
            throw new RuntimeException('BLOCKED V21: provisioning alias hanya oleh Admin Sekolah yang terautentikasi.');
        }
        // Existing mapping may only be re-used. The registry rejects changes
        // to an existing digest, so rotation or an inconsistent school key fails closed.
        $aliaser = CloudDirectoryAliasV21::fromConfig();
        $issued = $provision
            ? $aliaser->provisionLocalTeacherAlias($schoolId, $localLogin)
            : $aliaser->teacherAliasForAdmin($schoolId, $localLogin);
        if ($provision) {
            $verified = app(OpaqueRouteRegistryV21::class)->alias($schoolId, 'teacher', $localLogin);
            if (!hash_equals($issued, $verified)) {
                throw new RuntimeException('BLOCKED V21: mapping alias GTK tidak konsisten.');
            }
        } else {
            // GET /Siapkan Paket GTK is a read-only preview. Still reject a
            // changed alias key when a digest entry already exists.
            if (!Schema::hasTable('cloud_identity_alias_routes')) {
                throw new RuntimeException('BLOCKED V21: tabel registry alias lokal belum tersedia.');
            }
            $digest = OpaqueRouteRegistryV21::lookupDigest($schoolId, 'teacher', $localLogin);
            $current = DB::table('cloud_identity_alias_routes')
                ->where('school_id', $schoolId)->where('role', 'teacher')
                ->where('local_digest', $digest)->value('cloud_alias');
            if ($current !== null && !hash_equals((string)$current, $issued)) {
                throw new RuntimeException('BLOCKED V21: alias GTK berbeda dari registry; periksa secret sekolah.');
            }
        }
        return $issued;
    }

    public function prepareCloud(): array
    {
        $profiles=$this->catalog(true);
        if ($profiles===[]) throw new RuntimeException('Data GTK belum tersedia.');
        // Dua direktori dan snapshot harus berhasil sebelum paket boleh disebut siap.
        $snapshot=app(CloudMasterDataService::class)->pushSnapshot(true);
        $students=app(LmsSyncService::class)->pushStudentAccountDirectory();
        if (($students['success']??false)!==true||(int)($students['upserted']??-1)!==(int)($students['students']??0)) {
            throw new RuntimeException('Direktori siswa tidak diterapkan lengkap oleh Cloud. Siapkan kembali setelah masalah koneksi/data diperbaiki.');
        }
        $accounts=[];
        foreach ($profiles as $p) {
            $assignments=DB::table('teaching_assignments')->where('teacher_id',$p['teacher_id'])->get();
            $scopes=[]; $homeIds=[];
            foreach ($assignments as $a) {
                $h=DB::table('homerooms')->where('id',$a->homeroom_id)->first();
                $s=DB::table('subjects')->where('id',$a->subject_id)->first();
                if (!$h||!$s) throw new RuntimeException('Penugasan GTK memiliki rombel/mapel yang belum lengkap.');
                $homeIds[]=(int)$h->id;
                $scopes[]=['ta_id'=>(int)$a->id,'teacher_id'=>$p['teacher_id'],
                    'homeroom_id'=>(int)$h->id,'subject_id'=>(int)$s->id,
                    'homeroom_name'=>$h->nama,'subject_name'=>$s->nama_mata_pelajaran,
                    'semester_id'=>(string)($h->semester_id??''),
                    'roster'=>DB::table('enrollments')->join('students','students.id','=','enrollments.student_id')
                        ->where('enrollments.homeroom_id',$h->id)->select('students.id','students.nisn','students.nama')->get()->map(fn($r)=>(array)$r)->all()];
            }
            foreach (DB::table('homerooms')->where('homeroom_teacher_id',$p['teacher_id'])->get() as $h) {
                $homeIds[]=(int)$h->id;
                $waliRoster=DB::table('enrollments')->join('students','students.id','=','enrollments.student_id')
                    ->where('enrollments.homeroom_id',$h->id)
                    ->select('students.id','students.nisn','students.nama')->get()->map(fn($r)=>(array)$r)->all();
                $scopes[]=['teacher_id'=>$p['teacher_id'],'homeroom_id'=>(int)$h->id,
                    'ta_id'=>0,'is_homeroom_teacher'=>true,'roster'=>$waliRoster,
                    'semester_id'=>(string)($h->semester_id??'')];
            }
            foreach (DB::table('kokurikulers')->where('koordinator_id',$p['teacher_id'])->get() as $k) {
                $scopes[]=['teacher_id'=>$p['teacher_id'],'homeroom_id'=>(int)($k->homeroom_id??0),'kokurikuler_id'=>(int)$k->id];
            }
            if ($scopes===[]) $scopes[]=['teacher_id'=>$p['teacher_id']];
            $accounts[]=['login_id'=>$p['login_id'],'name'=>$p['name'],'school_name'=>DB::table('schools')->value('nama'),
                'password_hash'=>hash('sha256',$p['school_id'].'|'.$p['login_id'].'|'.$p['school_id']),
                'homeroom_ids'=>implode(',',array_unique($homeIds)),'assignments'=>$scopes];
        }
        // Strict projection at the final Cloud boundary: never POST a raw GTK
        // login or NISN in any nested roster. This matches LMS directory sync.
        if (OpaqueRouteRegistryV21::enabled()) {
            $aliaser=CloudDirectoryAliasV21::fromConfig();
            $schoolId=(string)$profiles[0]['school_id'];
            $rosterIds=[];
            foreach ($accounts as $account) {
                foreach ($account['assignments'] as $assignment) {
                    foreach (($assignment['roster'] ?? []) as $student) {
                        $id=trim((string)($student['nisn'] ?? ''));
                        if ($id === '') {
                            throw new RuntimeException('BLOCKED V21: daftar kelas berisi siswa tanpa identitas lokal.');
                        }
                        $rosterIds[$id]=$id;
                    }
                }
            }
            // Persist in small verified batches BEFORE publishing directory.
            // Repeated pupils in several rosters are written only once.
            $aliaser->provisionIds($schoolId, 'student', array_values($rosterIds));
            $aliaser->provisionIds($schoolId, 'teacher', array_column($accounts, 'login_id'));
            $accounts=array_map(static fn(array $account):array => $aliaser->teacher($schoolId,$account),$accounts);
        }
        // Worker V21 rejects payloads whose recursive identity scan exceeds
        // its 30,000-node limit. A fixed 25-teacher chunk may contain far more
        // than 30,000 nested roster entries. Keep a conservative bound on the
        // *actual* JSON structure while preserving each teacher's authoritative
        // assignments in one complete upsert (never split one teacher's roster).
        foreach ($this->teacherDirectoryBatches($accounts) as $batch) {
            if (OpaqueRouteRegistryV21::enabled()) {
                $aliaser->assertOpaquePayload(['accounts' => $batch]);
            }
            $r=$this->adminPost('/api/admin/teacher-accounts/sync',[
                'school_id'=>$profiles[0]['school_id'], 'accounts'=>$batch,
            ]);
            if ((int)($r['upserted']??-1)!==count($batch)) {
                throw new RuntimeException('Direktori GTK tidak diterapkan lengkap oleh Cloud; ulangi Siapkan Cloud.');
            }
        }
        return ['gtk'=>count($profiles),'master_version'=>(int)($snapshot['version']??0)];
    }

    public function token(array $profile, object $record): string
    {
        $token=$record->device_token ? Crypt::decryptString($record->device_token) : '';
        if ($token==='') {
            $local=\App\Models\SyncDevice::where('school_id',$profile['school_id'])->where('device_id',$profile['device_id'])->where('is_active',true)->first();
            $token=trim((string)($local?->sync_token??''));
        }
        if ($token!=='' && $this->validToken($token,$profile['school_id'],$profile['device_id'], $profile['cloud_login_id'] ?? $profile['login_id'])) return $token;
        $code=$this->adminPost('/api/admin/enrollment/create',['school_id'=>$profile['school_id'],'max_uses'=>1,
            'expires_at'=>now()->addHour()->toIso8601String()])['enrollment_code']??'';
        if (!is_string($code)||$code==='') throw new RuntimeException('Cloud tidak mengembalikan kode pairing.');
        $r=app(SyncClient::class)->registerDevice(['school_id'=>$profile['school_id'],'device_id'=>$profile['device_id'],
            'device_type'=>'teacher','login_id'=>($profile['cloud_login_id'] ?? $profile['login_id']),'device_name'=>OpaqueRouteRegistryV21::enabled() ? 'eduXid GTK PC' : 'eduXid '.$profile['name'],'enrollment_token'=>$code]);
        $token=$r['device_token']??$r['data']['device_token']??'';
        if (!is_string($token)||$token===''||!$this->validToken($token,$profile['school_id'],$profile['device_id'], $profile['cloud_login_id'] ?? $profile['login_id'])) throw new RuntimeException('Pairing GTK belum berhasil diverifikasi oleh Cloud.');
        return $token;
    }

    private function validToken(string $token,string $schoolId,string $expectedDevice,string $expectedLogin): bool
    {
        // Matching only NPSN is not sufficient: a token from a different PC or
        // from a legacy device must never be re-used for an opaque GTK bundle.
        $me=$this->http()->withToken($token)->get($this->url().'/api/device/me');
        if (in_array($me->status(),[401,403],true)) return false;
        if (!$me->successful() || $me->json('success')!==true) {
            throw new RuntimeException('Tidak dapat memverifikasi identitas perangkat Cloud.');
        }
        if (!hash_equals($schoolId,(string)$me->json('data.school_id'))
            || !hash_equals($expectedDevice,(string)$me->json('data.device_id'))
            || !hash_equals($expectedLogin,(string)$me->json('data.login_id'))
            || (string)$me->json('data.device_type')!=='teacher') return false;
        $r=$this->http()->withToken($token)->get($this->url().'/api/teacher/master-data');
        if (in_array($r->status(),[401,403],true)) return false;
        if (!$r->successful()||$r->json('success')!==true||!is_array($r->json('master')??$r->json('data.master'))
            || (string)$r->json('school_id')!==$schoolId) {
            throw new RuntimeException('Tidak dapat memverifikasi pairing dan Data Pokok Cloud. Coba lagi setelah koneksi pulih.');
        }
        return true;
    }

    /**
     * Bounds the Worker V21 recursive scanner, not just the number of teachers.
     * No identity, password, or roster is logged or exposed.
     *
     * @param array<int,array<string,mixed>> $accounts
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function teacherDirectoryBatches(array $accounts): array
    {
        $batches = [];
        $batch = [];
        $nodes = 3; // top-level envelope, school ID, and accounts array
        foreach ($accounts as $account) {
            $cost = $this->opaquePayloadNodeCount($account);
            if ($cost > 12000) {
                throw new RuntimeException(
                    'Satu direktori GTK terlalu besar untuk batas pemeriksaan Cloud V21. '
                    .'Periksa jumlah penugasan/roster ganda sebelum menyiapkan Cloud.'
                );
            }
            if ($batch !== [] && (count($batch) >= 25 || $nodes + $cost > 12000)) {
                $batches[] = $batch;
                $batch = [];
                $nodes = 3;
            }
            $batch[] = $account;
            $nodes += $cost;
        }
        if ($batch !== []) $batches[] = $batch;
        return $batches;
    }

    /** Mirrors the Worker's visited-node count with a lower safety budget. */
    private function opaquePayloadNodeCount(mixed $value, int $depth = 0): int
    {
        if ($depth > 22) {
            throw new RuntimeException('Direktori GTK terlalu dalam untuk protokol Cloud V21.');
        }
        if (!is_array($value)) return 1;
        $count = 1;
        foreach ($value as $child) {
            $count += $this->opaquePayloadNodeCount($child, $depth + 1);
            if ($count > 12000) return $count;
        }
        return $count;
    }

    /** Never expose the response body: Cloud may include personal fields. */
    private function safeCloudErrorCode(mixed $code): string
    {
        $code = (string) $code;
        return preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $code) === 1
            ? $code : 'UNSPECIFIED_REJECTION';
    }

    private function adminPost(string $path,array $body): array
    {
        $key=EduxidCloudConfig::adminToken();
        if ($key==='') throw new RuntimeException('Admin perlu mengatur koneksi Cloud sekolah terlebih dahulu.');
        $r=$this->http()->withHeaders(['Authorization'=>'Admin '.$key])->post($this->url().$path,$body);
        if (!$r->successful()||$r->json('success')!==true||!is_array($r->json('data'))) {
            // The previous generic HTTP 422 hid the one actionable diagnostic
            // that Cloud already supplies. Show only a validated error CODE;
            // never reflect error.message, response body, account data or tokens.
            $code = $this->safeCloudErrorCode($r->json('error.code'));
            throw new RuntimeException(
                'Direktori GTK ditolak Cloud (HTTP '.$r->status().' / '.$code.'). '
                .'Periksa aturan alias V21 dan kode penolakan; tidak perlu reset database atau pairing.'
            );
        }
        return $r->json('data');
    }

    private function url(): string
    {
        $url=rtrim(EduxidCloudConfig::syncServer(),'/');
        if (parse_url($url,PHP_URL_SCHEME)!=='https') throw new RuntimeException('URL Cloud harus memakai HTTPS.');
        return $url;
    }

    private function http()
    {
        $http=Http::timeout(120)->connectTimeout(15)->acceptJson()->asJson();
        $ca=trim((string)config('eduxid.ca_cert',''));
        if ($ca!=='') {
            if (!is_file($ca)) throw new RuntimeException('Sertifikat CA yang dikonfigurasi belum ditemukan.');
            $http=$http->withOptions(['verify'=>$ca]);
        }
        return $http;
    }
}
