@extends('errors.layout')

@php
    // Show the reason the system gave (always Arabic in AIMS); hide framework defaults written in English.
    $reason = $exception->getMessage();
    $reason = preg_match('/\p{Arabic}/u', (string) $reason) ? $reason : 'لا تملك صلاحية الوصول إلى هذه الصفحة أو تنفيذ هذا الإجراء.';
@endphp

@section('title', 'غير مصرح')
@section('code', '403')
@section('message', $reason)
@section('hint', 'إذا كنت تحتاج هذه الصلاحية فراجع رئيس قسمك أو مدير النظام.')
