@extends('layouts.app-shell')

@section('title', isset($packagingItem) ? (($packagingItem->name ?? __('packaging.page.title')) . ' · '.config('app.name')) : __('packaging.editor.create.page_title').' · '.config('app.name'))
@section('page_heading', isset($packagingItem) ? ($packagingItem->name ?? __('packaging.page.title')) : __('packaging.editor.create.page_title'))

@section('content')
    <livewire:dashboard.packaging-item-editor :packaging-item="$packagingItem ?? null" />
@endsection
