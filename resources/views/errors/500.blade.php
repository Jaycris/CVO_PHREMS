@extends('errors.layout')

@section('code', '500')
@section('title', 'Something went wrong at our end')

@section('message')
    This is not something you did. The fault has been written to the log with the time it happened,
    which is what whoever looks into it will need.
@endsection
