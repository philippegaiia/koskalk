@extends('layouts.app-shell')

@section('title', __('production_bench.inventory.current_position').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))

@section('content')
    <livewire:production-bench.inventory-material-detail
        :subject="request()->route($subjectType === 'ingredient' ? 'ingredient' : 'packagingItem')"
        :subject-type="$subjectType"
    />
@endsection
