<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Downloads raw bytes from an external URL, decoding whatever
 * Content-Encoding the server actually sent — instead of relying on
 * curl's automatic negotiation, which is what breaks here.
 *
 * Background: Guzzle/curl normally auto-negotiates compression — it
 * advertises the encodings its libcurl build supports via
 * Accept-Encoding, and auto-decodes whatever the server replies with.
 * That's usually invisible and fine. The problem: some CDNs (Agnes'
 * output host among them) send `Content-Encoding: br` (Brotli)
 * regardless of what Accept-Encoding the client actually sent — and
 * standard PHP builds don't ship Brotli support in libcurl at all
 * (it requires linking against libbrotli at compile time, which most
 * distro curl packages skip). Sending `Accept-Encoding: identity`
 * doesn't help if the server ignores it, which is exactly what's
 * happening here. The result is `cURL error 61: Unrecognized content
 * encoding type`.
 *
 * The fix: disable curl's automatic decoding entirely (`decode_content:
 * false`), so curl just hands back whatever bytes it received without
 * trying (and failing) to interpret them — then decode manually here
 * based on the real Content-Encoding header, using whichever decoder is
 * actually available. gzip/deflate are always available (core PHP,
 * via ext-zlib). Brotli is not — it needs the separate `brotli` PECL
 * extension, which isn't always available on shared hosting.
 */
class BinaryDownloader
{
    public static function get(string $url, int $timeoutSeconds = 60): string
    {
        $response = Http::withOptions(['decode_content' => false])
            ->timeout($timeoutSeconds)
            ->get($url)
            ->throw();

        $encoding = strtolower(trim((string) $response->header('Content-Encoding')));
        $body = $response->body();

        return match ($encoding) {
            'br' => self::decodeBrotli($body, $url),
            'gzip', 'x-gzip' => self::decodeOrFallback($body, fn ($b) => gzdecode($b)),
            'deflate' => self::decodeOrFallback($body, fn ($b) => @gzuncompress($b) ?: @gzinflate($b)),
            default => $body,
        };
    }

    protected static function decodeBrotli(string $data, string $url): string
    {
        if (function_exists('brotli_uncompress')) {
            $decoded = brotli_uncompress($data);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        // No usable decoder available — fail loudly and actionably rather
        // than silently writing corrupt bytes to disk (a broken .mp4 that
        // ffmpeg would fail on later, much further from the real cause).
        throw new RuntimeException(
            "Received a Brotli-compressed response from {$url} but this server has no way to decode it "
            . '(PHP\'s "brotli" extension is not installed). In cPanel: Software > Select PHP Version > '
            . '(your domain) > Extensions — enable "brotli" if it\'s listed. If it isn\'t offered, ask your '
            . 'host to add ext-brotli, or to disable forced Brotli compression on their CDN for this endpoint.'
        );
    }

    /** gzip/deflate decoders are always available in PHP (ext-zlib is effectively universal) — no missing-extension case to handle. */
    protected static function decodeOrFallback(string $data, callable $decoder): string
    {
        $decoded = $decoder($data);

        return $decoded !== false ? $decoded : $data;
    }
}
