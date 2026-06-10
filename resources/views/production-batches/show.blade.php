@extends('layouts.app-shell')

@section('title', $productionBatch->recipe_name.' · Production · '.config('app.name'))
@section('page_heading', 'Production')

@section('content')
    <div class="mx-auto max-w-[90rem] space-y-6">
        <h1>{{ $productionBatch->recipe_name }}</h1>
        <p>{{ $productionBatch->production_batch_number ?: 'No batch number' }}</p>
    </div>
@endsection
