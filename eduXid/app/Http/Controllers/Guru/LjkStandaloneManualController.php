<?php

namespace App\Http\Controllers\Guru;

use App\Http\Controllers\Controller;
use App\Services\Ljk\LjkStandaloneManualService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class LjkStandaloneManualController extends Controller
{
    public function __construct(private LjkStandaloneManualService $service) {}

    private function respond(callable $callback): JsonResponse
    {
        try { return response()->json(['success' => true, 'data' => $callback()]); }
        catch (Throwable $e) { report($e); return response()->json(['success' => false, 'message' => $e->getMessage()], 422); }
    }

    public function index(): JsonResponse
    {
        return $this->respond(fn() => [
            'assignments' => $this->service->assignments(),
            'sessions' => $this->service->sessions(),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            // Pencatatan sesi lokal adalah prioritas. Sinkronisasi HP masuk
            // antrean Cloud setelah sesi tersimpan; jika pairing belum ada,
            // guru dapat menekan Kirim ke PWA Guru sesudah diperbaiki.
            $session = $this->service->create($request->only([
                'teaching_assignment_id', 'title', 'tanggal', 'jenis_penilaian', 'keys',
            ]));
            try {
                $session['pwa_sync'] = $this->service->syncToPwa($session['session_id']);
            } catch (Throwable $error) {
                report($error);
                $session['pwa_sync_warning'] = $error->getMessage();
            }
            return $session;
        });
    }

    public function destroySession(string $session): JsonResponse
    {
        return $this->respond(fn() => $this->service->deleteSession($session));
    }

    public function show(string $session): JsonResponse
    {
        return $this->respond(fn() => $this->service->details($session));
    }

    public function results(string $session): JsonResponse
    {
        return $this->respond(fn() => $this->service->results($session));
    }

    public function scan(Request $request, string $session): JsonResponse
    {
        return $this->respond(function () use ($request, $session) {
            $request->validate(['image' => ['nullable','image','max:20480']]);
            return $this->service->saveScan($session, $request->all(), $request->file('image'));
        });
    }

    public function review(Request $request, string $session, string $scanId): JsonResponse
    {
        return $this->respond(fn() => $this->service->review($session, $scanId,
            $request->only(['nisn', 'answers'])));
    }

    public function image(string $session, string $scanId)
    {
        try { return response()->file($this->service->imagePath($session, $scanId),
            ['Cache-Control' => 'private, no-store']); }
        catch (Throwable) { abort(404); }
    }

    public function clearAll(string $session): JsonResponse
    {
        return $this->respond(fn() => $this->service->clearAll($session));
    }

    public function delete(string $session, string $scanId): JsonResponse
    {
        return $this->respond(fn() => $this->service->deleteScan($session, $scanId));
    }

    public function publish(Request $request, string $session): JsonResponse
    {
        return $this->respond(fn() => $this->service->publish($session, is_array($request->input('scan_ids')) ? $request->input('scan_ids') : []));
    }

    public function sync(string $session): JsonResponse
    {
        return $this->respond(fn() => $this->service->syncToPwa($session));
    }
}
