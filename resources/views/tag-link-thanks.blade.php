@extends('marketing::layout')

@section('title', __('marketing::public.tag_link_thanks_title'))

@section('content')
    <h1>{{ __('marketing::public.tag_link_thanks_title') }}</h1>
    <p>{{ $thanks }}</p>
@endsection
