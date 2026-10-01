@extends('layouts.dealer')

@section('title', 'Renewals due')

@section('content')
<div class="max-w-7xl mx-auto">
    @include('renewals._list')
</div>
@endsection