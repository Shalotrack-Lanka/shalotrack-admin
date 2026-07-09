@extends('layouts.admin')

@section('content')
<div class="flex">

    @include('partials.sidebars.admin')

    <main class="flex-1 p-6 bg-gray-50 min-h-screen">

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">

            <h1 class="text-lg font-semibold text-gray-800">
                Transfer stock to Dealer
            </h1>

        </div>

    </main>

</div>
@endsection