<!DOCTYPE html>

<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

{{-- ==========================================================
     FAVICON
     ========================================================== --}}

<link
    rel="icon"
    type="image/png"
    href="{{ asset('img/eduxid-icon.png') }}"
>


{{-- ==========================================================
     KATEX CSS
     ========================================================== --}}

<link
    rel="stylesheet"
    href="{{ asset('katex/katex.min.css') }}"
>


{{-- ==========================================================
     CSRF TOKEN
     ========================================================== --}}

<meta
    name="csrf-token"
    content="{{ csrf_token() }}"
>


{{-- ==========================================================
     TITLE
     ========================================================== --}}

<title>
    {{ config('app.name', 'eduXid') }}
    - Smart School Management
</title>


{{-- ==========================================================
     IDENTITAS SISWA LMS
     ==========================================================

     LMS Siswa menggunakan users.login_id sebagai identitas
     sinkronisasi submission.

     Hanya diinjeksi pada halaman /siswa/lms/...
     ========================================================== --}}

@if(request()->is('murid/lms*') && auth()->check())

    <script>
        window.EDUXID_STUDENT_LOGIN_ID =
            @json(auth()->user()->login_id ?? null);

        window.EDUXID_NPSN =
            @json(
                \Illuminate\Support\Facades\DB::table('schools')->value('npsn')
            );
    </script>

@endif

{{-- ==========================================================
     VITE
     ========================================================== --}}

@vite([
    'resources/css/app.css',
    'resources/js/app.js'
])

{{-- eduXid fallback: menjaga warna tombol tetap terbaca jika bundle Tailwind tertinggal. --}}
<link rel="stylesheet" href="{{ asset('css/eduxid-ui-fallback.css') }}?v=20261007-ui-v7">

@if(request()->is('murid/lms') || request()->is('murid/lms/*'))
    @vite('resources/js/lms-siswa.js')
@endif

{{-- ==========================================================
     GLOBAL STYLE
     ========================================================== --}}

<style>

    html,
    body {

        font-family:
            'Arial',
            system-ui,
            -apple-system,
            sans-serif;

    }

</style>


{{-- ==========================================================
     SUPPORT UNTUK HALAMAN YANG MEMAKAI @push('styles')
     ========================================================== --}}

@stack('styles')


{{-- ==========================================================
     SUPPORT OPSIONAL UNTUK SECTION STYLE
     ========================================================== --}}

@yield('styles')

</head>

@php
    /*
     * Buku Nilai adalah tampilan yang dikunci: standardisasi tombol global
     * tidak boleh mengubah ukuran/layout halaman ini.
     */
    $preserveGradebookUi = request()->routeIs('guru.mengajar', 'guru.nilai*');
@endphp
<body class="antialiased bg-gray-50{{ $preserveGradebookUi ? ' eduxid-preserve-gradebook-ui' : '' }}">

<div class="min-h-screen flex flex-col">

{{-- ==========================================================
     NAVIGATION
     ========================================================== --}}

@include('layouts.navigation')


{{-- ==========================================================
     PAGE HEADING
     ========================================================== --}}

@hasSection('header')

    <header class="bg-white shadow-sm">

        <div
            class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8"
        >

            @yield('header')

        </div>

    </header>

@elseif(isset($header))

    <header class="bg-white shadow-sm">

        <div
            class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8"
        >

            {{ $header }}

        </div>

    </header>

@endif


{{-- ==========================================================
     PAGE CONTENT

     Mendukung:

     1. @extends('layouts.app')
        @section('content')
            ...
        @endsection

     2. Component yang memiliki $slot
     ========================================================== --}}

<main class="flex-1 overflow-y-auto">

    @hasSection('content')

        @yield('content')

    @elseif(isset($slot))

        {{ $slot }}

    @endif

</main>


{{-- ==========================================================
     FOOTER
     ========================================================== --}}

<footer
    class="bg-white border-t border-gray-100 py-4 text-center text-xs text-gray-400"
>

    &copy; {{ date('Y') }}

    . Anas Farhan.

    eduXid untuk Indonesia.

</footer>

</div>

{{-- ==============================================================
LMS GURU AUTO SYNC
==============================================================

 File ini hanya dimuat pada halaman:

     /lms/...

 Tidak dimuat pada:

     /siswa/lms/...

 karena LMS Siswa mempunyai mesin sync sendiri.
 ============================================================== --}}

@if(request()->is('lms/*'))

@vite('resources/js/lms-guru.js')

@endif

{{-- ==============================================================
STACK SCRIPTS
============================================================== --}}

@stack('scripts')

{{-- ==============================================================
KATEX JAVASCRIPT
============================================================== --}}

<script
    defer
    src="{{ asset('katex/katex.min.js') }}"
></script>

<script
    defer
    src="{{ asset('katex/contrib/auto-render.min.js') }}"
    onload="renderMath();"
></script>

<script>

    function renderMath() {

        if (
            typeof renderMathInElement === 'function'
        ) {

            renderMathInElement(
                document.body,
                {

                    delimiters: [

                        {
                            left: '$$',
                            right: '$$',
                            display: true
                        },

                        {
                            left: '$',
                            right: '$',
                            display: false
                        }

                    ],

                    throwOnError: false

                }
            );

        }

    }

</script>


{{-- ==============================================================
DONATION REMINDER — status authoritative berasal dari eduXid Cloud.
Tidak mengunci fitur; hanya ditampilkan pada titik pemicu GTK.
============================================================== --}}
@php
    $eduxidDonationShouldPrompt = false;
    if (auth()->check()) {
        try {
            $eduxidDonationService = app(\App\Services\DonationStatusService::class);
            $eduxidDonationIsGtk = $eduxidDonationService->isGtk(auth()->user());
            $eduxidDonationAfterSemester = $eduxidDonationIsGtk
                ? (bool) session()->pull('donation_prompt_after_semester', false)
                : false;
            $eduxidDonationShouldPrompt = $eduxidDonationIsGtk && (
                $eduxidDonationAfterSemester || $eduxidDonationService->shouldPromptOnCurrentRoute(auth()->user())
            );
        } catch (\Throwable) {
            $eduxidDonationShouldPrompt = false;
        }
    }
@endphp

@if($eduxidDonationShouldPrompt)
    @includeIf('layouts.partials.donation-reminder')
@endif

</body>

</html>
