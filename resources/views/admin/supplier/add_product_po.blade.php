<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

</head>
<body x-data="{ sidebarOpen: false }"> <!-- Added Alpine state for Mobile Menu Toggle -->

            <div class="flex h-screen overflow-hidden"> <!-- Prevent double scrollbars -->

                    @include('partials.sidebars.admin')


                    @include('partials.header')


        <main class="p-4 md:p-6 flex-1">
            @yield('content')

                            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">


                                <div>

                            <div class="flex justify-between items-center">

                                <h1 class="text-5xl font-light text-gray-600">
                                    Purchase Order
                                </h1>

                                <button onclick="printPO()"
                                    class="bg-sky-500 hover:bg-sky-600 text-white px-8 py-2 rounded">

                                    Save & Print

                                </button>

                            </div>

                            <hr class="border-sky-400 mt-2 mb-10">

                        </div>


                        <div class="grid grid-cols-12 gap-4 items-center mb-12">

                        <label class="col-span-2 text-xl text-gray-700">

                            Select Supplier

                        </label>

                        <div class="col-span-4">

                            <select
                                class="w-full border rounded shadow px-4 py-2">

                                <option>--Select--</option>

                                <option>Dialog Axiata</option>

                                <option>Mobitel</option>

                                <option>Vertigo International</option>

                            </select>

                        </div>

                    </div>


                    <div class="border rounded overflow-hidden">

                        <table class="w-full">

                            <thead class="bg-gray-100">

                                <tr>

                                    <th class="border p-3">#</th>

                                    <th class="border p-3">Product</th>

                                    <th class="border p-3">Qty</th>

                                    <th class="border p-3">Price</th>

                                    <th class="border p-3">Total</th>

                                </tr>

                            </thead>

                            <tbody>

                                @for($i=1;$i<=8;$i++)

                                <tr>

                                    <td class="border p-2 text-center">{{ $i }}</td>

                                    <td class="border p-2">

                                        Product {{ $i }}

                                    </td>

                                    <td class="border p-2">

                                        <input
                                            type="number"
                                            value="1"
                                            class="w-24 border rounded px-2 py-1">

                                    </td>

                                    <td class="border p-2">

                                        <input
                                            type="number"
                                            value="2500"
                                            class="w-32 border rounded px-2 py-1">

                                    </td>

                                    <td class="border p-2 text-right">

                                        Rs.2500

                                    </td>

                                </tr>

                                @endfor

                            </tbody>

                        </table>

                    </div>



                    <div class="flex justify-end mt-8">

                        <div class="w-96">

                            <table class="w-full">

                                <tr>

                                    <td class="font-semibold text-xl py-3">

                                        Grand Total

                                    </td>

                                    <td class="text-right text-2xl font-bold text-sky-600">

                                        Rs. 20,000.00

                                    </td>

                                </tr>

                            </table>

                        </div>

                    </div>

                    <!-- Title -->
                    <div class="mb-10">

                        <h1 class="text-5xl font-light text-gray-600">
                            Stock Upload
                        </h1>

                        <hr class="mt-2 border-sky-400">

                    </div>

                <!-- Select Purchase Order -->
                <div class="grid grid-cols-12 items-center gap-5">

                    <label class="col-span-2 text-xl text-gray-700">
                        Select P.O.
                    </label>

                    <div class="col-span-3">

                        <select
                            class="w-full border border-gray-300 rounded-md shadow-sm px-4 py-2 focus:outline-none focus:ring-2 focus:ring-sky-400">

                            <option>PO-1001</option>
                            <option>PO-1002</option>
                            <option>PO-1003</option>

                        </select>

                    </div>

                </div>


        </main>

    </div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

<script>
function printPO()
{
    window.print();
}
</script>

</body>
</html>