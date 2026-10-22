<?php

namespace App\Http\Middleware;

use App\Services\Update\UpdateAfterSync;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TriggerUpdateAfterSync
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $status = $response->getStatusCode();
            $hasErrorFlash = $request->hasSession() && $request->session()->has('error');

            if ($status >= 200 && $status < 400 && !$hasErrorFlash) {
                app(UpdateAfterSync::class)->trigger();
            }
        } catch (Throwable) {
            // Update tidak boleh pernah menggagalkan proses sinkronisasi data.
        }

        return $response;
    }
}
