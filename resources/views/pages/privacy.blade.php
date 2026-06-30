@extends('layouts.app')

@section('title', 'Privacy Policy')

@section('content')
    <section class="max-w-4xl mx-auto px-6 py-16">
        <h1 class="text-3xl font-bold mb-6">Privacy Policy</h1>
        <p class="mb-6 text-sm text-gray-500">
            Last Updated: {{ date('F j, Y') }}
        </p>

        <h2 class="text-xl font-semibold mt-8 mb-2">Data Collection</h2>
        <p class="text-gray-700">
            This service connects to your Pinterest account using OAuth 2.0.
            We only store access tokens and metadata strictly required to
            publish content on your behalf.
        </p>

        <h2 class="text-xl font-semibold mt-8 mb-2">Third-Party Services</h2>
        <p class="text-gray-700">
            We interact exclusively with Pinterest’s official API.
            Please review Pinterest’s privacy policy for information on their
            data handling practices.
        </p>

        <h2 class="text-xl font-semibold mt-8 mb-2">User Control</h2>
        <p class="text-gray-700">
            You may revoke this application’s access at any time via your
            Pinterest account settings.
        </p>
    </section>
@endsection