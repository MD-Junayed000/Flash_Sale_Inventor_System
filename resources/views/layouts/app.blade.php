<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }} - @yield('title', 'Inventory')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800">
    <nav class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-6 py-3 flex items-center justify-between">
            <a href="{{ route('products.index') }}" class="text-lg font-semibold">
                ⚡ Flash Sale Inventory
            </a>
            <div class="space-x-2 text-sm">
                <a href="{{ route('products.index') }}" class="text-slate-600 hover:text-slate-900">Products</a>
                <a href="{{ route('products.create') }}" class="bg-blue-600 text-white px-3 py-1.5 rounded hover:bg-blue-700">+ New</a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-8">
        @if (session('status'))
            <div class="mb-4 p-3 bg-green-100 text-green-800 rounded border border-green-200">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
