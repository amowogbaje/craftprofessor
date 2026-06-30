<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Storytelling Engine')</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 text-gray-900 font-sans flex flex-col min-h-screen">

    {{-- Header --}}
    <header class="bg-white border-b">
        <nav class="max-w-6xl mx-auto p-6 flex justify-between items-center">
            <h1 class="text-2xl font-bold tracking-tight">
                Storytelling<span class="text-blue-600">.amowogbaje</span>
            </h1>

            <div class="space-x-4 text-sm font-semibold">
                <a href="{{ route('home') }}" class="hover:text-blue-600">Home</a>
                <a href="{{ route('privacy') }}" class="hover:text-blue-600">Privacy</a>
                <a href="{{ route('terms') }}" class="hover:text-blue-600">Terms</a>
            </div>
        </nav>
    </header>

    {{-- Main Content --}}
    <main class="flex-1">
        @yield('content')
    </main>

    {{-- Footer --}}
    <footer class="border-t bg-white text-center py-10 text-gray-500 text-sm">
        <div class="mb-4">
            <a href="{{ route('privacy') }}" class="hover:text-blue-600 mx-3">Privacy Policy</a>
            <a href="{{ route('terms') }}" class="hover:text-blue-600 mx-3">Terms of Service</a>
        </div>
        &copy; {{ date('Y') }} Amowogbaje Engineering.
    </footer>

</body>
</html>