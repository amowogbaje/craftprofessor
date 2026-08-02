<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\PinterestService;
use App\Services\SocialPlatforms\Support\OAuthProviderConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SocialAccountController extends Controller
{
    /**
     * Providers whose Connect button should actually be clickable. A
     * provider only counts as "configured" once its client_id AND
     * client_secret env vars are both set — until then the frontend shows
     * "Coming soon" instead of a button that would just fail on click.
     */
    private const PROVIDERS = ['pinterest', 'linkedin', 'twitter', 'youtube', 'instagram', 'facebook'];

    public function index(Request $request)
    {
        $accounts = $request->user()->socialAccounts()
            ->get(['id', 'provider', 'provider_username', 'scopes', 'connected_at']);

        return response()->json([
            'data' => $accounts,
            'configured_providers' => collect(self::PROVIDERS)
                ->mapWithKeys(fn (string $provider) => [$provider => $this->isConfigured($provider)]),
        ]);
    }

    protected function isConfigured(string $provider): bool
    {
        return OAuthProviderConfig::isConfigured($provider);
    }

    public function destroy(Request $request, string $provider)
    {
        $request->user()->socialAccounts()->where('provider', $provider)->delete();

        return response()->json(['message' => 'Disconnected.']);
    }

    public function pinterestConnect(Request $request, PinterestService $pinterest)
    {
        abort_unless($this->isConfigured('pinterest'), 503, 'Pinterest isn\'t configured on this server yet.');

        $state = $pinterest->generateState($request->user()->id);

        return response()->json(['url' => $pinterest->getAuthorizationUrl($state)]);
    }

    public function pinterestCallback(Request $request, PinterestService $pinterest)
    {
        try {
            $payload = $pinterest->parseState($request->query('state', ''));
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        $token = $pinterest->exchangeCodeForToken($request->query('code'));

        $account = SocialAccount::updateOrCreate(
            ['user_id' => $payload['user_id'], 'provider' => 'pinterest'],
            [
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null,
                'token_expires_at' => isset($token['expires_in'])
                    ? now()->addSeconds($token['expires_in'])
                    : null,
                'scopes' => isset($token['scope']) ? explode(' ', $token['scope']) : [],
                'connected_at' => now(),
            ]
        );

        try {
            $userAccount = PinterestService::forAccount($account)->getUserAccount();
            $account->update(['provider_username' => $userAccount['username'] ?? null]);
        } catch (\Throwable $e) {
            // non-fatal — account is connected even if this enrichment call fails
            Log::channel('pinterest')->warning('PinterestService: failed to fetch user account after connect', [
                'user_id' => $account->user_id,
                'error' => $e->getMessage(),
            ]);
        }
        try {
            PinterestService::forAccount($account)->syncBoardToAccount();
        } catch (\Throwable $e) {
            // non-fatal — account is connected even if board resolution/creation fails;
            // postPin() can retry board resolution later
            Log::channel('pinterest')->warning('PinterestService: failed to sync board after connect', [
                'user_id' => $account->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect(config('app.frontend_url') . '/settings/social-accounts?connected=pinterest');
    }
}