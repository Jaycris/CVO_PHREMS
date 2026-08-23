@extends('errors.layout')

@section('code', '419')
@section('title', 'Your session expired')

@section('message')
    You were signed out after a long spell of inactivity. Sign in again and carry on —
    nothing you had already saved is affected.
@endsection

@section('actions')
    <a href="{{ route('login') }}"
       class="inline-flex h-11 items-center rounded-lg bg-brand-700 px-5 text-sm font-bold text-white shadow-lg shadow-brand-900/20 transition hover:bg-brand-800">
        Sign in again
    </a>
@endsection
