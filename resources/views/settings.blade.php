@extends('layouts.app-shell')

@section('title', 'Settings · '.config('app.name'))
@section('page_heading', 'Settings')

@section('content')
 <livewire:dashboard.settings-index />
@endsection
