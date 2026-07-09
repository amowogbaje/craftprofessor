<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JwtService;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Laravel\Socialite\Facades\Socialite;

/**
 * Requires `composer require laravel/socialite` and the GOOGLE_CLIENT_ID /
 * GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI env vars (see config/services.php).
 * Works for both login AND signup — an existing email match links the Google
 * account to it; otherwise a brand-new, pre-verified user is created.
 */
class GoogleAuthController extends Controller
{
    /** GET /auth/google/redirect */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    /**
     * GET /auth/google/callback
     * Redirects to the SPA with the JWT access token in the query string
     * (matches a typical mobile/SPA OAuth handoff — swap for whatever your
     * frontend expects).
     */
    public function callback(WalletService $wallet, JwtService $jwt): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->stateless()->user();

        $user = User::where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        $isNew = false;

        if (!$user) {
            $isNew = true;
            $user = User::create([
                'name' => $googleUser->getName() ?? $googleUser->getNickname() ?? 'New User',
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'avatar_url' => $googleUser->getAvatar(),
                'password' => null,
                'email_verified_at' => now(), // Google already verified this email
            ]);
        } elseif (!$user->google_id) {
            $user->forceFill([
                'google_id' => $googleUser->getId(),
                'avatar_url' => $googleUser->getAvatar() ?? $user->avatar_url,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        }

        if ($isNew) {
            $signupBonus = (int) config('coins.signup_bonus');
            if ($signupBonus > 0) {
                $wallet->credit($user, $signupBonus, 'signup_bonus');
            }
        }

        $token = $jwt->issue($user);

        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');

        return Redirect::away("{$frontendUrl}/oauth/callback?token=" . urlencode($token));
    }
}
