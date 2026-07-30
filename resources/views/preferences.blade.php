@extends('marketing::layout')

@section('title', __('marketing::public.preferences_title'))

@section('content')
    <h1>{{ __('marketing::public.preferences_title') }}</h1>

    @include('marketing::partials.preferences', ['center' => $center])
@endsection
