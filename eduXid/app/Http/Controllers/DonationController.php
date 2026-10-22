<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DonationStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

final class DonationController extends Controller
{
    public function status(Request $request, DonationStatusService $service): JsonResponse|RedirectResponse
    {
        // V21R150: Status JSON hanya untuk pemanggil API/AJAX.
        // Navigasi browser biasa tidak boleh membuka payload Cloud mentah.
        // Tidak mengubah kontrak JSON atau status HTTP untuk AJAX.
        if (!$request->expectsJson()) {
            return redirect()->to(route('about.index') . '#eduxidDonationCenter');
        }

        $user = $request->user();

        if (!$user || !$service->isGtk($user)) {
            return response()->json(['success' => false, 'message' => 'Akses GTK diperlukan.'], 403);
        }

        try {
            $data = $service->status($user);
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\App\Services\Sync\GtkCloudPairingRequired $e) {
            return response()->json([
                'success' => false,
                'code' => 'CLOUD_GTK_PAIRING_REQUIRED',
                'message' => $e->getMessage(),
                'data' => [
                    'status' => DonationStatusService::STATUS_UNVERIFIED,
                    'verified' => false,
                    'cloud_reachable' => null,
                ],
            ], 409);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [
                    'status' => DonationStatusService::STATUS_UNVERIFIED,
                    'verified' => false,
                    'cloud_reachable' => false,
                ],
            ], 503);
        }
    }

    public function claim(Request $request, DonationStatusService $service): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$service->isGtk($user)) {
            return response()->json(['success' => false, 'message' => 'Akses GTK diperlukan.'], 403);
        }

        $validated = $request->validate([
            'donor_name' => ['required', 'string', 'max:100'],
            'whatsapp' => ['required', 'string', 'min:9', 'max:24', 'regex:/^[0-9+\-\s()]+$/'],
            'channel' => ['required', 'in:gopay,dana'],
            'amount' => ['nullable', 'integer', 'min:1000', 'max:100000000'],
            'transaction_ref' => ['required', 'string', 'min:4', 'max:120'],
            'donated_at' => ['nullable', 'date'],
        ]);

        try {
            $data = $service->claim($user, $validated);
            return response()->json(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }
    }

    public function redeem(Request $request, DonationStatusService $service): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$service->isGtk($user)) {
            return response()->json(['success' => false, 'message' => 'Akses GTK diperlukan.'], 403);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'min:20', 'max:96'],
        ]);

        try {
            $data = $service->redeem($user, (string) $validated['code']);
            return response()->json(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
