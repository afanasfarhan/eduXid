<?php

namespace App\Http\Controllers;

use App\Services\LocalMediaService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LocalMediaController extends Controller
{
    public function __construct(
        protected LocalMediaService $media
    ) {
    }

    public function show(Request $request, string $path): BinaryFileResponse
    {
        $relative = $this->media->normalizeLegacyPath($path);

        if ($relative === null) {
            throw new NotFoundHttpException();
        }

        $absolute = $this->media->path($relative);

        if ($absolute === null || !is_file($absolute)) {
            throw new NotFoundHttpException();
        }

        $mime = $this->media->mimeType($relative) ?? 'application/octet-stream';

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
