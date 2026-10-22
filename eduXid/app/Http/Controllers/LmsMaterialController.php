<?php

namespace App\Http\Controllers;

use App\Models\LmsCourse;
use App\Models\LmsMaterial;
use App\Models\LmsTopic;
use App\Models\Teacher;
use App\Services\Sync\LmsSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class LmsMaterialController extends Controller
{
    /*
     * ==========================================================
     * [REWRITE-13a] LMS MATERIAL CONTROLLER
     * ==========================================================
     * Perubahan vs versi lama:
     *
     *   1. [FIX-403-UX] syncIssueFrom(): deteksi FILE_UPLOAD_FAILED —
     *      materi dengan file gagal upload ditahan di outbox oleh
     *      service (hold-back); guru kini melihat PESAN JUJUR tentang
     *      penyebabnya (file/device), bukan "menunggu sinkronisasi"
     *      generik yang tidak membantu.
     *
     *   2. [UNPUBLISH=TARIK] update(): transisi published → draft
     *      pada materi yang SUDAH PERNAH ter-push kini mengirim
     *      DELETE event ke siswa. Dulu hanya flag — materi + file
     *      tetap hidup di PWA siswa (materi terlarang tetap terbuka).
     *      Publish ulang aman: sync_id sama, versi lebih tinggi.
     *
     *   3. [FORCE-DELETE] destroy(): kegagalan pra-antrean (konfigurasi
     *      cloud / relasi rusak) tidak lagi membuat materi tak bisa
     *      dihapus selamanya — guru dapat memilih hapus paksa lokal
     *      (?force=1) dengan pesan yang jujur tentang konsekuensinya.
     *
     *   4. [NULL-GUARD] edit/update/destroy mengecek topic/course
     *      null dulu (relasi rusak = 404 informatif, bukan fatal).
     *
     *   5. [DELETE-EPOCH] Versi delete = epoch-ms (konsisten; choke
     *      point service juga menormalkan).
     *
     *   6. [IDENTITAS] getTeacher(): relasi teacher() dulu, fallback
     *      NIP/NIK/NUPTK — satu semantik dengan modul lain.
     *
     *   7. [INPUT] remove_file + file bersamaan: file yang diunggah
     *      diabaikan TAPI dilaporkan (dulu senyap). Log context
     *      sync_status tanpa asumsi kolom.
     * ==========================================================
     */

    private function getTeacher(): Teacher
    {
        $user = auth()->user();

        $teacher = $user ? $user->teacher() : null;

        if (!$teacher) {
            $loginId = trim((string) ($user->login_id ?? ''));

            if ($loginId !== '') {
                $teacher = Teacher::where(function ($q) use ($loginId) {
                    $q->where('nip', $loginId)
                      ->orWhere('nik', $loginId)
                      ->orWhere('nuptk', $loginId);
                })->first();
            }
        }

        if (!$teacher) {
            abort(403, 'Akun yang login belum terhubung dengan data GTK.');
        }

        return $teacher;
    }

    private function authorizeCourseOwner(LmsCourse $course): Teacher
    {
        $teacher = $this->getTeacher();

        if ((int) $course->teacher_id !== (int) $teacher->id) {
            abort(403, 'Anda tidak memiliki akses ke kelas LMS ini.');
        }

        return $teacher;
    }

    /**
     * [NULL-GUARD] Topic + course wajib utuh; relasi rusak = 404.
     */
    private function authorizeMaterialOwner(LmsMaterial $material): Teacher
    {
        $material->loadMissing('topic.course');

        if (!$material->topic || !$material->topic->course) {
            abort(404, 'Kelas/topik LMS untuk materi ini tidak ditemukan.');
        }

        return $this->authorizeCourseOwner($material->topic->course);
    }

    private function syncIssueFrom(?array $result): ?string
    {
        if (!is_array($result)) {
            return 'Respon sinkronisasi tidak valid.';
        }

        $status = strtoupper((string) ($result['status'] ?? ''));
        $error = trim((string) ($result['error'] ?? ($result['message'] ?? '')));

        if (($result['success'] ?? false) === true
            && in_array($status, ['ACCEPTED', 'DUPLICATE', 'SUCCESS'], true)) {
            return null;
        }

        /*
         * [FIX-403-UX] Pesan ramah untuk hold-back file:
         * service menahan metadata sampai file ter-upload.
         */
        if (str_contains($error, 'FILE_UPLOAD_FAILED')) {
            $detail = trim(str_replace('FILE_UPLOAD_FAILED:', '', $error));

            if (str_contains($detail, 'tidak ada di disk')) {
                return 'FILE materi hilang dari penyimpanan laptop — buka ulang '
                    . 'materi ini di aplikasi guru dan unggah ulang filenya.';
            }

            if (str_contains($detail, 'device')) {
                return 'perangkat sinkronisasi guru belum siap untuk mengunggah file — '
                    . 'file akan terkirim otomatis setelah perangkat tersedia.';
            }

            return 'gagal mengunggah FILE ke Cloud (' . ($detail !== '' ? $detail : 'tidak diketahui')
                . ') — dicoba ulang otomatis; materi belum terkirim sampai filenya berhasil naik.';
        }

        if ($status === 'PENDING') {
            return 'menunggu sinkronisasi (akan dicoba ulang otomatis oleh antrean).';
        }

        if ($status === 'FAILED') {
            return 'gagal disinkronkan' . ($error !== '' ? ': ' . $error : '')
                . '. Akan dicoba ulang otomatis oleh antrean.';
        }

        return 'belum terkonfirmasi oleh Sync Server.';
    }

    private function logSyncIssue(string $action, LmsMaterial $material, ?string $issue): void
    {
        Log::warning('Sinkronisasi LMS material bermasalah.', [
            'action' => $action,
            'material_id' => $material->id,
            'sync_id' => $material->sync_id,
            'topic_id' => $material->topic_id,
            'is_published' => (bool) $material->is_published,
            'issue' => $issue,
        ]);
    }

    private function validateMaterial(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'atp_item_id' => 'nullable|integer|exists:atp_items,id',
            'type' => 'required|in:text,pdf,image,video,audio,file,link',
            'content' => 'nullable|string',
            'external_url' => 'nullable|url|max:2048',
            'file' => 'nullable|file|max:51200',
            'is_published' => 'nullable|boolean',
        ]);
    }

    private function storeFile(Request $request): ?array
    {
        if (!$request->hasFile('file')) {
            return null;
        }

        $file = $request->file('file');

        if (!$file->isValid()) {
            throw new \RuntimeException('File materi tidak valid.');
        }

        $path = $file->store('lms/materials', 'public');

        if (!$path) {
            throw new \RuntimeException('File materi gagal disimpan.');
        }

        return [
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ];
    }

    private function deleteLocalFile(?string $path): void
    {
        if (!$path) { return; }

        try {
            Storage::disk('public')->delete($path);
        } catch (Throwable $e) {
            Log::warning('Gagal menghapus file materi LMS dari storage lokal.', [
                'file_path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function store(Request $request, LmsTopic $topic, LmsSyncService $syncService)
    {
        $topic->load('course');

        if (!$topic->course) {
            abort(404, 'Kelas LMS untuk topik ini tidak ditemukan.');
        }

        $this->authorizeCourseOwner($topic->course);

        $validated = $this->validateMaterial($request);

        $validated['is_published'] = $validated['is_published'] ?? false;

        $data = [
            'title' => $validated['title'],
            'atp_item_id' => $validated['atp_item_id'] ?? null,
            'type' => $validated['type'],
            'content' => $validated['content'] ?? null,
            'external_url' => $validated['external_url'] ?? null,
            'is_published' => $validated['is_published'],
            'sort_order' => ((int) ($topic->materials()->max('sort_order') ?? 0)) + 1,
            'sync_version' => 1,
        ];

        $fileData = $this->storeFile($request);

        if ($fileData) {
            $data = array_merge($data, $fileData);
        }

        $material = $topic->materials()->create($data);

        /* [DRAFT-SKIP] Draft tidak di-push. */
        if (!$validated['is_published']) {
            return back()->with('success', 'Materi berhasil ditambahkan sebagai DRAFT. '
                . 'Publikasikan lewat menu Edit saat siap untuk dikirim ke murid.');
        }

        try {
            $issue = $this->syncIssueFrom(
                $syncService->pushMaterialToStudents($material->fresh()->load('topic.course'), 'create')
            );

            if ($issue !== null) {
                $this->logSyncIssue('create', $material, $issue);

                return back()->with('warning', 'Materi berhasil ditambahkan di perangkat guru, tetapi ' . $issue);
            }
        } catch (Throwable $e) {
            Log::warning('Materi LMS tersimpan lokal tetapi gagal diproses untuk sinkronisasi.', [
                'material_id' => $material->id,
                'sync_id' => $material->sync_id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('warning', 'Materi berhasil ditambahkan di perangkat guru, tetapi sinkronisasi belum berhasil.');
        }

        return back()->with('success', 'Materi berhasil ditambahkan dan disinkronkan.');
    }

    public function edit(LmsMaterial $material)
    {
        /* [NULL-GUARD] Konsisten di semua aksi. */
        $this->authorizeMaterialOwner($material);

        return view('lms.materials.edit', compact('material'));
    }

    public function update(Request $request, LmsMaterial $material, LmsSyncService $syncService)
    {
        $this->authorizeMaterialOwner($material);
        $topic = $material->topic;
        $course = $topic->course;

        $validated = $this->validateMaterial($request);

        $oldFilePath = $material->file_path;
        $wasPublished = (bool) $material->is_published;

        $validated['is_published'] = $validated['is_published'] ?? $material->is_published;

        $data = [
            'title' => $validated['title'],
            'atp_item_id' => $validated['atp_item_id'] ?? null,
            'type' => $validated['type'],
            'content' => $validated['content'] ?? null,
            'external_url' => $validated['external_url'] ?? null,
            'is_published' => $validated['is_published'],
        ];

        $removeFile = $request->boolean('remove_file');
        $newFileData = null;
        $ignoredUpload = false;

        if ($removeFile) {
            if (!$oldFilePath) {
                return back()->with('warning', 'Materi ini tidak memiliki file yang dapat dihapus.');
            }

            $data = array_merge($data, [
                'file_path' => null,
                'file_name' => null,
                'mime_type' => null,
                'file_size' => null,
            ]);

            if ($request->hasFile('file')) {
                /* [INPUT] remove_file menang — file yang diunggah diabaikan, dilaporkan. */
                $ignoredUpload = true;
            }
        } else {
            if ($request->hasFile('file')) {
                $newFileData = $this->storeFile($request);

                if ($newFileData) {
                    $data = array_merge($data, $newFileData);
                }
            }
        }

        $material->update($data);
        $material = $material->fresh()->load('topic.course');

        $nowPublished = (bool) $material->is_published;

        /*
         * [UNPUBLISH=TARIK] Transisi published → draft pada materi
         * yang SUDAH PERNAH ter-push: kirim DELETE event ke siswa.
         * Dulu tidak ada event — materi + file tetap hidup di PWA.
         */
        if (!$nowPublished) {
            if ($wasPublished && trim((string) $material->sync_id) !== '') {
                $deleteVersion = (int) floor(microtime(true) * 1000);

                try {
                    $result = $syncService->pushMaterialDeleteToStudents(
                        (string) $material->sync_id,
                        $deleteVersion
                    );

                    $status = strtoupper((string) ($result['status'] ?? ''));

                    if (in_array($status, ['ACCEPTED', 'DUPLICATE', 'SUCCESS'], true)) {
                        Log::info('Materi ditarik dari murid (unpublish → delete event).', [
                            'material_id' => $material->id,
                            'sync_id' => $material->sync_id,
                        ]);
                    } else {
                        $this->logSyncIssue('unpublish-delete', $material, 'delete event ' . $status);
                    }
                } catch (Throwable $e) {
                    Log::warning('Gagal mengirim delete event saat unpublish materi.', [
                        'material_id' => $material->id,
                        'error' => $e->getMessage(),
                    ]);

                    return back()->with('warning', 'Materi disembunyikan dari daftar, tetapi perintah '
                        . 'penarikan dari perangkat murid belum berhasil masuk antrean — tekan Simpan '
                        . 'ulang atau coba lagi.');
                }
            }

            if (($removeFile || $newFileData) && $oldFilePath) {
                $this->deleteLocalFile($oldFilePath);
            }

            $msg = 'Materi berhasil diperbarui (masih draft). '
                . 'Publikasikan lewat menu Edit saat siap untuk dikirim ke murid.';

            if ($wasPublished) {
                $msg = 'Materi DITARIK dari perangkat murid (kembali draft).';
            }

            if ($ignoredUpload) {
                $msg .= ' Catatan: file yang Anda unggah diabaikan karena "Lepas file" dicentang.';
            }

            return back()->with('success', $msg);
        }

        try {
            $issue = $this->syncIssueFrom(
                $syncService->pushMaterialToStudents($material, 'update')
            );

            if ($issue !== null) {
                $this->logSyncIssue('update', $material, $issue);

                return back()->with('warning', ($removeFile
                    ? 'File berhasil dilepas dari materi, tetapi '
                    : 'Materi berhasil diperbarui lokal, tetapi ') . $issue);
            }
        } catch (Throwable $e) {
            Log::warning('Materi LMS diperbarui lokal tetapi gagal diproses untuk sinkronisasi.', [
                'material_id' => $material->id,
                'sync_id' => $material->sync_id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('warning', $removeFile
                ? 'File berhasil dilepas dari materi, tetapi perubahan belum berhasil diproses untuk sinkronisasi.'
                : 'Materi berhasil diperbarui lokal, tetapi perubahan belum berhasil diproses untuk sinkronisasi.');
        }

        if (($removeFile || $newFileData) && $oldFilePath) {
            $this->deleteLocalFile($oldFilePath);
        }

        $msg = $removeFile
            ? 'File materi berhasil dihapus dan perubahan disinkronkan.'
            : 'Materi berhasil diperbarui dan disinkronkan.';

        if ($ignoredUpload) {
            $msg .= ' Catatan: file yang diunggah diabaikan karena "Lepas file" dicentang.';
        }

        return back()->with('success', $msg);
    }

    public function destroy(Request $request, LmsMaterial $material, LmsSyncService $syncService)
    {
        $this->authorizeMaterialOwner($material);

        $syncId = trim((string) $material->sync_id);
        $materialId = $material->id;
        $filePath = $material->file_path;

        /*
         * [FORCE-DELETE] Kegagalan pra-antrean dulu membuat materi
         * tak bisa dihapus selamanya. Dengan ?force=1: hapus lokal
         * tanpa perintah hapus (salinan siswa terakhir tetap ada)
         * — jalan keluar yang jujur.
         */
        $force = $request->boolean('force');

        if ($syncId === '') {
            $this->deleteLocalFile($filePath);
            $material->delete();

            return back()->with('success', 'Materi berhasil dihapus (belum pernah tersinkronisasi, '
                . 'tidak ada perintah hapus yang perlu dikirim).');
        }

        $deleteVersion = (int) floor(microtime(true) * 1000);   // [DELETE-EPOCH]

        try {
            $result = $syncService->pushMaterialDeleteToStudents($syncId, $deleteVersion);

            $status = strtoupper((string) ($result['status'] ?? ''));
            $ok = in_array($status, ['ACCEPTED', 'DUPLICATE', 'SUCCESS'], true);
            $queued = $status === 'PENDING' || ($result['success'] ?? false) === false;

            $this->deleteLocalFile($filePath);
            $material->delete();

            if ($ok) {
                return back()->with('success', 'Materi berhasil dihapus dan perintah hapus diteruskan ke perangkat murid.');
            }

            if ($queued) {
                Log::warning('DELETE materi masuk antrean — lokal dihapus.', [
                    'material_id' => $materialId,
                    'sync_id' => $syncId,
                    'status' => $status,
                ]);

                return back()->with('warning', 'Materi dihapus di perangkat guru. Perintah hapus masuk '
                    . 'antrean dan akan dicoba ulang otomatis oleh antrean.');
            }

            return back()->with('warning', 'Materi dihapus di perangkat guru, tetapi perintah hapus '
                . 'belum terkonfirmasi (' . $status . ').');
        } catch (Throwable $e) {
            if ($force) {
                Log::warning('FORCE-DELETE materi lokal (perintah hapus tidak terkirim).', [
                    'material_id' => $materialId,
                    'sync_id' => $syncId,
                    'error' => $e->getMessage(),
                ]);

                $this->deleteLocalFile($filePath);
                $material->delete();

                return back()->with('warning', 'Materi DIHAPUS PAKSA dari perangkat guru. Salinan di '
                    . 'perangkat murid yang pernah menerimanya tidak ikut terhapus.');
            }

            Log::warning('DELETE materi gagal masuk antrean. Penghapusan dibatalkan.', [
                'material_id' => $materialId,
                'sync_id' => $syncId,
                'error' => $e->getMessage(),
            ]);

            return back()->with('warning', 'Materi belum dihapus karena perintah DELETE belum berhasil '
                . 'masuk antrean (' . $e->getMessage() . '). Untuk menghapus paksa dari perangkat guru '
                . 'saja, buka halaman ini dengan ?force=1.');
        }
    }
}