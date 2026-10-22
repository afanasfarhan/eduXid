@extends('layouts.app')

@section('content')

<style>
    /* =========================================================
       LMS COURSES - eduXid
       ========================================================= */

    .lms-page {
        max-width: 1200px;
        margin-inline: auto;
    }

    /* ===== Header ===== */
    .lms-hero {
        background: linear-gradient(135deg, #4338ca 0%, #6d28d9 55%, #9333ea 100%);
        border: none;
        border-radius: 1rem;
        overflow: hidden;
    }

    .lms-hero-icon {
        width: 60px;
        height: 60px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255,255,255,.14);
        border: 1px solid rgba(255,255,255,.22);
        border-radius: 1rem;
        color: #fff;
        flex-shrink: 0;
    }

    .lms-hero .eyebrow {
        color: rgba(255,255,255,.72);
        letter-spacing: .08em;
        font-size: .75rem;
        font-weight: 700;
    }

    .lms-hero h2 {
        color: #fff;
    }

    .lms-hero-description {
        color: rgba(255,255,255,.75);
        max-width: 680px;
    }

    .btn-hero-light {
        background: #fff;
        color: #4338ca;
        border: none;
        font-weight: 600;
    }

    .btn-hero-light:hover,
    .btn-hero-light:focus {
        background: #eef2ff;
        color: #3730a3;
    }

    /* ===== Section ===== */
    .section-accent {
        width: 5px;
        height: 34px;
        border-radius: 50rem;
        background: linear-gradient(180deg, #4f46e5, #9333ea);
        flex-shrink: 0;
    }

    /* ===== Course Card ===== */
    .course-card {
        border: 1px solid #e9edf2;
        border-radius: 1rem;
        transition:
            transform .18s ease,
            box-shadow .18s ease,
            border-color .18s ease;
        height: 100%;
        overflow: hidden;
    }

    .course-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 .75rem 1.75rem rgba(15,23,42,.09) !important;
        border-color: #d9dff0;
    }

    .course-card-header {
        background: linear-gradient(135deg, #f8faff 0%, #f5f3ff 100%);
        border-bottom: 1px solid #edf0f5;
        padding: 1.25rem;
    }

    .course-icon {
        width: 50px;
        height: 50px;
        border-radius: .85rem;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #eef2ff;
        color: #4f46e5;
        font-size: 1.35rem;
        flex-shrink: 0;
    }

    .course-title {
        color: #1e293b;
        font-size: 1.05rem;
        line-height: 1.35;
    }

    .course-description {
        color: #64748b;
        font-size: .875rem;
        line-height: 1.55;
    }

    /* ===== Status ===== */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        padding: .35rem .75rem;
        border-radius: 50rem;
        font-size: .72rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .status-published {
        background: #dcfce7;
        color: #15803d;
    }

    .status-draft {
        background: #f1f5f9;
        color: #64748b;
    }

    /* ===== Meta ===== */
    .meta-pill {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        background: #f8fafc;
        border: 1px solid #e8ecf1;
        color: #475569;
        font-size: .76rem;
        padding: .34rem .7rem;
        border-radius: 50rem;
    }

    .meta-pill i {
        color: #64748b;
    }

    .meta-pill strong {
        color: #1e293b;
    }

    /* ===== Topic List ===== */
    .topic-list {
        border-top: 1px solid #eef1f5;
    }

    .topic-item {
        display: flex;
        align-items: center;
        gap: .65rem;
        padding: .65rem 0;
        border-bottom: 1px solid #f1f3f6;
        font-size: .82rem;
    }

    .topic-item:last-child {
        border-bottom: none;
    }

    .topic-number {
        width: 28px;
        height: 28px;
        border-radius: .55rem;
        background: #f1f5f9;
        color: #64748b;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: .72rem;
        font-weight: 700;
        flex-shrink: 0;
    }

    .topic-title {
        color: #334155;
        font-weight: 600;
        line-height: 1.35;
    }

    /* ===== Empty State ===== */
    .empty-state {
        border: 2px dashed #dbe2ea;
        border-radius: 1rem;
        background: linear-gradient(180deg, #fbfcfe 0%, #f6f8fb 100%);
    }

    .empty-icon {
        width: 84px;
        height: 84px;
        border-radius: 50%;
        background: #eef2f7;
        color: #94a3b8;
        font-size: 2.1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-inline: auto;
    }

    /* ===== Responsive ===== */
    @media (max-width: 767.98px) {
        .lms-page {
            padding-inline: .25rem;
        }

        .lms-hero .card-body {
            padding: 1.25rem !important;
        }

        .course-card-header {
            padding: 1rem;
        }
    }
</style>

<div class="container-fluid py-4 lms-page">

{{-- =========================================================
     HEADER LMS
     ========================================================= --}}
<div class="card lms-hero shadow-sm mb-4">

    <div class="card-body p-4">

        <div class="d-flex justify-content-between align-items-start flex-wrap gap-4">

            <div class="d-flex gap-3 align-items-start">

                <div class="lms-hero-icon">
                    <i class="bi bi-mortarboard-fill fs-3"></i>
                </div>

                <div>

                    <div class="eyebrow text-uppercase mb-1">
                        <i class="bi bi-laptop me-1"></i>
                        Learning Management System
                    </div>

                    <h2 class="fw-bold mb-2">
                        LMS eduXid
                    </h2>

                    <p class="lms-hero-description mb-0">
                        Kelola kelas, materi pembelajaran, topik, dan tugas
                        dalam satu tempat.
                    </p>

                </div>

            </div>


            <div>

                <a href="{{ route('lms.courses.create') }}"
                   class="btn btn-hero-light px-3">

                    <i class="bi bi-plus-lg me-1"></i>
                    Buat Kelas LMS

                </a>

            </div>

        </div>


        {{-- Statistik --}}
        <div class="d-flex flex-wrap gap-2 mt-4 pt-3"
             style="border-top:1px solid rgba(255,255,255,.2);">

            <span class="badge rounded-pill bg-white bg-opacity-10 text-white px-3 py-2">
                <i class="bi bi-collection me-1"></i>
                {{ $courses->count() }} Kelas
            </span>

            <span class="badge rounded-pill bg-white bg-opacity-10 text-white px-3 py-2">
                <i class="bi bi-journal-text me-1"></i>
                {{ $courses->sum(fn($course) => $course->topics->count()) }} Topik
            </span>

        </div>

    </div>

</div>


{{-- =========================================================
     PESAN SUKSES
     ========================================================= --}}
@if(session('success'))

    <div class="alert alert-success d-flex align-items-start gap-2 alert-dismissible fade show shadow-sm border-0">

        <i class="bi bi-check-circle-fill mt-1"></i>

        <div class="flex-grow-1">
            {{ session('success') }}
        </div>

        <button type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Tutup">
        </button>

    </div>

@endif


{{-- =========================================================
     PESAN ERROR
     ========================================================= --}}
@if(session('error'))

    <div class="alert alert-danger d-flex align-items-start gap-2 alert-dismissible fade show shadow-sm border-0">

        <i class="bi bi-exclamation-triangle-fill mt-1"></i>

        <div class="flex-grow-1">
            {{ session('error') }}
        </div>

        <button type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Tutup">
        </button>

    </div>

@endif


{{-- =========================================================
     VALIDATION ERROR
     ========================================================= --}}
@if($errors->any())

    <div class="alert alert-danger shadow-sm border-0">

        <div class="d-flex align-items-start gap-2">

            <i class="bi bi-exclamation-octagon-fill mt-1"></i>

            <div class="flex-grow-1">

                <strong>Terjadi kesalahan:</strong>

                <ul class="mb-0 mt-2">

                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach

                </ul>

            </div>

        </div>

    </div>

@endif


{{-- =========================================================
     JUDUL SECTION
     ========================================================= --}}
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 mt-4">

    <div class="d-flex align-items-center gap-3">

        <span class="section-accent"></span>

        <div>

            <h4 class="fw-bold mb-0">
                Kelas LMS
            </h4>

            <small class="text-muted">
                Daftar kelas pembelajaran yang Anda kelola
            </small>

        </div>

    </div>


    @if($courses->count() > 0)

        <span class="badge bg-light text-dark border">
            {{ $courses->count() }} kelas
        </span>

    @endif

</div>


{{-- =========================================================
     DAFTAR COURSE
     ========================================================= --}}
@forelse($courses as $course)

    <div class="card course-card shadow-sm mb-4">

        {{-- Header Course --}}
        <div class="course-card-header">

            <div class="d-flex justify-content-between align-items-start gap-3">

                <div class="d-flex gap-3 align-items-start">

                    <div class="course-icon">

                        <i class="bi bi-book-half"></i>

                    </div>


                    <div>

                        <div class="d-flex align-items-center flex-wrap gap-2 mb-1">

                            <h5 class="course-title fw-bold mb-0">
                                {{ $course->name }}
                            </h5>


                            @if($course->is_published)

                                <span class="status-badge status-published">

                                    <i class="bi bi-check-circle-fill"></i>
                                    Dipublikasikan

                                </span>

                            @else

                                <span class="status-badge status-draft">

                                    <i class="bi bi-pencil-fill"></i>
                                    Draft

                                </span>

                            @endif

                        </div>


                        @if($course->subject)

                            <div class="small text-muted">

                                <i class="bi bi-journal-bookmark me-1"></i>

                                {{ $course->subject->name }}

                            </div>

                        @endif

                    </div>

                </div>

            </div>


            {{-- Informasi --}}
            <div class="d-flex flex-wrap gap-2 mt-3">

                @if($course->homeroom)

                    <span class="meta-pill">

                        <i class="bi bi-people"></i>

                        Rombel:
                        <strong>
                            {{ $course->homeroom->name }}
                        </strong>

                    </span>

                @endif


                @if($course->academic_year)

                    <span class="meta-pill">

                        <i class="bi bi-calendar3"></i>

                        Tahun:
                        <strong>
                            {{ $course->academic_year }}
                        </strong>

                    </span>

                @endif


                @if($course->semester)

                    <span class="meta-pill">

                        <i class="bi bi-layers"></i>

                        Semester:
                        <strong>
                            {{ $course->semester }}
                        </strong>

                    </span>

                @endif


                <span class="meta-pill">

                    <i class="bi bi-list-ul"></i>

                    Topik:
                    <strong>
                        {{ $course->topics->count() }}
                    </strong>

                </span>

            </div>

        </div>


        {{-- Isi Course --}}
        <div class="card-body p-4">

            @if($course->description)

                <div class="course-description mb-4">

                    {{ $course->description }}

                </div>

            @endif


            {{-- =================================================
                 TOPIK
                 ================================================= --}}
            @if($course->topics->count() > 0)

                <div class="mb-3">

                    <div class="d-flex align-items-center justify-content-between mb-2">

                        <div class="fw-semibold text-dark">

                            <i class="bi bi-list-check me-1 text-primary"></i>
                            Topik Pembelajaran

                        </div>

                        <small class="text-muted">

                            {{ $course->topics->count() }} topik

                        </small>

                    </div>


                    <div class="topic-list">

                        @foreach($course->topics as $index => $topic)

                            <div class="topic-item">

                                <span class="topic-number">
                                    {{ $index + 1 }}
                                </span>


                                <div class="flex-grow-1">

                                    <div class="topic-title">
                                        {{ $topic->title }}
                                    </div>

                                </div>

                            </div>

                        @endforeach

                    </div>

                </div>

            @else

                <div class="text-muted small mb-3">

                    <i class="bi bi-info-circle me-1"></i>

                    Belum ada topik pembelajaran pada kelas ini.

                </div>

            @endif


            {{-- =================================================
                 AKSI COURSE
                 ================================================= --}}
            <div class="d-flex flex-wrap align-items-center gap-2 pt-3 border-top">

                <a href="{{ route('lms.courses.show', $course) }}"
                   class="btn btn-sm btn-primary px-3">

                    <i class="bi bi-eye me-1"></i>
                    Buka Kelas

                </a>


                <a href="{{ route('lms.courses.edit', $course) }}"
                   class="btn btn-sm btn-outline-secondary">

                    <i class="bi bi-pencil me-1"></i>
                    Edit

                </a>


                <form method="POST"
                      action="{{ route('lms.courses.destroy', $course) }}"
                      onsubmit="return confirm('Hapus kelas LMS ini? Semua data terkait kelas dapat ikut terhapus.')"
                      class="ms-md-auto">

                    @csrf

                    @method('DELETE')

                    <button type="submit"
                            class="btn btn-sm btn-outline-danger">

                        <i class="bi bi-trash me-1"></i>
                        Hapus

                    </button>

                </form>

            </div>

        </div>

    </div>

@empty

    {{-- =========================================================
         EMPTY STATE
         ========================================================= --}}
    <div class="card empty-state">

        <div class="card-body text-center py-5 px-4">

            <div class="empty-icon mb-3">

                <i class="bi bi-journal-x"></i>

            </div>


            <h5 class="fw-bold">
                Belum Ada Kelas LMS
            </h5>


            <p class="text-muted mb-4 mx-auto"
               style="max-width:460px;">

                Belum ada kelas LMS yang dibuat.
                Mulailah dengan membuat kelas pembelajaran
                untuk mata pelajaran dan rombel yang Anda ampu.

            </p>


            <a href="{{ route('lms.courses.create') }}"
               class="btn btn-primary px-4">

                <i class="bi bi-plus-lg me-1"></i>
                Buat Kelas LMS Pertama

            </a>

        </div>

    </div>

@endforelse

</div>

@endsection
