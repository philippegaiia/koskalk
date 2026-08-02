@extends('layouts.app-shell')

@section('title', (request()->route('listing') ? 'Edit' : 'New').' supplier listing · Production Bench · '.config('app.name'))
@section('page_heading', 'Production Bench')

@section('content')
    <livewire:production-bench.purchasing.supplier-listing-create :supplier="request()->route('supplier')" :listing="request()->route('listing')" />
@endsection
