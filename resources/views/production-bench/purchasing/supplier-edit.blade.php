@extends('layouts.app-shell')

@section('title', 'Edit supplier · Production Bench · '.config('app.name'))
@section('page_heading', 'Production Bench')

@section('content')
    <livewire:production-bench.purchasing.supplier-edit :supplier="request()->route('supplier')" />
@endsection
