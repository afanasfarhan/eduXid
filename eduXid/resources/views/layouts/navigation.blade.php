<nav x-data="{ open: false }" class="bg-white border-b border-gray-100">

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">

            <div class="flex">

                <div class="shrink-0 flex items-center">
                    <a href="{{ auth()->user()->role === 'siswa' ? route('siswa.lms.index') : route('dashboard') }}"
                       class="flex items-center gap-2">

                        <img src="{{ asset('img/eduxid-icon.png') }}"
                             alt="eduXid Icon"
                             class="h-8 w-auto"
                             onerror="this.style.display='none'">

                        <span class="font-bold text-xl text-gray-800 hidden sm:inline">
                            eduXid
                        </span>

                    </a>
                </div>

                @php
                    $user = auth()->user();
                    $role = $user->roleName();
                    $guru = in_array($role, ['guru', 'wali_kelas', 'guru_bk', 'tendik', 'kepala_sekolah'], true)
                        ? $user->teacher()
                        : null;

                    $isSetupMode = false;

                    if (!$isSetupMode) {

                        if ($role === 'admin') {

                            $isSetupMode =
                                !DB::table('schools')->exists()
                                ||
                                !DB::table('students')->exists();

                        } elseif (in_array($role, ['guru', 'wali_kelas', 'guru_bk', 'tendik', 'kepala_sekolah'])) {

                            $isSetupMode = !$guru;
                        }
                    }

                    $canAccessTeacherMenus = !$isSetupMode && $user->canTeach();
                    $canUseUjian = !$isSetupMode && $user->canTeach();

                    $isWaliKelas = false;
                    $isPembinaEkskul = false;
                    $isGuruBK = false;
                    $isKoorKokurikuler = false;

                    if (
                        !$isSetupMode &&
                        in_array($role, ['guru', 'wali_kelas', 'guru_bk'])
                    ) {

                        if ($guru) {

                            $isWaliKelas =
                                DB::table('homerooms')
                                    ->where('homeroom_teacher_id', $guru->id)
                                    ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
                                    ->where('jenis_rombel', 'not like', '%Pilihan%')
                                    ->exists();

                            $isPembinaEkskul =
                                DB::table('homerooms')
                                    ->where('homeroom_teacher_id', $guru->id)
                                    ->where('jenis_rombel', 'like', '%Ekstrakurikuler%')
                                    ->exists();

                            $isGuruBK =
                                DB::table('teaching_assignments')
                                    ->join(
                                        'subjects',
                                        'teaching_assignments.subject_id',
                                        '=',
                                        'subjects.id'
                                    )
                                    ->where(
                                        'teaching_assignments.teacher_id',
                                        $guru->id
                                    )
                                    ->where(function ($query) {
                                        $query->where(
                                            'subjects.nama_mata_pelajaran',
                                            'like',
                                            '%Bimbingan%'
                                        )->orWhere(
                                            'subjects.nama_mata_pelajaran',
                                            'like',
                                            '%Konseling%'
                                        );
                                    })
                                    ->exists();

                            $isKoorKokurikuler =
                                DB::table('kokurikulers')
                                    ->where('koordinator_id', $guru->id)
                                    ->exists();
                        }
                    }

                    // Tendik hanya memperoleh menu Ekstrakurikuler jika
                    // benar-benar tercatat sebagai pembina pada semester aktif.
                    // Hak aksesnya diperiksa ulang oleh RoleMiddleware.
                    if (!$isSetupMode && $role === 'tendik' && $guru) {
                        $isPembinaEkskul = $user->hasAdditionalDuty('ekskul');
                    }
                @endphp

                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">

                    @if($role == 'admin')

                        <x-nav-link
                            :href="route('admin.dashboard')"
                            :active="request()->routeIs('admin.dashboard')">
                            {{ __('Dasbor') }}
                        </x-nav-link>

                        @if(!$isSetupMode)

                            <x-nav-link
                                :href="route('guru.index')"
                                :active="request()->routeIs('guru.index')">
                                {{ __('GTK') }}
                            </x-nav-link>

                            <x-nav-link
                                :href="route('siswa.index')"
                                :active="request()->routeIs('murid.index')">
                                {{ __('Murid') }}
                            </x-nav-link>

                            <x-nav-link
                                :href="route('admin.kelas')"
                                :active="request()->routeIs('admin.kelas')">
                                {{ __('Kelas') }}
                            </x-nav-link>

                            <x-nav-link
                                :href="route('admin.pembelajaran')"
                                :active="request()->routeIs('admin.pembelajaran')">
                                {{ __('Pembelajaran') }}
                            </x-nav-link>

                            <x-nav-link
                                :href="route('admin.kokurikuler')"
                                :active="request()->routeIs('admin.kokurikuler')">
                                {{ __('Kokurikuler') }}
                            </x-nav-link>

                            <x-nav-link
                                :href="route('rapor.index')"
                                :active="request()->routeIs('rapor.index')">
                                {{ __('Rapor') }}
                            </x-nav-link>

                        @endif

                        <x-nav-link
                            :href="route('admin.pengaturan')"
                            :active="request()->routeIs('admin.pengaturan')">
                            {{ __('Pengaturan') }}
                        </x-nav-link>

                    @elseif($role == 'tendik')

                        <x-nav-link
                            :href="route('dashboard')"
                            :active="request()->routeIs('dashboard')">
                            {{ __('Dasbor') }}
                        </x-nav-link>

                        @if(!$isSetupMode)

                            <x-nav-link
                                :href="route('guru.index')"
                                :active="request()->routeIs('guru.index')">
                                {{ __('GTK') }}
                            </x-nav-link>

                            <x-nav-link
                                :href="route('siswa.index')"
                                :active="request()->routeIs('siswa.*') || request()->routeIs('murid.*')">
                                {{ __('Murid') }}
                            </x-nav-link>

                            {{-- V21R144: Ekskul hanya untuk Tendik pembina dengan penugasan sah. --}}
                            @if($isPembinaEkskul)
                                <x-nav-link
                                    :href="route('guru.ekskul')"
                                    :active="request()->routeIs('guru.ekskul*')">
                                    {{ __('Ekstrakurikuler') }}
                                </x-nav-link>
                            @endif

                            @if(\Illuminate\Support\Facades\Route::has('tu.ujian.index'))
                                <div class="flex items-center">
                                    <x-dropdown align="left" width="48">
                                        <x-slot name="trigger">
                                            <button type="button"
                                                class="inline-flex items-center px-1 pt-1 pb-0 border-b-2 {{ request()->routeIs('tu.ujian.*') ? 'border-indigo-400 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} text-sm font-medium leading-5 focus:outline-none transition duration-150 ease-in-out">
                                                <span class="relative top-[2px]">Ujian</span>
                                                <svg class="ms-1 h-4 w-4 relative top-[2px]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                                </svg>
                                            </button>
                                        </x-slot>

                                        <x-slot name="content">
                                            <x-dropdown-link
                                                :href="route('tu.ujian.index')"
                                                :active="request()->routeIs('tu.ujian.*')">
                                                {{ __('Administrasi Ujian') }}
                                            </x-dropdown-link>
                                        </x-slot>
                                    </x-dropdown>
                                </div>
                            @endif

                            <div class="flex items-center">
                                <x-dropdown align="left" width="48">
                                    <x-slot name="trigger">
                                        <button type="button"
                                            class="inline-flex items-center px-1 pt-1 pb-0 border-b-2 {{ request()->routeIs('tu.surat.*') || request()->routeIs('tu.kartu-pelajar.*') ? 'border-indigo-400 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} text-sm font-medium leading-5 focus:outline-none transition duration-150 ease-in-out">
                                            <span class="relative top-[2px]">Administrasi Sekolah</span>
                                            <svg class="ms-1 h-4 w-4 relative top-[2px]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                    </x-slot>

                                    <x-slot name="content">
                                        <x-dropdown-link
                                            :href="route('tu.profil.index')"
                                            :active="request()->routeIs('tu.profil.*')">
                                            {{ __('Profil') }}
                                        </x-dropdown-link>

                                        <x-dropdown-link
                                            :href="route('tu.surat.index')"
                                            :active="request()->routeIs('tu.surat.index')">
                                            {{ __('Buat Surat') }}
                                        </x-dropdown-link>

                                        <x-dropdown-link
                                            :href="route('tu.surat.riwayat')"
                                            :active="request()->routeIs('tu.surat.riwayat') || request()->routeIs('tu.surat.cetakUlang')">
                                            {{ __('Arsip Surat') }}
                                        </x-dropdown-link>

                                        <x-dropdown-link
                                            :href="route('tu.kartu-pelajar.index')"
                                            :active="request()->routeIs('tu.kartu-pelajar.*')">
                                            {{ __('Kartu Pelajar') }}
                                        </x-dropdown-link>
                                    </x-slot>
                                </x-dropdown>
                            </div>

                        @endif

                        <x-nav-link
                            :href="route('guru.pengaturan')"
                            :active="request()->routeIs('guru.pengaturan')">
                            {{ __('Pengaturan') }}
                        </x-nav-link>
                    @elseif($role == 'siswa')

                        <x-nav-link
                            :href="route('siswa.lms.index')"
                            :active="request()->routeIs('murid.lms.*')">
                            {{ __('Beranda LMS') }}
                        </x-nav-link>

                    @else

                        <x-nav-link
                            :href="route('dashboard')"
                            :active="request()->routeIs('dashboard')">
                            {{ __('Dasbor') }}
                        </x-nav-link>

                        @if(!$isSetupMode && $canUseUjian)
                            <x-nav-link
                                :href="route('guru.atp.index')"
                                :active="request()->routeIs('guru.atp.*')">
                                {{ __('ATP') }}
                            </x-nav-link>
                        @endif

                        {{-- Tambahan Modul Ajar AI: navigasi lama tetap dipertahankan. --}}
                        @if(!$isSetupMode && $canAccessTeacherMenus
                            && in_array($role, ['guru', 'wali_kelas', 'guru_bk', 'pengajar'], true)
                            && \Illuminate\Support\Facades\Route::has('guru.modul-ajar.index'))
                            <x-nav-link
                                :href="route('guru.modul-ajar.index')"
                                :active="request()->routeIs('guru.modul-ajar.*')">
                                {{ __('Modul Ajar') }}
                            </x-nav-link>
                        @endif

                        @if(!$isSetupMode)

                            @if($canAccessTeacherMenus)

                                <x-nav-link
                                    :href="route('lms.courses.index')"
                                    :active="request()->routeIs('lms.*')">
                                    {{ __('LMS') }}
                                </x-nav-link>

                            @endif

                            @if($canUseUjian)

                                <div class="flex items-center">

                                    <x-dropdown align="left" width="48">

                                        <x-slot name="trigger">

                                            <button
                                                type="button"
                                                class="inline-flex items-center px-1 pt-1 pb-0 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 hover:text-gray-700 hover:border-gray-300 focus:outline-none focus:text-gray-700 focus:border-gray-300 transition duration-150 ease-in-out">

                                                <span class="relative top-[2px]">
                                                    Ujian
                                                </span>

                                                <svg
                                                    class="ms-1 h-4 w-4 relative top-[2px]"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor">

                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd" />

                                                </svg>

                                            </button>

                                        </x-slot>

                                        <x-slot name="content">

                                            <x-dropdown-link
                                                :href="route('guru.bankSoal')"
                                                :active="request()->routeIs('guru.bankSoal')">
                                                Bank Soal
                                            </x-dropdown-link>

                                            <x-dropdown-link
                                                :href="route('guru.cetakSoal')"
                                                :active="request()->routeIs('guru.cetakSoal')">
                                                Cetak Soal
                                            </x-dropdown-link>

                                            <x-dropdown-link
                                                :href="route('guru.cbt')"
                                                :active="request()->routeIs('guru.cbt', 'guru.cbt.create', 'guru.cbt.soal*')">
                                                Manajemen Ujian
                                            </x-dropdown-link>

                                            @if(\Illuminate\Support\Facades\Route::has('tu.ujian.index'))
                                                <x-dropdown-link
                                                    :href="route('tu.ujian.index')"
                                                    :active="request()->routeIs('tu.ujian.*')">
                                                    Administrasi Ujian
                                                </x-dropdown-link>
                                            @endif

                                            <x-dropdown-link
                                                :href="route('guru.cbt.koreksi')"
                                                :active="request()->routeIs('guru.cbt.koreksi*')">
                                                Koreksi CBT
                                            </x-dropdown-link>

                                            <x-dropdown-link
                                                :href="route('guru.ljk-auto.index')"
                                                :active="request()->routeIs('guru.ljk.*', 'guru.ljk-auto.*')">
                                                Koreksi LJK
                                            </x-dropdown-link>

                                        </x-slot>

                                    </x-dropdown>

                                </div>

                            @endif

                            @if($canAccessTeacherMenus)

                                <x-nav-link
                                    :href="route('guru.mengajar')"
                                    :active="request()->routeIs('guru.mengajar')">
                                    {{ __('Buku Nilai') }}
                                </x-nav-link>

                            @endif

                            @if($isWaliKelas)

                                <x-nav-link
                                    :href="route('guru.waliKelas')"
                                    :active="request()->routeIs('guru.waliKelas')">
                                    {{ __('Wali Kelas') }}
                                </x-nav-link>

                            @endif

                            @if($isPembinaEkskul)

                                <x-nav-link
                                    :href="route('guru.ekskul')"
                                    :active="request()->routeIs('guru.ekskul')">
                                    {{ __('Ekstrakurikuler') }}
                                </x-nav-link>

                            @endif

                            @if($isKoorKokurikuler)

                                <x-nav-link
                                    :href="route('guru.kokurikuler')"
                                    :active="request()->routeIs('guru.kokurikuler')">
                                    {{ __('Kokurikuler') }}
                                </x-nav-link>

                            @endif

                            @if($isGuruBK || $role === 'guru_bk')

                                <x-nav-link
                                    :href="route('guru.bk')"
                                    :active="request()->routeIs('guru.bk')">
                                    {{ __('Bimbingan Konseling') }}
                                </x-nav-link>

                            @endif

                            @if(
                                in_array($role, ['admin', 'kepala_sekolah'])
                                ||
                                $isWaliKelas
                            )

                                <x-nav-link
                                    :href="route('rapor.index')"
                                    :active="request()->routeIs('rapor.index')">
                                    {{ __('Rapor') }}
                                </x-nav-link>

                            @endif

                        @endif

                        <x-nav-link
                            :href="route('guru.pengaturan')"
                            :active="request()->routeIs('guru.pengaturan')">
                            {{ __('Pengaturan') }}
                        </x-nav-link>

                    @endif

                    <x-nav-link
                        :href="route('about.index')"
                        :active="request()->routeIs('about.index')">
                        {{ __('Donasi') }}
                    </x-nav-link>

                </div>

            </div>

            <div class="hidden sm:flex sm:items-center sm:ms-6">

                <x-dropdown align="right" width="48">

                    <x-slot name="trigger">

                        <button
                            class="inline-flex items-center pt-2 pe-3 pb-2 border-transparent text-sm leading-4 font-medium text-gray-500 bg-white rounded-md hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">

                            <div>
                                {{ auth()->user()->name }}
                            </div>

                            <div class="ms-1">

                                <svg
                                    class="fill-current h-4 w-4"
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 20 20">

                                    <path
                                        fill-rule="evenodd"
                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                        clip-rule="evenodd" />

                                </svg>

                            </div>

                        </button>

                    </x-slot>

                                        <x-slot name="content">
                        <div class="py-1">
                            <x-dropdown-link :href="route('profile.edit') . '#ubah-profil'">
                                {{ __('Ubah Profil') }}
                            </x-dropdown-link>

                            <x-dropdown-link :href="route('profile.edit') . '#ganti-password'">
                                {{ __('Ganti Password') }}
                            </x-dropdown-link>

                            <x-dropdown-link :href="route('pilih.semester')">
                                {{ __('Ganti Periode') }}
                            </x-dropdown-link>

                            <div class="my-1 border-t border-gray-100"></div>

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf

                                <x-dropdown-link
                                    :href="route('logout')"
                                    onclick="event.preventDefault(); this.closest('form').submit();">
                                    {{ __('Log Out') }}
                                </x-dropdown-link>
                            </form>
                        </div>
                    </x-slot>

                </x-dropdown>

            </div>

            <div class="-me-2 flex items-center sm:hidden">

                <button
                    @click="open = ! open"
                    class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500">

                    <svg
                        class="h-6 w-6"
                        stroke="currentColor"
                        fill="none"
                        viewBox="0 0 24 24">

                        <path
                            class="inline-flex"
                            :class="{'hidden': open, 'inline-flex': ! open}"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16" />

                        <path
                            class="hidden"
                            :class="{'block': open, 'hidden': ! open}"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M6 18L18 6L6 6L18 18" />

                    </svg>

                </button>

            </div>

        </div>
    </div>

    <div
        :class="{'block': open, 'hidden': ! open}"
        class="hidden sm:hidden">

        <div class="pt-2 pb-3 space-y-1">

            @if($role == 'admin')

                <x-responsive-nav-link
                    :href="route('admin.dashboard')"
                    :active="request()->routeIs('admin.dashboard')">
                    {{ __('Dasbor') }}
                </x-responsive-nav-link>

                @if(!$isSetupMode)

                    <x-responsive-nav-link
                        :href="route('guru.index')"
                        :active="request()->routeIs('guru.index')">
                        {{ __('GTK') }}
                    </x-responsive-nav-link>

                    <x-responsive-nav-link
                        :href="route('siswa.index')"
                        :active="request()->routeIs('murid.index')">
                        {{ __('Murid') }}
                    </x-responsive-nav-link>

                    <x-responsive-nav-link
                        :href="route('admin.kelas')"
                        :active="request()->routeIs('admin.kelas')">
                        {{ __('Kelas') }}
                    </x-responsive-nav-link>

                    <x-responsive-nav-link
                        :href="route('admin.pembelajaran')"
                        :active="request()->routeIs('admin.pembelajaran')">
                        {{ __('Pembelajaran') }}
                    </x-responsive-nav-link>

                    <x-responsive-nav-link
                        :href="route('admin.kokurikuler')"
                        :active="request()->routeIs('admin.kokurikuler')">
                        {{ __('Kokurikuler') }}
                    </x-responsive-nav-link>

                    <x-responsive-nav-link
                        :href="route('rapor.index')"
                        :active="request()->routeIs('rapor.index')">
                        {{ __('Rapor') }}
                    </x-responsive-nav-link>

                @endif

                <x-responsive-nav-link
                    :href="route('admin.pengaturan')"
                    :active="request()->routeIs('admin.pengaturan')">
                    {{ __('Pengaturan') }}
                </x-responsive-nav-link>

            @elseif($role == 'tendik')

                <x-responsive-nav-link
                    :href="route('dashboard')"
                    :active="request()->routeIs('dashboard')">
                    {{ __('Dasbor') }}
                </x-responsive-nav-link>

                @if(!$isSetupMode && $canUseUjian)
                    <x-responsive-nav-link
                        :href="route('guru.atp.index')"
                        :active="request()->routeIs('guru.atp.*')">
                        {{ __('ATP') }}
                    </x-responsive-nav-link>
                @endif

                @if(!$isSetupMode)

                    <x-responsive-nav-link
                        :href="route('guru.index')"
                        :active="request()->routeIs('guru.index')">
                        {{ __('GTK') }}
                    </x-responsive-nav-link>

                    <x-responsive-nav-link
                        :href="route('siswa.index')"
                        :active="request()->routeIs('siswa.*') || request()->routeIs('murid.*')">
                        {{ __('Murid') }}
                    </x-responsive-nav-link>

                    {{-- V21R144: Ekskul hanya untuk Tendik pembina dengan penugasan sah. --}}
                    @if($isPembinaEkskul)
                        <x-responsive-nav-link
                            :href="route('guru.ekskul')"
                            :active="request()->routeIs('guru.ekskul*')">
                            {{ __('Ekstrakurikuler') }}
                        </x-responsive-nav-link>
                    @endif

                    @if(\Illuminate\Support\Facades\Route::has('tu.ujian.index'))
                        <div class="px-4 pt-4 pb-1 text-xs font-bold text-gray-400 uppercase tracking-wider">
                            Ujian
                        </div>

                        <x-responsive-nav-link
                            :href="route('tu.ujian.index')"
                            :active="request()->routeIs('tu.ujian.*')">
                            {{ __('Administrasi Ujian') }}
                        </x-responsive-nav-link>
                    @endif

                    <div class="px-4 pt-4 pb-1 text-xs font-bold text-gray-400 uppercase tracking-wider">
                        Administrasi Sekolah
                    </div>

                    <x-responsive-nav-link
                        :href="route('tu.profil.index')"
                        :active="request()->routeIs('tu.profil.*')">
                        {{ __('Profil') }}
                    </x-responsive-nav-link>

                    <x-responsive-nav-link
                        :href="route('tu.surat.index')"
                        :active="request()->routeIs('tu.surat.index')">
                        {{ __('Buat Surat') }}
                    </x-responsive-nav-link>

                    <x-responsive-nav-link
                        :href="route('tu.surat.riwayat')"
                        :active="request()->routeIs('tu.surat.riwayat') || request()->routeIs('tu.surat.cetakUlang')">
                        {{ __('Arsip Surat') }}
                    </x-responsive-nav-link>

                    <x-responsive-nav-link
                        :href="route('tu.kartu-pelajar.index')"
                        :active="request()->routeIs('tu.kartu-pelajar.*')">
                        {{ __('Kartu Pelajar') }}
                    </x-responsive-nav-link>

                @endif

                <x-responsive-nav-link
                    :href="route('guru.pengaturan')"
                    :active="request()->routeIs('guru.pengaturan')">
                    {{ __('Pengaturan') }}
                </x-responsive-nav-link>
            @elseif($role == 'siswa')

                <x-responsive-nav-link
                    :href="route('siswa.lms.index')"
                    :active="request()->routeIs('murid.lms.*')">
                    {{ __('Beranda LMS') }}
                </x-responsive-nav-link>

            @else

                <x-responsive-nav-link
                    :href="route('dashboard')"
                    :active="request()->routeIs('dashboard')">
                    {{ __('Dasbor') }}
                </x-responsive-nav-link>

                {{-- Perencanaan Guru juga tersedia pada menu responsif lama. --}}
                @if(!$isSetupMode && $canUseUjian && \Illuminate\Support\Facades\Route::has('guru.atp.index'))
                    <x-responsive-nav-link
                        :href="route('guru.atp.index')"
                        :active="request()->routeIs('guru.atp.*')">
                        {{ __('ATP') }}
                    </x-responsive-nav-link>
                @endif

                @if(!$isSetupMode && $canAccessTeacherMenus
                    && in_array($role, ['guru', 'wali_kelas', 'guru_bk', 'pengajar'], true)
                    && \Illuminate\Support\Facades\Route::has('guru.modul-ajar.index'))
                    <x-responsive-nav-link
                        :href="route('guru.modul-ajar.index')"
                        :active="request()->routeIs('guru.modul-ajar.*')">
                        {{ __('Modul Ajar') }}
                    </x-responsive-nav-link>
                @endif

                @if(!$isSetupMode)

                    @if($canAccessTeacherMenus)

                        <x-responsive-nav-link
                            :href="route('lms.courses.index')"
                            :active="request()->routeIs('lms.*')">
                            {{ __('LMS') }}
                        </x-responsive-nav-link>

                    @endif

                    @if($canUseUjian)

                        <div class="px-4 py-2 text-xs font-bold text-gray-400 uppercase tracking-wider">
                            Ujian
                        </div>

                        <x-responsive-nav-link
                            :href="route('guru.bankSoal')"
                            :active="request()->routeIs('guru.bankSoal')">
                            {{ __('Bank Soal') }}
                        </x-responsive-nav-link>

                        <x-responsive-nav-link
                            :href="route('guru.cetakSoal')"
                            :active="request()->routeIs('guru.cetakSoal')">
                            {{ __('Cetak Soal') }}
                        </x-responsive-nav-link>

                        <x-responsive-nav-link
                            :href="route('guru.cbt')"
                            :active="request()->routeIs('guru.cbt', 'guru.cbt.create', 'guru.cbt.soal*')">
                            {{ __('Manajemen Ujian') }}
                        </x-responsive-nav-link>

                        @if(\Illuminate\Support\Facades\Route::has('tu.ujian.index'))
                            <x-responsive-nav-link
                                :href="route('tu.ujian.index')"
                                :active="request()->routeIs('tu.ujian.*')">
                                {{ __('Administrasi Ujian') }}
                            </x-responsive-nav-link>
                        @endif

                        <x-responsive-nav-link
                            :href="route('guru.cbt.koreksi')"
                            :active="request()->routeIs('guru.cbt.koreksi*')">
                            {{ __('Koreksi CBT') }}
                        </x-responsive-nav-link>

                        <x-responsive-nav-link
                            :href="route('guru.ljk-auto.index')"
                            :active="request()->routeIs('guru.ljk.*', 'guru.ljk-auto.*')">
                            {{ __('Koreksi LJK') }}
                        </x-responsive-nav-link>

                    @endif

                    @if($canAccessTeacherMenus)

                        <x-responsive-nav-link
                            :href="route('guru.mengajar')"
                            :active="request()->routeIs('guru.mengajar')">
                            {{ __('Buku Nilai') }}
                        </x-responsive-nav-link>

                    @endif

                    @if($isWaliKelas)

                        <x-responsive-nav-link
                            :href="route('guru.waliKelas')"
                            :active="request()->routeIs('guru.waliKelas')">
                            {{ __('Wali Kelas') }}
                        </x-responsive-nav-link>

                    @endif

                    @if($isPembinaEkskul)

                        <x-responsive-nav-link
                            :href="route('guru.ekskul')"
                            :active="request()->routeIs('guru.ekskul')">
                            {{ __('Ekstrakurikuler') }}
                        </x-responsive-nav-link>

                    @endif

                    @if($isKoorKokurikuler)

                        <x-responsive-nav-link
                            :href="route('guru.kokurikuler')"
                            :active="request()->routeIs('guru.kokurikuler')">
                            {{ __('Kokurikuler') }}
                        </x-responsive-nav-link>

                    @endif

                    @if($isGuruBK || $role === 'guru_bk')

                        <x-responsive-nav-link
                            :href="route('guru.bk')"
                            :active="request()->routeIs('guru.bk')">
                            {{ __('Bimbingan Konseling') }}
                        </x-responsive-nav-link>

                    @endif

                    @if(
                        in_array($role, ['admin', 'kepala_sekolah'])
                        ||
                        $isWaliKelas
                    )

                        <x-responsive-nav-link
                            :href="route('rapor.index')"
                            :active="request()->routeIs('rapor.index')">
                            {{ __('Rapor') }}
                        </x-responsive-nav-link>

                    @endif

                @endif

                <x-responsive-nav-link
                    :href="route('guru.pengaturan')"
                    :active="request()->routeIs('guru.pengaturan')">
                    {{ __('Pengaturan') }}
                </x-responsive-nav-link>

            @endif

            <x-responsive-nav-link
                :href="route('about.index')"
                :active="request()->routeIs('about.index')">
                {{ __('Donasi') }}
            </x-responsive-nav-link>

        </div>

        <div class="pt-4 pb-1 border-t border-gray-200">

            <div class="px-4">

                <div class="font-medium text-base text-gray-800">
                    {{ auth()->user()->name }}
                </div>

                <div class="font-medium text-sm text-gray-500">
                    {{ auth()->user()->email }}
                </div>

            </div>

                        <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit') . '#ubah-profil'">
                    {{ __('Ubah Profil') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('profile.edit') . '#ganti-password'">
                    {{ __('Ganti Password') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('pilih.semester')">
                    {{ __('Ganti Periode') }}
                </x-responsive-nav-link>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link
                        :href="route('logout')"
                        onclick="event.preventDefault(); this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </form>
            </div>

        </div>

    </div>

</nav>

{{-- V21R87: modal profil murid sama pada seluruh halaman HTML terautentikasi. --}}
@include('layouts.partials.student-profile-overlay')
