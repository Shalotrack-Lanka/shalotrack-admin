@extends('layouts.dealer')

@section('title', 'My scorecard')

@section('content')
<div class="max-w-7xl mx-auto">
    @include('scorecard._table')
</div>
@endsection