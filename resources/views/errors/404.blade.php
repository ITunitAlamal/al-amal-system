@extends('errors.layout')

@php
    $reason = $exception->getMessage();
    $reason = preg_match('/\p{Arabic}/u', (string) $reason) ? $reason : 'الرابط غير صحيح، أو أن السجل غير موجود، أو أنه خارج نطاق صلاحياتك.';
@endphp

@section('title', 'الصفحة غير موجودة')
@section('code', '404')
@section('message', $reason)
