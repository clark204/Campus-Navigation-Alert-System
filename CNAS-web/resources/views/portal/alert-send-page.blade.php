{{-- Faculty portal "Send emergency alert" (Portal\AlertController@create) for trusted faculty and staff: the admin's
     own map-first form (partials/alert-send-form), sent as them. --}}
@extends('layout.portal-layout')

@section('title', 'Send emergency alert')
@section('heading', 'Emergency alerts')

@section('content.portal')
    @include('partials.alert-send-form', ['action' => route('faculty.alerts.store'), 'backUrl' => route('faculty.alerts.index'), 'backLabel' => 'Emergency alerts'])
@endsection
