@extends('layouts.app')

@section('title', 'CraftProfessor — Turn Your Stories Into Traffic')

@section('content')
    {{-- Hero --}}
    <section class="max-w-6xl mx-auto px-6 py-20 text-center">
        <p class="uppercase tracking-widest text-sm font-semibold text-blue-600 mb-4">
            CraftProfessor
        </p>
        <h2 class="text-5xl md:text-6xl font-extrabold mb-6">
            Your Stories Deserve
            <span class="block">More Readers</span>
        </h2>

        <p class="text-xl text-gray-600 dark:text-gray-300 mb-10 max-w-2xl mx-auto">
            CraftProfessor helps writers and bookstores put their stories in
            front of the people already looking for them &mdash; turning
            scattered posts into steady, real traffic for your site or shop.
        </p>

        <div class="flex justify-center gap-4 flex-wrap">
            <a href="https://craftprofessorui.amowogbaje.com/"
               class="px-8 py-3 bg-blue-600 text-white rounded-lg font-medium shadow hover:bg-blue-700 transition">
                Get Started
            </a>
            <a href="#how-it-works"
               class="px-8 py-3 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg font-medium hover:border-blue-600 transition">
                See How It Works
            </a>
        </div>
    </section>

    {{-- Mission --}}
    <section class="max-w-4xl mx-auto px-6 py-8 text-center">
        <p class="text-lg text-gray-700 dark:text-gray-300 leading-relaxed">
            Whether you run a story site or a book store, your best marketing
            is your own content. We make sure it actually gets seen &mdash;
            consistently, and without you having to babysit it every day.
        </p>
    </section>

    {{-- Features --}}
    <section id="how-it-works" class="max-w-6xl mx-auto px-6 py-16 grid md:grid-cols-3 gap-8">
        <div class="bg-white dark:bg-gray-900 p-8 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800">
            <h3 class="font-bold text-xl mb-3">Your Stories, Everywhere</h3>
            <p class="text-gray-600 dark:text-gray-300">
                We take the stories and titles you already have and put them
                where readers are actively browsing &mdash; no extra writing,
                no extra work on your end.
            </p>
        </div>

        <div class="bg-white dark:bg-gray-900 p-8 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800">
            <h3 class="font-bold text-xl mb-3">Pinterest, Done For You</h3>
            <p class="text-gray-600 dark:text-gray-300">
                We connect securely to your Pinterest account and share your
                stories and books as pins &mdash; the same way top publishers
                and bookstores get discovered.
            </p>
        </div>

        <div class="bg-white dark:bg-gray-900 p-8 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800">
            <h3 class="font-bold text-xl mb-3">Steady, Not Spammy</h3>
            <p class="text-gray-600 dark:text-gray-300">
                Your content goes out on a healthy, consistent schedule &mdash;
                so you build an audience over time instead of getting flagged
                for posting too much, too fast.
            </p>
        </div>
    </section>

    {{-- Closing statement --}}
    <section class="max-w-4xl mx-auto px-6 py-16 text-center">
        <h3 class="text-2xl font-bold mb-4">Built for storytellers and booksellers.</h3>
        <p class="text-gray-600 dark:text-gray-300 max-w-2xl mx-auto mb-8">
            You focus on writing and curating great stories. We focus on
            getting them in front of real readers, every single day.
        </p>
        <a href="https://craftprofessorui.amowogbaje.com/"
           class="inline-block px-8 py-3 bg-blue-600 text-white rounded-lg font-medium shadow hover:bg-blue-700 transition">
            Start Driving Traffic
        </a>
    </section>
@endsection