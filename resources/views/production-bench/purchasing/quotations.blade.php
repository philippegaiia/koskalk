@extends('layouts.app-shell')
@section('title', __('production_bench.procurement.quotation_requests').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))
@section('content')<livewire:production-bench.purchasing.procurement-index :stage="\App\Enums\ProcurementStage::Quotation->value" />@endsection
