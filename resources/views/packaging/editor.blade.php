@extends('layouts.app-shell')

@section('title', isset($packagingItem) ? (($packagingItem->name ?? 'Packaging Item') . ' · Koskalk') : 'New Packaging Item · Koskalk')
@section('page_heading', isset($packagingItem) ? ($packagingItem->name ?? 'Edit Packaging Item') : 'New Packaging Item')

@section('content')
    <livewire:dashboard.packaging-item-editor :packaging-item="$packagingItem ?? null" />
@endsection
