@extends('layouts.app')

@section('title', 'Edit Product')

@section('content')
    <div class="max-w-2xl bg-white shadow rounded-lg p-6">
        <h2 class="text-lg font-semibold mb-4">Edit Product #{{ $product->id }}</h2>
        <form action="{{ route('products.update', $product) }}" method="POST">
            @method('PUT')
            @include('products._form')
        </form>
    </div>
@endsection
