@extends('layouts.app')

@section('title', 'CraftProfessor — Storytelling as Architecture')

@section('content')
    {{-- Hero --}}
    <section class="max-w-6xl mx-auto px-6 py-20 text-center">
        <p class="uppercase tracking-widest text-sm font-semibold text-blue-600 mb-4">
            CraftProfessor
        </p>
        <h2 class="text-5xl md:text-6xl font-extrabold mb-6">
            Storytelling is an Architecture,
            <span class="block">Not Just an Art</span>
        </h2>

        <p class="text-xl text-gray-600 mb-10 max-w-2xl mx-auto">
            We merge high-level engineering with the timeless craft of narrative,
            building automated systems that breathe life into stories &mdash;
            transforming raw data and logic into immersive, compelling
            experiences for the modern reader.
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

    {{-- Mission --}}
    <section class="max-w-4xl mx-auto px-6 py-8 text-center">
        <p class="text-lg text-gray-700 leading-relaxed">
            Our mission is to push the boundaries of digital publishing &mdash;
            engineering the systems that let narrative scale without losing
            its craft.
        </p>
    </section>

    {{-- Features --}}
    <section class="max-w-6xl mx-auto px-6 py-16 grid md:grid-cols-3 gap-8">
        <div class="bg-white p-8 rounded-xl shadow-sm border">
            <h3 class="font-bold text-xl mb-3">Narrative Engineering</h3>
            <p class="text-gray-600">
                Structured pipelines that treat story assets like
                architecture &mdash; generated, versioned, and stored with the
                same rigor as any production system.
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
                high-volume content delivery &mdash; so stories reach readers
                exactly when they should.
            </p>
        </div>
    </section>

    {{-- Closing statement --}}
    <section class="max-w-4xl mx-auto px-6 py-16 text-center">
        <h3 class="text-2xl font-bold mb-4">Built for the modern reader.</h3>
        <p class="text-gray-600 max-w-2xl mx-auto">
            CraftProfessor exists at the intersection of engineering and
            narrative craft, giving storytellers the automated infrastructure
            to publish immersive experiences at scale.
        </p>
    </section>
@endsection
