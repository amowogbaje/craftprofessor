<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'CraftProfessor')</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    {{-- Set theme BEFORE Tailwind/paint to avoid flash of wrong theme --}}
    <script>
        (function () {
            const stored = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const isDark = stored ? stored === 'dark' : systemDark;
            document.documentElement.classList.toggle('dark', isDark);
        })();
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        };
    </script>
</head>

<body class="bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100 font-sans flex flex-col min-h-screen transition-colors">

    {{-- Header --}}
    <header class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 transition-colors">
        <nav class="max-w-6xl mx-auto p-6 flex justify-between items-center">
            <h1 class="text-2xl font-bold tracking-tight">
                CraftProfessor<span class="text-blue-600">.amowogbaje</span>
            </h1>

            <div class="flex items-center gap-6 text-sm font-semibold">
                <a href="{{ route('home') }}" class="hover:text-blue-600">Home</a>
                <a href="{{ route('privacy') }}" class="hover:text-blue-600">Privacy</a>
                <a href="{{ route('terms') }}" class="hover:text-blue-600">Terms</a>
                <a href="https://craftprofessorui.amowogbaje.com/"
                   class="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    Login
                </a>

                {{-- Theme toggle --}}
                <button
                    id="theme-toggle"
                    type="button"
                    aria-label="Toggle dark mode"
                    class="p-2 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
                >
                    {{-- Sun icon (shown in dark mode) --}}
                    <svg id="icon-sun" class="w-5 h-5 hidden dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    {{-- Moon icon (shown in light mode) --}}
                    <svg id="icon-moon" class="w-5 h-5 block dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                </button>
            </div>
        </nav>
    </header>

    {{-- Main Content --}}
    <main class="flex-1">
        @yield('content')
    </main>

    {{-- Footer --}}
    <footer class="border-t border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 text-center py-10 text-gray-500 dark:text-gray-400 text-sm transition-colors">
        <div class="mb-4">
            <a href="{{ route('privacy') }}" class="hover:text-blue-600 mx-3">Privacy Policy</a>
            <a href="{{ route('terms') }}" class="hover:text-blue-600 mx-3">Terms of Service</a>
        </div>
        &copy; {{ date('Y') }} Amowogbaje Engineering.
    </footer>

    {{-- Theme toggle logic --}}
    <script>
        const toggleBtn = document.getElementById('theme-toggle');
        toggleBtn.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });

        // Keep in sync if system theme changes and user hasn't set a manual preference
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
            if (!localStorage.getItem('theme')) {
                document.documentElement.classList.toggle('dark', e.matches);
            }
        });
    </script>

</body>
</html>