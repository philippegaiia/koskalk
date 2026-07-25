@extends('layouts.app-shell')

@section('title', 'Media Library · '.config('app.name'))
@section('page_heading', 'Media Library')

@section('content')
    <livewire:dashboard.media-library-index />
@endsection
