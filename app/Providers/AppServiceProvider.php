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
        $this->app->bind(\App\Ai\Contracts\ImageProviderContract::class, function ($app) {
            return match (config('ai.default_image_provider')) {
                'cloudflare' => new \App\Ai\Providers\CloudflareWorkersAiProvider(
                    config('ai.image_providers.cloudflare.account_id'),
                    config('ai.image_providers.cloudflare.key'),
                    config('ai.image_providers.cloudflare.model'),
                ),
                'together' => new \App\Ai\Providers\TogetherAiImageProvider(
                    config('ai.image_providers.together.key'),
                    config('ai.image_providers.together.model'),
                ),
                default => $app->make(\App\Ai\Agents\ImageGeneratorAgent::class),
            };
        });
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

