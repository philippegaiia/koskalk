@extends('layouts.app-shell')
@section('title', __('production_bench.procurement.new_quotation').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))
@section('content')<livewire:production-bench.purchasing.procurement-create :stage="\App\ProcurementStage::Quotation->value" />@endsection
