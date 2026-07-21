@extends('layouts.app-shell')

@section('title', __('packaging.page.title').' · '.config('app.name'))
@section('page_heading', __('packaging.page.title'))

@section('content')
    <livewire:dashboard.packaging-items-index />
@endsection
