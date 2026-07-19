<?php

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\StoryImagePrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PinterestService
{
    // Consent/authorize page — same in both environments. Only the data
    // API and token exchange endpoints differ between sandbox/production.
    public const AUTH_URL = 'https://www.pinterest.com/oauth/';
    public const STATE_TTL_MINUTES = 10;

    public const ALL_SCOPES = [
        'pins:read',
        'boards:read',
        'boards:write',
        'user_accounts:read',
        'ads:read',
        'catalogs:read',
        'pins:write', // needed for postPin
    ];

    // 'production' | 'sandbox'. Switchable via config/services.php
    // (services.pinterest.environment / PINTEREST_ENVIRONMENT env var) or
    // per-instance via useSandbox()/useProduction() below.
    protected string $environment;

    protected ?SocialAccount $account = null;

    public function __construct(protected ?string $accessToken = null, protected ?string $boardId = null)
    {
        // Fallback to static config token only when nothing else is supplied
        // (kept for cron/legacy use — prefer forUser()/forAccount() below).
        $this->accessToken ??= config('services.pinterest.access_token');
        $this->boardId ??= config('services.pinterest.board_id');

        $this->environment = config('services.pinterest.environment', 'production');
    }

    /** Build a service bound to a specific user's connected Pinterest account. */
    public static function forUser(int $userId): self
    {
        $account = SocialAccount::where('user_id', $userId)
            ->where('provider', 'pinterest')
            ->firstOrFail();

        return self::forAccount($account);
    }

    public function getFirstBoardId(): string
    {
        $this->requireAuth();
        $this->requireScope('boards:read');

        $boards = $this->listBoards(1); // page_size = 1, we only need the first

        $items = $boards['items'] ?? [];

        if (empty($items)) {
            throw new RuntimeException('No Pinterest boards found for this account.');
        }

        return $items[0]['id'];
    }

    public function getLastBoardId(int $sampleSize = 100): string
    {
        $this->requireAuth();
        $this->requireScope('boards:read');

        $boards = $this->listBoards($sampleSize);
        $items = $boards['items'] ?? [];

        if (empty($items)) {
            throw new RuntimeException('No Pinterest boards found for this account.');
        }

        // Don't trust API ordering — sort by created_at (if present) and take
        // the newest. Falls back to the last item in the response if Pinterest
        // doesn't return a created_at field for some reason.
        usort($items, fn ($a, $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));

        return $items[0]['id'];
    }

    public static function forAccount(SocialAccount $account): self
    {
        $service = new self($account->access_token, $account->meta['default_board_id'] ?? null);
        $service->account = $account;

        return $service;
    }

    // ---------------------------------------------------------------
    // Sandbox / production — controlled entirely via .env
    // (PINTEREST_ENVIRONMENT=sandbox|production, see config/services.php)
    // ---------------------------------------------------------------

    public function isSandbox(): bool
    {
        return $this->environment === 'sandbox';
    }

    protected function baseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api-sandbox.pinterest.com/v5'
            : 'https://api.pinterest.com/v5';
    }

    protected function tokenUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api-sandbox.pinterest.com/v5/oauth/token'
            : 'https://api.pinterest.com/v5/oauth/token';
    }

    protected function requireAuth(): void
    {
        if (empty($this->accessToken)) {
            throw new RuntimeException('Pinterest access token not set — connect an account or pass a token.');
        }
    }

    protected function requireScope(string $scope): void
    {
        if ($this->account && !$this->account->hasScope($scope)) {
            throw new RuntimeException("Connected Pinterest account is missing scope: {$scope}");
        }
    }

    // ---------------------------------------------------------------
    // OAuth
    // ---------------------------------------------------------------

    public function generateState(int $userId, array $extra = []): string
    {
        $payload = array_merge($extra, [
            'user_id' => $userId,
            'nonce' => Str::random(32),
            'expires_at' => now()->addMinutes(self::STATE_TTL_MINUTES)->timestamp,
        ]);

        $encrypted = encrypt(json_encode($payload));

        return rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
    }

    /**
     * Decode + verify a state string produced by generateState().
     * Throws if tampered, malformed, or expired.
     */
    public function parseState(string $state): array
    {
        $padded = str_pad(strtr($state, '-_', '+/'), strlen($state) % 4 === 0 ? strlen($state) : strlen($state) + (4 - strlen($state) % 4), '=');

        try {
            $encrypted = base64_decode($padded, true);
            if ($encrypted === false) {
                throw new RuntimeException('Malformed state encoding.');
            }
            $payload = json_decode(decrypt($encrypted), true);
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid or tampered OAuth state.');
        }

        if (!is_array($payload) || !isset($payload['user_id'], $payload['expires_at'])) {
            throw new RuntimeException('Malformed OAuth state payload.');
        }

        if ($payload['expires_at'] < now()->timestamp) {
            throw new RuntimeException('OAuth state expired — please try connecting again.');
        }

        return $payload;
    }

    public function getAuthorizationUrl(string $state, array $scopes = self::ALL_SCOPES): string
    {
        $query = http_build_query([
            'client_id' => config('services.pinterest.client_id'),
            'redirect_uri' => config('services.pinterest.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(',', $scopes),
            'state' => $state,
        ]);

        return self::AUTH_URL . '?' . $query;
    }

    public function exchangeCodeForToken(string $code): array
    {
        $clientId = config('services.pinterest.client_id');
        $clientSecret = config('services.pinterest.client_secret');
        $redirectUri = config('services.pinterest.redirect_uri');

        Log::info('PinterestService: token exchange attempt', [
            'environment' => $this->environment,
            'client_id' => $clientId,
            'client_secret_length' => strlen((string) $clientSecret),
            'client_secret_preview' => substr((string) $clientSecret, 0, 3) . '...' . substr((string) $clientSecret, -3),
            'redirect_uri' => $redirectUri,
            'code_preview' => substr($code, 0, 10) . '...',
        ]);

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->post($this->tokenUrl(), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]);

        if ($response->failed()) {
            Log::error('PinterestService: token exchange failed', [
                'environment' => $this->environment,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 1000),
            ]);
            throw new RuntimeException("Pinterest token exchange failed: {$response->body()}");
        }

        return $response->json();
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $response = Http::asForm()
            ->withBasicAuth(
                config('services.pinterest.client_id'),
                config('services.pinterest.client_secret'),
            )
            ->post($this->tokenUrl(), [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Pinterest token refresh failed: {$response->body()}");
        }

        return $response->json();
    }

    // ---------------------------------------------------------------
    // Scoped reads — one method per scope, each independently testable
    // ---------------------------------------------------------------

    public function getUserAccount(): array
    {
        $this->requireAuth();
        $this->requireScope('user_accounts:read');

        return $this->get('/user_account');
    }

    public function listBoards(int $pageSize = 25): array
    {
        $this->requireAuth();
        $this->requireScope('boards:read');

        return $this->get('/boards', ['page_size' => $pageSize]);
    }

    public function listPins(?string $boardId = null, int $pageSize = 25): array
    {
        $this->requireAuth();
        $this->requireScope('pins:read');

        $endpoint = $boardId ? "/boards/{$boardId}/pins" : '/pins';

        return $this->get($endpoint, ['page_size' => $pageSize]);
    }

    public function listAdAccounts(): array
    {
        $this->requireAuth();
        $this->requireScope('ads:read');

        return $this->get('/ad_accounts');
    }

    public function listCatalogs(): array
    {
        $this->requireAuth();
        $this->requireScope('catalogs:read');

        return $this->get('/catalogs');
    }

    protected function get(string $endpoint, array $query = []): array
    {
        $response = Http::withToken($this->accessToken)
            ->timeout(30)
            ->get("{$this->baseUrl()}{$endpoint}", $query);

        if ($response->failed()) {
            Log::error('PinterestService: GET failed', [
                'environment' => $this->environment,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 1000),
            ]);
            throw new RuntimeException("Pinterest GET {$endpoint} failed ({$response->status()}): {$response->body()}");
        }

        Log::info('PinterestService: GET success', [
            'environment' => $this->environment,
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'body_preview' => Str::limit($response->body(), 500),
        ]);
        return $response->json();
    }

    // ---------------------------------------------------------------
    // Pin creation
    // ---------------------------------------------------------------

    public function postPin(StoryImagePrompt $imagePrompt): string
    {
        $this->requireAuth();

        Log::info('PinterestService: posting pin', [
            'environment' => $this->environment,
            'story_image_prompt_id' => $imagePrompt->id,
            'board_id' => $this->boardId,
        ]);

        $response = Http::withToken($this->accessToken)
            ->timeout(30)
            ->post("{$this->baseUrl()}/pins", [
                'board_id' => $this->boardId,
                'title' => $imagePrompt->pinterest_title,
                'description' => $imagePrompt->pinterest_description,
                'link' => $imagePrompt->pinterest_link,
                'media_source' => [
                    'source_type' => 'image_url',
                    'url' => $imagePrompt->image_generated_url,
                ],
            ]);

        if ($response->failed()) {
            Log::error('PinterestService: pin creation failed', [
                'environment' => $this->environment,
                'story_image_prompt_id' => $imagePrompt->id,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 1000),
            ]);
            throw new RuntimeException("Pinterest pin creation failed: {$response->body()}");
        }

        $pinId = $response->json('id');

        if (!$pinId) {
            throw new RuntimeException('Pinterest response did not include a pin id.');
        }

        Log::info('PinterestService: pin posted', [
            'environment' => $this->environment,
            'story_image_prompt_id' => $imagePrompt->id,
            'pin_id' => $pinId,
        ]);

        return $pinId;
    }

    public function postPinToFirstBoard(StoryImagePrompt $imagePrompt): string
    {
        $this->boardId = $this->getFirstBoardId();

        Log::info('PinterestService: resolved first board for pin', [
            'story_image_prompt_id' => $imagePrompt->id,
            'board_id' => $this->boardId,
        ]);

        return $this->postPin($imagePrompt);
    }

    public function postLocalImagePin(string $title, string $description, string $link, string $imagePath): string
    {
        $this->requireAuth();

        $fullPath = public_path($imagePath);
        if (!file_exists($fullPath)) {
            throw new RuntimeException("File not found at: {$fullPath}");
        }

        $imageUrl = asset($imagePath);

        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl()}/pins", [
                'board_id' => $this->getFirstBoardId(),
                'title' => $title,
                'description' => $description,
                'link' => $link,
                'media_source' => ['source_type' => 'image_url', 'url' => $imageUrl],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Pinterest API Error: {$response->body()}");
        }

        return $response->json('id');
    }
}