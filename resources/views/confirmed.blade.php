@extends('marketing::layout')

@section('title', __('marketing::public.confirmed_title'))

@section('content')
    <h1>{{ __('marketing::public.confirmed_title') }}</h1>
    <p>{{ __('marketing::public.confirmed_body', ['list' => $list?->name ?? $subscription->list_handle]) }}</p>
@endsection
