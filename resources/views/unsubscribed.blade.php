@extends('marketing::layout')

@section('title', __('marketing::public.unsubscribed_title'))

@section('content')
    <h1>{{ __('marketing::public.unsubscribed_title') }}</h1>
    <p>{{ __('marketing::public.unsubscribed_body', ['list' => $list?->name ?? $subscription->list_handle]) }}</p>
@endsection
