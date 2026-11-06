@extends('layouts.app-shell')

@section('title', __('organization.manage').' · '.config('app.name'))
@section('page_heading', __('organization.manage'))

@section('content')
    <livewire:dashboard.product-organization-manager />
@endsection
