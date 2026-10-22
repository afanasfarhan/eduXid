@extends('layouts.app')

@section('content')

<div class="container-fluid py-4">

<div class="max-w-5xl mx-auto">

{{-- =========================================================
     HEADER
========================================================== --}}

<div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden mb-6">

    <div class="bg-gradient-to-r from-indigo-50 to-white px-6 py-6 border-b border-indigo-100">

        <div class="flex items-center justify-between gap-4 flex-wrap">

            <div class="min-w-0">

                <div class="flex items-center gap-2 mb-2">

                    <span class="inline-flex items-center gap-1.5 bg-indigo-600 text-white px-3 py-1.5 rounded-full text-xs font-bold">
                        📚 Materi LMS
                    </span>

                    @if($material->is_published)

                        <span class="inline-flex items-center gap-1.5 bg-green-50 text-green-700 border border-green-200 px-3 py-1.5 rounded-full text-xs font-bold">
                            ✓ Dipublikasikan
                        </span>

                    @else

                        <span class="inline-flex items-center gap-1.5 bg-gray-100 text-gray-600 border border-gray-200 px-3 py-1.5 rounded-full text-xs font-bold">
                            ◷ Draft
                        </span>

                    @endif

                </div>

                <h2 class="text-2xl md:text-3xl font-bold text-indigo-950 leading-tight">
                    Edit Materi
                </h2>

                <p class="text-sm text-gray-500 mt-2">
                    Perbarui materi pembelajaran tanpa mengubah topik tempat materi ini berada.
                </p>

            </div>

            <a href="{{ route('lms.courses.show', $material->topic->course) }}"
               class="inline-flex items-center justify-center gap-2 bg-white text-gray-700 border border-gray-300 px-4 py-3 min-h-[48px] rounded-lg hover:bg-gray-50 transition text-sm font-bold shadow-sm">

                ← Kembali

            </a>

        </div>

    </div>

</div>


{{-- =========================================================
     PESAN ERROR / SUCCESS / WARNING
========================================================== --}}

@if($errors->any())

    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-4 rounded-xl mb-6 shadow-sm">

        <div class="font-bold mb-2">
            ⚠️ Periksa kembali data materi.
        </div>

        <ul class="list-disc list-inside text-sm space-y-1">

            @foreach($errors->all() as $error)

                <li>{{ $error }}</li>

            @endforeach

        </ul>

    </div>

@endif


@if(session('success'))

    <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-4 rounded-xl mb-6 shadow-sm">

        <div class="font-bold">
            ✓ {{ session('success') }}
        </div>

    </div>

@endif


@if(session('warning'))

    <div class="bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-4 rounded-xl mb-6 shadow-sm">

        <div class="font-bold">
            ⚠️ {{ session('warning') }}
        </div>

    </div>

@endif


{{-- =========================================================
     INFORMASI TOPIK
========================================================== --}}

<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 mb-6">

    <div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-2">
        Topik
    </div>

    <div class="flex items-center gap-3">

        <div class="w-10 h-10 rounded-lg bg-indigo-50 flex items-center justify-center">
            📖
        </div>

        <div>

            <div class="font-bold text-gray-800">
                {{ $material->topic->title ?? 'Topik' }}
            </div>

            @if($material->topic->course)

                <div class="text-xs text-gray-500 mt-0.5">
                    {{ $material->topic->course->name }}
                </div>

            @endif

        </div>

    </div>

</div>


{{-- =========================================================
     FORM EDIT
========================================================== --}}

<div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">

    <div class="bg-indigo-50 px-6 py-5 border-b border-indigo-100">

        <h3 class="text-lg font-bold text-indigo-950">
            ✏️ Informasi Materi
        </h3>

        <p class="text-sm text-indigo-600 mt-1">
            Ubah judul, jenis, isi, file, atau link materi.
        </p>

    </div>


    <form method="POST"
          action="{{ route('lms.materials.update', $material) }}"
          enctype="multipart/form-data">

        @csrf

        @include('guru.atp.selector-lms', ['topic' => $material->topic, 'selectedAtpId' => old('atp_item_id', $material->atp_item_id ?? null)])
        @method('PUT')


        <div class="p-6">

            {{-- =================================================
                 JUDUL + JENIS
            ================================================== --}}

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                {{-- JUDUL --}}

                <div>

                    <label class="block text-sm font-bold text-gray-700 mb-1.5">
                        Judul Materi
                        <span class="text-red-500">*</span>
                    </label>

                    <input type="text"
                           name="title"
                           value="{{ old('title', $material->title) }}"
                           class="block w-full border border-gray-300 rounded-lg text-sm px-3 py-2.5 min-h-[48px] focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none"
                           required>

                </div>


                {{-- JENIS --}}

                <div>

                    <label class="block text-sm font-bold text-gray-700 mb-1.5">
                        Jenis Materi
                        <span class="text-red-500">*</span>
                    </label>

                    <select name="type"
                            class="block w-full border border-gray-300 rounded-lg text-sm px-3 py-2.5 min-h-[48px] focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none"
                            required>

                        <option value="text"
                            @selected(old('type', $material->type) === 'text')}>
                            Teks
                        </option>

                        <option value="pdf"
                            @selected(old('type', $material->type) === 'pdf')}>
                            PDF
                        </option>

                        <option value="image"
                            @selected(old('type', $material->type) === 'image')}>
                            Gambar
                        </option>

                        <option value="video"
                            @selected(old('type', $material->type) === 'video')}>
                            Video
                        </option>

                        <option value="audio"
                            @selected(old('type', $material->type) === 'audio')}>
                            Audio
                        </option>

                        <option value="file"
                            @selected(old('type', $material->type) === 'file')}>
                            File
                        </option>

                        <option value="link"
                            @selected(old('type', $material->type) === 'link')}>
                            Link
                        </option>

                    </select>

                </div>

            </div>


            {{-- =================================================
                 ISI MATERI
            ================================================== --}}

            <div class="mt-5">

                <label class="block text-sm font-bold text-gray-700 mb-1.5">
                    Isi Materi
                </label>

                <textarea name="content"
                          rows="12"
                          class="block w-full border border-gray-300 rounded-lg text-sm px-3 py-3 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none resize-y"
                          placeholder="Isi materi atau keterangan tambahan...">{{ old('content', $material->content) }}</textarea>

            </div>


            {{-- =================================================
                 LINK EKSTERNAL
            ================================================== --}}

            <div class="mt-5">

                <label class="block text-sm font-bold text-gray-700 mb-1.5">
                    Link Eksternal
                </label>

                <input type="url"
                       name="external_url"
                       value="{{ old('external_url', $material->external_url) }}"
                       class="block w-full border border-gray-300 rounded-lg text-sm px-3 py-2.5 min-h-[48px] focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none"
                       placeholder="https://...">

                <p class="text-xs text-gray-400 mt-1.5">
                    Isi jika materi menggunakan link eksternal.
                </p>

            </div>


            {{-- =================================================
                 FILE LAMA
            ================================================== --}}

            @if($material->file_path)

                <div class="mt-5 bg-gray-50 border border-gray-200 rounded-xl p-4">

                    <div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-3">
                        File Saat Ini
                    </div>

                    <div class="flex items-center justify-between gap-4 flex-wrap">

                        {{-- INFORMASI FILE --}}

                        <div class="flex items-center gap-3 min-w-0">

                            <div class="w-10 h-10 rounded-lg bg-white border border-gray-200 flex items-center justify-center shrink-0">
                                📎
                            </div>

                            <div class="min-w-0">

                                <div class="text-sm font-bold text-gray-700 break-all">
                                    {{ $material->file_name ?: basename($material->file_path) }}
                                </div>

                                @if($material->file_size)

                                    <div class="text-xs text-gray-400 mt-0.5">
                                        {{ number_format($material->file_size / 1024, 1, ',', '.') }} KB
                                    </div>

                                @endif

                            </div>

                        </div>


                        {{-- TOMBOL HAPUS FILE --}}

                        <button
                            type="submit"
                            name="remove_file"
                            value="1"
                            formnovalidate
                            onclick="return confirm('Hapus file yang diupload dari materi ini? Materinya tetap akan dipertahankan.')"
                            class="inline-flex items-center justify-center gap-2 bg-red-50 text-red-700 border border-red-200 px-4 py-2.5 min-h-[44px] rounded-lg hover:bg-red-100 transition text-sm font-bold shrink-0">

                            🗑️
                            Hapus File

                        </button>

                    </div>

                    <p class="text-xs text-gray-400 mt-3">
                        Menghapus file tidak akan menghapus materi, judul, isi, rumus, atau topik.
                    </p>

                </div>

            @endif


            {{-- =================================================
                 FILE BARU
            ================================================== --}}

            <div class="mt-5">

                <label class="block text-sm font-bold text-gray-700 mb-1.5">
                    Ganti File
                </label>

                <input type="file"
                       name="file"
                       class="block w-full border border-gray-300 rounded-lg text-sm px-3 py-2.5 min-h-[48px] bg-white">

                <p class="text-xs text-gray-400 mt-1.5">
                    Kosongkan jika tidak ingin mengganti file yang sudah ada.
                </p>

            </div>


            {{-- =================================================
                 STATUS PUBLIKASI
            ================================================== --}}

            <div class="mt-6 bg-gray-50 border border-gray-200 rounded-xl p-4">

                <div class="flex items-center gap-3">

                    @if($material->is_published)

                        <div class="w-3 h-3 rounded-full bg-green-500"></div>

                        <div>

                            <div class="text-sm font-bold text-green-700">
                                Materi sedang dipublikasikan
                            </div>

                            <div class="text-xs text-gray-500 mt-0.5">
                                Perubahan materi akan diproses melalui mekanisme sinkronisasi LMS.
                            </div>

                        </div>

                    @else

                        <div class="w-3 h-3 rounded-full bg-gray-400"></div>

                        <div>

                            <div class="text-sm font-bold text-gray-700">
                                Materi masih Draft
                            </div>

                            <div class="text-xs text-gray-500 mt-0.5">
                                Materi belum ditampilkan kepada murid.
                            </div>

                        </div>

                    @endif

                </div>

            </div>

        </div>


        {{-- =================================================
             FOOTER BUTTON
        ================================================== --}}

        <div class="px-6 py-5 bg-gray-50 border-t border-gray-200">

            <div class="flex items-center justify-end gap-3 flex-wrap">

                <a href="{{ route('lms.courses.show', $material->topic->course) }}"
                   class="inline-flex items-center justify-center gap-2 bg-white text-gray-700 border border-gray-300 px-5 py-3 min-h-[48px] rounded-lg hover:bg-gray-50 transition text-sm font-bold">

                    Batal

                </a>

                <button type="submit"
                        class="inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-6 py-3 min-h-[48px] rounded-lg hover:bg-indigo-700 transition text-sm font-bold shadow-sm">

                    ✓
                    Simpan Perubahan

                </button>

            </div>

        </div>

    </form>

</div>

</div>

</div>

@endsection
