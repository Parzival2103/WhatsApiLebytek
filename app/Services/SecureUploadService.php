<?php

namespace App\Services;

use App\Models\Core\Archivo;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class SecureUploadService
{
    /** @var list<string> */
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function store(UploadedFile $file, User $actor, string $purpose): Archivo
    {
        $detectedMime = $this->detectMimeType($file);

        if (! in_array($detectedMime, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException('File type is not allowed.');
        }

        if ($detectedMime === 'image/svg+xml' || $file->getClientOriginalExtension() === 'svg') {
            throw new InvalidArgumentException('SVG uploads are not allowed.');
        }

        $encoded = $this->reencode($file->getRealPath(), $detectedMime);
        $disk = config('filesystems.uploads_disk', 'uploads');
        $filename = Str::random(40).'.'.$this->extensionForMime($detectedMime);
        $path = 'branding/'.$filename;

        Storage::disk($disk)->put($path, $encoded);

        return Archivo::create([
            'tenant_id' => $actor->tenant_id ?? $this->resolveTenantId($actor),
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $detectedMime,
            'size' => strlen($encoded),
            'hash' => hash('sha256', $encoded),
            'purpose' => $purpose,
        ]);
    }

    private function detectMimeType(UploadedFile $file): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file->getRealPath());

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    private function reencode(string $path, string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => $this->reencodeJpeg($path),
            'image/png' => $this->reencodePng($path),
            'image/webp' => $this->reencodeWebp($path),
            default => throw new InvalidArgumentException('Unsupported image type.'),
        };
    }

    private function reencodeJpeg(string $path): string
    {
        $image = imagecreatefromjpeg($path);

        if ($image === false) {
            throw new RuntimeException('Unable to process JPEG image.');
        }

        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function reencodePng(string $path): string
    {
        $image = imagecreatefrompng($path);

        if ($image === false) {
            throw new RuntimeException('Unable to process PNG image.');
        }

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function reencodeWebp(string $path): string
    {
        if (! function_exists('imagecreatefromwebp')) {
            throw new RuntimeException('WebP support is not available.');
        }

        $image = imagecreatefromwebp($path);

        if ($image === false) {
            throw new RuntimeException('Unable to process WebP image.');
        }

        ob_start();
        imagewebp($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }

    private function resolveTenantId(User $actor): int
    {
        return app(ConfigurationService::class)->resolveTenantId($actor->tenant_id);
    }
}
