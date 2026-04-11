@extends('layouts.app-shell')

@section('title', isset($packagingItem) ? (($packagingItem->name ?? 'Packaging Item') . ' · '.config('app.name')) : 'New Packaging Item · '.config('app.name'))
@section('page_heading', isset($packagingItem) ? ($packagingItem->name ?? 'Edit Packaging Item') : 'New Packaging Item')

@section('content')
    <livewire:dashboard.packaging-item-editor :packaging-item="$packagingItem ?? null" />
@endsection
