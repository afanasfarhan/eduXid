<x-app-layout>

<x-slot name="header">

    <div class="flex items-center justify-between flex-wrap gap-3">

        <div>

            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                LMS Murid
            </h2>

            <p class="text-sm text-gray-500 mt-0.5">
                Materi dan aktivitas pembelajaran Anda.
            </p>

        </div>

    </div>

</x-slot>


<div class="py-8">

    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">


        {{-- =====================================================
             PESAN
             ===================================================== --}}

        @if(session('success'))

            <div class="bg-green-50 border border-green-200 text-green-700
                        px-4 py-3 rounded-xl mb-6 shadow-sm">

                <span class="font-bold mr-1">✓</span>

                {{ session('success') }}

            </div>

        @endif


        @if(session('error'))

            <div class="bg-red-50 border border-red-200 text-red-700
                        px-4 py-3 rounded-xl mb-6 shadow-sm">

                <span class="font-bold mr-1">⚠️</span>

                {{ session('error') }}

            </div>

        @endif


        {{-- =====================================================
             IDENTITAS SISWA
             ===================================================== --}}

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200
                    overflow-hidden mb-6">

            <div class="bg-gradient-to-r from-indigo-50 to-white
                        px-6 py-6 border-b border-indigo-100">

                <div class="flex items-start justify-between gap-4 flex-wrap">

                    <div class="flex items-center gap-4">

                        <div class="w-16 h-16 rounded-full bg-indigo-100
                                    border border-indigo-200 flex items-center
                                    justify-center text-2xl shrink-0">

                            👨‍🎓

                        </div>


                        <div>

                            <p class="text-xs font-bold uppercase
                                      tracking-wide text-indigo-500">

                                Selamat datang

                            </p>

                            <h1 class="text-2xl font-bold text-indigo-950 mt-1">

                                {{ $student->nama ?? 'Murid' }}

                            </h1>

                            @if($student->nisn)

                                <p class="text-sm text-gray-500 mt-1">

                                    NISN:
                                    <span class="font-semibold text-gray-700">
                                        {{ $student->nisn }}
                                    </span>

                                </p>

                            @endif

                        </div>

                    </div>


                    {{-- ROMBEL UTAMA --}}

                    @if($homeroom)

                        <div class="bg-white border border-indigo-200
                                    rounded-xl px-4 py-3 shadow-sm">

                            <p class="text-xs font-bold text-gray-400
                                      uppercase tracking-wide">

                                Rombel

                            </p>

                            <p class="text-lg font-bold text-indigo-700 mt-1">

                                {{ $homeroom->nama }}

                            </p>

                        </div>

                    @endif

                </div>

            </div>


            {{-- INFORMASI ENROLLMENT --}}

            @if($enrollments->count() > 1)

                <div class="px-6 py-4 bg-white">

                    <p class="text-xs font-bold text-gray-400 uppercase
                              tracking-wide mb-2">

                        Rombel yang terdaftar

                    </p>

                    <div class="flex flex-wrap gap-2">

                        @foreach($enrollments as $enrollment)

                            @if($enrollment->homeroom)

                                <span class="inline-flex items-center gap-1.5
                                             bg-indigo-50 text-indigo-700
                                             border border-indigo-200
                                             px-3 py-1.5 rounded-full
                                             text-xs font-bold">

                                    👥
                                    {{ $enrollment->homeroom->nama }}

                                </span>

                            @endif

                        @endforeach

                    </div>

                </div>

            @endif

        </div>


        {{-- =====================================================
             STATISTIK SERVER FALLBACK
             ===================================================== --}}

        @php

            $courseCount = $courses->count();

            $topicCount = $courses->sum(function ($course) {
                return $course->topics_count ?? 0;
            });

            $materialCount = $courses->sum(function ($course) {
                return $course->topics->sum(function ($topic) {
                    return $topic->materials_count ?? 0;
                });
            });

            $activityCount = $courses->sum(function ($course) {
                return $course->topics->sum(function ($topic) {
                    return $topic->assignments_count ?? 0;
                });
            });

        @endphp


        <div class="grid grid-cols-1 sm:grid-cols-2
                    lg:grid-cols-4 gap-4 mb-6">


            {{-- KELAS --}}

            <div class="bg-white rounded-xl border border-gray-200
                        shadow-sm p-5">

                <div class="text-xs font-bold text-gray-400
                            uppercase tracking-wide mb-3">

                    Kelas LMS

                </div>

                <div class="flex items-end gap-2">

                    <span id="lms-course-count"
                          class="text-3xl font-bold text-indigo-600">

                        {{ $courseCount }}

                    </span>

                    <span class="text-sm font-semibold text-gray-500 pb-1">

                        kelas

                    </span>

                </div>

            </div>


            {{-- TOPIK --}}

            <div class="bg-white rounded-xl border border-gray-200
                        shadow-sm p-5">

                <div class="text-xs font-bold text-gray-400
                            uppercase tracking-wide mb-3">

                    Topik

                </div>

                <div class="flex items-end gap-2">

                    <span id="lms-topic-count"
                          class="text-3xl font-bold text-purple-600">

                        {{ $topicCount }}

                    </span>

                    <span class="text-sm font-semibold text-gray-500 pb-1">

                        topik

                    </span>

                </div>

            </div>


            {{-- MATERI --}}

            <div class="bg-white rounded-xl border border-gray-200
                        shadow-sm p-5">

                <div class="text-xs font-bold text-gray-400
                            uppercase tracking-wide mb-3">

                    Materi

                </div>

                <div class="flex items-end gap-2">

                    <span id="lms-material-count"
                          class="text-3xl font-bold text-blue-600">

                        {{ $materialCount }}

                    </span>

                    <span class="text-sm font-semibold text-gray-500 pb-1">

                        materi

                    </span>

                </div>

            </div>


            {{-- AKTIVITAS --}}

            <div class="bg-white rounded-xl border border-gray-200
                        shadow-sm p-5">

                <div class="text-xs font-bold text-gray-400
                            uppercase tracking-wide mb-3">

                    Aktivitas

                </div>

                <div class="flex items-end gap-2">

                    <span id="lms-activity-count"
                          class="text-3xl font-bold text-orange-600">

                        {{ $activityCount }}

                    </span>

                    <span class="text-sm font-semibold text-gray-500 pb-1">

                        aktivitas

                    </span>

                </div>

            </div>

        </div>


        {{-- =====================================================
             JUDUL KELAS
             ===================================================== --}}

        <div class="bg-white rounded-xl border border-gray-200
                    shadow-sm px-6 py-5 mb-5">

            <div class="flex items-center gap-3">

                <div class="w-11 h-11 rounded-xl bg-indigo-50
                            flex items-center justify-center text-xl">

                    📚

                </div>

                <div>

                    <h3 class="text-lg font-bold text-gray-800">
                        Kelas Pembelajaran
                    </h3>

                    <p class="text-sm text-gray-500 mt-0.5">
                        Pilih kelas untuk melihat materi dan aktivitas
                        pembelajaran.
                    </p>

                </div>

            </div>

        </div>


        {{-- =====================================================
             DAFTAR KELAS
             ===================================================== --}}

        <div id="lms-course-list">

            @if($courses->count() > 0)

                <div class="grid grid-cols-1 md:grid-cols-2
                            lg:grid-cols-3 gap-6">

                    @foreach($courses as $course)

                        <div class="bg-white rounded-2xl shadow-sm
                                    border border-gray-200 overflow-hidden
                                    flex flex-col transition-all duration-300
                                    hover:shadow-lg hover:-translate-y-1">


                            {{-- HEADER --}}

                            <div class="bg-indigo-50 px-5 py-5
                                        border-b border-indigo-100">

                                <div class="flex items-start
                                            justify-between gap-3">

                                    <div class="flex-1 min-w-0">

                                        <div class="flex items-center gap-2
                                                    flex-wrap mb-2">

                                            <span class="inline-flex
                                                         items-center gap-1.5
                                                         bg-indigo-600
                                                         text-white px-2.5
                                                         py-1 rounded-full
                                                         text-xs font-bold">

                                                📚 LMS

                                            </span>

                                        </div>

                                        <h3 class="text-lg font-bold
                                                   text-indigo-950
                                                   leading-tight break-words">

                                            {{ $course->name }}

                                        </h3>

                                    </div>


                                    @if($course->homeroom)

                                        <span class="shrink-0 whitespace-nowrap
                                                     bg-white text-indigo-700
                                                     border border-indigo-200
                                                     px-3 py-1.5 rounded-full
                                                     text-sm font-bold shadow-sm">

                                            {{ $course->homeroom->nama }}

                                        </span>

                                    @endif

                                </div>


                                {{-- MAPEL --}}

                                @if($course->subject)

                                    <div class="mt-3">

                                        <span class="inline-flex
                                                     items-center gap-1.5
                                                     text-sm font-semibold
                                                     text-indigo-600">

                                            📖

                                            {{ $course->subject->nama_mata_pelajaran
                                                ?? $course->subject->name }}

                                        </span>

                                    </div>

                                @endif

                            </div>


                            {{-- BODY --}}

                            <div class="p-5 flex-grow flex flex-col">


                                {{-- DESKRIPSI --}}

                                @if($course->description)

                                    <p class="text-sm text-gray-500
                                              leading-relaxed mb-4">

                                        {{ \Illuminate\Support\Str::limit(
                                            $course->description,
                                            120
                                        ) }}

                                    </p>

                                @endif


                                {{-- STATISTIK KELAS --}}

                                <div class="grid grid-cols-2 gap-3 mb-5">

                                    <div class="bg-gray-50 border
                                                border-gray-200
                                                rounded-lg px-3 py-3">

                                        <p class="text-xs text-gray-400
                                                  font-semibold">

                                            Topik

                                        </p>

                                        <p class="text-lg font-bold
                                                  text-indigo-600 mt-1">

                                            {{ $course->topics_count ?? 0 }}

                                        </p>

                                    </div>


                                    <div class="bg-gray-50 border
                                                border-gray-200
                                                rounded-lg px-3 py-3">

                                        <p class="text-xs text-gray-400
                                                  font-semibold">

                                            Materi

                                        </p>

                                        <p class="text-lg font-bold
                                                  text-blue-600 mt-1">

                                            {{
                                                $course->topics->sum(
                                                    fn ($topic)
                                                        => $topic->materials_count ?? 0
                                                )
                                            }}

                                        </p>

                                    </div>

                                </div>


                                {{-- TOMBOL --}}

                                <div class="mt-auto">

                                    <a href="{{ route(
                                        'siswa.lms.course',
                                        $course
                                    ) }}"
                                       class="flex items-center
                                              justify-center gap-2 w-full
                                              bg-indigo-600 text-white
                                              px-4 py-3 rounded-lg
                                              hover:bg-indigo-700
                                              transition-colors
                                              text-sm font-bold shadow-sm">

                                        📚 Buka Kelas

                                    </a>

                                </div>

                            </div>

                        </div>

                    @endforeach

                </div>

            @else

                <div class="bg-white rounded-2xl shadow-sm
                            border border-gray-200 p-12 text-center">

                    <div class="text-6xl mb-4">
                        🎓
                    </div>

                    <h3 class="text-xl font-bold text-gray-800 mb-2">
                        Belum Ada Kelas LMS
                    </h3>

                    <p class="text-sm text-gray-500 max-w-xl mx-auto">
                        Belum ada kelas LMS yang dipublikasikan untuk
                        rombel Anda.
                    </p>

                    <p class="text-xs text-gray-400 mt-2">
                        Kelas akan muncul setelah guru mempublikasikannya.
                    </p>

                </div>

            @endif

        </div>


        {{-- =====================================================
             STATUS DATA LOKAL
             ===================================================== --}}

        <div id="lms-local-status"
             class="mt-6 bg-indigo-50 border border-indigo-100
                    rounded-xl px-4 py-3 text-sm text-indigo-700">

            Memuat data LMS lokal...

        </div>


    </div>

</div>


{{-- =============================================================
     INDEXEDDB → UI LMS
     ============================================================= --}}

<script>

    document.addEventListener('DOMContentLoaded', async function () {

        if (!window.eduXidLmsDb) {

            console.warn(
                'eduXidLmsDb belum tersedia.'
            );

            return;

        }


        const courseList =
            document.getElementById('lms-course-list');

        const courseCount =
            document.getElementById('lms-course-count');

        const topicCount =
            document.getElementById('lms-topic-count');

        const materialCount =
            document.getElementById('lms-material-count');

        const activityCount =
            document.getElementById('lms-activity-count');

        const localStatus =
            document.getElementById('lms-local-status');


        try {

            await window.eduXidLmsDb.open();


            const [
                allCourses,
                topics,
                materials,
                assignments
            ] = await Promise.all([

                window.eduXidLmsDb.getAll('courses'),

                window.eduXidLmsDb.getAll('topics'),

                window.eduXidLmsDb.getAll('materials'),

                window.eduXidLmsDb.getAll('assignments')

            ]);


            /*
             * Hanya kelas yang sudah dipublikasikan
             * yang boleh ditampilkan.
             */

            const courses =
                allCourses.filter(course => {

                    return (
                        course.is_published === true ||
                        course.is_published === 1 ||
                        course.is_published === '1'
                    );

                });


            /*
             * Bila belum ada course yang dipublikasikan
             * di IndexedDB, gunakan HTML Laravel sebagai fallback.
             */

            if (courses.length === 0) {

                localStatus.textContent =
                    allCourses.length > 0
                        ? 'Belum ada kelas LMS yang dipublikasikan pada perangkat.'
                        : 'Belum ada data kelas LMS di perangkat. ' +
                          'Hubungkan ke internet lalu lakukan sinkronisasi.';

                return;

            }


            /*
             * Statistik menggunakan data IndexedDB.
             */

            courseCount.textContent =
                courses.length;


            /*
             * Hanya topic yang terkait dengan course
             * yang dipublikasikan.
             */

            const publishedCourseKeys =
                new Set(

                    courses.flatMap(course => [

                        course.sync_id,
                        course.id

                    ].filter(Boolean))

                );


            const publishedTopics =
                topics.filter(topic => {

                    const courseKey =
                        topic.course_sync_id ??
                        topic.lms_course_sync_id ??
                        topic.course_id ??
                        topic.lms_course_id ??
                        null;

                    return publishedCourseKeys.has(courseKey);

                });


            topicCount.textContent =
                publishedTopics.length;


            /*
             * Material yang berhubungan dengan topic
             * yang sudah dipublikasikan.
             */

            const publishedTopicKeys =
                new Set(

                    publishedTopics.flatMap(topic => [

                        topic.sync_id,
                        topic.id

                    ].filter(Boolean))

                );


            const publishedMaterials =
                materials.filter(material => {

                    const topicKey =
                        material.topic_sync_id ??
                        material.lms_topic_sync_id ??
                        material.topic_id ??
                        material.lms_topic_id ??
                        null;

                    return publishedTopicKeys.has(topicKey);

                });


            materialCount.textContent =
                publishedMaterials.length;


            /*
             * Aktivitas.
             */

            const publishedAssignments =
                assignments.filter(assignment => {

                    const topicKey =
                        assignment.topic_sync_id ??
                        assignment.lms_topic_sync_id ??
                        assignment.topic_id ??
                        assignment.lms_topic_id ??
                        null;

                    const assignmentCourseKey =
                        assignment.course_sync_id ??
                        assignment.lms_course_sync_id ??
                        assignment.course_id ??
                        assignment.lms_course_id ??
                        null;

                    return (
                        publishedTopicKeys.has(topicKey) ||
                        publishedCourseKeys.has(assignmentCourseKey)
                    );

                });


            activityCount.textContent =
                publishedAssignments.length;


            /*
             * Topic berdasarkan course.
             */

            const topicsByCourse = {};


            publishedTopics.forEach(topic => {

                const courseKey =
                    topic.course_sync_id ??
                    topic.lms_course_sync_id ??
                    topic.course_id ??
                    topic.lms_course_id ??
                    null;


                if (!courseKey) {
                    return;
                }


                if (!topicsByCourse[courseKey]) {

                    topicsByCourse[courseKey] = [];

                }


                topicsByCourse[courseKey].push(topic);

            });


            /*
             * Material berdasarkan topic.
             */

            const materialsByTopic = {};


            publishedMaterials.forEach(material => {

                const topicKey =
                    material.topic_sync_id ??
                    material.lms_topic_sync_id ??
                    material.topic_id ??
                    material.lms_topic_id ??
                    null;


                if (!topicKey) {
                    return;
                }


                if (!materialsByTopic[topicKey]) {

                    materialsByTopic[topicKey] = 0;

                }


                materialsByTopic[topicKey]++;

            });


            /*
             * Render ulang daftar kelas
             * menggunakan IndexedDB.
             */

            courseList.innerHTML = '';


            const grid =
                document.createElement('div');

            grid.className =
                'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6';


            courses.forEach(course => {

                const courseKey =
                    course.sync_id ??
                    course.id;


                const courseTopics =
                    topicsByCourse[courseKey] ?? [];


                let materialTotal = 0;


                courseTopics.forEach(topic => {

                    const topicKey =
                        topic.sync_id ??
                        topic.id;


                    materialTotal +=
                        materialsByTopic[topicKey] ?? 0;

                });


                const card =
                    document.createElement('div');

                card.className =
                    'bg-white rounded-2xl shadow-sm border ' +
                    'border-gray-200 overflow-hidden flex flex-col ' +
                    'transition-all duration-300 hover:shadow-lg ' +
                    'hover:-translate-y-1';


                const header =
                    document.createElement('div');

                header.className =
                    'bg-indigo-50 px-5 py-5 border-b border-indigo-100';


                header.innerHTML = `

                    <div class="flex items-start justify-between gap-3">

                        <div class="flex-1 min-w-0">

                            <div class="flex items-center gap-2
                                        flex-wrap mb-2">

                                <span class="inline-flex items-center gap-1.5
                                             bg-indigo-600 text-white px-2.5
                                             py-1 rounded-full text-xs font-bold">

                                    📚 LMS

                                </span>

                            </div>

                            <h3 class="text-lg font-bold text-indigo-950
                                       leading-tight break-words">

                                ${escapeHtml(
                                    course.name ?? 'Kelas LMS'
                                )}

                            </h3>

                        </div>

                    </div>


                    <div class="mt-3">

                        <span class="inline-flex items-center gap-1.5
                                     text-sm font-semibold text-indigo-600">

                            📖

                            LMS

                        </span>

                    </div>

                `;


                const body =
                    document.createElement('div');

                body.className =
                    'p-5 flex-grow flex flex-col';


                const description =
                    course.description ?? '';


                body.innerHTML = `

                    ${description ? `

                        <p class="text-sm text-gray-500
                                  leading-relaxed mb-4">

                            ${escapeHtml(
                                description.length > 120
                                    ? description.substring(0, 120) + '...'
                                    : description
                            )}

                        </p>

                    ` : ''}


                    <div class="grid grid-cols-2 gap-3 mb-5">

                        <div class="bg-gray-50 border border-gray-200
                                    rounded-lg px-3 py-3">

                            <p class="text-xs text-gray-400 font-semibold">
                                Topik
                            </p>

                            <p class="text-lg font-bold text-indigo-600 mt-1">

                                ${courseTopics.length}

                            </p>

                        </div>


                        <div class="bg-gray-50 border border-gray-200
                                    rounded-lg px-3 py-3">

                            <p class="text-xs text-gray-400 font-semibold">
                                Materi
                            </p>

                            <p class="text-lg font-bold text-blue-600 mt-1">

                                ${materialTotal}

                            </p>

                        </div>

                    </div>


                    <div class="mt-auto">

                        <a href="/siswa/lms/courses/${encodeURIComponent(course.id)}"
                           class="flex items-center justify-center gap-2 w-full
                                  bg-indigo-600 text-white px-4 py-3 rounded-lg
                                  hover:bg-indigo-700 transition-colors
                                  text-sm font-bold shadow-sm">

                            📚 Buka Kelas

                        </a>

                    </div>

                `;


                card.appendChild(header);

                card.appendChild(body);

                grid.appendChild(card);

            });


            courseList.appendChild(grid);


            localStatus.innerHTML =
                '✓ LMS berhasil dimuat dari ' +
                '<strong>IndexedDB perangkat</strong>. ' +
                `${courses.length} kelas yang dipublikasikan tersedia secara lokal.`;


        } catch (error) {

            console.error(
                'Gagal memuat LMS dari IndexedDB:',
                error
            );


            localStatus.textContent =
                '⚠️ Data LMS lokal gagal dimuat. ' +
                'Periksa Console browser.';

        }


        function escapeHtml(value) {

            return String(value)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');

        }

    });

</script>

</x-app-layout>
