@extends('layouts.app')

@section('content')

<div class="container-fluid py-4">

<div class="max-w-7xl mx-auto">

{{-- =========================================================
HEADER KELAS
========================================================= --}}

<div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden mb-6">

<div class="bg-gradient-to-r from-indigo-50 to-white px-6 py-6 border-b border-indigo-100">


<div class="flex items-start justify-between gap-5 flex-wrap">

    <div class="min-w-0 flex-1">

        <div class="flex items-center gap-2 flex-wrap mb-3">

            <span class="inline-flex items-center gap-1.5 bg-indigo-600 text-white px-3 py-1.5 rounded-full text-xs font-bold shadow-sm">
                📚 Kelas LMS
            </span>

            @if($course->is_published)

                <span class="inline-flex items-center gap-1.5 bg-green-50 text-green-700 border border-green-200 px-3 py-1.5 rounded-full text-xs font-bold">
                    ✓ Dipublikasikan
                </span>

            @else

                <span class="inline-flex items-center gap-1.5 bg-gray-100 text-gray-600 border border-gray-200 px-3 py-1.5 rounded-full text-xs font-bold">
                    ◷ Draft
                </span>

            @endif

        </div>

        <h2 class="text-2xl md:text-3xl font-bold text-indigo-950 leading-tight break-words">
            {{ $course->name }}
        </h2>

        <div class="flex items-center flex-wrap gap-2 mt-4">

            @if($course->subject)

                <span class="inline-flex items-center gap-2 bg-white text-gray-700 border border-gray-200 px-3 py-2 rounded-lg text-sm font-semibold shadow-sm">
                    📚
                    {{ $course->subject->name }}
                </span>

            @endif

            @if($course->homeroom)

                <span class="inline-flex items-center gap-2 bg-white text-indigo-700 border border-indigo-200 px-3 py-2 rounded-lg text-sm font-bold shadow-sm">
                    👥
                    {{ $course->homeroom->name }}
                </span>

            @endif

            @if($course->academic_year)

                <span class="inline-flex items-center gap-2 bg-white text-gray-600 border border-gray-200 px-3 py-2 rounded-lg text-sm">
                    📅
                    {{ $course->academic_year }}
                </span>

            @endif

            @if($course->semester)

                <span class="inline-flex items-center gap-2 bg-white text-gray-600 border border-gray-200 px-3 py-2 rounded-lg text-sm">
                    🗓️
                    Semester {{ $course->semester }}
                </span>

            @endif

        </div>

    </div>


    {{-- AKSI KELAS --}}

    <div class="flex items-center gap-2 flex-wrap shrink-0">

        {{-- =============================================
             PUBLIKASI CEPAT
             ============================================= --}}

        @if($course->is_published)

            <form method="POST"
                  action="{{ route('lms.courses.unpublish', $course) }}"
                  onsubmit="return confirm('Batalkan publikasi kelas ini? Kelas tidak akan tampil kepada murid.')">

                @csrf

                <button type="submit"
                        class="inline-flex items-center justify-center gap-2 bg-yellow-50 text-yellow-700 border border-yellow-200 px-4 py-3 min-h-[48px] rounded-lg hover:bg-yellow-100 transition text-sm font-bold"
                        title="Batalkan publikasi">

                    ◷
                    Batalkan Publikasi

                </button>

            </form>

        @else

            <form method="POST"
                  action="{{ route('lms.courses.publish', $course) }}"
                  onsubmit="return confirm('Publikasikan kelas ini kepada murid?')">

                @csrf

                <button type="submit"
                        class="inline-flex items-center justify-center gap-2 bg-green-600 text-white px-4 py-3 min-h-[48px] rounded-lg hover:bg-green-700 transition text-sm font-bold shadow-sm"
                        title="Publikasikan kelas">

                    🚀
                    Publikasikan

                </button>

            </form>

        @endif


        <a href="{{ route('lms.courses.edit', $course) }}"
           class="inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-4 py-3 min-h-[48px] rounded-lg hover:bg-indigo-700 transition text-sm font-bold shadow-sm">

            ✏️
            Edit Kelas

        </a>

        <a href="{{ route('lms.courses.index') }}"
           class="inline-flex items-center justify-center gap-2 bg-white text-gray-700 border border-gray-300 px-4 py-3 min-h-[48px] rounded-lg hover:bg-gray-50 transition text-sm font-bold shadow-sm">

            ←
            Kembali

        </a>

    </div>

</div>


</div>

@if($course->description)


<div class="px-6 py-4 bg-white">

    <p class="text-sm text-gray-600 leading-relaxed">
        {{ $course->description }}
    </p>

</div>


@endif

</div>

{{-- =========================================================
PESAN
========================================================= --}}

@if(session('success'))

<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-6 shadow-sm">



<span class="font-bold mr-1">
    ✓
</span>

{{ session('success') }}


</div>

@endif

{{-- BANNER WARNING — status publikasi / sync queued --}}

@if(session('warning'))

<div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-xl mb-6 shadow-sm">


<span class="font-bold mr-1">
    ⚠️
</span>

{{ session('warning') }}


</div>

@endif

@if(session('error'))

<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-6 shadow-sm">


<span class="font-bold mr-1">
    ⚠️
</span>

{{ session('error') }}


</div>

@endif

{{-- =========================================================
STATISTIK
========================================================= --}}

@php

 $materialCount = $course->topics->sum(
fn ($topic) => $topic->materials->count()
);

 $assignmentCount = $course->topics->sum(
fn ($topic) => $topic->assignments->count()
);

 $cbtCount = $course->topics->sum(
fn ($topic) => $topic->assignments
->where('assignment_type', 'cbt')
->count()
);

@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

{{-- STATUS --}}

<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">


<div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-3">
    Status Kelas
</div>

@if($course->is_published)

    <div class="flex items-center gap-2">

        <span class="w-3 h-3 rounded-full bg-green-500"></span>

        <span class="text-lg font-bold text-green-700">
            Dipublikasikan
        </span>

    </div>

@else

    <div class="flex items-center gap-2">

        <span class="w-3 h-3 rounded-full bg-gray-400"></span>

        <span class="text-lg font-bold text-gray-600">
            Draft
        </span>

    </div>

@endif


</div>

{{-- TOPIK --}}

<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">


<div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-3">
    Topik Pembelajaran
</div>

<div class="flex items-end gap-2">

    <span class="text-3xl font-bold text-indigo-600">
        {{ $course->topics->count() }}
    </span>

    <span class="text-sm font-semibold text-gray-500 pb-1">
        topik
    </span>

</div>


</div>

{{-- MATERI --}}

<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">


<div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-3">
    Materi Pembelajaran
</div>

<div class="flex items-end gap-2">

    <span class="text-3xl font-bold text-blue-600">
        {{ $materialCount }}
    </span>

    <span class="text-sm font-semibold text-gray-500 pb-1">
        materi
    </span>

</div>


</div>

{{-- AKTIVITAS --}}

<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">


<div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-3">
    Aktivitas
</div>

<div class="flex items-end gap-2 flex-wrap">

    <span class="text-3xl font-bold text-purple-600">
        {{ $assignmentCount }}
    </span>

    <span class="text-sm font-semibold text-gray-500 pb-1">
        tugas
    </span>

    @if($cbtCount > 0)

        <span class="text-gray-300 pb-1">
            •
        </span>

        <span class="text-sm font-bold text-orange-600 pb-1">
            {{ $cbtCount }} CBT
        </span>

    @endif

</div>


</div>

</div>

{{-- =========================================================
JUDUL MATERI & AKTIVITAS
========================================================= --}}

<div class="bg-white rounded-xl border border-gray-200 shadow-sm px-6 py-5 mb-5">

<div class="flex items-center gap-3">


<div class="w-11 h-11 rounded-xl bg-indigo-50 flex items-center justify-center text-xl">
    📚
</div>

<div>

    <h3 class="text-lg font-bold text-gray-800">
        Materi Pembelajaran
    </h3>

    <p class="text-sm text-gray-500 mt-0.5">
        Kelola topik, materi, tugas, dan aktivitas CBT kelas ini.
    </p>

</div>


</div>

</div>

{{-- =========================================================
DAFTAR TOPIK
========================================================= --}}

@forelse($course->topics as $topic)

<div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden mb-6">


{{-- HEADER TOPIK --}}

<div class="bg-indigo-50 px-6 py-5 border-b border-indigo-100">

    <div class="flex items-start justify-between gap-4 flex-wrap">

        <div class="flex-1 min-w-0">

            <div class="flex items-center gap-2 flex-wrap">

                <span class="bg-indigo-600 text-white px-3 py-1.5 rounded-full text-xs font-bold">
                    Topik {{ $loop->iteration }}
                </span>

                <h4 class="text-lg font-bold text-indigo-950 break-words">
                    {{ $topic->title }}
                </h4>

            </div>

            @if($topic->description)

                <p class="text-sm text-gray-600 mt-2">
                    {{ $topic->description }}
                </p>

            @endif

        </div>


{{-- AKSI TOPIK --}}

<div class="flex items-center gap-2 shrink-0">

    {{-- STATUS TOPIK --}}

    @if($topic->is_published)

        <span class="inline-flex items-center justify-center gap-1.5 bg-green-50 text-green-700 border border-green-200 px-3 py-3 min-h-[48px] rounded-lg text-sm font-bold">
            ✓ Dipublikasikan
        </span>

        <form method="POST"
              action="{{ route('lms.topics.unpublish', $topic) }}"
              onsubmit="return confirm('Batalkan publikasi topik ini? Materi dan aktivitas di dalamnya tidak akan tampil kepada murid.')">

            @csrf

            <button type="submit"
                    class="inline-flex items-center justify-center gap-2 bg-yellow-50 text-yellow-700 border border-yellow-200 px-4 py-3 min-h-[48px] rounded-lg hover:bg-yellow-100 transition text-sm font-bold"
                    title="Batalkan publikasi">

                ◷
                Batalkan Publikasi

            </button>

        </form>

    @else

        <span class="inline-flex items-center justify-center gap-1.5 bg-gray-100 text-gray-600 border border-gray-200 px-3 py-3 min-h-[48px] rounded-lg text-sm font-bold">
            ◷ Draft
        </span>

        <form method="POST"
              action="{{ route('lms.topics.publish', $topic) }}"
              onsubmit="return confirm('Publikasikan topik ini kepada murid?')">

            @csrf

            <button type="submit"
                    class="inline-flex items-center justify-center gap-2 bg-green-600 text-white px-4 py-3 min-h-[48px] rounded-lg hover:bg-green-700 transition text-sm font-bold shadow-sm"
                    title="Publikasikan topik">

                ✓
                Publikasikan

            </button>

        </form>

    @endif


    {{-- EDIT TOPIK --}}

    <form method="POST"
          action="{{ route('lms.topics.update', $topic) }}"
          class="flex items-center gap-2">

        @csrf
        @method('PUT')

        <input type="text"
               name="title"
               value="{{ $topic->title }}"
               class="border border-gray-300 rounded-lg text-sm px-3 py-3 h-[48px] w-52 bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none">

        <input type="hidden"
               name="description"
               value="{{ $topic->description }}">

        <button type="submit"
                class="inline-flex items-center justify-center bg-white text-indigo-600 border border-indigo-200 px-4 py-3 min-h-[48px] min-w-[48px] rounded-lg hover:bg-indigo-50 transition"
                title="Simpan perubahan">

            ✓

        </button>

    </form>


    {{-- HAPUS TOPIK --}}

    <form method="POST"
          action="{{ route('lms.topics.destroy', $topic) }}"
          onsubmit="return confirm('Hapus topik ini beserta materi dan tugas di dalamnya?')">

        @csrf
        @method('DELETE')

        <button type="submit"
                class="inline-flex items-center justify-center bg-red-50 text-red-600 border border-red-200 px-4 py-3 min-h-[48px] min-w-[48px] rounded-lg hover:bg-red-100 transition"
                title="Hapus topik">

            🗑️

        </button>

    </form>

</div>


{{-- BODY TOPIK --}}

<div class="p-6">


    {{-- =====================================================
         MATERI
         ===================================================== --}}

    <div>

        <div class="flex items-center justify-between mb-4">

            <div>

                <h5 class="font-bold text-gray-800">
                    📖 Materi
                </h5>

                <p class="text-xs text-gray-500 mt-0.5">
                    Materi pembelajaran untuk topik ini.
                </p>

            </div>

        </div>


        @forelse($topic->materials as $material)

            <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 mb-3">

                <div class="flex items-start justify-between gap-3">

                    <div class="flex-1 min-w-0">

                        <h6 class="font-bold text-gray-800">
                            {{ $material->title }}
                        </h6>

                        <span class="inline-block mt-1 bg-indigo-50 text-indigo-600 border border-indigo-200 px-2 py-0.5 rounded text-xs font-bold">
                            {{ strtoupper($material->type) }}
                        </span>

                    </div>

@if(Route::has('lms.materials.edit'))

{{-- EDIT MATERI --}}

<a href="{{ route('lms.materials.edit', $material) }}"
   class="inline-flex items-center justify-center bg-indigo-50 text-indigo-600 border border-indigo-200 px-4 py-3 min-h-[48px] min-w-[48px] rounded-lg hover:bg-indigo-100 transition"
   title="Edit materi">

    ✏️

</a>

@endif

@if(Route::has('lms.materials.destroy'))

{{-- HAPUS MATERI --}}

<form method="POST"
      action="{{ route('lms.materials.destroy', $material) }}"
      onsubmit="return confirm('Hapus materi ini?')">

    @csrf
    @method('DELETE')

    <button type="submit"
            class="inline-flex items-center justify-center bg-red-50 text-red-600 border border-red-200 px-4 py-3 min-h-[48px] min-w-[48px] rounded-lg hover:bg-red-100 transition"
            title="Hapus materi">

        🗑️

    </button>

</form>

@endif

                </div>


                {{-- ISI MATERI --}}

                <div class="mt-3">

                    @if($material->type === 'text')

                        @if($material->content)

                            <div class="bg-white border border-gray-200 rounded-lg p-4 text-sm text-gray-700 leading-relaxed">
                                {!! nl2br(e($material->content)) !!}
                            </div>

                        @else

                            <div class="text-sm text-gray-400">
                                Tidak ada isi materi.
                            </div>

                        @endif


                    @elseif($material->type === 'pdf')

                        @if($material->file_path)

                            <div class="aspect-video w-full">

                                <iframe
                                    src="{{ app(\App\Services\LocalMediaService::class)->url($material->file_path) }}"
                                    class="w-full h-full rounded-lg border"
                                    title="{{ $material->title }}">
                                </iframe>

                            </div>

                            <div class="mt-2">

                                <a href="{{ app(\App\Services\LocalMediaService::class)->url($material->file_path) }}"
                                   target="_blank"
                                   class="inline-flex items-center justify-center gap-2 bg-indigo-50 text-indigo-700 border border-indigo-200 px-4 py-3 min-h-[48px] rounded-lg hover:bg-indigo-100 transition text-sm font-semibold">

                                    ↗ Buka PDF

                                </a>

                            </div>

                        @endif


                    @elseif($material->type === 'image')

                        @if($material->file_path)

                            <div class="text-center bg-white border border-gray-200 rounded-lg p-3">

                                <img src="{{ app(\App\Services\LocalMediaService::class)->url($material->file_path) }}"
                                     alt="{{ $material->title }}"
                                     class="max-w-full mx-auto rounded-lg"
                                     style="max-height:500px;">

                            </div>

                        @endif


                    @elseif($material->type === 'video')

                        @if($material->file_path)

                            <video controls
                                   class="w-full rounded-lg"
                                   style="max-height:500px;">

                                <source
                                    src="{{ app(\App\Services\LocalMediaService::class)->url($material->file_path) }}"
                                    type="{{ $material->mime_type }}">

                                Browser Anda tidak mendukung video.

                            </video>

                        @elseif($material->external_url)

                            <div class="aspect-video">

                                <iframe
                                    src="{{ $material->external_url }}"
                                    class="w-full h-full rounded-lg border"
                                    allowfullscreen>
                                </iframe>

                            </div>

                        @endif


                    @elseif($material->type === 'audio')

                        @if($material->file_path)

                            <audio controls class="w-full">

                                <source
                                    src="{{ app(\App\Services\LocalMediaService::class)->url($material->file_path) }}"
                                    type="{{ $material->mime_type }}">

                            </audio>

                        @endif


                    @elseif($material->type === 'link')

                        @if($material->external_url)

                            <a href="{{ $material->external_url }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="inline-flex items-center justify-center gap-2 bg-indigo-50 text-indigo-700 border border-indigo-200 px-4 py-3 min-h-[48px] rounded-lg hover:bg-indigo-100 transition text-sm font-bold">

                                ↗ Buka Materi

                            </a>

                        @endif


                    @else

                        @if($material->file_path)

                            <a href="{{ app(\App\Services\LocalMediaService::class)->url($material->file_path) }}"
                               target="_blank"
                               class="inline-flex items-center justify-center gap-2 bg-indigo-50 text-indigo-700 border border-indigo-200 px-4 py-3 min-h-[48px] rounded-lg hover:bg-indigo-100 transition text-sm font-bold">

                                📎
                                {{ $material->file_name }}

                            </a>

                        @endif

                    @endif

                </div>

            </div>

        @empty

            <div class="bg-gray-50 border border-dashed border-gray-300 rounded-xl text-center py-6">

                <div class="text-3xl mb-2">
                    📂
                </div>

                <p class="text-sm text-gray-400">
                    Belum ada materi pada topik ini.
                </p>

            </div>

        @endforelse


        {{-- =================================================
             TAMBAH MATERI
             ================================================= --}}

        <div class="mt-5 pt-5 border-t border-gray-200">

            <h6 class="font-bold text-gray-800 mb-4">
                ➕ Tambah Materi
            </h6>

            <form method="POST"
                  action="{{ route('lms.materials.store', $topic) }}"
                  enctype="multipart/form-data">

                @csrf

                @include('guru.atp.selector-lms', ['topic' => $topic, 'selectedAtpId' => old('atp_item_id')])


                {{-- BARIS FIELD --}}

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">


                    {{-- JUDUL --}}

                    <div class="w-full min-w-0">

                        <label class="block text-sm font-bold text-gray-700 mb-1.5">
                            Judul Materi
                        </label>

                        <input type="text"
                               name="title"
                               class="block w-full min-w-0 border border-gray-300 rounded-lg text-sm px-3 py-2.5 min-h-[48px] focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none"
                               placeholder="Contoh: Materi Pertemuan 1"
                               required>

                    </div>


                    {{-- JENIS --}}

                    <div class="w-full min-w-0">

                        <label class="block text-sm font-bold text-gray-700 mb-1.5">
                            Jenis
                        </label>

                        <select name="type"
                                class="block w-full min-w-0 border border-gray-300 rounded-lg text-sm px-3 py-2.5 min-h-[48px] focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none"
                                required>

                            <option value="text">
                                Teks
                            </option>

                            <option value="pdf">
                                PDF
                            </option>

                            <option value="image">
                                Gambar
                            </option>

                            <option value="video">
                                Video
                            </option>

                            <option value="audio">
                                Audio
                            </option>

                            <option value="file">
                                File
                            </option>

                            <option value="link">
                                Link
                            </option>

                        </select>

                    </div>


                    {{-- FILE --}}

                    <div class="w-full min-w-0">

                        <label class="block text-sm font-bold text-gray-700 mb-1.5">
                            File
                        </label>

                        <input type="file"
                               name="file"
                               class="block w-full min-w-0 border border-gray-300 rounded-lg text-sm px-3 py-2.5 min-h-[48px] bg-white">

                    </div>


                    {{-- LINK EKSTERNAL --}}

                    <div class="w-full min-w-0">

                        <label class="block text-sm font-bold text-gray-700 mb-1.5">
                            Link Eksternal
                        </label>

                        <input type="url"
                               name="external_url"
                               class="block w-full min-w-0 border border-gray-300 rounded-lg text-sm px-3 py-2.5 min-h-[48px] focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none"
                               placeholder="https://...">

                    </div>

                </div>

                {{-- =================================================
                     ISI MATERI - FULL WIDTH
                     ================================================= --}}

                <div class="w-full mt-4">

                    <label class="block text-sm font-bold text-gray-700 mb-1.5">
                        Isi Materi
                    </label>

                    <textarea name="content"
                              rows="10"
                              class="block w-full min-w-0 max-w-none border border-gray-300 rounded-lg text-sm px-3 py-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none resize-y"
                              placeholder="Isi materi atau keterangan tambahan..."></textarea>

                </div>


                {{-- =================================================
                     TOMBOL TAMBAH MATERI
                     ================================================= --}}

                <div class="mt-3 flex justify-end">

                    <button type="submit"
                            class="inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-5 py-3 min-h-[48px] rounded-lg hover:bg-indigo-700 transition text-sm font-bold shadow-sm whitespace-nowrap">

                        ➕
                        Tambah Materi

                    </button>

                </div>

            </form>

        </div>

    </div>


    {{-- PEMBATAS --}}

    <div class="border-t border-gray-200 my-7"></div>


    {{-- =====================================================
         TUGAS / CBT
         ===================================================== --}}

    <div>

        {{-- HEADER AKTIVITAS --}}

        <div class="mb-4">

            <h5 class="font-bold text-gray-800">
                📋 Aktivitas Pembelajaran
            </h5>

            <p class="text-xs text-gray-500 mt-0.5">
                Tugas dan aktivitas CBT untuk topik ini.
            </p>

        </div>


        @forelse($topic->assignments as $assignment)

            @if($assignment->assignment_type === 'cbt')

                {{-- CBT --}}

                <div class="bg-orange-50 border border-orange-200 rounded-xl p-4 mb-3">

                    <div class="flex items-start justify-between gap-4">

                        <div class="flex-1 min-w-0">

                            <div class="flex items-center gap-2 flex-wrap">

                                <span class="inline-flex items-center gap-1.5 bg-orange-500 text-white px-2.5 py-1 rounded-full text-xs font-bold">
                                    🖥️ CBT
                                </span>

                                @if($assignment->is_published)

                                    <span class="bg-green-50 text-green-700 border border-green-200 px-2 py-1 rounded-full text-xs font-bold">
                                        ✓ Dipublikasikan
                                    </span>

                                @else

                                    <span class="bg-gray-100 text-gray-600 border border-gray-200 px-2 py-1 rounded-full text-xs font-bold">
                                        Draft
                                    </span>

                                @endif

                            </div>

                            <h6 class="font-bold text-gray-900 mt-2">
                                {{ $assignment->title }}
                            </h6>

                            @if($assignment->instructions)

                                <p class="text-sm text-gray-600 mt-1">
                                    {{ \Illuminate\Support\Str::limit($assignment->instructions, 180) }}
                                </p>

                            @endif

                            <div class="flex items-center flex-wrap gap-x-4 gap-y-2 text-xs text-gray-600 mt-3">

                                <span class="font-semibold">
                                    🏆 Nilai maksimal:
                                    {{ rtrim(rtrim(number_format($assignment->max_score, 2, ',', '.'), '0'), ',') }}
                                </span>

                                @if($assignment->exam)

                                    <span class="font-semibold">
                                        📝 Ujian:
                                        {{ $assignment->exam->judul_ujian }}
                                    </span>

                                    @if($assignment->exam->durasi_menit)

                                        <span class="font-semibold">
                                            ⏱️
                                            {{ $assignment->exam->durasi_menit }} menit
                                        </span>

                                    @endif

                                @else

                                    <span class="text-orange-700 font-semibold">
                                        ⚠️ Belum terhubung ke ujian
                                    </span>

                                @endif

                                @if($assignment->due_at)

                                    <span>
                                        ⏰ Batas:
                                        {{ \Carbon\Carbon::parse($assignment->due_at)->format('d/m/Y H:i') }}
                                    </span>

                                @endif

                            </div>

                        </div>


                        <div class="flex items-center gap-2 shrink-0">

                            <a href="{{ route('lms.assignments.show', $assignment) }}"
                               class="inline-flex items-center justify-center bg-white text-gray-600 border border-gray-300 px-4 py-3 min-h-[48px] min-w-[48px] rounded-lg hover:bg-gray-50 transition"
                               title="Lihat CBT">

                                👁️

                            </a>

                            <a href="{{ route('lms.assignments.edit', $assignment) }}"
                               class="inline-flex items-center justify-center bg-white text-indigo-600 border border-indigo-200 px-4 py-3 min-h-[48px] min-w-[48px] rounded-lg hover:bg-indigo-50 transition"
                               title="Edit CBT">

                                ✏️

                            </a>

                            <form method="POST"
                                  action="{{ route('lms.assignments.destroy', $assignment) }}"
                                  onsubmit="return confirm('Hapus aktivitas CBT ini?')">

                                @csrf
                                @method('DELETE')

                                <button type="submit"
                                        class="inline-flex items-center justify-center bg-white text-red-600 border border-red-200 px-4 py-3 min-h-[48px] min-w-[48px] rounded-lg hover:bg-red-50 transition"
                                        title="Hapus CBT">

                                    🗑️

                                </button>

                            </form>

                        </div>

                    </div>

                </div>

            @else

                {{-- TUGAS BIASA --}}

                <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 mb-3">

                    <div class="flex items-start justify-between gap-3">

                        <div class="flex-1 min-w-0">

                            <div class="flex items-center gap-2 flex-wrap">

                                <span class="inline-flex items-center gap-1.5 bg-indigo-50 text-indigo-700 border border-indigo-200 px-2.5 py-1 rounded-full text-xs font-bold">
                                    📋 Tugas
                                </span>

                                @if($assignment->is_published)

                                    <span class="bg-green-50 text-green-700 border border-green-200 px-2 py-1 rounded-full text-xs font-bold">
                                        ✓ Dipublikasikan
                                    </span>

                                @else

                                    <span class="bg-gray-100 text-gray-600 border border-gray-200 px-2 py-1 rounded-full text-xs font-bold">
                                        Draft
                                    </span>

                                @endif

                            </div>

                            <h6 class="font-bold text-gray-800 mt-2">
                                {{ $assignment->title }}
                            </h6>

                            @if($assignment->instructions)

                                <p class="text-sm text-gray-500 mt-1">
                                    {{ \Illuminate\Support\Str::limit($assignment->instructions, 180) }}
                                </p>

                            @endif

                            <div class="flex items-center flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 mt-3">

                                <span>
                                    🏆 Nilai maksimal:
                                    {{ rtrim(rtrim(number_format($assignment->max_score, 2, ',', '.'), '0'), ',') }}
                                </span>

                                <span>

                                    📎

                                    @switch($assignment->submission_type)

                                        @case('text')
                                            Teks
                                            @break

                                        @case('file')
                                            File
                                            @break

                                        @case('text_file')
                                            Teks / File
                                            @break

                                        @default
                                            {{ $assignment->submission_type }}

                                    @endswitch

                                </span>

                                @if($assignment->due_at)

                                    <span>
                                        ⏰ Batas:
                                        {{ \Carbon\Carbon::parse($assignment->due_at)->format('d/m/Y H:i') }}
                                    </span>

                                @endif

                            </div>

                        </div>


                        <div class="flex items-center gap-2 shrink-0">

                            <a href="{{ route('lms.assignments.show', $assignment) }}"
                               class="inline-flex items-center justify-center bg-white text-gray-600 border border-gray-300 px-4 py-3 min-h-[48px] min-w-[48px] rounded-lg hover:bg-gray-50 transition"
                               title="Lihat tugas">

                                👁️

                            </a>

                            <a href="{{ route('lms.assignments.edit', $assignment) }}"
                               class="inline-flex items-center justify-center bg-indigo-50 text-indigo-600 border border-indigo-200 px-4 py-3 min-h-[48px] min-w-[48px] rounded-lg hover:bg-indigo-100 transition"
                               title="Edit tugas">

                                ✏️

                            </a>

                            <form method="POST"
                                  action="{{ route('lms.assignments.destroy', $assignment) }}"
                                  onsubmit="return confirm('Hapus tugas ini?')">

                                @csrf
                                @method('DELETE')

                                <button type="submit"
                                        class="inline-flex items-center justify-center bg-red-50 text-red-600 border border-red-200 px-4 py-3 min-h-[48px] min-w-[48px] rounded-lg hover:bg-red-100 transition"
                                        title="Hapus tugas">

                                    🗑️

                                </button>

                            </form>

                        </div>

                    </div>

                </div>

            @endif

        @empty

            {{-- BELUM ADA AKTIVITAS --}}

            <div class="bg-gray-50 border border-dashed border-gray-300 rounded-xl text-center py-7">

                <div class="text-3xl mb-2">
                    📋
                </div>

                <p class="text-sm text-gray-400">
                    Belum ada aktivitas pada topik ini.
                </p>

                {{-- TOMBOL DI BAWAH PESAN --}}

                <div class="mt-4 flex justify-end">

                    <a href="{{ route('lms.assignments.create', $topic) }}"
                       class="inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-5 py-3 min-h-[48px] rounded-lg hover:bg-indigo-700 transition text-sm font-bold shadow-sm">
                        ➕
                        Tambah Aktivitas

                    </a>

                </div>

            </div>


        @endforelse

    </div>

</div>


</div>

@empty

{{-- =========================================================
BELUM ADA TOPIK
========================================================= --}}

<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-10 text-center mb-6">


<div class="text-5xl mb-4">
    📚
</div>

<h4 class="text-lg font-bold text-gray-800 mb-2">
    Belum ada topik
</h4>

<p class="text-sm text-gray-500">
    Tambahkan topik pertama untuk mulai membuat materi dan aktivitas pembelajaran.
</p>


</div>

@endforelse

{{-- =========================================================
TAMBAH TOPIK
========================================================= --}}

<div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden mt-6">

<div class="bg-indigo-50 px-6 py-5 border-b border-indigo-100">


<div class="flex items-center gap-3">

    <div class="w-10 h-10 rounded-lg bg-white border border-indigo-100 flex items-center justify-center">
        ➕
    </div>

    <div>

        <h4 class="font-bold text-indigo-950">
            Tambah Topik Baru
        </h4>

        <p class="text-xs text-indigo-500 mt-0.5">
            Buat topik pembelajaran baru untuk kelas ini.
        </p>

    </div>

</div>


</div>

<div class="p-6">


<form method="POST"
      action="{{ route('lms.topics.store', $course) }}">

    @csrf

    <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">

        {{-- NAMA TOPIK --}}

        <div class="md:col-span-5">

            <label class="block text-sm font-bold text-gray-700 mb-1.5">

                Nama Topik

                <span class="text-red-500">*</span>

            </label>

            <input type="text"
                   name="title"
                   class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2.5 min-h-[48px] focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none"
                   placeholder="Contoh: Pertemuan 1 - Pengenalan"
                   required>

        </div>


        {{-- DESKRIPSI --}}

        <div class="md:col-span-5">

            <label class="block text-sm font-bold text-gray-700 mb-1.5">
                Deskripsi
            </label>

            <input type="text"
                   name="description"
                   class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2.5 min-h-[48px] focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none"
                   placeholder="Deskripsi singkat topik">

        </div>


        {{-- TOMBOL DI SEBELAH KANAN --}}

        <div class="md:col-span-2 flex items-end justify-end">

            <button type="submit"
                    class="inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-5 py-3 min-h-[48px] rounded-lg hover:bg-indigo-700 transition text-sm font-bold shadow-sm whitespace-nowrap">

                ➕
                Tambah Topik

            </button>

        </div>

    </div>

</form>


</div>

</div>

</div>

</div>

@endsection