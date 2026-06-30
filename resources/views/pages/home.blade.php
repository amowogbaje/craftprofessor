@extends('layouts.app')

@section('title', 'Automated Storytelling Engine')

@section('content')
    {{-- Hero --}}
    <section class="max-w-6xl mx-auto px-6 py-20 text-center">
        <h2 class="text-5xl md:text-6xl font-extrabold mb-6">
            Automated Storytelling for the Modern Web
        </h2>

        <p class="text-xl text-gray-600 mb-10 max-w-2xl mx-auto">
            A specialized backend pipeline for programmatic asset generation
            and automated delivery to social platforms.
        </p>

        <div class="flex justify-center gap-4 flex-wrap">
            <span class="px-6 py-3 bg-blue-600 text-white rounded-lg font-medium shadow">
                API-First Architecture
            </span>
            <span class="px-6 py-3 bg-white border border-gray-200 rounded-lg font-medium">
                Laravel & Pinterest Integration
            </span>
        </div>
    </section>

    {{-- Features --}}
    <section class="max-w-6xl mx-auto px-6 py-16 grid md:grid-cols-3 gap-8">
        <div class="bg-white p-8 rounded-xl shadow-sm border">
            <h3 class="font-bold text-xl mb-3">Asset Persistence</h3>
            <p class="text-gray-600">
                Secure storage and management of generated image and video assets
                ready for controlled distribution.
            </p>
        </div>

        <div class="bg-white p-8 rounded-xl shadow-sm border">
            <h3 class="font-bold text-xl mb-3">Pinterest API Sync</h3>
            <p class="text-gray-600">
                Programmatic publishing to Pinterest boards using OAuth-secured
                API access on behalf of authenticated users.
            </p>
        </div>

        <div class="bg-white p-8 rounded-xl shadow-sm border">
            <h3 class="font-bold text-xl mb-3">Queue Orchestration</h3>
            <p class="text-gray-600">
                Background job pipelines designed for safe, rate-limited,
                high-volume content delivery.
            </p>
        </div>
    </section>
@endsection