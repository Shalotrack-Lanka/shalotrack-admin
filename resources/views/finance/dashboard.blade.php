@extends('layouts.simple')

@section('title', 'Finance')

@section('content')
    <div class="bg-white rounded-xl shadow-md border border-gray-200 p-8 text-center">
        <h1 class="text-lg font-bold text-slate-900">Welcome, {{ Auth::user()->full_name }}</h1>
        <p class="mt-2 text-sm text-slate-600">
            There is no Finance dashboard set up yet, so there is nothing to show here.
            If you expected to see something, please contact a ShaloTrack administrator.
        </p>
    </div>
@endsection
