@extends('layouts.app-shell')
@section('title', __('production_bench.receipt.plural').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))
@section('content')<livewire:production-bench.purchasing.receipt-index />@endsection
