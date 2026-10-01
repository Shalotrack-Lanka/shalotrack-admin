@extends('layouts.dealer')

@section('title', 'Device health')

@section('content')
<div class="max-w-7xl mx-auto">
    @include('device-health._list')
</div>
@endsection