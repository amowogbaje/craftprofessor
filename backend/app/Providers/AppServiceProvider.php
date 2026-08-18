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
                // Opt-in only — set IMAGE_PROVIDER=agnes in .env to try it.
                // Cloudflare/together/default all remain one env-var edit away.
                'agnes' => new \App\Ai\Providers\AgnesAiImageProvider(
                    config('ai.image_providers.agnes.key'),
                    config('ai.image_providers.agnes.base_url'),
                    config('ai.image_providers.agnes.model'),
                ),
                default => $app->make(\App\Ai\Agents\ImageGeneratorAgent::class),
            };
        });

        $this->app->bind(\App\Ai\Contracts\VideoProviderContract::class, function ($app) {
            return match (config('ai.default_video_provider')) {
                // Opt-in only — set VIDEO_PROVIDER=agnes in .env to try it.
                // Leave unset (or 'veo') to keep using Vertex AI Veo.
                'agnes' => new \App\Ai\Providers\AgnesAiVideoProvider(
                    config('ai.video_providers.agnes.key'),
                    config('ai.video_providers.agnes.base_url'),
                    config('ai.video_providers.agnes.model'),
                ),
                default => $app->make(\App\Ai\Providers\VeoVideoProvider::class),
            };
        });

        $this->app->bind(\App\Ai\Contracts\TtsProviderContract::class, function ($app) {
            return match (config('ai.default_tts_provider')) {
                'eleven' => new \App\Ai\Providers\ElevenLabsTtsProvider(
                    config('ai.tts_providers.eleven.key'),
                    config('ai.tts_providers.eleven.voice_id'),
                    config('ai.tts_providers.eleven.base_url'),
                    config('ai.tts_providers.eleven.model'),
                ),
                default => new \App\Ai\Providers\OpenAiTtsProvider(
                    config('ai.tts_providers.openai.key'),
                    config('ai.tts_providers.openai.base_url'),
                    config('ai.tts_providers.openai.model'),
                    config('ai.tts_providers.openai.voice'),
                ),
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

