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
    public const AUTH_URL = 'https://www.pinterest.com/oauth/';
    public const TOKEN_URL = 'https://api.pinterest.com/v5/oauth/token';

    public const ALL_SCOPES = [
        'pins:read',
        'boards:read',
        'user_accounts:read',
        'ads:read',
        'catalogs:read',
        'pins:write', // needed for postPin
    ];

    protected string $baseUrl = 'https://api.pinterest.com/v5';

    protected ?SocialAccount $account = null;

    public function __construct(protected ?string $accessToken = null, protected ?string $boardId = null)
    {
        // Fallback to static config token only when nothing else is supplied
        // (kept for cron/legacy use — prefer forUser()/forAccount() below).
        $this->accessToken ??= config('services.pinterest.access_token');
        $this->boardId ??= config('services.pinterest.board_id');
    }

    /** Build a service bound to a specific user's connected Pinterest account. */
    public static function forUser(int $userId): self
    {
        $account = SocialAccount::where('user_id', $userId)
            ->where('provider', 'pinterest')
            ->firstOrFail();

        return self::forAccount($account);
    }

    public static function forAccount(SocialAccount $account): self
    {
        $service = new self($account->access_token, $account->meta['default_board_id'] ?? null);
        $service->account = $account;

        return $service;
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
        $response = Http::asForm()
            ->withBasicAuth(
                config('services.pinterest.client_id'),
                config('services.pinterest.client_secret'),
            )
            ->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('services.pinterest.redirect_uri'),
            ]);

        if ($response->failed()) {
            Log::error('PinterestService: token exchange failed', ['body' => Str::limit($response->body(), 1000)]);
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
            ->post(self::TOKEN_URL, [
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
            ->get("{$this->baseUrl}{$endpoint}", $query);

        if ($response->failed()) {
            Log::error('PinterestService: GET failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 1000),
            ]);
            throw new RuntimeException("Pinterest GET {$endpoint} failed ({$response->status()}): {$response->body()}");
        }

        return $response->json();
    }

    // ---------------------------------------------------------------
    // Existing pin creation (unchanged behavior, just uses $this->accessToken/boardId)
    // ---------------------------------------------------------------

    public function postPin(StoryImagePrompt $imagePrompt): string
    {
        $this->requireAuth();

        Log::info('PinterestService: posting pin', [
            'story_image_prompt_id' => $imagePrompt->id,
            'board_id' => $this->boardId,
        ]);

        $response = Http::withToken($this->accessToken)
            ->timeout(30)
            ->post("{$this->baseUrl}/pins", [
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
            'story_image_prompt_id' => $imagePrompt->id,
            'pin_id' => $pinId,
        ]);

        return $pinId;
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
            ->post("{$this->baseUrl}/pins", [
                'board_id' => $this->boardId,
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