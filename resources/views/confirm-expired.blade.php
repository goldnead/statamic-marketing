@extends('marketing::layout')

@section('title', __('marketing::public.confirm_expired_title'))

@section('content')
    <h1>{{ __('marketing::public.confirm_expired_title') }}</h1>
    <p>{{ __('marketing::public.confirm_expired_body') }}</p>
@endsection
