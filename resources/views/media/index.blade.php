@extends('layouts.app-shell')

@section('title', __('media_library.title').' · '.config('app.name'))
@section('page_heading', __('media_library.title'))

@section('content')
    <livewire:dashboard.media-library-index />
@endsection
