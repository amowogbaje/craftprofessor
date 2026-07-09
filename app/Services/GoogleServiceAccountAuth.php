<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mints a Vertex AI OAuth2 access token from the service account JSON at
 * GOOGLE_APPLICATION_CREDENTIALS, without pulling in google/apiclient.
 * Signs a JWT assertion (RS256) and exchanges it at Google's token endpoint.
 * Tokens are cached for their lifetime (minus a safety margin).
 */
class GoogleServiceAccountAuth
{
    protected const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';
    protected const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    public function getAccessToken(): string
    {
        return Cache::remember('google_vertex_access_token', 3300, function () {
            $credentialsPath = env('GOOGLE_APPLICATION_CREDENTIALS');

            if (empty($credentialsPath) || !is_readable($credentialsPath)) {
                throw new RuntimeException(
                    'GOOGLE_APPLICATION_CREDENTIALS must point to a readable service account JSON file.'
                );
            }

            $credentials = json_decode(file_get_contents($credentialsPath), true);
            $clientEmail = $credentials['client_email'] ?? null;
            $privateKey = $credentials['private_key'] ?? null;

            if (!$clientEmail || !$privateKey) {
                throw new RuntimeException('Service account JSON missing client_email or private_key.');
            }

            $now = time();
            $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->base64UrlEncode(json_encode([
                'iss' => $clientEmail,
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URI,
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            $signatureInput = "{$header}.{$claims}";
            openssl_sign($signatureInput, $signature, $privateKey, 'sha256WithRSAEncryption');
            $jwt = $signatureInput . '.' . $this->base64UrlEncode($signature);

            $response = Http::asForm()->post(self::TOKEN_URI, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->failed()) {
                throw new RuntimeException('Failed to obtain Google access token: ' . $response->body());
            }

            return $response->json('access_token');
        });
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
