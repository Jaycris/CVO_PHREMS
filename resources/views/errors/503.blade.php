@extends('errors.layout')

@section('code', '503')
@section('title', 'Down for maintenance')

@section('message')
    {{ config('app.name') }} is being updated and will be back shortly. Nothing is lost — try again in a few minutes.
@endsection

@section('actions')
    <span class="text-sm font-medium text-ink-500 dark:text-ink-400">Please check back shortly.</span>
@endsection
