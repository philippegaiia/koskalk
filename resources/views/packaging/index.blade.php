@extends('layouts.app-shell')

@section('title', 'Packaging Items · '.config('app.name'))
@section('page_heading', 'Packaging Items')

@section('content')
    <livewire:dashboard.packaging-items-index />
@endsection
