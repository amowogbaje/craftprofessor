<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\PinterestService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SocialAccountController extends Controller
{
    public function index(Request $request)
    {
        $accounts = $request->user()->socialAccounts()
            ->get(['id', 'provider', 'provider_username', 'scopes', 'connected_at']);

        return response()->json(['data' => $accounts]);
    }

    public function destroy(Request $request, string $provider)
    {
        $request->user()->socialAccounts()->where('provider', $provider)->delete();

        return response()->json(['message' => 'Disconnected.']);
    }

    public function pinterestConnect(Request $request, PinterestService $pinterest)
    {
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
        }

        return redirect(config('app.frontend_url') . '/settings/social?connected=pinterest');
    }
}