@extends('layouts.app')

@section('title', 'Products')

@section('content')
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-100">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-semibold text-slate-600 uppercase">ID</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold text-slate-600 uppercase">Name</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold text-slate-600 uppercase">SKU</th>
                    <th class="px-4 py-2 text-right text-xs font-semibold text-slate-600 uppercase">Price</th>
                    <th class="px-4 py-2 text-right text-xs font-semibold text-slate-600 uppercase">Stock</th>
                    <th class="px-4 py-2 text-left text-xs font-semibold text-slate-600 uppercase">Status</th>
                    <th class="px-4 py-2 text-right text-xs font-semibold text-slate-600 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($products as $product)
                    <tr>
                        <td class="px-4 py-2 text-sm">{{ $product->id }}</td>
                        <td class="px-4 py-2 text-sm font-medium">{{ $product->name }}</td>
                        <td class="px-4 py-2 text-sm font-mono">{{ $product->sku }}</td>
                        <td class="px-4 py-2 text-sm text-right">${{ number_format((float) $product->price, 2) }}</td>
                        <td class="px-4 py-2 text-sm text-right">
                            <span class="{{ $product->stock_quantity === 0 ? 'text-red-600 font-bold' : ($product->stock_quantity < 5 ? 'text-amber-600' : 'text-slate-700') }}">
                                {{ $product->stock_quantity }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-sm">
                            <span class="px-2 py-0.5 rounded text-xs
                                {{ $product->status->value === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ ucfirst($product->status->value) }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right text-sm space-x-2">
                            <a href="{{ route('products.edit', $product) }}" class="text-blue-600 hover:underline">Edit</a>
                            <form action="{{ route('products.destroy', $product) }}" method="POST" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline"
                                    onclick="return confirm('Delete this product?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-slate-500">
                            No products yet. <a href="{{ route('products.create') }}" class="text-blue-600 hover:underline">Create one</a>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $products->links() }}
    </div>
@endsection
