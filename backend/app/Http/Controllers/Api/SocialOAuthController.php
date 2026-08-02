<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\SocialPlatforms\Support\OAuthProviderConfig;
use App\Services\SocialPlatforms\Support\OAuthStateSigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One connect()/callback() pair driving LinkedIn, Twitter, YouTube,
 * Instagram, and Facebook's OAuth2 flows, config-driven via
 * OAuthProviderConfig — adding a 6th provider here is a config entry, not
 * new controller code. Pinterest deliberately keeps its own dedicated
 * SocialAccountController flow (predates this, already working, has its
 * own post-connect board-sync step) rather than being folded in here.
 *
 * provider_user_id is populated with whatever id field each provider's
 * "who am I" response returns — this is what LinkedInPlatform,
 * InstagramPlatform, FacebookPlatform build their post-target URNs/paths
 * from later, so getting this right at connect time matters.
 */
class SocialOAuthController extends Controller
{
    public function connect(Request $request, string $provider)
    {
        $config = OAuthProviderConfig::for($provider);

        abort_if(
            blank($config['client_id']) || blank($config['client_secret']),
            503,
            ucfirst($provider) . " isn't configured on this server yet."
        );

        $state = OAuthStateSigner::generate($request->user()->id);

        $params = array_merge([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(' ', $config['scopes']),
            'state' => $state,
        ], $config['extra_authorize_params'] ?? []);

        if ($config['pkce']) {
            $verifier = Str::random(64);
            // Cached against the state (which is itself unguessable/signed),
            // TTL matching the state's own expiry.
            Cache::put("oauth_pkce:{$state}", $verifier, now()->addMinutes(OAuthStateSigner::TTL_MINUTES));

            $params['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $params['code_challenge_method'] = 'S256';
        }

        return response()->json(['url' => $config['authorize_url'] . '?' . http_build_query($params)]);
    }

    public function callback(Request $request, string $provider)
    {
        $config = OAuthProviderConfig::for($provider);

        try {
            $payload = OAuthStateSigner::parse($request->query('state', ''));
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        $tokenRequest = [
            'grant_type' => 'authorization_code',
            'code' => $request->query('code'),
            'redirect_uri' => $config['redirect_uri'],
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
        ];

        if ($config['pkce']) {
            $verifier = Cache::pull("oauth_pkce:{$request->query('state')}");
            if (!$verifier) {
                abort(422, 'PKCE code verifier expired or missing — please try connecting again.');
            }
            $tokenRequest['code_verifier'] = $verifier;
        }

        $tokenResponse = Http::asForm()->post($config['token_url'], $tokenRequest);

        if ($tokenResponse->failed()) {
            Log::channel($provider)->error("{$provider} OAuth: token exchange failed", [
                'status' => $tokenResponse->status(),
                'body' => Str::limit($tokenResponse->body(), 1000),
            ]);
            abort(422, "Failed to connect {$provider} — token exchange was rejected.");
        }

        $token = $tokenResponse->json();

        $account = SocialAccount::updateOrCreate(
            ['user_id' => $payload['user_id'], 'provider' => $provider],
            [
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null,
                'token_expires_at' => isset($token['expires_in']) ? now()->addSeconds($token['expires_in']) : null,
                'scopes' => isset($token['scope']) ? explode(' ', str_replace(',', ' ', $token['scope'])) : $config['scopes'],
                'connected_at' => now(),
            ]
        );

        try {
            $this->enrichProviderIdentity($provider, $account);
        } catch (\Throwable $e) {
            // Non-fatal — account is connected even if identity enrichment fails;
            // the platform classes will surface a clear error the first time
            // they need provider_user_id and it's missing.
            Log::channel($provider)->warning("{$provider} OAuth: failed to enrich provider identity after connect", [
                'user_id' => $account->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect(config('app.frontend_url') . "/settings/social-accounts?connected={$provider}");
    }

    /**
     * Fetch + store whatever id each platform needs later to know *where*
     * to post (a LinkedIn member URN, an Instagram Business account id, a
     * Facebook Page id + Page access token...). Best-effort: connecting
     * still succeeds even if this fails, per the try/catch in callback().
     */
    protected function enrichProviderIdentity(string $provider, SocialAccount $account): void
    {
        match ($provider) {
            'linkedin' => $this->enrichLinkedIn($account),
            'youtube' => $this->enrichYouTube($account),
            'facebook' => $this->enrichFacebookPage($account),
            'instagram' => $this->enrichInstagramBusinessAccount($account),
            default => null,
        };
    }

    protected function enrichLinkedIn(SocialAccount $account): void
    {
        $me = Http::withToken($account->access_token)->get('https://api.linkedin.com/v2/userinfo');
        $account->update(['provider_user_id' => $me->json('sub'), 'provider_username' => $me->json('name')]);
    }

    protected function enrichYouTube(SocialAccount $account): void
    {
        $channel = Http::withToken($account->access_token)
            ->get('https://www.googleapis.com/youtube/v3/channels', ['part' => 'id,snippet', 'mine' => 'true']);

        $item = $channel->json('items.0');
        $account->update([
            'provider_user_id' => $item['id'] ?? null,
            'provider_username' => $item['snippet']['title'] ?? null,
        ]);
    }

    /**
     * A user token can't post to a Page — exchange it for the first
     * manageable Page's own access token and store THAT as access_token,
     * with provider_user_id = the Page id. FacebookPlatform posts as that
     * Page, not as the connecting user.
     */
    protected function enrichFacebookPage(SocialAccount $account): void
    {
        $version = config('services.facebook.graph_api_version');
        $pages = Http::withToken($account->access_token)
            ->get("https://graph.facebook.com/{$version}/me/accounts");

        $page = $pages->json('data.0');

        if (!$page) {
            throw new RuntimeException('No manageable Facebook Page found for this account.');
        }

        $account->update([
            'provider_user_id' => $page['id'],
            'provider_username' => $page['name'] ?? null,
            'access_token' => $page['access_token'], // Page token, not the user token
        ]);
    }

    /** The Instagram Business account is reached *through* its linked Facebook Page. */
    protected function enrichInstagramBusinessAccount(SocialAccount $account): void
    {
        $version = config('services.instagram.graph_api_version');
        $pages = Http::withToken($account->access_token)
            ->get("https://graph.facebook.com/{$version}/me/accounts", ['fields' => 'instagram_business_account,name,access_token']);

        $page = collect($pages->json('data', []))->firstWhere('instagram_business_account');

        if (!$page) {
            throw new RuntimeException('No Facebook Page with a linked Instagram Business account was found.');
        }

        $account->update([
            'provider_user_id' => $page['instagram_business_account']['id'],
            'provider_username' => $page['name'] ?? null,
            'access_token' => $page['access_token'],
        ]);
    }
}
