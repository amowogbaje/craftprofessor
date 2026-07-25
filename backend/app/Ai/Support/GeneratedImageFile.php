<?php

namespace App\Ai\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Wraps raw provider bytes and produces TWO stored variants:
 *
 *  - "optimized": small WebP, capped dimension, for on-page display/load speed.
 *  - "quality":   larger JPEG, higher fidelity, for the user to download/reuse
 *                 elsewhere (social media, print, etc).
 *
 * Built against Intervention Image v4 (composer.json: 4.2.0). v4 replaced
 * v3's `new ImageManager(new Driver())` / `->read()` / `->toWebp()` with a
 * static factory, `decodeBinary()`, and explicit Encoder objects passed to
 * `encode()` — see https://image.intervention.io/v4 if this needs updating
 * again on a future major version bump.
 */
class GeneratedImageFile
{
    // "Optimized" variant — tuned for fast page load.
    protected int $displayMaxDimension = 1280;
    protected int $webpQuality = 80;

    // "Quality" variant — tuned for downloading/reuse, not on-page display.
    protected int $downloadMaxDimension = 2048;
    protected int $downloadJpegQuality = 90;

    public function __construct(protected string $binary) {}

    /**
     * Small, fast-loading WebP for on-page display. Use this URL anywhere
     * the image is rendered in the app itself.
     */
    public function storeOptimizedAs(string $path): string
    {
        $path = $this->withExtension($path, 'webp');

        try {
            $encoded = $this->freshImage()
                ->scaleDown(width: $this->displayMaxDimension, height: $this->displayMaxDimension)
                ->encode(new WebpEncoder(quality: $this->webpQuality));

            Storage::disk('public')->put($path, $encoded->toString(), 'public');
        } catch (\Throwable $e) {
            // Resize/encode failed (bad Intervention API call, corrupt
            // bytes, missing driver, etc). Don't lose the generation the
            // provider already paid for — store the raw bytes as-is under
            // the original path/extension instead of throwing.
            Log::warning('GeneratedImageFile: optimize failed, storing raw bytes', ['error' => $e->getMessage()]);
            return $this->storeRawAs($path);
        }

        return $path;
    }

    /**
     * Larger, higher-fidelity JPEG meant for the user to download and reuse
     * elsewhere. JPEG (not PNG) since these are photographic AI-generated
     * images with no transparency to preserve, and JPEG gives noticeably
     * smaller files than PNG at visually-equivalent quality.
     */
    public function storeQualityAs(string $path): string
    {
        $path = $this->withExtension($path, 'jpg');

        try {
            $encoded = $this->freshImage()
                ->scaleDown(width: $this->downloadMaxDimension, height: $this->downloadMaxDimension)
                ->encode(new JpegEncoder(quality: $this->downloadJpegQuality));

            Storage::disk('public')->put($path, $encoded->toString(), 'public');
        } catch (\Throwable $e) {
            Log::warning('GeneratedImageFile: optimize failed, storing raw bytes', ['error' => $e->getMessage()]);
            return $this->storeRawAs($path);
        }

        return $path;
    }

    /**
     * Fallback used when resize/encode blows up: write the untouched
     * provider bytes so the generation isn't wasted, under whatever raw
     * format the provider actually sent (we can't guarantee webp/jpeg if
     * encoding itself is what failed).
     */
    protected function storeRawAs(string $path): string
    {
        $path = preg_replace('/\.\w+$/', '.png', $path);
        Storage::disk('public')->put($path, $this->binary, 'public');

        return $path;
    }

    protected function freshImage(): ImageInterface
    {
        // Fresh decode per variant so scaleDown() for one doesn't affect the
        // other. usingDriver() takes the driver class-string (v4), not an
        // instance like v3's `new ImageManager(new Driver())`.
        return ImageManager::usingDriver(Driver::class)->decodeBinary($this->binary);
    }

    protected function withExtension(string $path, string $ext): string
    {
        return preg_replace('/\.\w+$/', ".{$ext}", $path);
    }
}