<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security\DonationIntegrityV21;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * V21R36: non-punitive tamper detection. Never gates teaching, exams or grading.
 * Do not trust this process against an attacker who controls the entire OS/runtime.
 */
final class VerifyDonationIntegrityV21
{
    public function handle(Request $request, Closure $next): Response
    {
        $audit = DonationIntegrityV21::verify(base_path(), (string) config('eduxid.update_public_key', ''));
        if ($audit['ok']) {
            return $next($request);
        }

        // Do not render untrusted payment details or accept claim/redeem through altered code.
        if ($request->is('about') || $request->is('donasi/*')) {
            return response('Komponen Donasi eduXid berubah atau tidak lengkap. Pembayaran dan verifikasi donasi sementara tidak tersedia pada instalasi ini. Fitur pembelajaran tetap gratis dan dapat digunakan. Hubungi administrator untuk memulihkan file dari rilis resmi.', 503)
                ->header('Content-Type', 'text/plain; charset=UTF-8')
                ->header('Cache-Control', 'no-store')
                ->header('X-eduXid-Donation-Integrity', 'failed');
        }

        if (!$request->attributes->has('eduxid_donation_integrity_logged')) {
            $request->attributes->set('eduxid_donation_integrity_logged', true);
            Log::warning('eduXid donation integrity check failed', ['errors' => $audit['errors']]);
        }
        $response = $next($request);
        $response->headers->set('X-eduXid-Donation-Integrity', 'failed');
        if (!$response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            && $response->getStatusCode() === 200
            && str_contains(strtolower((string) $response->headers->get('Content-Type', '')), 'text/html')) {
            $html = $response->getContent();
            if (is_string($html) && stripos($html, '</body>') !== false) {
                $notice = '<div role="alert" style="position:fixed;bottom:8px;left:8px;right:8px;z-index:2147483647;background:#7f1d1d;color:#fff;padding:12px 16px;border-radius:10px;font:600 14px system-ui">Peringatan keamanan eduXid: integritas halaman Donasi/Reminder tidak sesuai rilis. Jangan melakukan pembayaran melalui instalasi ini. Fitur sekolah tetap gratis.</div>';
                $html = preg_replace('~</body>~i', $notice.'</body>', $html, 1);
                $response->setContent($html);
                $response->headers->remove('Content-Length');
            }
        }
        return $response;
    }
}
