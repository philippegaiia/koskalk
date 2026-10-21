@extends('layouts.app-shell')

@section('title', __('dossiers.raw_materials.title').' · '.config('app.name'))
@section('page_heading', __('dossiers.raw_materials.title'))

@section('content')
    <livewire:dossiers.raw-materials-index />
@endsection
