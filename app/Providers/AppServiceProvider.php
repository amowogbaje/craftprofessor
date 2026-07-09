<?php

namespace App\Providers;

use App\Services\JwtService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registers the 'jwt' driver used by config/auth.php's 'api' guard.
        // Auth::viaRequest is Laravel's built-in hook for a stateless guard
        // that resolves the user straight from the request (here, the
        // Authorization: Bearer <jwt> header) — no session, no token table.
        Auth::viaRequest('jwt', function ($request) {
            $token = $request->bearerToken();

            if (!$token) {
                return null;
            }

            return app(JwtService::class)->userFromToken($token);
        });
    }
}

