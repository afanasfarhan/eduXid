<x-app-layout>

<style>
    /* ========================================================
       ANTI-CHEAT: cegah seleksi & salin teks
       ======================================================== */
    #assignment-header-card,
    #assignment-header-card * {
        -webkit-user-select: none !important;
        -moz-user-select: none !important;
        user-select: none !important;
        -webkit-touch-callout: none !important;
    }

    #answer_text {
        user-select: text !important;
        -webkit-user-select: text !important;
    }

    /* ========================================================
       WATERMARK IDENTITAS
       ======================================================== */
    .cbt-watermark {
        position: fixed;
        inset: 0;
        pointer-events: none;
        z-index: 9999;
        overflow: hidden;
    }

    .cbt-watermark span {
        position: absolute;
        font-size: 13px;
        font-weight: 800;
        color: rgba(79, 70, 229, 0.08);
        white-space: nowrap;
        transform: rotate(-28deg);
        letter-spacing: 1px;
    }
</style>

<x-slot name="header">

<div class="flex items-center justify-between gap-3 flex-wrap">

```
<div class="min-w-0">

    <p class="text-xs text-indigo-500 font-bold uppercase tracking-wide">
        Aktivitas Pembelajaran
    </p>

    <h2
        id="local-assignment-title"
        class="font-semibold text-xl text-gray-800 leading-tight mt-1 truncate"
    >
        Memuat aktivitas...
    </h2>

</div>

<button
    type="button"
    id="btn-back-topic"
    class="
        inline-flex items-center justify-center gap-2
        h-10 px-4
        bg-white
        border border-gray-300
        text-gray-700
        rounded-lg
        text-sm font-bold
        hover:bg-gray-50
        transition
        shrink-0
    "
>
    ← Kembali
</button>
```

</div>

</x-slot>

<div class="py-6 sm:py-8">

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

{{-- =========================================================
STATUS
========================================================= --}}

<div
    id="local-message"
    class="hidden mb-5 rounded-xl px-4 py-3 text-sm"
></div>

{{-- =========================================================
HEADER AKTIVITAS
========================================================= --}}

<div
    id="assignment-header-card"
    class="
        bg-white rounded-2xl border border-gray-200
        shadow-sm overflow-hidden mb-6
    "
>

```
<div
    id="assignment-header"
    class="px-5 sm:px-6 py-5 sm:py-6 bg-indigo-50"
>

    <div class="flex items-start gap-4">

        <div
            id="assignment-icon"
            class="
                w-11 h-11
                rounded-xl
                bg-indigo-600
                text-white
                flex items-center justify-center
                text-lg
                shrink-0
            "
        >
            📄
        </div>

        <div class="min-w-0 flex-1">

            <span
                id="assignment-badge"
                class="
                    inline-flex items-center
                    px-2.5 py-1
                    rounded-full
                    text-[11px]
                    font-bold
                    uppercase
                    tracking-wide
                    bg-indigo-100
                    text-indigo-700
                "
            >
                TUGAS
            </span>

            <h1
                id="assignment-title"
                class="
                    text-xl sm:text-2xl font-bold
                    text-gray-900 mt-2 break-words
                "
            >
                Memuat aktivitas...
            </h1>

            <p
                id="assignment-topic"
                class="text-sm text-gray-500 mt-1.5"
            >
                Memuat topik...
            </p>

        </div>

    </div>

</div>


{{-- =====================================================
     INFORMASI
     ===================================================== --}}

<div class="p-5 sm:p-6">

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">

        <div
            class="
                bg-gray-50 border border-gray-200
                rounded-xl p-4
            "
        >
            <p class="text-xs text-gray-500 font-medium">
                Jenis
            </p>

            <p
                id="assignment-type"
                class="text-sm font-bold text-gray-800 mt-1"
            >
                -
            </p>
        </div>


        <div
            class="
                bg-gray-50 border border-gray-200
                rounded-xl p-4
            "
        >
            <p class="text-xs text-gray-500 font-medium">
                Nilai Maksimal
            </p>

            <p
                id="assignment-max-score"
                class="text-sm font-bold text-gray-800 mt-1"
            >
                -
            </p>
        </div>


        <div
            class="
                bg-gray-50 border border-gray-200
                rounded-xl p-4
            "
        >
            <p class="text-xs text-gray-500 font-medium">
                Batas Pengumpulan
            </p>

            <p
                id="assignment-due-at"
                class="text-sm font-bold text-gray-800 mt-1"
            >
                -
            </p>
        </div>

    </div>


    {{-- =================================================
         INSTRUKSI
         ================================================= --}}

    <div class="mb-6">

        <div class="flex items-center gap-2 mb-2">

            <div
                class="
                    w-7 h-7
                    rounded-lg
                    bg-indigo-50
                    text-indigo-600
                    flex items-center justify-center
                    text-sm
                "
            >
                ℹ
            </div>

            <h3 class="text-sm font-bold text-gray-800">
                Instruksi
            </h3>

        </div>

        <div
            id="assignment-instructions"
            class="
                bg-gray-50
                border border-gray-200
                rounded-xl
                p-4 sm:p-5
                text-sm
                text-gray-700
                whitespace-pre-line
                leading-relaxed
            "
        >
            Memuat instruksi...
        </div>

    </div>


    {{-- =================================================
         CBT
         ================================================= --}}

    <div
        id="cbt-info"
        class="hidden"
    ></div>


    {{-- =================================================
         HASIL PENILAIAN
         ================================================= --}}

    <div
        id="submission-result"
        class="hidden mb-6"
    ></div>


    {{-- =================================================
         FORM TUGAS
         ================================================= --}}

    <div
        id="submission-panel"
        class="
            bg-indigo-50
            border border-indigo-200
            rounded-2xl
            p-5
        "
    >

        <div class="flex items-start gap-3">

            <div
                class="
                    w-10 h-10
                    rounded-xl
                    bg-indigo-600
                    text-white
                    flex items-center justify-center
                    text-lg
                    shrink-0
                "
            >
                📚
            </div>

            <div class="flex-1 min-w-0">

                <h3 class="font-bold text-indigo-900">
                    Pengumpulan Tugas
                </h3>

                <p
                    id="submission-description"
                    class="text-sm text-indigo-700 mt-1"
                >
                    Jawaban akan disimpan di perangkat.
                </p>


                {{-- =================================================
                     METODE
                     ================================================= --}}

                <div
                    class="
                        mt-4
                        bg-white
                        border border-indigo-100
                        rounded-xl
                        p-4
                    "
                >

                    <div
                        class="
                            flex items-center
                            justify-between
                            gap-3 flex-wrap
                        "
                    >

                        <p class="text-sm text-gray-600">
                            Metode pengumpulan
                        </p>

                        <span
                            id="submission-type"
                            class="
                                inline-flex items-center
                                px-3 py-1
                                rounded-full
                                bg-indigo-50
                                text-indigo-700
                                text-xs
                                font-bold
                            "
                        >
                            -
                        </span>

                    </div>

                </div>


                {{-- =================================================
                     FORM
                     ================================================= --}}

                <form
                    id="offline-submission-form"
                    class="mt-4"
                >

                    <div
                        id="text-answer-field"
                        class="mb-4"
                    >

                        <label
                            for="answer_text"
                            class="
                                block
                                text-sm
                                font-semibold
                                text-gray-700
                                mb-2
                            "
                        >
                            Jawaban
                        </label>

                        <textarea
                            id="answer_text"
                            rows="8"
                            class="
                                block w-full
                                rounded-xl
                                border-gray-300
                                shadow-sm
                                text-sm
                                text-gray-800
                                focus:border-indigo-500
                                focus:ring-indigo-500
                                resize-y
                            "
                            placeholder="Tulis jawaban Anda di sini..."
                        ></textarea>

                    </div>


                    {{-- =================================================
                         FILE
                         ================================================= --}}

                    <div
                        id="file-answer-field"
                        class="hidden mb-4"
                    >

                        <label
                            for="answer_file"
                            class="
                                block
                                text-sm
                                font-semibold
                                text-gray-700
                                mb-2
                            "
                        >
                            File Jawaban
                        </label>

                        <div
                            class="
                                border
                                border-dashed
                                border-gray-300
                                rounded-xl
                                p-4
                                bg-gray-50
                            "
                        >

                            <input
                                id="answer_file"
                                type="file"
                                class="
                                    block
                                    w-full
                                    text-sm
                                    text-gray-600
                                    cursor-pointer
                                "
                            >

                            <p class="text-xs text-gray-500 mt-2">
                                Pada tahap ini file belum dikirim ke server.
                                Penyimpanan file offline akan diaktifkan
                                pada tahap berikutnya.
                            </p>

                        </div>

                    </div>


                    {{-- =================================================
                         STATUS LOKAL
                         ================================================= --}}

                    <div
                        id="offline-save-status"
                        class="
                            hidden
                            mb-4
                            bg-yellow-50
                            border border-yellow-200
                            text-yellow-800
                            rounded-xl
                            px-4 py-3
                            text-sm
                        "
                    ></div>


                    {{-- =================================================
                         BUTTON
                         ================================================= --}}

                    <div
                        class="
                            pt-4
                            border-t border-indigo-100
                            flex items-center justify-end
                        "
                    >

                        <button
                            type="submit"
                            id="save-answer-button"
                            class="
                                inline-flex
                                items-center
                                justify-center
                                gap-2
                                h-10 px-5
                                bg-indigo-600
                                text-white
                                rounded-lg
                                text-sm font-bold
                                hover:bg-indigo-700
                                transition
                            "
                        >
                            💾 Simpan Jawaban
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

</div>
```

</div>

{{-- =========================================================
NAVIGASI
========================================================= --}}

<div class="flex items-center justify-between gap-3">

```
<button
    type="button"
    id="btn-back-topic-bottom"
    class="
        inline-flex items-center justify-center gap-2
        h-10 px-4
        bg-gray-100
        text-gray-700
        border border-gray-200
        rounded-lg
        text-sm font-bold
        hover:bg-gray-200
        transition
    "
>
    ← Kembali ke Topik
</button>
```

</div>

</div>

</div>

<script>

/*
|--------------------------------------------------------------------------
| IDENTITAS SISWA LMS
|--------------------------------------------------------------------------
*/

window.EDUXID_NPSN =
    @json($npsn ?? '');

const studentLoginIdFromServer =
    @json(auth()->user()->login_id ?? null);

const studentNisnFromServer =
    @json($student->nisn ?? null);

const assignmentIdFromServer =
    @json($assignment->id ?? null);

const assignmentSyncIdFromServer =
    @json($assignment->sync_id ?? null);

window.EDUXID_STUDENT_LOGIN_ID =
    studentLoginIdFromServer;

window.EDUXID_STUDENT_NISN =
    studentNisnFromServer;


(async function () {

    'use strict';


    /* ==========================================================
       IDENTITAS
       ========================================================== */

    const studentLoginId =
        studentLoginIdFromServer !== null &&
        studentLoginIdFromServer !== undefined
            ? String(studentLoginIdFromServer).trim()
            : '';


    if (!studentLoginId) {

        console.error(
            'eduXid: login_id murid tidak tersedia.'
        );

    } else {

        window.EDUXID_STUDENT_LOGIN_ID =
            studentLoginId;

    }


    /* ==========================================================
       ELEMENT
       ========================================================== */

    const titleElement =
        document.getElementById(
            'assignment-title'
        );

    const headerTitleElement =
        document.getElementById(
            'local-assignment-title'
        );

    const topicElement =
        document.getElementById(
            'assignment-topic'
        );

    const typeElement =
        document.getElementById(
            'assignment-type'
        );

    const maxScoreElement =
        document.getElementById(
            'assignment-max-score'
        );

    const dueElement =
        document.getElementById(
            'assignment-due-at'
        );

    const instructionElement =
        document.getElementById(
            'assignment-instructions'
        );

    const assignmentBadge =
        document.getElementById(
            'assignment-badge'
        );

    const assignmentIcon =
        document.getElementById(
            'assignment-icon'
        );

    const assignmentHeader =
        document.getElementById(
            'assignment-header'
        );

    const submissionTypeElement =
        document.getElementById(
            'submission-type'
        );

    const submissionDescription =
        document.getElementById(
            'submission-description'
        );

    const form =
        document.getElementById(
            'offline-submission-form'
        );

    const answerText =
        document.getElementById(
            'answer_text'
        );

    const answerFile =
        document.getElementById(
            'answer_file'
        );

    const textAnswerField =
        document.getElementById(
            'text-answer-field'
        );

    const fileAnswerField =
        document.getElementById(
            'file-answer-field'
        );

    const saveButton =
        document.getElementById(
            'save-answer-button'
        );

    const saveStatus =
        document.getElementById(
            'offline-save-status'
        );

    const resultPanel =
        document.getElementById(
            'submission-result'
        );

    const cbtInfo =
        document.getElementById(
            'cbt-info'
        );

    const localMessage =
        document.getElementById(
            'local-message'
        );


    /* ==========================================================
       LOCAL DATA
       ========================================================== */

    let assignment = null;

    let existingSubmission = null;


    /* ==========================================================
       ANTI-CHEAT
       ========================================================== */

    buildWatermark();

    blockClipboardEvents();


    function buildWatermark() {

        const label =
            'NISN ' +
            (
                studentNisnFromServer ||
                ''
            );

        const wm =
            document.createElement('div');

        wm.className =
            'cbt-watermark';

        for (
            let i = 0;
            i < 12;
            i++
        ) {

            const span =
                document.createElement('span');

            span.textContent =
                label;

            span.style.top =
                (
                    6 +
                    (i % 4) * 26
                ) + '%';

            span.style.left =
                (
                    (i % 3) * 32 -
                    8
                ) + '%';

            wm.appendChild(span);
        }

        document.body.appendChild(wm);
    }


    function blockClipboardEvents() {

        const blocked =
            [
                'copy',
                'cut',
                'contextmenu',
                'selectstart',
                'dragstart'
            ];

        blocked.forEach(
            function (evt) {

                document.addEventListener(
                    evt,
                    function (e) {

                        e.preventDefault();

                    }
                );

            }
        );
    }


    /* ==========================================================
       FUNGSI BARU:
       PILIH SUBMISSION TERBAIK
       ==========================================================
       Aturan:

       1. Harus milik siswa yang sedang login.
       2. Cocok assignment_sync_id jika tersedia.
       3. Fallback ke assignment_id.
       4. sync_version terbesar menang.
       5. Jika versi sama, graded menang.
       6. Jika masih sama, updated_at terbaru menang.
       ========================================================== */

    function findBestSubmissionForAssignment(
        submissions,
        currentAssignment,
        currentStudentLoginId
    ) {

        if (
            !Array.isArray(submissions) ||
            !currentAssignment
        ) {

            return null;
        }


        const studentId =
            String(
                currentStudentLoginId ?? ''
            ).trim();


        const assignmentSyncId =
            currentAssignment.sync_id
                ? String(
                    currentAssignment.sync_id
                )
                : '';


        const assignmentId =
            currentAssignment.id !== null &&
            currentAssignment.id !== undefined
                ? String(
                    currentAssignment.id
                )
                : '';


        /*
         * =====================================================
         * FILTER KANDIDAT
         * =====================================================
         */

        const candidates =
            submissions.filter(
                function (submission) {

                    /*
                     * Submission tanpa student_id
                     * tidak boleh digunakan oleh halaman siswa.
                     */

                    const submissionStudentId =
                        String(
                            submission.student_id ?? ''
                        ).trim();

                    if (!studentId) {

                        return false;
                    }

                    if (
                        submissionStudentId !==
                        studentId
                    ) {

                        return false;
                    }


                    /*
                     * Prioritas pencocokan:
                     * assignment_sync_id
                     */

                    if (
                        assignmentSyncId &&
                        submission.assignment_sync_id
                    ) {

                        if (
                            String(
                                submission.assignment_sync_id
                            ) ===
                            assignmentSyncId
                        ) {

                            return true;
                        }

                    }


                    /*
                     * Fallback:
                     * assignment_id
                     */

                    if (
                        assignmentId &&
                        submission.assignment_id !== null &&
                        submission.assignment_id !== undefined
                    ) {

                        if (
                            String(
                                submission.assignment_id
                            ) ===
                            assignmentId
                        ) {

                            return true;
                        }

                    }


                    return false;

                }
            );


        if (
            candidates.length === 0
        ) {

            return null;
        }


        /*
         * =====================================================
         * URUTKAN SUBMISSION
         * =====================================================
         */

        candidates.sort(
            function (a, b) {

                /*
                 * -------------------------------------------------
                 * PRIORITAS 1:
                 * sync_version terbesar
                 * -------------------------------------------------
                 */

                const versionA =
                    Number(
                        a.sync_version ?? 0
                    );

                const versionB =
                    Number(
                        b.sync_version ?? 0
                    );

                if (
                    versionA !==
                    versionB
                ) {

                    return (
                        versionB -
                        versionA
                    );
                }


                /*
                 * -------------------------------------------------
                 * PRIORITAS 2:
                 * GRADED
                 * -------------------------------------------------
                 */

                const gradedA =
                    (
                        a.status === 'graded' ||
                        (
                            a.score !== null &&
                            a.score !== undefined
                        )
                    )
                        ? 1
                        : 0;

                const gradedB =
                    (
                        b.status === 'graded' ||
                        (
                            b.score !== null &&
                            b.score !== undefined
                        )
                    )
                        ? 1
                        : 0;

                if (
                    gradedA !==
                    gradedB
                ) {

                    return (
                        gradedB -
                        gradedA
                    );
                }


                /*
                 * -------------------------------------------------
                 * PRIORITAS 3:
                 * updated_at terbaru
                 * -------------------------------------------------
                 */

                const timeA =
                    a.updated_at
                        ? new Date(
                            a.updated_at
                        ).getTime()
                        : 0;

                const timeB =
                    b.updated_at
                        ? new Date(
                            b.updated_at
                        ).getTime()
                        : 0;

                if (
                    timeA !==
                    timeB
                ) {

                    return (
                        timeB -
                        timeA
                    );
                }


                /*
                 * -------------------------------------------------
                 * PRIORITAS 4:
                 * last_synced_at terbaru
                 * -------------------------------------------------
                 */

                const syncedA =
                    a.last_synced_at
                        ? new Date(
                            a.last_synced_at
                        ).getTime()
                        : 0;

                const syncedB =
                    b.last_synced_at
                        ? new Date(
                            b.last_synced_at
                        ).getTime()
                        : 0;

                return (
                    syncedB -
                    syncedA
                );

            }
        );


        return candidates[0];

    }


    /* ==========================================================
       LOAD ASSIGNMENT
       ========================================================== */

    try {

        if (!window.eduXidLmsDb) {

            throw new Error(
                'eduXidLmsDb tidak tersedia.'
            );

        }


        await window.eduXidLmsDb.open();


        /*
         * Simpan login ID ke meta.
         */

        if (studentLoginId) {

            if (
                typeof
                window.eduXidLmsDb.saveStudentLoginId ===
                'function'
            ) {

                try {

                    await
                    window.eduXidLmsDb.saveStudentLoginId(
                        studentLoginId
                    );

                } catch (
                    identityMetaError
                ) {

                    console.warn(
                        'eduXid: gagal menyimpan student_login_id ke meta.',
                        identityMetaError
                    );

                }

            }

        }


        /* ======================================================
           CARI ASSIGNMENT
           ====================================================== */

        const assignments =
            await
            window.eduXidLmsDb.getAll(
                'assignments'
            );


        /*
         * Prioritas:
         * sync_id
         */

        assignment =
            assignments.find(
                function (item) {

                    return (
                        assignmentSyncIdFromServer &&
                        String(
                            item.sync_id
                        ) ===
                        String(
                            assignmentSyncIdFromServer
                        )
                    );

                }
            );


        /*
         * Fallback:
         * database id
         */

        if (!assignment) {

            assignment =
                assignments.find(
                    function (item) {

                        return (
                            assignmentIdFromServer !==
                                null &&
                            assignmentIdFromServer !==
                                undefined &&
                            String(
                                item.id
                            ) ===
                            String(
                                assignmentIdFromServer
                            )
                        );

                    }
                );

        }


        if (!assignment) {

            throw new Error(
                'Aktivitas belum tersedia di IndexedDB perangkat.'
            );

        }


        /* ======================================================
           CARI SUBMISSION SISWA
           ====================================================== */

        const submissions =
            await
            window.eduXidLmsDb.getAll(
                'submissions'
            );


        existingSubmission =
            findBestSubmissionForAssignment(
                submissions,
                assignment,
                studentLoginId
            );


        /*
         * ======================================================
         * PEMULIHAN IDENTITAS SUBMISSION
         * ======================================================
         */

        if (existingSubmission) {

            const currentStudentId =
                existingSubmission.student_id !== null &&
                existingSubmission.student_id !== undefined
                    ? String(
                        existingSubmission.student_id
                    ).trim()
                    : '';


            /*
             * Submission tanpa student_id
             */

            if (
                !currentStudentId &&
                studentLoginId
            ) {

                existingSubmission.student_id =
                    studentLoginId;

                existingSubmission.sync_status =
                    'pending';

                existingSubmission._sync_server_change_id =
                    null;

                existingSubmission._sync_delivery =
                    null;

                existingSubmission.last_synced_at =
                    null;

                const oldVersion =
                    Number(
                        existingSubmission.sync_version ??
                        0
                    );

                existingSubmission.sync_version =
                    Math.max(
                        1,
                        oldVersion + 1
                    );

                existingSubmission.updated_at =
                    new Date().toISOString();


                await
                window.eduXidLmsDb.put(
                    'submissions',
                    existingSubmission
                );

            }
            else if (
                currentStudentId &&
                studentLoginId &&
                currentStudentId !==
                    studentLoginId
            ) {

                console.warn(
                    'eduXid: student_id submission berbeda dari login_id akun aktif.',
                    {
                        submission_student_id:
                            currentStudentId,

                        current_login_id:
                            studentLoginId
                    }
                );

            }


            /*
             * Pastikan NISN tersedia.
             */

            if (
                !existingSubmission.student_nisn &&
                studentNisnFromServer
            ) {

                existingSubmission.student_nisn =
                    studentNisnFromServer;

                await
                window.eduXidLmsDb.put(
                    'submissions',
                    existingSubmission
                );

            }

        }


        /*
         * SIMPAN NISN KE META
         */

        if (
            studentNisnFromServer &&
            window.eduXidLmsDb &&
            typeof
            window.eduXidLmsDb.saveStudentNisn ===
                'function'
        ) {

            try {

                await
                window.eduXidLmsDb.saveStudentNisn(
                    studentNisnFromServer
                );

            } catch (metaError) {

                console.warn(
                    'eduXid: gagal menyimpan NISN murid ke meta.',
                    metaError
                );

            }

        }


        /*
         * RENDER
         */

        renderAssignment();


        /*
         * Jika submission masih pending,
         * coba sinkronisasi.
         */

        if (
            existingSubmission &&
            existingSubmission.sync_status ===
                'pending' &&
            studentLoginId &&
            navigator.onLine
        ) {

            try {

                const repairSyncResult =
                    await
                    window.eduXidLmsDb.sync();

                console.log(
                    'eduXid Submission Repair Sync:',
                    repairSyncResult
                );


                /*
                 * Ambil ulang seluruh submission
                 * dan gunakan selector yang sama.
                 */

                const refreshedSubmissions =
                    await
                    window.eduXidLmsDb.getAll(
                        'submissions'
                    );


                const refreshedSubmission =
                    findBestSubmissionForAssignment(
                        refreshedSubmissions,
                        assignment,
                        studentLoginId
                    );


                if (
                    refreshedSubmission
                ) {

                    existingSubmission =
                        refreshedSubmission;

                    renderExistingSubmission();

                }

            } catch (
                repairSyncError
            ) {

                console.warn(
                    'eduXid: repair submission sync gagal.',
                    repairSyncError
                );

            }

        }


    } catch (error) {

        console.error(
            'eduXid Offline Assignment ERROR:',
            error
        );

        showMessage(
            error.message ||
            'Aktivitas tidak dapat dimuat.',
            'error'
        );

        if (form) {

            form.style.display =
                'none';

        }

    }


    /* ==========================================================
       RENDER ASSIGNMENT
       ========================================================== */

    function renderAssignment() {

        const title =
            assignment.title ??
            'Aktivitas Pembelajaran';

        const assignmentType =
            assignment.assignment_type ??
            'assignment';

        const submissionType =
            assignment.submission_type ??
            'text';


        titleElement.textContent =
            title;

        headerTitleElement.textContent =
            title;

        topicElement.textContent =
            'Aktivitas tersimpan di perangkat';


        /*
         * ======================================================
         * CBT
         * ======================================================
         */

        if (
            assignmentType ===
            'cbt'
        ) {

            typeElement.textContent =
                'CBT / Ujian';

            assignmentBadge.textContent =
                'CBT / UJIAN';

            assignmentBadge.className =
                'inline-flex items-center px-2.5 py-1 ' +
                'rounded-full text-[11px] font-bold uppercase ' +
                'tracking-wide bg-orange-100 text-orange-700';

            assignmentIcon.textContent =
                '📝';

            assignmentIcon.className =
                'w-11 h-11 rounded-xl bg-orange-500 ' +
                'text-white flex items-center justify-center ' +
                'text-lg shrink-0';

            assignmentHeader.className =
                'px-5 sm:px-6 py-5 sm:py-6 bg-orange-50';


            renderCbtInfo();

            form.classList.add(
                'hidden'
            );

        }
        else {

            typeElement.textContent =
                'Tugas';

            assignmentBadge.textContent =
                'TUGAS';

            assignmentBadge.className =
                'inline-flex items-center px-2.5 py-1 ' +
                'rounded-full text-[11px] font-bold uppercase ' +
                'tracking-wide bg-indigo-100 text-indigo-700';

            assignmentIcon.textContent =
                '📄';

            assignmentIcon.className =
                'w-11 h-11 rounded-xl bg-indigo-600 ' +
                'text-white flex items-center justify-center ' +
                'text-lg shrink-0';

            assignmentHeader.className =
                'px-5 sm:px-6 py-5 sm:py-6 bg-indigo-50';

            cbtInfo.classList.add(
                'hidden'
            );

        }


        maxScoreElement.textContent =
            formatNumber(
                assignment.max_score
            );

        dueElement.textContent =
            assignment.due_at
                ? formatDate(
                    assignment.due_at
                )
                : 'Tidak dibatasi';


        instructionElement.textContent =
            assignment.instructions ||
            'Tidak ada instruksi tambahan.';


        submissionTypeElement.textContent =
            getSubmissionTypeLabel(
                submissionType
            );


        textAnswerField.classList.toggle(
            'hidden',
            ![
                'text',
                'text_file'
            ].includes(
                submissionType
            )
        );


        fileAnswerField.classList.toggle(
            'hidden',
            ![
                'file',
                'text_file'
            ].includes(
                submissionType
            )
        );


        if (
            existingSubmission
        ) {

            renderExistingSubmission();

        }

    }


    /* ==========================================================
       RENDER CBT INFO
       ========================================================== */

    async function renderCbtInfo() {

        cbtInfo.classList.remove(
            'hidden'
        );

        cbtInfo.innerHTML = `

            <div class="bg-orange-50 border border-orange-200
                        rounded-2xl p-5 mb-6">

                <div class="flex items-start gap-3">

                    <div class="w-10 h-10 rounded-xl
                                bg-orange-500 text-white
                                flex items-center justify-center
                                text-lg shrink-0">

                        ⏳

                    </div>

                    <div class="flex-1 min-w-0">

                        <h3 class="font-bold text-orange-900">
                            Memeriksa paket ujian...
                        </h3>

                    </div>

                </div>

            </div>

        `;


        let cbtExam =
            null;


        try {

            const examSyncId =
                assignment.exam_sync_id ??
                null;


            if (
                examSyncId
            ) {

                cbtExam =
                    await
                    window.eduXidLmsDb.get(
                        'cbt_exams',
                        examSyncId
                    );

            }


            if (
                !cbtExam &&
                assignment.exam_id !== null &&
                assignment.exam_id !== undefined
            ) {

                const allExams =
                    await
                    window.eduXidLmsDb.getAll(
                        'cbt_exams'
                    );


                cbtExam =
                    allExams.find(
                        function (e) {

                            return (
                                String(e.id) ===
                                String(
                                    assignment.exam_id
                                )
                            );

                        }
                    ) ??
                    null;

            }

        } catch (
            cbtError
        ) {

            console.warn(
                'eduXid: gagal memeriksa paket CBT.',
                cbtError
            );

        }


        /*
         * ======================================================
         * STATUS SESI
         * ======================================================
         */

        let existingSession =
            null;


        if (
            cbtExam
        ) {

            const sessions =
                await
                window.eduXidLmsDb.getAll(
                    'cbt_sessions'
                );


            existingSession =
                sessions.find(
                    function (s) {

                        return (
                            s.exam_sync_id ===
                            cbtExam.sync_id
                        );

                    }
                ) ??
                null;

        }


        /*
         * ======================================================
         * BELUM ADA PAKET CBT
         * ======================================================
         */

        if (
            !cbtExam
        ) {

            cbtInfo.innerHTML = `

                <div class="bg-orange-50 border border-orange-200
                            rounded-2xl p-5 mb-6">

                    <div class="flex items-start gap-3">

                        <div class="w-10 h-10 rounded-xl
                                    bg-orange-500 text-white
                                    flex items-center justify-center
                                    text-lg shrink-0">

                            📝

                        </div>

                        <div class="flex-1 min-w-0">

                            <h3 class="font-bold text-orange-900">
                                Ujian CBT
                            </h3>

                            <p class="text-sm text-orange-700 mt-1">
                                Soal ujian belum tersedia di perangkat ini.
                                Pastikan guru sudah menekan
                                <span class="font-semibold">
                                    "📱 Sinkronkan ke Murid"
                                </span>
                                dan tunggu sinkronisasi selesai.
                            </p>

                        </div>

                    </div>

                </div>

            `;

            return;

        }


        /*
         * ======================================================
         * SUDAH SELESAI
         * ======================================================
         */

        if (
            existingSession &&
            existingSession.status ===
                'selesai'
        ) {

            const hasScore =
                existingSession.score !== null &&
                existingSession.score !== undefined;


            const scoreText =
                hasScore
                    ? `
                        Hasil:
                        <span class="font-bold">
                            ${existingSession.score}
                        </span>
                        / 100 · Benar:
                        ${existingSession.correct_count ?? '-'}
                        dari
                        ${existingSession.total_questions ?? '-'}
                        soal
                    `
                    : `
                        Jawaban Anda sedang dikoreksi
                        di server guru. Hasil akan muncul
                        setelah tersinkronisasi.
                    `;


            cbtInfo.innerHTML = `

                <div class="bg-green-50 border border-green-200
                            rounded-2xl p-5 mb-6">

                    <div class="flex items-start gap-3">

                        <div class="w-10 h-10 rounded-xl
                                    bg-green-600 text-white
                                    flex items-center justify-center
                                    text-lg shrink-0">

                            ${hasScore ? '🏅' : '✅'}

                        </div>

                        <div class="flex-1 min-w-0">

                            <h3 class="font-bold text-green-900">
                                Ujian Selesai
                            </h3>

                            <p class="text-sm text-green-700 mt-1">
                                ${scoreText}
                            </p>

                        </div>

                    </div>

                </div>

            `;

            return;

        }


        /*
         * ======================================================
         * BELUM DIBUKA
         * ======================================================
         */

        if (
            cbtExam.status_aktif !==
            true
        ) {

            cbtInfo.innerHTML = `

                <div class="bg-gray-50 border border-gray-200
                            rounded-2xl p-5 mb-6">

                    <div class="flex items-start gap-3">

                        <div class="w-10 h-10 rounded-xl
                                    bg-gray-400 text-white
                                    flex items-center justify-center
                                    text-lg shrink-0">

                            ⏳

                        </div>

                        <div class="flex-1 min-w-0">

                            <h3 class="font-bold text-gray-800">
                                Ujian CBT
                            </h3>

                            <p class="text-sm text-gray-600 mt-1">

                                ${escapeHtml(
                                    cbtExam.judul_ujian ??
                                    'Ujian'
                                )}

                                belum dibuka oleh guru,
                                atau sudah ditutup.

                            </p>

                        </div>

                    </div>

                </div>

            `;

            return;

        }


        /*
         * ======================================================
         * CBT AKTIF
         * ======================================================
         */

        const resume =
            existingSession &&
            existingSession.status ===
                'sedang_mengerjakan';


        const soalCount =
            Array.isArray(
                cbtExam.questions
            )
                ? cbtExam.questions.length
                : 0;


        cbtInfo.innerHTML = `

            <div class="bg-orange-50 border border-orange-200
                        rounded-2xl p-5 mb-6">

                <div class="flex items-start gap-3">

                    <div class="w-10 h-10 rounded-xl
                                bg-orange-500 text-white
                                flex items-center justify-center
                                text-lg shrink-0">

                        🖥️

                    </div>

                    <div class="flex-1 min-w-0">

                        <h3 class="font-bold text-orange-900">

                            ${escapeHtml(
                                cbtExam.judul_ujian ??
                                'Ujian CBT'
                            )}

                        </h3>

                        <p class="text-sm text-orange-700 mt-1">

                            ${soalCount} soal ·
                            ${escapeHtml(
                                cbtExam.durasi_menit ??
                                30
                            )}
                            menit

                            ${
                                resume
                                    ? '· sesi Anda masih berjalan — lanjutkan'
                                    : ''
                            }

                        </p>


                        <a
                            href="/siswa/lms/cbt/${encodeURIComponent(
                                cbtExam.sync_id
                            )}"
                            class="
                                mt-4 inline-flex items-center gap-2
                                bg-orange-500 text-white
                                px-5 py-3 rounded-xl
                                hover:bg-orange-600 transition
                                text-sm font-bold shadow-sm
                            "
                        >

                            ${
                                resume
                                    ? '▶️ Lanjutkan Ujian'
                                    : '🖥️ Mulai Ujian CBT'
                            }

                        </a>

                    </div>

                </div>

            </div>

        `;

    }


    /* ==========================================================
       RENDER SUBMISSION
       ========================================================== */

    function renderExistingSubmission() {

        if (
            !existingSubmission
        ) {

            resultPanel.classList.add(
                'hidden'
            );

            return;

        }


        /*
         * Isi kembali jawaban.
         */

        if (
            existingSubmission.answer_text
        ) {

            answerText.value =
                existingSubmission.answer_text;

        }


        /*
         * STATUS LOKAL
         */

        let statusText =
            'Jawaban tersimpan di perangkat.';


        if (
            existingSubmission.sync_status ===
            'pending'
        ) {

            statusText =
                'Jawaban tersimpan dan menunggu sinkronisasi.';

        }


        if (
            existingSubmission.sync_status ===
            'synced'
        ) {

            statusText =
                'Jawaban sudah tersinkronisasi.';

        }


        saveStatus.textContent =
            statusText;

        saveStatus.classList.remove(
            'hidden'
        );


        /*
         * ======================================================
         * CEK PENILAIAN
         * ======================================================
         */

        const isGraded =
            existingSubmission.status ===
                'graded'
            ||
            (
                existingSubmission.score !==
                    null &&
                existingSubmission.score !==
                    undefined
            );


        if (
            isGraded
        ) {

            const scoreValue =
                formatNumber(
                    existingSubmission.score
                );


            const feedbackValue =
                existingSubmission.teacher_feedback
                    ? escapeHtml(
                        existingSubmission.teacher_feedback
                    )
                    : null;


            resultPanel.innerHTML = `

                <div class="
                    bg-white
                    border-2 border-green-300
                    rounded-xl p-5
                ">

                    <div class="flex items-start gap-3">

                        <div class="
                            w-10 h-10
                            rounded-xl
                            bg-green-600
                            text-white
                            flex items-center justify-center
                            text-lg shrink-0
                        ">
                            🏅
                        </div>


                        <div class="flex-1 min-w-0">

                            <p class="
                                text-xs font-bold
                                uppercase tracking-wide
                                text-green-600
                            ">
                                Sudah Dinilai Guru
                            </p>


                            <div class="
                                flex items-baseline
                                gap-2 mt-1
                            ">

                                <span class="
                                    text-3xl
                                    font-bold
                                    text-green-700
                                ">
                                    ${scoreValue}
                                </span>

                                <span class="
                                    text-sm
                                    text-gray-400
                                ">
                                    /
                                    ${formatNumber(
                                        assignment.max_score
                                    )}
                                </span>

                            </div>


                            ${
                                feedbackValue
                                    ? `

                                        <div class="
                                            mt-3
                                            bg-blue-50
                                            border border-blue-200
                                            rounded-lg p-3
                                            text-sm text-blue-800
                                            whitespace-pre-line
                                        ">

                                            <p class="
                                                text-xs font-bold
                                                uppercase tracking-wide
                                                text-blue-500 mb-1
                                            ">
                                                Feedback Guru
                                            </p>

                                            ${feedbackValue}

                                        </div>

                                    `
                                    : `

                                        <p class="
                                            text-xs
                                            text-gray-400
                                            mt-2
                                        ">
                                            Feedback belum ditulis guru.
                                        </p>

                                    `
                            }


                            <p class="
                                text-[11px]
                                text-gray-400
                                mt-3
                            ">

                                Versi penilaian:
                                v${existingSubmission.sync_version ?? '-'}

                                · Diterima:
                                ${formatDate(
                                    existingSubmission.last_synced_at
                                )}

                            </p>

                        </div>

                    </div>

                </div>

            `;

        }
        else {

            resultPanel.innerHTML = `

                <div class="
                    bg-green-50
                    border border-green-200
                    rounded-xl p-4
                ">

                    <div class="flex items-start gap-3">

                        <div class="
                            w-9 h-9
                            rounded-lg
                            bg-green-100
                            text-green-700
                            flex items-center justify-center
                            font-bold shrink-0
                        ">
                            ✓
                        </div>


                        <div>

                            <p class="
                                text-sm
                                font-bold
                                text-green-800
                            ">
                                Jawaban sudah tersimpan
                            </p>


                            <p class="
                                text-xs
                                text-green-700
                                mt-1
                            ">

                                Status:
                                ${escapeHtml(
                                    existingSubmission.sync_status ??
                                    'pending'
                                )}

                            </p>


                            <span class="
                                inline-flex
                                items-center
                                mt-2
                                px-2.5 py-1
                                rounded-full
                                bg-gray-100
                                text-gray-600
                                text-[11px]
                                font-bold
                            ">

                                ⏳ Menunggu penilaian guru

                            </span>

                        </div>

                    </div>

                </div>

            `;

        }


        resultPanel.classList.remove(
            'hidden'
        );

    }


    /* ==========================================================
       POLLING
       ========================================================== */

    setInterval(
        async function () {

            if (
                !window.eduXidLmsDb ||
                !assignment
            ) {

                return;

            }


            try {

                /*
                 * ==================================================
                 * TUGAS BIASA
                 * ==================================================
                 */

                if (
                    assignment.assignment_type !==
                    'cbt'
                ) {

                    const submissions =
                        await
                        window.eduXidLmsDb.getAll(
                            'submissions'
                        );


                    const fresh =
                        findBestSubmissionForAssignment(
                            submissions,
                            assignment,
                            studentLoginId
                        );


                    if (
                        !fresh
                    ) {

                        return;

                    }


                    const changed =
                        Number(
                            fresh.sync_version ?? 0
                        ) !==
                        Number(
                            existingSubmission?.sync_version ??
                            0
                        )

                        ||

                        String(
                            fresh.score ?? ''
                        ) !==
                        String(
                            existingSubmission?.score ??
                            ''
                        )

                        ||

                        String(
                            fresh.teacher_feedback ??
                            ''
                        ) !==
                        String(
                            existingSubmission?.teacher_feedback ??
                            ''
                        )

                        ||

                        String(
                            fresh.status ??
                            ''
                        ) !==
                        String(
                            existingSubmission?.status ??
                            ''
                        );


                    if (
                        changed
                    ) {

                        existingSubmission =
                            fresh;

                        renderExistingSubmission();

                    }

                    return;

                }


                /*
                 * ==================================================
                 * CBT
                 * ==================================================
                 */

                const examSyncId =
                    assignment.exam_sync_id ??
                    null;


                if (
                    !examSyncId
                ) {

                    return;

                }


                const cbtExam =
                    await
                    window.eduXidLmsDb.get(
                        'cbt_exams',
                        examSyncId
                    );


                if (
                    cbtExam
                ) {

                    renderCbtInfo();

                }

            } catch (
                pollError
            ) {

                /* Silent */

            }

        },
        5000
    );


    /* ==========================================================
       SUBMIT
       ========================================================== */

    form.addEventListener(
        'submit',
        async function (event) {

            event.preventDefault();


            try {

                saveButton.disabled =
                    true;

                saveButton.textContent =
                    '⏳ Menyimpan...';


                if (
                    !studentLoginId
                ) {

                    throw new Error(
                        'Login ID murid tidak ditemukan. Jawaban tidak dapat disimpan.'
                    );

                }


                const submissionType =
                    assignment.submission_type ??
                    'text';


                const answer =
                    answerText.value.trim();


                if (
                    [
                        'text',
                        'text_file'
                    ].includes(
                        submissionType
                    ) &&
                    answer === ''
                ) {

                    throw new Error(
                        'Jawaban belum diisi.'
                    );

                }


                if (
                    [
                        'file',
                        'text_file'
                    ].includes(
                        submissionType
                    ) &&
                    answerFile.files.length > 0
                ) {

                    showMessage(
                        'Jawaban teks akan disimpan. Penyimpanan file offline akan kita aktifkan pada tahap berikutnya.',
                        'info'
                    );

                }


                /*
                 * ==================================================
                 * BUAT / GUNAKAN SUBMISSION
                 * ==================================================
                 */

                const submission =
                    existingSubmission ??
                    {
                        id:
                            crypto.randomUUID(),

                        sync_id:
                            crypto.randomUUID(),

                        entity:
                            'lms_assignment_submission'
                    };


                submission.assignment_id =
                    assignment.id ??
                    null;


                submission.assignment_sync_id =
                    assignment.sync_id ??
                    assignmentSyncIdFromServer ??
                    null;


                submission.student_id =
                    studentLoginId;


                submission.student_nisn =
                    existingSubmission?.student_nisn ??
                    studentNisnFromServer ??
                    null;


                submission.answer_text =
                    answer ||
                    null;


                submission.status =
                    'submitted';


                submission.submitted_at =
                    new Date().toISOString();


                const previousVersion =
                    Number(
                        existingSubmission?.sync_version ??
                        0
                    );


                submission.sync_version =
                    Math.max(
                        1,
                        previousVersion + 1
                    );


                submission.sync_status =
                    'pending';


                submission.last_synced_at =
                    null;


                submission._sync_server_change_id =
                    null;


                submission._sync_delivery =
                    null;


                /*
                 * Jangan menghapus nilai lama
                 * jika siswa mengedit jawaban.
                 */

                submission.score =
                    existingSubmission?.score ??
                    null;


                submission.teacher_feedback =
                    existingSubmission?.teacher_feedback ??
                    null;


                submission.file_path =
                    existingSubmission?.file_path ??
                    null;


                submission.file_name =
                    existingSubmission?.file_name ??
                    null;


                submission.file_size =
                    existingSubmission?.file_size ??
                    null;


                submission.created_at =
                    existingSubmission?.created_at ??
                    new Date().toISOString();


                submission.updated_at =
                    new Date().toISOString();


                if (
                    !submission.student_id ||
                    String(
                        submission.student_id
                    ).trim() === ''
                ) {

                    throw new Error(
                        'Identitas murid tidak ditemukan.'
                    );

                }


                await
                window.eduXidLmsDb.put(
                    'submissions',
                    submission
                );


                existingSubmission =
                    submission;


                saveStatus.textContent =
                    '✓ Jawaban tersimpan di perangkat.';

                saveStatus.classList.remove(
                    'hidden'
                );


                resultPanel.innerHTML = `

                    <div class="
                        bg-green-50
                        border border-green-200
                        rounded-xl p-4
                    ">

                        <div class="flex items-start gap-3">

                            <div class="
                                w-9 h-9
                                rounded-lg
                                bg-green-100
                                text-green-700
                                flex items-center justify-center
                                font-bold shrink-0
                            ">
                                ✓
                            </div>


                            <div>

                                <p class="
                                    text-sm
                                    font-bold
                                    text-green-800
                                ">
                                    Jawaban tersimpan
                                </p>


                                <p class="
                                    text-xs
                                    text-green-700
                                    mt-1
                                ">
                                    Versi jawaban:
                                    ${submission.sync_version}
                                </p>


                                <span class="
                                    inline-flex
                                    items-center
                                    mt-3
                                    px-2.5 py-1
                                    rounded-full
                                    bg-yellow-100
                                    text-yellow-700
                                    text-[11px]
                                    font-bold
                                ">
                                    Menunggu sinkronisasi
                                </span>

                            </div>

                        </div>

                    </div>

                `;

                resultPanel.classList.remove(
                    'hidden'
                );


                /*
                 * ==================================================
                 * OFFLINE
                 * ==================================================
                 */

                if (
                    !navigator.onLine
                ) {

                    saveButton.textContent =
                        '✓ Tersimpan';

                    saveStatus.textContent =
                        '✓ Jawaban tersimpan di perangkat. Akan dikirim otomatis saat internet tersedia.';

                    return;

                }


                /*
                 * ==================================================
                 * ONLINE → SYNC
                 * ==================================================
                 */

                saveButton.textContent =
                    '⏳ Mengirim...';


                saveStatus.textContent =
                    '✓ Jawaban tersimpan. Sedang dikirim ke server...';


                try {

                    const syncResult =
                        await
                        window.eduXidLmsDb.sync();


                    console.log(
                        'eduXid Submission Sync:',
                        syncResult
                    );


                    const pushFailed =
                        syncResult?.push?.success ===
                        false;


                    if (
                        pushFailed
                    ) {

                        saveButton.textContent =
                            '✓ Tersimpan';

                        saveStatus.textContent =
                            '✓ Jawaban tersimpan di perangkat. Menunggu sinkronisasi berikutnya.';

                    }
                    else {

                        /*
                         * Ambil submission terbaik setelah sync.
                         */

                        const updatedSubmissions =
                            await
                            window.eduXidLmsDb.getAll(
                                'submissions'
                            );


                        const updatedSubmission =
                            findBestSubmissionForAssignment(
                                updatedSubmissions,
                                assignment,
                                studentLoginId
                            );


                        if (
                            updatedSubmission
                        ) {

                            existingSubmission =
                                updatedSubmission;

                        }


                        saveButton.textContent =
                            '✓ Terkirim';


                        saveStatus.textContent =
                            '✓ Jawaban berhasil disimpan dan dikirim untuk sinkronisasi.';


                        resultPanel.innerHTML = `

                            <div class="
                                bg-green-50
                                border border-green-200
                                rounded-xl p-4
                            ">

                                <div class="flex items-start gap-3">

                                    <div class="
                                        w-9 h-9
                                        rounded-lg
                                        bg-green-100
                                        text-green-700
                                        flex items-center justify-center
                                        font-bold shrink-0
                                    ">
                                        ✓
                                    </div>


                                    <div>

                                        <p class="
                                            text-sm
                                            font-bold
                                            text-green-800
                                        ">
                                            Jawaban berhasil dikirim
                                        </p>


                                        <p class="
                                            text-xs
                                            text-green-700
                                            mt-1
                                        ">
                                            Versi jawaban:
                                            ${existingSubmission?.sync_version ??
                                              submission.sync_version}
                                        </p>


                                        <span class="
                                            inline-flex
                                            items-center
                                            mt-3
                                            px-2.5 py-1
                                            rounded-full
                                            bg-green-100
                                            text-green-700
                                            text-[11px]
                                            font-bold
                                        ">
                                            Sinkronisasi berhasil
                                        </span>

                                    </div>

                                </div>

                            </div>

                        `;

                    }


                } catch (
                    syncError
                ) {

                    console.warn(
                        'eduXid Submission Sync gagal:',
                        syncError
                    );


                    saveButton.textContent =
                        '✓ Tersimpan';


                    saveStatus.textContent =
                        '✓ Jawaban tersimpan di perangkat. Akan dikirim kembali saat koneksi tersedia.';


                    resultPanel.innerHTML = `

                        <div class="
                            bg-yellow-50
                            border border-yellow-200
                            rounded-xl p-4
                        ">

                            <div class="flex items-start gap-3">

                                <div class="
                                    w-9 h-9
                                    rounded-lg
                                    bg-yellow-100
                                    text-yellow-700
                                    flex items-center justify-center
                                    font-bold shrink-0
                                ">
                                    ⟳
                                </div>


                                <div>

                                    <p class="
                                        text-sm
                                        font-bold
                                        text-yellow-800
                                    ">
                                        Jawaban tersimpan
                                    </p>


                                    <p class="
                                        text-xs
                                        text-yellow-700
                                        mt-1
                                    ">
                                        Sinkronisasi belum berhasil.
                                        Jawaban tetap aman di perangkat
                                        dan akan dikirim kembali.
                                    </p>


                                    <span class="
                                        inline-flex
                                        items-center
                                        mt-3
                                        px-2.5 py-1
                                        rounded-full
                                        bg-yellow-100
                                        text-yellow-700
                                        text-[11px]
                                        font-bold
                                    ">
                                        Menunggu sinkronisasi
                                    </span>

                                </div>

                            </div>

                        </div>

                    `;

                }


                /*
                 * Render kembali berdasarkan
                 * submission terbaik.
                 */

                renderExistingSubmission();


            } catch (
                error
            ) {

                console.error(
                    'Offline submission ERROR:',
                    error
                );


                showMessage(
                    error.message ||
                    'Jawaban gagal disimpan.',
                    'error'
                );


                saveButton.textContent =
                    '💾 Simpan Jawaban';


            } finally {

                saveButton.disabled =
                    false;

            }

        }
    );


    /* ==========================================================
       AUTO SYNC SAAT ONLINE KEMBALI
       ========================================================== */

    window.addEventListener(
        'online',
        async function () {

            if (
                !window.eduXidLmsDb
            ) {

                return;

            }


            try {

                saveStatus.textContent =
                    '⟳ Koneksi kembali. Memeriksa sinkronisasi...';

                saveStatus.classList.remove(
                    'hidden'
                );


                await
                window.eduXidLmsDb.sync();


                if (
                    assignment.assignment_type ===
                    'cbt'
                ) {

                    renderCbtInfo();

                }
                else {

                    const submissions =
                        await
                        window.eduXidLmsDb.getAll(
                            'submissions'
                        );


                    const latestSubmission =
                        findBestSubmissionForAssignment(
                            submissions,
                            assignment,
                            studentLoginId
                        );


                    if (
                        latestSubmission
                    ) {

                        existingSubmission =
                            latestSubmission;

                        renderExistingSubmission();

                    }

                }

            } catch (
                error
            ) {

                console.warn(
                    'eduXid Auto Sync gagal:',
                    error
                );

            }

        }
    );


    /* ==========================================================
       OFFLINE EVENT
       ========================================================== */

    window.addEventListener(
        'offline',
        function () {

            if (
                existingSubmission?.sync_status ===
                'pending'
            ) {

                saveStatus.textContent =
                    '✓ Jawaban tersimpan di perangkat. Internet terputus, sinkronisasi akan dilanjutkan saat online.';

                saveStatus.classList.remove(
                    'hidden'
                );

            }

        }
    );


    /* ==========================================================
       NAVIGASI
       ========================================================== */

    function goBack() {

        window.history.back();

    }


    document
        .getElementById(
            'btn-back-topic'
        )
        .addEventListener(
            'click',
            goBack
        );


    document
        .getElementById(
            'btn-back-topic-bottom'
        )
        .addEventListener(
            'click',
            goBack
        );


    /* ==========================================================
       MESSAGE
       ========================================================== */

    function showMessage(
        message,
        type
    ) {

        localMessage.textContent =
            message;


        localMessage.className =
            'mb-5 rounded-xl px-4 py-3 text-sm';


        if (
            type ===
            'success'
        ) {

            localMessage.classList.add(
                'bg-green-50',
                'border',
                'border-green-200',
                'text-green-800'
            );

        }
        else if (
            type ===
            'error'
        ) {

            localMessage.classList.add(
                'bg-red-50',
                'border',
                'border-red-200',
                'text-red-800'
            );

        }
        else {

            localMessage.classList.add(
                'bg-blue-50',
                'border',
                'border-blue-200',
                'text-blue-800'
            );

        }


        localMessage.classList.remove(
            'hidden'
        );

    }


    /* ==========================================================
       UTIL
       ========================================================== */

    function getSubmissionTypeLabel(
        type
    ) {

        switch (
            type
        ) {

            case 'text':
                return 'Teks';

            case 'file':
                return 'File';

            case 'text_file':
                return 'Teks / File';

            default:
                return type ||
                    'Teks';

        }

    }


    function formatNumber(
        value
    ) {

        if (
            value === null ||
            value === undefined ||
            value === ''
        ) {

            return '-';

        }


        return new Intl.NumberFormat(
            'id-ID',
            {
                maximumFractionDigits:
                    2
            }
        ).format(
            Number(value)
        );

    }


    function formatDate(
        value
    ) {

        if (
            !value
        ) {

            return 'Tidak dibatasi';

        }


        const date =
            new Date(value);


        if (
            Number.isNaN(
                date.getTime()
            )
        ) {

            return String(
                value
            );

        }


        return date.toLocaleString(
            'id-ID',
            {
                dateStyle:
                    'medium',

                timeStyle:
                    'short'
            }
        );

    }


    function escapeHtml(
        value
    ) {

        return String(
            value
        )
            .replaceAll(
                '&',
                '&amp;'
            )
            .replaceAll(
                '<',
                '&lt;'
            )
            .replaceAll(
                '>',
                '&gt;'
            )
            .replaceAll(
                '"',
                '&quot;'
            )
            .replaceAll(
                "'",
                '&#039;'
            );

    }


})();
</script>

</x-app-layout>
