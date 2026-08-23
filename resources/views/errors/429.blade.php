@extends('errors.layout')

@section('code', '429')
@section('title', 'Too many attempts')

@section('message')
    Give it a minute and try again. This limit is what stops someone guessing their way into an account.
@endsection
