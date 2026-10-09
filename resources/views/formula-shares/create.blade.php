@extends('layouts.app-shell')

@section('title', __('sharing.title').' · '.config('app.name'))
@section('page_heading', __('sharing.title'))

@section('content')
    <livewire:dashboard.formula-share-create :recipe="$recipe" />
@endsection
