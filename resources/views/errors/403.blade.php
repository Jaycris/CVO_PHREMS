@extends('errors.layout')

@section('code', '403')
@section('title', 'You cannot open this page')

@section('message')
    Either this page is not part of your access, or what you asked for belongs to someone else.
    If you think it should be yours, Human Resources can grant it.
@endsection

{{-- The app raises deliberate 403s with a plain-language reason: "That payslip
     is not yours", "No employee profile is linked to your account". Those are
     written for the person reading them, so they get shown. --}}
@if (($exception?->getMessage() ?? '') !== '')
    @section('detail', $exception->getMessage())
@endif
