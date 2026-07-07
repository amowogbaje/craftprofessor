@extends('layouts.app')

@section('title', 'Privacy Policy')

@section('content')
<section class="max-w-4xl mx-auto px-6 py-16">
    <h1 class="text-3xl font-bold mb-6">Privacy Policy</h1>
    <p class="mb-6 text-sm text-gray-500">Last Updated: {{ date('F j, Y') }}</p>

    <h2 class="text-xl font-semibold mt-8 mb-2">Introduction</h2>
    <p class="text-gray-700 mb-4">
        CraftProfessor is a storytelling and publishing platform that merges
        engineering with narrative craft to build automated systems for
        digital publishing. We respect your privacy and are committed to
        protecting your personal data.
        <strong>Note: This application is not endorsed by, affiliated with, or sponsored by Pinterest, LinkedIn, or Google.</strong>
    </p>

    <h2 class="text-xl font-semibold mt-8 mb-2">Data Collection & Usage</h2>
    <p class="text-gray-700 mb-4">
        We collect only the minimum data required to provide our services. This includes:
    </p>
    <ul class="list-disc ml-6 text-gray-700 mb-4">
        <li><strong>Authentication Data:</strong> When you connect your Pinterest, LinkedIn, or Google accounts, we receive OAuth 2.0 tokens. We do not store your account passwords.</li>
        <li><strong>Account Metadata:</strong> Basic information (such as name or profile handle) required to identify your account for publishing purposes.</li>
    </ul>

    <h2 class="text-xl font-semibold mt-8 mb-2">Third-Party Platform Integrations</h2>
    <p class="text-gray-700 mb-4">
        Our service interacts with the following official APIs:
    </p>
    <ul class="list-disc ml-6 text-gray-700 mb-4">
        <li><strong>Pinterest:</strong> Used to publish story scenes. We do not sell or redistribute Pinterest content.</li>
        <li><strong>LinkedIn:</strong> Used to share professional narrative updates. Data is only accessed during active sessions.</li>
        <li><strong>Google:</strong> Used for authentication and asset management.</li>
    </ul>

    <h2 class="text-xl font-semibold mt-8 mb-2">Data Protection & Non-Resale</h2>
    <p class="text-gray-700 mb-4">
        We do not sell, rent, or lease your personal information or third-party platform data to any third party. We utilize industry-standard security measures to protect your access tokens.
    </p>

    <h2 class="text-xl font-semibold mt-8 mb-2">User Rights & Data Deletion</h2>
    <p class="text-gray-700 mb-4">
        You may revoke this application's access at any time through your Pinterest, LinkedIn, or Google account settings. Upon revocation or account deletion, we immediately purge all associated API-derived data from our systems.
    </p>

    <h2 class="text-xl font-semibold mt-8 mb-2">Contact Information</h2>
    <p class="text-gray-700">
        If you have questions regarding this policy, please contact us at: <strong>hello@amowogbaje.com</strong>
    </p>
</section>
@endsection
