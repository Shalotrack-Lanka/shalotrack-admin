<!-- Overlay Background when Mobile Sidebar is open -->
<div x-show="sidebarOpen"
     @click="sidebarOpen = false"
     class="fixed inset-0 bg-black/50 z-40 lg:hidden">
</div>

<!-- Main Content Container -->
<div class="main-content flex-1 h-screen overflow-y-auto pl-0 lg:pl-72 flex flex-col">

    <!-- Header -->
    <header class="header-bar px-4 md:px-6 py-4 flex items-center justify-between sticky top-0 z-30 border-b border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-900">

        <div class="flex items-center gap-3">

            <!-- Mobile Menu -->
            <button
                @click="sidebarOpen = true"
                class="lg:hidden text-gray-700 dark:text-white p-1 rounded-md focus:outline-none">

                <svg class="w-6 h-6"
                     fill="none"
                     stroke="currentColor"
                     viewBox="0 0 24 24">

                    <path stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M4 6h16M4 12h16M4 18h16"/>

                </svg>

            </button>

            <h2 class="page-title text-xl md:text-2xl font-bold truncate">
                @yield('title')
            </h2>

        </div>

        <div class="flex items-center gap-2 md:gap-3">

            <!-- Dark Mode -->

            <button
                id="themeToggle"
                class="toggle-btn w-9 h-9 md:w-10 md:h-10 rounded-full flex items-center justify-center transition duration-300 shadow">

                <svg id="moonIcon"
                     xmlns="http://www.w3.org/2000/svg"
                     class="w-4 h-4 md:w-5 md:h-5 text-gray-700"
                     fill="none"
                     viewBox="0 0 24 24"
                     stroke="currentColor">

                    <path stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9 9 0 008.354-5.646z"/>

                </svg>

                <svg id="sunIcon"
                     xmlns="http://www.w3.org/2000/svg"
                     class="hidden w-4 h-4 md:w-5 md:h-5 text-yellow-400"
                     fill="none"
                     viewBox="0 0 24 24"
                     stroke="currentColor">

                    <path stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M12 3v2m0 14v2m9-9h-2M5 12H3m15.364 6.364l-1.414-1.414M7.05 7.05 5.636 5.636m12.728 0L16.95 7.05M7.05 16.95l-1.414 1.414M12 8a4 4 0 100 8 4 4 0 000-8z"/>

                </svg>

            </button>

            <!-- User Dropdown -->

            <div x-data="{open:false}" class="relative">

                <button
                    @click="open=!open"
                    @click.outside="open=false"
                    class="flex items-center gap-1 md:gap-2 px-2 md:px-3 py-2 rounded-lg transition">

                    <div class="w-8 h-8 md:w-9 md:h-9 rounded-full bg-[#0B1B3F] text-white flex items-center justify-center font-semibold text-xs md:text-sm">
                        {{ strtoupper(substr(Auth::user()->full_name,0,1)) }}
                    </div>

                    <span class="username-text font-medium text-xs md:text-sm hidden sm:inline">
                        {{ Auth::user()->full_name }}
                    </span>

                    <svg
                        :class="open ? 'rotate-180' : ''"
                        class="w-3 h-3 md:w-4 md:h-4 transition-transform duration-200 username-text"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24">

                        <path stroke-linecap="round"
                              stroke-linejoin="round"
                              stroke-width="2"
                              d="M19 9l-7 7-7-7"/>

                    </svg>

                </button>

                <div
                    x-show="open"
                    x-transition
                    class="dropdown-menu absolute right-0 mt-2 w-48 rounded-lg shadow-lg z-50">

                    <div class="dropdown-info px-4 py-3 border-b">

                        <p class="font-semibold">
                            {{ Auth::user()->full_name }}
                        </p>

                        <span class="text-sm">
                            {{ Auth::user()->role }}
                        </span>

                    </div>

                    @if(Auth::user()->role=='ADMIN')
                        <a href="{{ route('admin.profile') }}"
                           class="block px-4 py-2 hover:bg-gray-100">
                            Profile
                        </a>
                    @elseif(Auth::user()->role=='DEALER')
                        <a href="{{ route('dealer.profile') }}"
                           class="block px-4 py-2 hover:bg-gray-100">
                            Profile
                        </a>
                    @elseif(Auth::user()->role=='FINANCE')
                        <a href="{{ route('finance.profile') }}"
                           class="block px-4 py-2 hover:bg-gray-100">
                            Profile
                        </a>
                    @else
                        <a href="{{ route('technician.profile') }}"
                           class="block px-4 py-2 hover:bg-gray-100">
                            Profile
                        </a>
                    @endif

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf

                        <button
                            type="submit"
                            class="w-full text-left px-4 py-2 text-red-500 hover:bg-gray-100">

                            Logout

                        </button>

                    </form>

                </div>

            </div>

        </div>

    </header>