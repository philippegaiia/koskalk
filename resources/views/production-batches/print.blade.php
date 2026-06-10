@extends('layouts.print')

@section('title', $productionBatch->recipe_name.' · Production Print · '.config('app.name'))

@section('content')
    <article class="document-sheet border border-slate-300 bg-white p-6 print:border-0 print:p-0">
        <h1>{{ $productionBatch->recipe_name }}</h1>
    </article>
@endsection
