<x-app-layout>

<x-slot name="header">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">LMS Murid</h2>
            <p class="text-sm text-gray-500 mt-0.5">Materi dan aktivitas pembelajaran Anda.</p>
        </div>
        <div class="inline-flex items-center gap-2 bg-gray-100 border border-gray-200 text-gray-600 px-3 py-2 rounded-xl text-xs font-bold shadow-sm">
            <span id="lms-sync-dot" class="w-2.5 h-2.5 rounded-full bg-gray-400"></span>
            <span id="lms-sync-label">Memeriksa koneksi...</span>
        </div>
    </div>
</x-slot>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden mb-6">
            <div class="bg-gradient-to-r from-indigo-50 to-white px-6 py-6 border-b border-indigo-100">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div class="flex items-center gap-4">
                        <div class="w-16 h-16 rounded-full bg-indigo-100 border border-indigo-200 flex items-center justify-center text-2xl shrink-0">👨‍🎓</div>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wide text-indigo-500">Selamat datang</p>
                            <h1 class="text-2xl font-bold text-indigo-950 mt-1" id="welcome-name">{{ $student->nama ?? 'Murid' }}</h1>
                            @if($student->nisn)<p class="text-sm text-gray-500 mt-1">NISN: <span class="font-semibold text-gray-700">{{ $student->nisn }}</span></p>@endif
                        </div>
                    </div>
                    <div class="flex items-center gap-3 flex-wrap">
                        <button type="button" id="lms-sync-now" class="inline-flex items-center gap-2 bg-white border border-indigo-200 text-indigo-700 px-4 py-2.5 rounded-xl text-xs font-bold hover:bg-indigo-50 transition shadow-sm">🔄 Sinkronkan</button>
                        @if($homeroom)
                            <div class="bg-white border border-indigo-200 rounded-xl px-4 py-3 shadow-sm">
                                <p class="text-xs font-bold text-gray-400 uppercase tracking-wide">Rombel</p>
                                <p class="text-lg font-bold text-indigo-700 mt-1">{{ $homeroom->nama }}</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            @if($enrollments->count() > 1)
                <div class="px-6 py-4 bg-white">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-2">Rombel yang terdaftar</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($enrollments as $enrollment)
                            @if($enrollment->homeroom)<span class="inline-flex items-center gap-1.5 bg-indigo-50 text-indigo-700 border border-indigo-200 px-3 py-1.5 rounded-full text-xs font-bold">👥 {{ $enrollment->homeroom->nama }}</span>@endif
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-3">Kelas LMS</div>
                <div class="flex items-end gap-2"><span id="lms-course-count" class="text-3xl font-bold text-indigo-600">0</span><span class="text-sm font-semibold text-gray-500 pb-1">kelas</span></div>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-3">Topik</div>
                <div class="flex items-end gap-2"><span id="lms-topic-count" class="text-3xl font-bold text-purple-600">0</span><span class="text-sm font-semibold text-gray-500 pb-1">topik</span></div>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-3">Materi</div>
                <div class="flex items-end gap-2"><span id="lms-material-count" class="text-3xl font-bold text-blue-600">0</span><span class="text-sm font-semibold text-gray-500 pb-1">materi</span></div>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-3">Aktivitas</div>
                <div class="flex items-end gap-2"><span id="lms-activity-count" class="text-3xl font-bold text-orange-600">0</span><span class="text-sm font-semibold text-gray-500 pb-1">aktivitas</span></div>
            </div>
        </div>

        <div id="lms-page-title" class="bg-white rounded-xl border border-gray-200 shadow-sm px-6 py-5 mb-5"></div>
        <div id="lms-content"></div>

        <div id="lms-local-status" class="mt-6 bg-indigo-50 border border-indigo-100 rounded-xl px-4 py-3 text-sm text-indigo-700">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-2"><span id="lms-local-status-icon">📦</span><span id="lms-local-status-text">Memuat data LMS perangkat...</span></div>
                <div id="lms-last-sync" class="text-xs font-semibold text-indigo-500">Belum ada sinkronisasi</div>
            </div>
        </div>

    </div>
</div>

<link rel="stylesheet" href="{{ asset('katex/katex.min.css') }}">
<style>
.eduxid-material-content { line-height: 1.8; overflow-x: auto; overflow-wrap: anywhere; }
.eduxid-material-content .katex-display { margin-top: 1rem; margin-bottom: 1rem; overflow-x: auto; overflow-y: hidden; padding-top: 0.25rem; padding-bottom: 0.25rem; }
</style>
<script src="{{ asset('katex/katex.min.js') }}"></script>
<script src="{{ asset('katex/contrib/auto-render.min.js') }}"></script>

@if(!isset($eduxidCloudLoaded))
<script>window.EDUXID_CLOUD_URL = '{{ rtrim(config('eduxid.sync_server'), '/') }}';</script>
<script src="{{ asset('js/lms-cloud-direct.js') }}"></script>
@endif

<script>
window.EDUXID_STUDENT_LOGIN_ID = @json(auth()->user()->login_id ?? null);
window.EDUXID_STUDENT_HOMEROOM_IDS = @json($enrollments->pluck('homeroom_id')->filter()->values()->all());
</script>

<script>
document.addEventListener('DOMContentLoaded', async function () {

    const CLOUD = window.eduXidCloud;
    if (!CLOUD) {
        document.getElementById('lms-content').innerHTML = '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-8 text-center">Client sync v2 gagal dimuat. Muat ulang halaman.</div>';
        return;
    }

    const content = document.getElementById('lms-content'),
        pageTitle = document.getElementById('lms-page-title'),
        statusText = document.getElementById('lms-local-status-text'),
        statusIcon = document.getElementById('lms-local-status-icon'),
        statusBox = document.getElementById('lms-local-status'),
        lastSync = document.getElementById('lms-last-sync'),
        syncDot = document.getElementById('lms-sync-dot'),
        syncLabel = document.getElementById('lms-sync-label'),
        syncButton = document.getElementById('lms-sync-now');

    const homeroomIds = (window.EDUXID_STUDENT_HOMEROOM_IDS || []).map(Number).filter(Number.isFinite);

    let courses = [], topics = [], materials = [], assignments = [], submissions = [];
    let view = { type: 'courses', courseKey: null, topicKey: null, assignmentKey: null };

    function isPublished(r) { return !!r && (r.is_published === true || r.is_published === 1 || r.is_published === '1'); }
    function sameId(a, b) { return a !== null && a !== undefined && b !== null && b !== undefined && String(a) === String(b); }

    function setLocalStatus(msg, type) {
        if (!statusBox) return;
        statusBox.className = 'mt-6 rounded-xl px-4 py-3 text-sm ' + ({
            offline: 'bg-orange-50 border border-orange-200 text-orange-700',
            syncing: 'bg-blue-50 border border-blue-200 text-blue-700',
            success: 'bg-green-50 border border-green-200 text-green-700',
            error: 'bg-red-50 border border-red-200 text-red-700'
        }[type || 'local'] || 'bg-indigo-50 border border-indigo-100 text-indigo-700');
        statusIcon.textContent = { offline: '📴', syncing: '🔄', success: '✓', error: '⚠️' }[type || 'local'] || '📦';
        statusText.textContent = msg;
    }

    function setSyncStatus(state) {
        const map = {
            syncing: ['bg-blue-50 border-blue-200 text-blue-700', 'bg-blue-500', 'Menyinkronkan...'],
            synced: ['bg-green-50 border-green-200 text-green-700', 'bg-green-500', 'Tersinkron'],
            offline: ['bg-orange-50 border-orange-200 text-orange-700', 'bg-orange-500', 'Offline'],
            error: ['bg-red-50 border-red-200 text-red-700', 'bg-red-500', 'Sync Error'],
            need_login: ['bg-red-50 border-red-200 text-red-700', 'bg-red-500', 'Sesi berakhir']
        };
        const s = map[state] || ['bg-gray-100 border-gray-200 text-gray-600', 'bg-gray-400', 'Siap sinkronisasi'];
        syncDot.className = 'w-2.5 h-2.5 rounded-full ' + s[1];
        syncLabel.textContent = s[2];
    }

    function fmtDate(v) {
        if (!v) return '';
        const d = new Date(v);
        return Number.isNaN(d.getTime()) ? String(v) : d.toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
    }
    function esc(v) { return String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'); }
    function renderMath(root) {
        if (root && typeof window.renderMathInElement === 'function') {
            try { window.renderMathInElement(root, { delimiters: [{ left: '$$', right: '$$', display: true }, { left: '\\(', right: '\\)', display: false }], throwOnError: false, strict: 'ignore' }); } catch (e) {}
        }
    }

    async function loadFromDevice() {
        const [c, t, m, a, s] = await Promise.all([
            CLOUD.dbAll('courses'), CLOUD.dbAll('topics'), CLOUD.dbAll('materials'),
            CLOUD.dbAll('assignments'), CLOUD.dbAll('submissions')
        ]);
        const me = String(window.EDUXID_STUDENT_LOGIN_ID ?? '').trim();

        courses = c.filter(r => isPublished(r) && homeroomIds.includes(Number(r.homeroom_id)));

        const courseKeySet = new Set(courses.flatMap(r => [String(r.sync_id), String(r.id ?? '')]));
        topics = t.filter(r => isPublished(r) && (courseKeySet.has(String(r.course_sync_id)) || courseKeySet.has(String(r.course_id))));
        const topicKeySet = new Set(topics.flatMap(r => [String(r.sync_id), String(r.id ?? '')]));
        materials = m.filter(r => isPublished(r) && (topicKeySet.has(String(r.topic_sync_id)) || topicKeySet.has(String(r.topic_id))));
        assignments = a.filter(r => isPublished(r) && (topicKeySet.has(String(r.topic_sync_id)) || topicKeySet.has(String(r.topic_id))));
        submissions = s.filter(r => r && !r.deleted && (sameId(r.student_id, me) || sameId(r.student_nisn, me)));

        document.getElementById('lms-course-count').textContent = courses.length;
        document.getElementById('lms-topic-count').textContent = topics.length;
        document.getElementById('lms-material-count').textContent = materials.length;
        document.getElementById('lms-activity-count').textContent = assignments.length;
    }

    const topicsOf = (course) => topics.filter(t => sameId(t.course_sync_id, course.sync_id) || sameId(t.course_id, course.id))
        .sort((x, y) => Number(x.sort_order ?? 0) - Number(y.sort_order ?? 0));
    const materialsOf = (topic) => materials.filter(m => sameId(m.topic_sync_id, topic.sync_id) || sameId(m.topic_id, topic.id))
        .sort((x, y) => Number(x.sort_order ?? 0) - Number(y.sort_order ?? 0));
    const assignmentsOf = (topic) => assignments.filter(a => sameId(a.topic_sync_id, topic.sync_id) || sameId(a.topic_id, topic.id));

    const findCourse = () => courses.find(c => sameId(c.sync_id, view.courseKey) || sameId(c.id, view.courseKey)) || null;
    const findTopic = (course) => course ? topicsOf(course).find(t => sameId(t.sync_id, view.topicKey) || sameId(t.id, view.topicKey)) || null : null;
    const findAssignment = () => assignments.find(a => sameId(a.sync_id, view.assignmentKey) || sameId(a.id, view.assignmentKey)) || null;

    function submissionOf(assignment) {
        const list = submissions.filter(s => sameId(s.assignment_sync_id, assignment.sync_id) || sameId(s.assignment_id, assignment.id));
        list.sort((a, b) => Number(b.sync_version ?? 0) - Number(a.sync_version ?? 0));
        return list[0] || null;
    }

    function submissionBanner(assignment) {
        const sub = submissionOf(assignment);
        if (!sub) return '<div class="mb-6 rounded-2xl border border-gray-200 bg-gray-50 p-5 text-sm text-gray-500">Anda belum mengumpulkan jawaban untuk aktivitas ini.</div>';
        if (String(sub.status).toLowerCase() === 'graded') {
            const score = (sub.score !== null && sub.score !== undefined && String(sub.score) !== '')
                ? '<div class="mt-3 inline-flex items-center gap-2 bg-white border border-green-200 rounded-xl px-4 py-3"><span class="text-sm font-semibold text-gray-600">Nilai</span><span class="text-2xl font-bold text-green-700">' + esc(sub.score) + '</span>' + (assignment.max_score != null ? '<span class="text-sm font-semibold text-gray-500">/ ' + esc(assignment.max_score) + '</span>' : '') + '</div>' : '';
            const fb = String(sub.teacher_feedback ?? '').trim();
            return '<div class="mb-6 rounded-2xl border border-green-200 bg-green-50 p-5"><h3 class="font-bold text-green-900">Sudah Dinilai Guru</h3>' + score +
                (fb ? '<div class="mt-4 rounded-xl bg-white border border-green-200 p-4"><p class="text-xs font-bold uppercase tracking-wide text-gray-400">Feedback Guru</p><p class="text-sm text-gray-700 mt-2 whitespace-pre-line">' + esc(fb) + '</p></div>' : '<p class="text-sm text-green-700 mt-3">Guru belum memberikan feedback tertulis.</p>') + '</div>';
        }
        if (sub.sync_status === 'pending') return '<div class="mb-6 rounded-2xl border border-yellow-200 bg-yellow-50 p-5"><h3 class="font-bold text-yellow-900">Jawaban Menunggu Sinkronisasi</h3><p class="text-sm text-yellow-700 mt-1">Tersimpan di perangkat; akan terkirim otomatis saat internet tersedia.</p></div>';
        return '<div class="mb-6 rounded-2xl border border-blue-200 bg-blue-50 p-5"><h3 class="font-bold text-blue-900">Jawaban Sudah Terkirim</h3><p class="text-sm text-blue-700 mt-1">Menunggu penilaian guru.</p></div>';
    }

    function renderCourseList() {
        view = { type: 'courses', courseKey: null, topicKey: null, assignmentKey: null };
        pageTitle.innerHTML = '<div class="flex items-center gap-3"><div class="w-11 h-11 rounded-xl bg-indigo-50 flex items-center justify-center text-xl">📚</div><div><h3 class="text-lg font-bold text-gray-800">Kelas Pembelajaran</h3><p class="text-sm text-gray-500 mt-0.5">Pilih kelas untuk melihat materi dan aktivitas.</p></div></div>';
        content.innerHTML = '';
        if (!courses.length) {
            content.innerHTML = '<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-12 text-center"><div class="text-6xl mb-4">🎓</div><h3 class="text-xl font-bold text-gray-800 mb-2">Belum Ada Kelas LMS</h3><p class="text-sm text-gray-500">Kelas yang dipublikasikan guru akan muncul di sini setelah tersinkron.</p></div>';
            return;
        }
        const grid = document.createElement('div');
        grid.className = 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6';
        courses.forEach(function (course) {
            const t = topicsOf(course);
            let mat = 0; t.forEach(tp => mat += materialsOf(tp).length);
            const card = document.createElement('div');
            card.className = 'bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden flex flex-col transition-all duration-300 hover:shadow-lg hover:-translate-y-1';
            card.innerHTML = '<div class="bg-indigo-50 px-5 py-5 border-b border-indigo-100"><h3 class="text-lg font-bold text-indigo-950 leading-tight break-words">' + esc(course.name ?? 'Kelas LMS') + '</h3></div>' +
                '<div class="p-5 flex-grow flex flex-col">' +
                (course.description ? '<p class="text-sm text-gray-500 leading-relaxed mb-4">' + esc(course.description) + '</p>' : '') +
                '<div class="grid grid-cols-2 gap-3 mb-5"><div class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-3"><p class="text-xs text-gray-400 font-semibold">Topik</p><p class="text-lg font-bold text-indigo-600 mt-1">' + t.length + '</p></div><div class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-3"><p class="text-xs text-gray-400 font-semibold">Materi</p><p class="text-lg font-bold text-blue-600 mt-1">' + mat + '</p></div></div>' +
                '<div class="mt-auto"><button type="button" class="open-course w-full bg-indigo-600 text-white px-4 py-3 rounded-lg hover:bg-indigo-700 transition text-sm font-bold shadow-sm">📚 Buka Kelas</button></div></div>';
            card.querySelector('.open-course').addEventListener('click', function () {
                view = { type: 'course', courseKey: String(course.sync_id ?? course.id), topicKey: null, assignmentKey: null };
                renderCourseDetail(course);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
            grid.appendChild(card);
        });
        content.appendChild(grid);
    }

    function renderCourseDetail(course) {
        if (!course) { renderCourseList(); return; }
        const list = topicsOf(course);
        pageTitle.innerHTML = '<div class="flex items-center justify-between gap-4 flex-wrap"><div class="flex items-center gap-3"><div class="w-11 h-11 rounded-xl bg-indigo-50 flex items-center justify-center text-xl">📚</div><div><h3 class="text-lg font-bold text-gray-800">' + esc(course.name ?? 'Kelas LMS') + '</h3></div></div><button type="button" id="back-btn" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-bold hover:bg-gray-50">← Kembali</button></div>';
        document.getElementById('back-btn').addEventListener('click', function () { renderCourseList(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
        content.innerHTML = '';
        if (!list.length) { content.innerHTML = '<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-10 text-center"><div class="text-4xl mb-3">📂</div><h3 class="font-bold text-gray-800">Belum Ada Topik</h3></div>'; return; }
        list.forEach(function (topic) {
            const mats = materialsOf(topic), asgs = assignmentsOf(topic);
            const card = document.createElement('div');
            card.className = 'bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden mb-5';
            card.innerHTML = '<div class="bg-indigo-50 px-5 py-5 border-b border-indigo-100"><h4 class="text-lg font-bold text-indigo-950 break-words">' + esc(topic.title ?? 'Topik') + '</h4>' + (topic.description ? '<p class="text-sm text-gray-600 mt-1 whitespace-pre-line">' + esc(topic.description) + '</p>' : '') + '</div>' +
                '<div class="p-5">' +
                (mats.length ? '<h5 class="text-sm font-bold text-gray-700 mb-3">📖 Materi</h5><div class="space-y-3 mb-6">' + mats.map(function (m) {
                    const isPdf = String(m.type ?? '').toLowerCase() === 'pdf' || String(m.mime_type ?? '').toLowerCase() === 'application/pdf';
                    return '<div class="border border-gray-200 rounded-xl p-4"><p class="font-bold text-gray-800">' + esc(m.title ?? 'Materi') + '</p>' +
                        (m.content ? '<div class="text-sm text-gray-600 mt-2 whitespace-pre-line leading-relaxed">' + esc(m.content) + '</div>' : '') +
                        (isPdf && m.file_path ? '<a href="' + esc('/media/' + String(m.file_path).replace(/^\/+/, '')) + '" target="_blank" class="inline-flex mt-3 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700">📖 Buka PDF</a>' : '') +
                        (m.external_url ? '<a href="' + esc(m.external_url) + '" target="_blank" rel="noopener" class="inline-flex items-center gap-2 mt-3 text-sm font-bold text-indigo-600">🔗 Buka Link</a>' : '') + '</div>';
                }).join('') + '</div>' : '<div class="bg-gray-50 border border-gray-200 rounded-xl p-4 mb-4 text-sm text-gray-500">Belum ada materi pada topik ini.</div>') +
                (asgs.length ? '<h5 class="text-sm font-bold text-gray-700 mb-3">📝 Aktivitas</h5><div class="space-y-3">' + asgs.map(function (a) {
                    const sub = submissionOf(a);
                    const graded = sub && String(sub.status).toLowerCase() === 'graded';
                    return '<div class="border border-purple-200 bg-purple-50 rounded-xl p-4"><p class="font-bold text-gray-800">' + esc(a.title ?? 'Aktivitas') + '</p>' +
                        (a.instructions ? '<p class="text-sm text-gray-600 mt-2 whitespace-pre-line">' + esc(a.instructions) + '</p>' : '') +
                        '<div class="flex flex-wrap gap-2 mt-3">' +
                        (a.max_score != null ? '<span class="bg-white border border-purple-200 rounded-lg px-3 py-1.5 text-xs font-bold text-purple-700">Nilai Maks: ' + esc(a.max_score) + '</span>' : '') +
                        (a.due_at ? '<span class="bg-white border border-gray-200 rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-600">Tenggat: ' + fmtDate(a.due_at) + '</span>' : '') +
                        (graded ? '<span class="bg-green-100 border border-green-200 rounded-lg px-3 py-1.5 text-xs font-bold text-green-700">✓ Sudah Dinilai</span>' : '') + '</div>' +
'<button type="button" class="open-assignment mt-4 w-full bg-purple-600 text-white px-4 py-3 rounded-lg hover:bg-purple-700 transition text-sm font-bold" data-key="' + esc(String(a.sync_id ?? a.id)) + '" data-topic-key="' + esc(String(topic.sync_id ?? topic.id)) + '">📝 ' + (String(a.assignment_type).toLowerCase() === 'cbt' ? 'Buka Ujian CBT' : 'Kerjakan Tugas') + '</button></div>';     
           }).join('') + '</div>' : '<div class="bg-gray-50 border border-gray-200 rounded-xl p-4 text-sm text-gray-500">Belum ada aktivitas pada topik ini.</div>') + '</div>';
            content.appendChild(card);
            renderMath(card);
        });
        content.querySelectorAll('.open-assignment').forEach(function (btn) {
            btn.addEventListener('click', function () {
                view.courseKey = String(course.sync_id ?? course.id);
                view.topicKey = String(btn.dataset.topicKey ?? '');
                view.assignmentKey = String(btn.dataset.key ?? '');
                const course2 = findCourse(), topic = findTopic(course2), assignment = findAssignment();
                if (course2 && topic && assignment) { renderAssignment(assignment, topic, course2); window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
                // Fallback: cari assignment langsung (topic tidak wajib untuk render)
                const a2 = assignments.find(x => sameId(x.sync_id, view.assignmentKey) || sameId(x.id, view.assignmentKey));
                if (a2) { renderAssignment(a2, { title: a2.topic_sync_id ? '' : '' }, course2); }
            });
        });
    }

    function renderAssignment(assignment, topic, course) {
        pageTitle.innerHTML = '<div class="flex items-center justify-between gap-4 flex-wrap"><div class="flex items-center gap-3"><div class="w-11 h-11 rounded-xl bg-purple-50 flex items-center justify-center text-xl">📝</div><div><h3 class="text-lg font-bold text-gray-800">' + esc(assignment.title ?? 'Aktivitas') + '</h3><p class="text-sm text-gray-500 mt-0.5">' + esc(topic.title ?? '') + '</p></div></div><button type="button" id="back-btn" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-bold hover:bg-gray-50">← Kembali</button></div>';
        document.getElementById('back-btn').addEventListener('click', function () { view.assignmentKey = null; renderCourseDetail(course); window.scrollTo({ top: 0, behavior: 'smooth' }); });
        content.innerHTML = '';
        const card = document.createElement('div');
        card.className = 'bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden';
        card.innerHTML = '<div class="bg-purple-50 border-b border-purple-100 px-5 sm:px-6 py-6"><h1 class="text-2xl sm:text-3xl font-bold text-purple-950 break-words">' + esc(assignment.title ?? 'Aktivitas') + '</h1></div><div class="p-5 sm:p-6">' +
            '<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6"><div class="bg-gray-50 border border-gray-200 rounded-xl p-4"><p class="text-xs text-gray-500">Jenis</p><p class="text-sm font-bold text-gray-800 mt-1">' + (String(assignment.assignment_type).toLowerCase() === 'cbt' ? 'CBT / Ujian' : 'Tugas') + '</p></div><div class="bg-gray-50 border border-gray-200 rounded-xl p-4"><p class="text-xs text-gray-500">Nilai Maksimal</p><p class="text-sm font-bold text-gray-800 mt-1">' + esc(assignment.max_score ?? '-') + '</p></div><div class="bg-gray-50 border border-gray-200 rounded-xl p-4"><p class="text-xs text-gray-500">Tenggat</p><p class="text-sm font-bold text-gray-800 mt-1">' + (assignment.due_at ? fmtDate(assignment.due_at) : 'Tidak dibatasi') + '</p></div></div>' +
            submissionBanner(assignment) +
            '<div class="mb-6"><h3 class="text-sm font-bold text-gray-800 mb-2">Instruksi</h3><div class="bg-gray-50 border border-gray-200 rounded-xl p-4 sm:p-5 text-sm text-gray-700 whitespace-pre-line leading-relaxed">' + esc(assignment.instructions || 'Tidak ada instruksi tambahan.') + '</div></div>' +
            (String(assignment.assignment_type).toLowerCase() === 'cbt'
                ? '<div class="bg-orange-50 border border-orange-200 rounded-2xl p-5"><h3 class="font-bold text-orange-900">Ujian CBT</h3><p id="cbt-info" class="text-sm text-orange-700 mt-1">Memeriksa paket ujian di perangkat...</p><div id="cbt-action" class="mt-4"></div></div>'
                : '<div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-5"><h3 class="font-bold text-indigo-900 mb-4">📝 Jawaban Murid</h3><textarea id="offline-answer" rows="9" class="block w-full rounded-xl border-gray-300 shadow-sm text-sm text-gray-800 resize-y" placeholder="Tulis jawaban Anda di sini..."></textarea><div id="answer-status" class="hidden mt-4 rounded-xl px-4 py-3 text-sm"></div><div class="mt-4 flex justify-end"><button type="button" id="save-answer" class="bg-indigo-600 text-white px-5 py-3 rounded-lg hover:bg-indigo-700 transition text-sm font-bold">💾 Simpan Jawaban</button></div></div>') +
            '</div>';
        content.appendChild(card);

        if (String(assignment.assignment_type).toLowerCase() === 'cbt') {
            (async function () {
                const info = document.getElementById('cbt-info'), action = document.getElementById('cbt-action');
                const examSyncId = String(assignment.exam_sync_id ?? '').trim();
                if (!examSyncId) { info.textContent = 'Aktivitas ini belum terhubung dengan paket ujian CBT.'; return; }
                const exam = await CLOUD.dbGet('cbt_exams', examSyncId);
                if (!exam) { info.textContent = 'Paket ujian belum tersedia di perangkat. Tekan "Sinkronkan" saat online.'; return; }
                const q = Array.isArray(exam.questions) ? exam.questions.length : 0;
                info.textContent = (exam.judul_ujian ?? 'Ujian') + ' · ' + q + ' soal · ' + Number(exam.durasi_menit ?? 0) + ' menit' + (exam.status_aktif === false ? ' · ujian belum diaktifkan guru' : '');
                if (q > 0 && exam.status_aktif !== false) {
                    action.innerHTML = '<a href="/siswa/lms/cbt/' + encodeURIComponent(examSyncId) + '" class="inline-flex bg-blue-600 text-white px-5 py-3 rounded-xl text-sm font-bold shadow-sm">📝 Mulai Ujian CBT</a>';
                }
            })();
        } else {
            const existing = submissionOf(assignment);
            const ta = document.getElementById('offline-answer');
            if (ta && existing && existing.answer_text) { ta.value = existing.answer_text; }
            const saveBtn = document.getElementById('save-answer');
            saveBtn.addEventListener('click', async function () {
                const status = document.getElementById('answer-status');
                const answer = (document.getElementById('offline-answer').value || '').trim();
                if (!answer) { status.textContent = 'Jawaban belum diisi.'; status.className = 'mt-4 rounded-xl px-4 py-3 text-sm bg-red-50 border border-red-200 text-red-700'; return; }
                saveBtn.disabled = true; saveBtn.textContent = '⏳ Menyimpan...';
                try {
                    const myId = String(window.EDUXID_STUDENT_LOGIN_ID ?? '').trim();
                    if (myId === '') { window.location.href = '{{ route('pwa.cloud.login') }}'; return; }
                    const now = new Date().toISOString();
                    const sub = existing || {
                        sync_id: crypto.randomUUID(), id: null,
                        assignment_id: assignment.id ?? null, assignment_sync_id: assignment.sync_id ?? null,
                        student_id: myId, student_nisn: myId,
                        created_at: now,
                    };
                    sub.answer_text = answer;
                    sub.status = 'submitted';
                    sub.submitted_at = now;
                    sub.updated_at = now;
                    sub.sync_version = Number(sub.sync_version ?? 0) + 1;
                    await CLOUD.saveSubmission(sub);
                    await loadFromDevice();
                    renderAssignment(assignment, topic, course);
                    status.textContent = navigator.onLine ? '✓ Jawaban tersimpan dan dikirim ke server.' : '✓ Jawaban tersimpan di perangkat. Akan terkirim otomatis saat internet kembali.';
                    status.className = navigator.onLine ? 'mt-4 rounded-xl px-4 py-3 text-sm bg-green-50 border border-green-200 text-green-700' : 'mt-4 rounded-xl px-4 py-3 text-sm bg-orange-50 border border-orange-200 text-orange-700';
                } catch (e) {
                    status.textContent = '⚠️ Gagal menyimpan: ' + e.message;
                    status.className = 'mt-4 rounded-xl px-4 py-3 text-sm bg-red-50 border border-red-200 text-red-700';
                } finally {
                    saveBtn.disabled = false; saveBtn.textContent = '💾 Simpan Jawaban';
                }
            });
        }
        renderMath(card);
    }

    async function refresh(preserve) {
        await loadFromDevice();
        if (view.type === 'courses' || !preserve) { renderCourseList(); return; }
        const course = findCourse();
        if (!course) { renderCourseList(); return; }
        if (view.type === 'course') { renderCourseDetail(course); return; }
        const topic = findTopic(course), assignment = findAssignment();
        if (topic && assignment) { renderAssignment(assignment, topic, course); } else { renderCourseDetail(course); }
    }

    window.addEventListener('eduxid:sync-status', async function (e) {
        const st = e.detail && e.detail.status;
        setSyncStatus(st === 'synced' ? 'synced' : st === 'error' ? 'error' : 'syncing');
        if (st === 'synced') { setLocalStatus('✓ LMS tersinkron dengan eduXid Cloud.', 'success'); const t = await CLOUD.getLastSync(); lastSync.textContent = t ? 'Terakhir: ' + fmtDate(t) : 'Belum ada sinkronisasi'; await refresh(true); }
    });

    window.addEventListener('eduxid:need-login', function () { window.location.href = '{{ route('pwa.cloud.login') }}'; });

    if (syncButton) {
        syncButton.addEventListener('click', async function () {
            syncButton.disabled = true; syncButton.textContent = '⏳ Menyinkronkan...';
            try { const r = await CLOUD.sync(); if (r && r.need_login) { window.location.href = '{{ route('pwa.cloud.login') }}'; return; } await refresh(true); setLocalStatus('✓ Sinkronisasi selesai.', 'success'); }
            catch (e) { setLocalStatus('Sinkronisasi gagal. Data lokal tetap tersedia.', 'error'); }
            finally { syncButton.disabled = false; syncButton.textContent = '🔄 Sinkronkan'; }
        });
    }

    // ===== INIT =====
    // Identitas tunggal = sesi server. Sesi Cloud wajib cocok; jika tidak,
    // paksa login ganda ulang agar nama/data tidak tercampur antar siswa.
    const serverLoginId = String(window.EDUXID_STUDENT_LOGIN_ID ?? '').trim();
    if (serverLoginId === '') {
        window.location.href = '{{ route('pwa.cloud.login') }}'; return;
    }
    const sess = await CLOUD.session();
    if (!sess || String(sess.login_id ?? '').trim() !== serverLoginId) {
        window.location.href = '{{ route('pwa.cloud.login') }}'; return;
    }
    // Nama TIDAK ditimpa dari Cloud — yang server-render adalah satu-satunya yang tampil.

    try {
        await refresh(false);
        setLocalStatus('✓ LMS dimuat dari penyimpanan perangkat (' + courses.length + ' kelas tersedia offline).', 'local');
        if (navigator.onLine) { CLOUD.sync().catch(function () {}); }
        if (navigator.onLine) { setInterval(function () { if (navigator.onLine) { CLOUD.sync().catch(function () {}); } }, 30000); }
    } catch (e) {
        content.innerHTML = '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-8 text-center"><div class="text-4xl mb-3">⚠️</div><h3 class="font-bold mb-2">Data LMS gagal dimuat</h3><p class="text-sm">' + esc(e.message ?? '') + '</p></div>';
    }
});
</script>

</x-app-layout>