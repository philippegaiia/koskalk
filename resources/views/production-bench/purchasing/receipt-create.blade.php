@extends('layouts.app-shell')
@section('title', __('production_bench.receipt.new').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))
@section('content')<livewire:production-bench.purchasing.receipt-create />@endsection
