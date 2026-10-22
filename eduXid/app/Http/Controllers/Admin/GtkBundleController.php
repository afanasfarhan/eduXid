<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GtkBundle\{Builder, Installation, Provisioner};
use App\Services\Sync\{EduxidCloudConfig, OpaqueRouteRegistryV21, SchoolIdentity};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class GtkBundleController extends Controller
{
    private function authorizeAdmin(): void
    {
        abort_unless(auth()->check()&&strtolower(trim((string)auth()->user()->role))==='admin',403);
        abort_if(Installation::owner()!==null,403,'Paket GTK hanya dapat disiapkan pada instalasi admin sekolah.');
    }

    /**
     * Only inspect schema. Restored pre-V21 databases must be migrated by an
     * administrator after backup, never implicitly from an HTTP request.
     */
    private function assertRestoreSchemaReady(): void
    {
        $required = [
            'schools', 'teachers', 'students', 'homerooms', 'subjects',
            'teaching_assignments', 'enrollments', 'users', 'app_settings',
            'sync_devices', 'gtk_bundle_exports',
        ];
        if (OpaqueRouteRegistryV21::enabled()) {
            $required[] = 'cloud_identity_alias_routes';
        }
        $missing = array_values(array_filter($required, static fn (string $table): bool => !Schema::hasTable($table)));
        if ($missing !== []) {
            throw new RuntimeException(
                'Database hasil restore belum memiliki struktur Paket GTK V21 ('
                .implode(', ', $missing)
                .'). Backup database terlebih dahulu, jalankan php artisan migrate --force, lalu siapkan Cloud kembali. Tidak perlu tarik Dapodik jika data pokok restore masih lengkap.'
            );
        }
    }

    private function safeFailure(Throwable $e): string
    {
        if ($e instanceof \Illuminate\Database\QueryException
            && str_contains(strtolower($e->getMessage()), 'no such column: user_id')) {
            report($e);
            // Hanya nama tabel dan lokasi file, bukan nilai parameter, NIP/NISN, token, atau path absolut.
            $sql = $e->getSql();
            $table = null;
            if (preg_match('/\b(?:from|into|update)\s+[\"`]?([a-z_][a-z0-9_]*)/i', $sql, $match)) {
                $table = strtolower($match[1]);
            }
            $origin = null;
            foreach ($e->getTrace() as $frame) {
                $file = str_replace('\\', '/', (string)($frame['file'] ?? ''));
                if (str_contains($file, '/app/') && str_ends_with(strtolower($file), '.php')) {
                    $origin = basename($file).':'.(int)($frame['line'] ?? 0);
                    break;
                }
            }
            return 'Query masih memakai user_id pada tabel tanpa kolom tersebut'
                .($table ? ' (tabel='.$table.')' : '')
                .($origin ? ' [kode='.$origin.']' : '')
                .'. Jangan menambah kolom secara manual.';
        }
        if ($e instanceof RuntimeException || $e instanceof \App\Services\Sync\SchoolIdentityException) {
            return mb_substr($e->getMessage(), 0, 550);
        }
        report($e);
        return 'Proses Paket GTK gagal di server. Periksa storage/logs/laravel.log pada instalasi Admin; data lama tidak dihapus.';
    }

    public function index(Provisioner $provisioner, Builder $builder)
    {
        $this->authorizeAdmin();
        $profiles=[]; $error=null; $schoolId=null; $cloudPrepared=false;
        try {
            $this->assertRestoreSchemaReady();
            $schoolId=app(SchoolIdentity::class)->schoolId();
            $profiles=$provisioner->catalog();
            $builder->preflight();
            $cloudPrepared = (bool)DB::table('app_settings')
                ->where('key', 'gtk_bundle::cloud_ready')->value('value');
        } catch(Throwable $e) { $error=$this->safeFailure($e); }
        $exports=$schoolId && Schema::hasTable('gtk_bundle_exports') ? DB::table('gtk_bundle_exports')->where('school_id',$schoolId)->where('status','ready')->get()->keyBy('teacher_id') : collect();
        return view('admin.gtk-bundles',compact('profiles','exports','error','schoolId','cloudPrepared'));
    }

    public function prepare(Request $request, Provisioner $provisioner, Builder $builder)
    {
        $this->authorizeAdmin();
        @set_time_limit(600);
        // Two browser tabs must not provision the same directory concurrently.
        // A local file lock avoids extra writes to the already-busy SQLite DB.
        $lock = @fopen(storage_path('framework/gtk-cloud-prepare.lock'), 'c');
        if ($lock === false) {
            return response()->json(['success' => false,
                'message' => 'Tidak dapat mengunci proses persiapan Cloud; periksa izin storage/framework.'], 503);
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return response()->json(['success' => false,
                'message' => 'Persiapan Cloud sedang berjalan di jendela lain. Selesaikan proses tersebut terlebih dahulu.'], 409);
        }
        try {
            $this->assertRestoreSchemaReady();
            $builder->preflight();
            $hash = $builder->fingerprint();
            $url = EduxidCloudConfig::syncServer();
            $result = $provisioner->prepareCloud();
            if ($hash !== $builder->fingerprint() || $url !== EduxidCloudConfig::syncServer()) {
                throw new RuntimeException('Data Pokok atau koneksi Cloud berubah saat persiapan. Ulangi persiapan Cloud sebelum membuat paket.');
            }
            DB::table('app_settings')->updateOrInsert(['key' => 'gtk_bundle::cloud_ready'], [
                'value' => json_encode([
                    'school_id' => app(SchoolIdentity::class)->schoolId(),
                    'url' => EduxidCloudConfig::syncServer(),
                    'hash' => $hash,
                    'result' => $result,
                    'at' => now()->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                'created_at' => now(),
            ]);
            return response()->json(['success' => true,
                'message' => 'Data Pokok dan direktori Cloud siap. Pilih GTK lalu buat paket.', 'result' => $result]);
        } catch (Throwable $e) {
            // Never mask the ORIGINAL lock/alias error with a second failed
            // write while SQLite is still busy. Do not reset the database.
            try {
                if (Schema::hasTable('app_settings')) {
                    DB::table('app_settings')->where('key', 'gtk_bundle::cloud_ready')->delete();
                }
            } catch (Throwable $cleanupError) {
                report($cleanupError);
            }
            $message = $this->safeFailure($e);
            $code = str_contains(strtolower($message), 'sqlite sedang digunakan') ? 503 : 422;
            return response()->json(['success' => false, 'message' => $message], $code);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ensureCloudReadyForCreation(Builder $builder, Provisioner $provisioner): void
    {
        $school = app(SchoolIdentity::class)->schoolId();
        $url = EduxidCloudConfig::syncServer();
        $hash = $builder->fingerprint();
        $ready = json_decode((string) DB::table('app_settings')->where('key', 'gtk_bundle::cloud_ready')->value('value'), true);
        if (is_array($ready) && ($ready['school_id'] ?? '') === $school
            && ($ready['url'] ?? '') === $url && ($ready['hash'] ?? '') === $hash) {
            return;
        }
        // Menyiapkan ulang direktori Cloud hanya menggunakan token Admin Sekolah
        // yang sah. Tidak pernah melakukan pairing sekolah otomatis tanpa izin.
        if (EduxidCloudConfig::adminToken() === '') {
            throw new RuntimeException('Pairing Cloud sekolah belum tersedia. Hubungkan Admin Sekolah ke Cloud terlebih dahulu.');
        }
        $lock = @fopen(storage_path('framework/gtk-cloud-prepare.lock'), 'c');
        if ($lock === false) throw new RuntimeException('Tidak dapat mengunci persiapan Cloud GTK.');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Persiapan Cloud GTK sedang berjalan di jendela lain.');
            $ready = json_decode((string) DB::table('app_settings')->where('key','gtk_bundle::cloud_ready')->value('value'), true);
            if (is_array($ready) && ($ready['school_id'] ?? '') === $school
                && ($ready['url'] ?? '') === $url && ($ready['hash'] ?? '') === $hash) return;
            $builder->preflight();
            $result = $provisioner->prepareCloud();
            if ($hash !== $builder->fingerprint() || $url !== EduxidCloudConfig::syncServer()) {
                throw new RuntimeException('Data Pokok atau URL Cloud berubah. Buat ulang persiapan setelah data stabil.');
            }
            DB::table('app_settings')->updateOrInsert(['key' => 'gtk_bundle::cloud_ready'], [
                'value' => json_encode(['school_id'=>$school,'url'=>$url,'hash'=>$hash,
                    'result'=>$result,'at'=>now()->toIso8601String()], JSON_THROW_ON_ERROR),
                'created_at'=>now(), 'updated_at'=>now(),
            ]);
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public function create(Request $request, Builder $builder, Provisioner $provisioner)
    {
        $this->authorizeAdmin();
        $input=$request->validate(['teacher_id'=>'required|integer|min:1|exists:teachers,id','server_ip'=>'nullable|ip']);
        @set_time_limit(600);
        try {
            $this->assertRestoreSchemaReady();
            $this->ensureCloudReadyForCreation($builder, $provisioner);
            $result=$builder->create((int)$input['teacher_id'],$input['server_ip']??null);
            $result['download_url']=route('admin.gtk-bundles.download',$result['id']);
            return response()->json(['success'=>true,'result'=>$result]);
        } catch(Throwable $e) { return response()->json(['success'=>false,'message'=>$this->safeFailure($e)],422); }
    }

    public function download(string $id)
    {
        $this->authorizeAdmin();
        $school=app(SchoolIdentity::class)->schoolId();
        $record=DB::table('gtk_bundle_exports')->where('id',$id)->where('school_id',$school)->where('status','ready')->first();
        abort_unless($record,404);
        $path=(string)config('gtk_bundle.output_directory').'/'.$record->id.'.zip';
        abort_unless(is_file($path),404,'Paket belum tersedia atau telah dihapus; buat ulang dari halaman Paket GTK.');
        abort_unless(hash_equals((string)$record->sha256,hash_file('sha256',$path)),409,'Paket berubah atau rusak; buat ulang sebelum membagikan.');
        // Existing READY bundles keep their SHA-256 and opaque identity. Only
        // their browser-facing download filename changes; no rebuild is needed.
        try {
            $downloadName=Builder::archiveNameForTeacher($school,(int)$record->teacher_id);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return response()->download($path,$downloadName,['Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
}
