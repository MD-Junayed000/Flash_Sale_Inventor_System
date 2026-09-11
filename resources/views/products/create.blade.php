@extends('layouts.app')

@section('title', 'Create Product')

@section('content')
    <div class="max-w-2xl bg-white shadow rounded-lg p-6">
        <h2 class="text-lg font-semibold mb-4">Create New Product</h2>
        <form action="{{ route('products.store') }}" method="POST">
            @include('products._form')
        </form>
    </div>
@endsection
