@extends('layouts.app-shell')

@section('title', __('sharing.title').' · '.config('app.name'))
@section('page_heading', __('sharing.title'))

@section('content')
    <livewire:dashboard.formula-share-review :share="$share" />
@endsection
