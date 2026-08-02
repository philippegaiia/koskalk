@extends('layouts.app-shell')
@section('title', __('production_bench.procurement.document').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))
@section('content')<livewire:production-bench.purchasing.procurement-detail :purchase-order="$purchaseOrder" />@endsection
