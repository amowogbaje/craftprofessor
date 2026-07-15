<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => 'gemini',
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'cohere',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
            'image_deployment' => env('AZURE_OPENAI_IMAGE_DEPLOYMENT', 'gpt-image-1'),
            'store' => env('AZURE_OPENAI_STORE', true),
        ],

        'bedrock' => [
            'driver' => 'bedrock',
            'region' => env('AWS_BEDROCK_REGION', 'us-east-1'),
            'key' => env('AWS_BEARER_TOKEN_BEDROCK'),
            'access_key_id' => env('AWS_ACCESS_KEY_ID'),
            'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
            'session_token' => env('AWS_SESSION_TOKEN'),
            'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIALS', true),
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'model' => 'gemini-2.0-flash', // Use 2.5 for text tasks
            'options' => [
                'timeout' => 60,
                'safety_settings' => [
                    'HATE_SPEECH' => 'BLOCK_ONLY_HIGH',
                    'HARASSMENT' => 'BLOCK_ONLY_HIGH',
                    'DANGEROUS_CONTENT' => 'BLOCK_ONLY_HIGH',
                    'SEXUALLY_EXPLICIT' => 'BLOCK_ONLY_HIGH',
                ],
            ],
            // 'image_model' => 'imagen-3.0-generate-002', // Keep this, it is standard for now
            'url' => 'https://generativelanguage.googleapis.com/v1beta/', // Note: Plan to move to a stable 'v1' URL if possible
        ],

        'imagen' => [
            'driver' => 'gemini', // The driver stays 'gemini' as it handles the logic
            'key' => null, // The SDK will automatically look for the JSON file via GOOGLE_APPLICATION_CREDENTIALS
            'model' => 'imagen-3.0-generate-002',
            // Use the Vertex regional endpoint
            'url' => 'https://us-central1-aiplatform.googleapis.com/v1/projects/' . env('GOOGLE_CLOUD_PROJECT_ID') . '/locations/us-central1/publishers/google/models/',
        ],
        // Veo (image-to-video) via Vertex AI. Vertex auth is OAuth2 (a
        // short-lived bearer token minted from your service account JSON),
        // NOT a simple API key — see VideoGeneratorService::accessToken().
        'veo' => [
            'driver' => 'vertex-veo',
            'model' => env('VEO_MODEL', 'veo-3.0-generate-001'),
            'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
            'location' => env('GOOGLE_CLOUD_LOCATION', 'us-central1'),
            'url' => 'https://us-central1-aiplatform.googleapis.com/v1/projects/' . env('GOOGLE_CLOUD_PROJECT_ID') . '/locations/us-central1/publishers/google/models/',
        ],

        'imagen-2' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'), // Keep using your AIza... API Key
            // Use a model that actually supports image generation in AI Studio
            'model' => 'gemini-2.5-flash-image', 
            // Use the standard AI Studio URL
            'url' => 'https://generativelanguage.googleapis.com/v1beta/',
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', true),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],


    // config/ai.php — add near the bottom, alongside existing keys

    /*
    |--------------------------------------------------------------------------
    | Custom Image Providers (NOT native Laravel\Ai drivers)
    |--------------------------------------------------------------------------
    |
    | These power App\Ai\Contracts\ImageProviderContract directly — bound in
    | AppServiceProvider — and are separate from the 'providers' array above,
    | which only contains drivers AiManager itself knows how to resolve via
    | createXxxDriver()/extend(). Do NOT reference 'cloudflare' or 'together'
    | as a Laravel\Ai provider name (e.g. Image::of(...)->generate(provider: ...))
    | — they only exist behind ImageGeneratorService's own $imageAgent.
    |
    */

    'default_image_provider' => env('IMAGE_PROVIDER', 'default'), // default | cloudflare | together

    'image_providers' => [
        'cloudflare' => [
            'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
            'key' => env('CLOUDFLARE_API_TOKEN'),
            'model' => env('CLOUDFLARE_IMAGE_MODEL', '@cf/stabilityai/stable-diffusion-xl-base-1.0'),
        ],
        'together' => [
            'key' => env('TOGETHER_API_KEY'),
            'model' => env('TOGETHER_IMAGE_MODEL', 'black-forest-labs/FLUX.1-schnell'),
        ],
    ],

];
