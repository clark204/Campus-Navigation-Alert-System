{{-- Admin "Send emergency alert". The form itself (map-first, shared with the trusted users' portal page) is
     partials/alert-send-form. --}}
@extends('layout.admin-layout')

@section('title', 'Send emergency alert')
@section('heading', 'Emergency alerts')

@section('content.admin')
    @include('partials.alert-send-form', ['action' => route('admin.alerts.store'), 'backUrl' => route('admin.alerts.index'), 'backLabel' => 'Emergency alerts'])
@endsection
