@extends('layouts.app-shell')

@section('title', __('settings.page.title').' · '.config('app.name'))
@section('page_heading', __('settings.page.title'))

@section('content')
    <livewire:dashboard.settings-index />
@endsection
