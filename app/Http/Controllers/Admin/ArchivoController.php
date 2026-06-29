<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\Archivo;
use App\Services\SecureUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArchivoController extends Controller
{
    public function store(Request $request, SecureUploadService $uploader): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'purpose' => ['required', 'string', 'in:logo,favicon,pwa_icon'],
        ]);

        try {
            $archivo = $uploader->store(
                $validated['file'],
                $request->user(),
                $validated['purpose'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'file' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'publicId' => $archivo->public_id,
            'purpose' => $archivo->purpose,
            'mimeType' => $archivo->mime_type,
        ], 201);
    }

    public function show(Archivo $archivo): StreamedResponse
    {
        $this->authorizeTenantAccess($archivo);

        return Storage::disk($archivo->disk)->response($archivo->path, $archivo->original_name, [
            'Content-Type' => $archivo->mime_type,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function authorizeTenantAccess(Archivo $archivo): void
    {
        $user = auth()->user();

        if ($user === null) {
            abort(401);
        }

        if ($user->isPlatformAdmin()) {
            return;
        }

        if ($user->tenant_id !== $archivo->tenant_id) {
            abort(403);
        }
    }
}
