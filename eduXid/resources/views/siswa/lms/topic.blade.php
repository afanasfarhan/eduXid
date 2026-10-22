<x-app-layout>

    {{-- ================================================================
         HEADER
         ================================================================ --}}
    <x-slot name="header">

        <div class="flex items-center justify-between gap-4 flex-wrap">

            <div class="min-w-0">

                <p
                    id="topic-course-name"
                    class="text-xs text-indigo-500 font-bold uppercase tracking-wide"
                >
                    Kelas
                </p>

                <h2
                    id="topic-title"
                    class="text-xl md:text-2xl font-bold text-gray-800 break-words"
                >
                    Materi Pembelajaran
                </h2>

            </div>

            <a
                href="{{ route('siswa.lms.index') }}"
                class="inline-flex items-center gap-2
                       bg-white border border-gray-200
                       text-gray-600
                       px-4 py-2.5
                       rounded-xl
                       text-sm font-semibold
                       hover:bg-gray-50
                       transition
                       shadow-sm"
            >
                ← Kembali
            </a>

        </div>

    </x-slot>


    {{-- ================================================================
         MAIN
         ================================================================ --}}
    <div class="py-6">

        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

            {{-- ========================================================
                 TOPIC PAGE
                 ======================================================== --}}
            <div
                id="topic-page"
                data-topic-id="{{ $topic->id }}"
                data-topic-sync-id="{{ $topic->sync_id }}"
                data-course-id="{{ $topic->course_id }}"
                data-course-sync-id="{{ $topic->course_sync_id }}"
            >

                {{-- ====================================================
                     STATUS BAR
                     ==================================================== --}}
                <div
                    id="topic-status"
                    class="mb-5 bg-white border border-gray-200
                           rounded-2xl shadow-sm px-4 py-3"
                >

                    <div class="flex items-center justify-between gap-4 flex-wrap">

                        <div class="flex items-center gap-3 min-w-0">

                            <div
                                id="topic-status-icon"
                                class="w-9 h-9 rounded-xl
                                       bg-indigo-50
                                       flex items-center justify-center
                                       shrink-0"
                            >
                                📚
                            </div>

                            <div class="min-w-0">

                                <p
                                    id="topic-status-text"
                                    class="text-sm font-semibold text-gray-700"
                                >
                                    Memuat LMS...
                                </p>

                                <p
                                    id="topic-status-detail"
                                    class="text-xs text-gray-400 mt-0.5"
                                >
                                    Menyiapkan data pembelajaran dari perangkat.
                                </p>

                            </div>

                        </div>

                        <div class="flex items-center gap-4 text-xs text-gray-400">

                            <span>
                                Materi:
                                <strong
                                    id="topic-material-count"
                                    class="text-gray-600"
                                >
                                    0
                                </strong>
                            </span>

                            <span>
                                Tugas:
                                <strong
                                    id="topic-assignment-count"
                                    class="text-gray-600"
                                >
                                    0
                                </strong>
                            </span>

                        </div>

                    </div>

                </div>


                {{-- ====================================================
                     LOADING
                     ==================================================== --}}
                <div
                    id="topic-loading"
                    class="bg-white border border-gray-200
                           rounded-2xl shadow-sm
                           p-10 text-center"
                >

                    <div
                        class="w-12 h-12 mx-auto mb-4
                               rounded-full
                               border-4 border-gray-200
                               border-t-indigo-500
                               animate-spin"
                    ></div>

                    <h3 class="text-lg font-bold text-gray-800">
                        Memuat materi...
                    </h3>

                    <p class="text-sm text-gray-500 mt-2">
                        Data LMS sedang dibaca dari perangkat.
                    </p>

                </div>


                {{-- ====================================================
                     ERROR
                     ==================================================== --}}
                <div
                    id="topic-error"
                    class="hidden bg-red-50
                           border border-red-200
                           rounded-2xl
                           p-6"
                >

                    <div class="flex items-start gap-3">

                        <div class="text-2xl">
                            ⚠️
                        </div>

                        <div>

                            <h3 class="font-bold text-red-800">
                                Materi tidak dapat dibuka
                            </h3>

                            <p
                                id="topic-error-text"
                                class="text-sm text-red-700 mt-1"
                            >
                                Terjadi kesalahan saat memuat data LMS.
                            </p>

                        </div>

                    </div>

                </div>


                {{-- ====================================================
                     CONTENT
                     ==================================================== --}}
                <div
                    id="topic-content"
                    class="hidden space-y-6"
                ></div>

            </div>

        </div>

    </div>


    {{-- ================================================================
         SCRIPT
         ================================================================ --}}
    <script>

        document.addEventListener(
            'DOMContentLoaded',
            function () {

                'use strict';


                /* ======================================================
                   ELEMENTS
                   ====================================================== */

                const page =
                    document.getElementById(
                        'topic-page'
                    );

                const courseName =
                    document.getElementById(
                        'topic-course-name'
                    );

                const topicTitle =
                    document.getElementById(
                        'topic-title'
                    );

                const statusIcon =
                    document.getElementById(
                        'topic-status-icon'
                    );

                const statusText =
                    document.getElementById(
                        'topic-status-text'
                    );

                const statusDetail =
                    document.getElementById(
                        'topic-status-detail'
                    );

                const materialCount =
                    document.getElementById(
                        'topic-material-count'
                    );

                const assignmentCount =
                    document.getElementById(
                        'topic-assignment-count'
                    );

                const loading =
                    document.getElementById(
                        'topic-loading'
                    );

                const errorBox =
                    document.getElementById(
                        'topic-error'
                    );

                const errorText =
                    document.getElementById(
                        'topic-error-text'
                    );

                const content =
                    document.getElementById(
                        'topic-content'
                    );


                /* ======================================================
                   PAGE DATA
                   ====================================================== */

                if (!page) {
                    return;
                }


                const topicId =
                    page.dataset.topicId ||
                    null;

                const topicSyncId =
                    page.dataset.topicSyncId ||
                    null;

                const courseId =
                    page.dataset.courseId ||
                    null;

                const courseSyncId =
                    page.dataset.courseSyncId ||
                    null;


                console.log(
                    '[eduXid] TOPIC PAGE:',
                    {
                        topicId,
                        topicSyncId,
                        courseId,
                        courseSyncId
                    }
                );


                /* ======================================================
                   HELPERS
                   ====================================================== */

                function escapeHtml(
                    value
                ) {

                    if (
                        value === null ||
                        value === undefined
                    ) {
                        return '';
                    }

                    const div =
                        document.createElement(
                            'div'
                        );

                    div.textContent =
                        String(value);

                    return div.innerHTML;
                }


                function escapeAttribute(
                    value
                ) {

                    return escapeHtml(
                        value
                    )
                        .replace(
                            /"/g,
                            '&quot;'
                        )
                        .replace(
                            /'/g,
                            '&#039;'
                        );
                }


                function formatFileSize(
                    bytes
                ) {

                    const size =
                        Number(bytes || 0);

                    if (
                        !size ||
                        size < 1
                    ) {
                        return '';
                    }

                    if (
                        size < 1024
                    ) {
                        return size + ' B';
                    }

                    if (
                        size < 1024 * 1024
                    ) {
                        return (
                            (size / 1024)
                                .toFixed(1)
                        ) + ' KB';
                    }

                    if (
                        size < 1024 * 1024 * 1024
                    ) {
                        return (
                            (size / (1024 * 1024))
                                .toFixed(1)
                        ) + ' MB';
                    }

                    return (
                        (size / (1024 * 1024 * 1024))
                            .toFixed(1)
                    ) + ' GB';
                }


                function buildLocalFileUrl(
                    filePath
                ) {

                    if (
                        !filePath
                    ) {
                        return '';
                    }

                    let path =
                        String(
                            filePath
                        )
                            .trim();

                    if (
                        path === ''
                    ) {
                        return '';
                    }

                    /*
                     * Jika backend sudah mengirim URL lengkap,
                     * gunakan langsung.
                     */
                    if (
                        /^https?:\/\//i.test(
                            path
                        )
                    ) {
                        return path;
                    }

                    /*
                     * Jika sudah diawali /storage/,
                     * jangan tambahkan lagi.
                     */
                    if (path.startsWith('/media/')) {
                        return path;
                    }
                    if (path.startsWith('/storage/')) {
                        return '/media/' + path.substring('/storage/'.length);
                    }

                    /*
                     * Bersihkan slash awal.
                     */
                    path =
                        path.replace(
                            /^\/+/,
                            ''
                        );

                    return (
                        '/media/' +
                        path
                    );
                }


                function isPublished(
                    record
                ) {

                    if (
                        !record
                    ) {
                        return false;
                    }

                    return (
                        record.is_published === true ||
                        record.is_published === 1 ||
                        record.is_published === '1' ||
                        record.is_published === 'true'
                    );
                }


                function normalizeType(
                    material
                ) {

                    const type =
                        String(
                            material?.type ||
                            ''
                        )
                            .trim()
                            .toLowerCase();

                    if (
                        type === 'pdf' ||
                        type === 'application/pdf'
                    ) {
                        return 'pdf';
                    }

                    if (
                        type === 'image' ||
                        type.startsWith('image/')
                    ) {
                        return 'image';
                    }

                    if (
                        type === 'video' ||
                        type.startsWith('video/')
                    ) {
                        return 'video';
                    }

                    if (
                        type === 'audio' ||
                        type.startsWith('audio/')
                    ) {
                        return 'audio';
                    }

                    if (
                        type === 'link' ||
                        type === 'url'
                    ) {
                        return 'link';
                    }

                    if (
                        type === 'file' ||
                        type === 'document'
                    ) {
                        return 'file';
                    }

                    return 'text';
                }


                function getMaterialIcon(
                    material
                ) {

                    const type =
                        normalizeType(
                            material
                        );

                    if (
                        type === 'pdf'
                    ) {
                        return '📄';
                    }

                    if (
                        type === 'image'
                    ) {
                        return '🖼️';
                    }

                    if (
                        type === 'video'
                    ) {
                        return '▶️';
                    }

                    if (
                        type === 'audio'
                    ) {
                        return '🎧';
                    }

                    if (
                        type === 'link'
                    ) {
                        return '🔗';
                    }

                    if (
                        type === 'file'
                    ) {
                        return '📎';
                    }

                    return '📖';
                }


                function getMaterialTypeLabel(
                    material
                ) {

                    const type =
                        normalizeType(
                            material
                        );

                    const labels = {
                        text: 'Materi',
                        pdf: 'PDF',
                        image: 'Gambar',
                        video: 'Video',
                        audio: 'Audio',
                        link: 'Tautan',
                        file: 'File'
                    };

                    return (
                        labels[type] ||
                        'Materi'
                    );
                }


                /* ======================================================
                   RICH TEXT SANITIZER
                   ====================================================== */

                function sanitizeRichHtml(
                    html
                ) {

                    if (
                        !html
                    ) {
                        return '';
                    }

                    const wrapper =
                        document.createElement(
                            'div'
                        );

                    wrapper.innerHTML =
                        String(
                            html
                        );


                    const dangerousTags = [
                        'script',
                        'style',
                        'iframe',
                        'object',
                        'embed',
                        'form',
                        'input',
                        'button',
                        'textarea',
                        'select',
                        'option',
                        'meta',
                        'link',
                        'base'
                    ];


                    dangerousTags.forEach(
                        function (
                            tag
                        ) {

                            wrapper
                                .querySelectorAll(
                                    tag
                                )
                                .forEach(
                                    function (
                                        element
                                    ) {
                                        element.remove();
                                    }
                                );

                        }
                    );


                    wrapper
                        .querySelectorAll('*')
                        .forEach(
                            function (
                                element
                            ) {

                                Array.from(
                                    element.attributes
                                ).forEach(
                                    function (
                                        attribute
                                    ) {

                                        const name =
                                            attribute.name
                                                .toLowerCase();

                                        const value =
                                            attribute.value ||
                                            '';


                                        /*
                                         * Hapus event handler.
                                         */
                                        if (
                                            name.startsWith(
                                                'on'
                                            )
                                        ) {
                                            element.removeAttribute(
                                                attribute.name
                                            );

                                            return;
                                        }


                                        /*
                                         * Bersihkan URL berbahaya.
                                         */
                                        if (
                                            name === 'href' ||
                                            name === 'src' ||
                                            name === 'action' ||
                                            name === 'formaction'
                                        ) {

                                            const normalized =
                                                value
                                                    .trim()
                                                    .toLowerCase();

                                            if (
                                                normalized.startsWith(
                                                    'javascript:'
                                                ) ||
                                                normalized.startsWith(
                                                    'vbscript:'
                                                ) ||
                                                normalized.startsWith(
                                                    'data:text/html'
                                                )
                                            ) {

                                                element.removeAttribute(
                                                    attribute.name
                                                );

                                            }

                                        }

                                    }
                                );


                                /*
                                 * Bersihkan style inline
                                 * yang berpotensi membawa URL.
                                 */
                                if (
                                    element.hasAttribute(
                                        'style'
                                    )
                                ) {

                                    const style =
                                        element.getAttribute(
                                            'style'
                                        ) || '';

                                    if (
                                        /url\s*\(/i.test(
                                            style
                                        )
                                    ) {
                                        element.removeAttribute(
                                            'style'
                                        );
                                    }

                                }


                                /*
                                 * Link eksternal dibuka
                                 * pada tab baru.
                                 */
                                if (
                                    element.tagName === 'A'
                                ) {

                                    element.setAttribute(
                                        'target',
                                        '_blank'
                                    );

                                    element.setAttribute(
                                        'rel',
                                        'noopener noreferrer'
                                    );

                                }

                            }
                        );


                    return wrapper.innerHTML;
                }


                /* ======================================================
                   KATEX
                   ====================================================== */

                function renderKatex(
                    element
                ) {

                    if (
                        !element
                    ) {
                        return;
                    }

                    if (
                        typeof window.renderMathInElement !==
                        'function'
                    ) {
                        return;
                    }

                    try {

                        window.renderMathInElement(
                            element,
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
                    catch (
                        error
                    ) {

                        console.warn(
                            '[eduXid] KaTeX render error:',
                            error
                        );

                    }

                }


                /* ======================================================
                   STATUS
                   ====================================================== */

                function setStatus(
                    message,
                    detail,
                    icon
                ) {

                    if (
                        statusText
                    ) {
                        statusText.textContent =
                            message || '';
                    }

                    if (
                        statusDetail
                    ) {
                        statusDetail.textContent =
                            detail || '';
                    }

                    if (
                        statusIcon
                    ) {
                        statusIcon.textContent =
                            icon || '📚';
                    }

                }


                function showError(
                    message
                ) {

                    if (
                        loading
                    ) {
                        loading.classList.add(
                            'hidden'
                        );
                    }

                    if (
                        content
                    ) {
                        content.classList.add(
                            'hidden'
                        );
                    }

                    if (
                        errorBox
                    ) {
                        errorBox.classList.remove(
                            'hidden'
                        );
                    }

                    if (
                        errorText
                    ) {
                        errorText.textContent =
                            message ||
                            'Terjadi kesalahan.';
                    }

                    setStatus(
                        'Materi tidak dapat dimuat',
                        'Silakan coba sinkronkan LMS kembali.',
                        '⚠️'
                    );

                }


                /* ======================================================
                   FIND LOCAL TOPIC
                   ====================================================== */

                async function findLocalTopic(
                    db,
                    id,
                    syncId
                ) {

                    if (
                        !db
                    ) {
                        return null;
                    }


                    /*
                     * Fungsi helper yang mungkin
                     * tersedia pada implementasi IndexedDB.
                     */
                    if (
                        typeof db.getTopicBySyncId ===
                        'function' &&
                        syncId
                    ) {

                        try {

                            const result =
                                await db.getTopicBySyncId(
                                    syncId
                                );

                            if (
                                result
                            ) {
                                return result;
                            }

                        }
                        catch (
                            error
                        ) {

                            console.warn(
                                '[eduXid] getTopicBySyncId error:',
                                error
                            );

                        }

                    }


                    if (
                        typeof db.getTopic ===
                        'function'
                    ) {

                        try {

                            const result =
                                await db.getTopic(
                                    id ||
                                    syncId
                                );

                            if (
                                result
                            ) {
                                return result;
                            }

                        }
                        catch (
                            error
                        ) {

                            console.warn(
                                '[eduXid] getTopic error:',
                                error
                            );

                        }

                    }


                    return null;
                }


                /* ======================================================
                   GENERIC DB READ
                   ====================================================== */

                async function getAllFromDb(
                    db,
                    methodNames
                ) {

                    for (
                        const methodName of
                        methodNames
                    ) {

                        if (
                            typeof db[methodName] !==
                            'function'
                        ) {
                            continue;
                        }

                        try {

                            const result =
                                await db[methodName]();

                            if (
                                Array.isArray(
                                    result
                                )
                            ) {
                                return result;
                            }

                            if (
                                result &&
                                Array.isArray(
                                    result.data
                                )
                            ) {
                                return result.data;
                            }

                        }
                        catch (
                            error
                        ) {

                            console.warn(
                                '[eduXid] DB read error:',
                                methodName,
                                error
                            );

                        }

                    }

                    return [];
                }


                /* ======================================================
                   TOPIC RELATION
                   ====================================================== */

                function belongsToTopic(
                    record,
                    topic
                ) {

                    if (
                        !record
                    ) {
                        return false;
                    }

                    const recordTopicId =
                        record.topic_id;

                    const recordTopicSyncId =
                        record.topic_sync_id;


                    const localTopicId =
                        topic?.id ??
                        topicId;

                    const localTopicSyncId =
                        topic?.sync_id ??
                        topicSyncId;


                    const byId =
                        recordTopicId !== null &&
                        recordTopicId !== undefined &&
                        localTopicId !== null &&
                        localTopicId !== undefined &&
                        String(
                            recordTopicId
                        ) ===
                        String(
                            localTopicId
                        );


                    const bySyncId =
                        recordTopicSyncId !== null &&
                        recordTopicSyncId !== undefined &&
                        localTopicSyncId !== null &&
                        localTopicSyncId !== undefined &&
                        String(
                            recordTopicSyncId
                        ) ===
                        String(
                            localTopicSyncId
                        );


                    return (
                        byId ||
                        bySyncId
                    );
                }


                /* ======================================================
                   RENDER PDF
                   ====================================================== */

                function renderPdfMaterial(
                    material
                ) {

                    const filePath =
                        material.file_path ||
                        '';

                    const fileUrl =
                        buildLocalFileUrl(
                            filePath
                        );

                    const fileName =
                        material.file_name ||
                        'Dokumen PDF';

                    const fileSize =
                        formatFileSize(
                            material.file_size
                        );


                    if (
                        !fileUrl
                    ) {

                        return `
                            <div class="
                                mt-4
                                rounded-xl
                                border border-amber-200
                                bg-amber-50
                                p-5
                            ">

                                <div class="
                                    flex
                                    items-start
                                    gap-3
                                ">

                                    <div class="text-2xl">
                                        ⚠️
                                    </div>

                                    <div>

                                        <p class="
                                            font-bold
                                            text-amber-900
                                        ">
                                            File PDF belum tersedia
                                        </p>

                                        <p class="
                                            text-sm
                                            text-amber-800
                                            mt-1
                                        ">
                                            File materi belum tersimpan
                                            pada perangkat murid.
                                        </p>

                                    </div>

                                </div>

                            </div>
                        `;
                    }


                    return `
                        <div class="
                            mt-4
                            rounded-2xl
                            border border-gray-200
                            bg-gray-50
                            overflow-hidden
                        ">

                            <div class="
                                px-4 py-4
                                bg-white
                                border-b border-gray-200
                                flex
                                items-center
                                justify-between
                                gap-4
                                flex-wrap
                            ">

                                <div class="
                                    flex
                                    items-center
                                    gap-3
                                    min-w-0
                                ">

                                    <div class="
                                        w-11 h-11
                                        rounded-xl
                                        bg-red-50
                                        text-red-600
                                        flex
                                        items-center
                                        justify-center
                                        text-xl
                                        shrink-0
                                    ">
                                        📄
                                    </div>

                                    <div class="min-w-0">

                                        <p class="
                                            text-sm
                                            font-bold
                                            text-gray-800
                                            truncate
                                        ">
                                            ${escapeHtml(
                                                fileName
                                            )}
                                        </p>

                                        <p class="
                                            text-xs
                                            text-gray-400
                                            mt-0.5
                                        ">
                                            PDF
                                            ${
                                                fileSize
                                                    ? ' • ' +
                                                      escapeHtml(
                                                          fileSize
                                                      )
                                                    : ''
                                            }
                                        </p>

                                    </div>

                                </div>


                                <a
                                    href="${escapeAttribute(fileUrl)}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="
                                        inline-flex
                                        items-center
                                        justify-center
                                        gap-2
                                        bg-indigo-600
                                        text-white
                                        px-5 py-3
                                        rounded-xl
                                        text-sm
                                        font-bold
                                        shadow-sm
                                        hover:bg-indigo-700
                                        transition
                                        shrink-0
                                    "
                                >
                                    📖
                                    Buka Materi
                                </a>

                            </div>


                            <div class="
                                p-4
                                bg-gray-50
                            ">

                                <div class="
                                    rounded-xl
                                    border border-dashed
                                    border-gray-300
                                    bg-white
                                    px-5 py-8
                                    text-center
                                ">

                                    <div class="
                                        text-5xl
                                        mb-3
                                    ">
                                        📄
                                    </div>

                                    <p class="
                                        text-sm
                                        font-semibold
                                        text-gray-700
                                    ">
                                        Materi PDF siap dibaca
                                    </p>

                                    <p class="
                                        text-xs
                                        text-gray-400
                                        mt-1
                                        max-w-md
                                        mx-auto
                                    ">
                                        Tekan tombol
                                        <strong>
                                            Buka Materi
                                        </strong>
                                        untuk membuka dokumen PDF
                                        pada browser perangkat.
                                    </p>

                                </div>

                            </div>

                        </div>
                    `;
                }


                /* ======================================================
                   RENDER IMAGE
                   ====================================================== */

                function renderImageMaterial(
                    material
                ) {

                    const fileUrl =
                        buildLocalFileUrl(
                            material.file_path
                        );


                    if (
                        !fileUrl
                    ) {

                        return `
                            <div class="
                                mt-4
                                rounded-xl
                                border border-amber-200
                                bg-amber-50
                                p-5
                                text-sm
                                text-amber-800
                            ">
                                File gambar belum tersedia
                                pada perangkat.
                            </div>
                        `;
                    }


                    return `
                        <div class="
                            mt-4
                            rounded-2xl
                            bg-gray-50
                            border border-gray-200
                            p-3
                        ">

                            <img
                                src="${escapeAttribute(fileUrl)}"
                                alt="${escapeAttribute(
                                    material.title ||
                                    'Gambar materi'
                                )}"
                                class="
                                    block
                                    w-full
                                    max-h-[720px]
                                    object-contain
                                    rounded-xl
                                    bg-white
                                "
                                loading="lazy"
                            >

                            <div class="
                                mt-3
                                flex
                                justify-end
                            ">

                                <a
                                    href="${escapeAttribute(fileUrl)}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="
                                        inline-flex
                                        items-center
                                        gap-2
                                        px-4 py-2.5
                                        rounded-xl
                                        bg-white
                                        border border-gray-200
                                        text-gray-700
                                        text-xs
                                        font-bold
                                        hover:bg-gray-50
                                        transition
                                    "
                                >
                                    🖼️
                                    Buka Materi
                                </a>

                            </div>

                        </div>
                    `;
                }


                /* ======================================================
                   RENDER VIDEO
                   ====================================================== */

                function renderVideoMaterial(
                    material
                ) {

                    const fileUrl =
                        buildLocalFileUrl(
                            material.file_path
                        );

                    const externalUrl =
                        material.external_url ||
                        '';


                    if (
                        fileUrl
                    ) {

                        return `
                            <div class="
                                mt-4
                                rounded-2xl
                                overflow-hidden
                                bg-black
                                border border-gray-200
                            ">

                                <video
                                    controls
                                    playsinline
                                    preload="metadata"
                                    class="
                                        w-full
                                        max-h-[720px]
                                    "
                                >

                                    <source
                                        src="${escapeAttribute(
                                            fileUrl
                                        )}"
                                        ${
                                            material.mime_type
                                                ? `type="${escapeAttribute(
                                                    material.mime_type
                                                )}"`
                                                : ''
                                        }
                                    >

                                    Browser perangkat
                                    tidak mendukung pemutaran video.
                                </video>

                                <div class="
                                    bg-white
                                    px-4 py-3
                                    flex
                                    justify-end
                                ">

                                    <a
                                        href="${escapeAttribute(fileUrl)}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="
                                            inline-flex
                                            items-center
                                            gap-2
                                            px-4 py-2.5
                                            rounded-xl
                                            bg-indigo-50
                                            text-indigo-700
                                            text-xs
                                            font-bold
                                            hover:bg-indigo-100
                                            transition
                                        "
                                    >
                                        📖
                                        Buka Materi
                                    </a>

                                </div>

                            </div>
                        `;
                    }


                    if (
                        externalUrl
                    ) {

                        return `
                            <div class="
                                mt-4
                                rounded-2xl
                                border border-gray-200
                                bg-gray-50
                                p-5
                            ">

                                <a
                                    href="${escapeAttribute(
                                        externalUrl
                                    )}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="
                                        inline-flex
                                        items-center
                                        gap-2
                                        bg-indigo-600
                                        text-white
                                        px-5 py-3
                                        rounded-xl
                                        text-sm
                                        font-bold
                                        hover:bg-indigo-700
                                        transition
                                    "
                                >
                                    ▶️
                                    Buka Materi
                                </a>

                            </div>
                        `;
                    }


                    return `
                        <div class="
                            mt-4
                            rounded-xl
                            border border-amber-200
                            bg-amber-50
                            p-5
                            text-sm
                            text-amber-800
                        ">
                            File video belum tersedia
                            pada perangkat.
                        </div>
                    `;
                }


                /* ======================================================
                   RENDER AUDIO
                   ====================================================== */

                function renderAudioMaterial(
                    material
                ) {

                    const fileUrl =
                        buildLocalFileUrl(
                            material.file_path
                        );


                    if (
                        !fileUrl
                    ) {

                        return `
                            <div class="
                                mt-4
                                rounded-xl
                                border border-amber-200
                                bg-amber-50
                                p-5
                                text-sm
                                text-amber-800
                            ">
                                File audio belum tersedia
                                pada perangkat.
                            </div>
                        `;
                    }


                    return `
                        <div class="
                            mt-4
                            rounded-2xl
                            border border-gray-200
                            bg-gray-50
                            p-5
                        ">

                            <div class="
                                flex
                                items-center
                                gap-3
                                mb-4
                            ">

                                <div class="
                                    w-11 h-11
                                    rounded-xl
                                    bg-indigo-50
                                    text-indigo-600
                                    flex
                                    items-center
                                    justify-center
                                    text-xl
                                ">
                                    🎧
                                </div>

                                <div>

                                    <p class="
                                        text-sm
                                        font-bold
                                        text-gray-800
                                    ">
                                        Audio Materi
                                    </p>

                                    <p class="
                                        text-xs
                                        text-gray-400
                                    ">
                                        ${escapeHtml(
                                            material.file_name ||
                                            'Audio'
                                        )}
                                    </p>

                                </div>

                            </div>

                            <audio
                                controls
                                preload="metadata"
                                class="w-full"
                            >
                                <source
                                    src="${escapeAttribute(fileUrl)}"
                                    ${
                                        material.mime_type
                                            ? `type="${escapeAttribute(
                                                material.mime_type
                                            )}"`
                                            : ''
                                    }
                                >
                            </audio>

                            <div class="
                                mt-4
                                flex
                                justify-end
                            ">

                                <a
                                    href="${escapeAttribute(fileUrl)}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="
                                        inline-flex
                                        items-center
                                        gap-2
                                        px-4 py-2.5
                                        rounded-xl
                                        bg-white
                                        border border-gray-200
                                        text-gray-700
                                        text-xs
                                        font-bold
                                        hover:bg-gray-50
                                        transition
                                    "
                                >
                                    📖
                                    Buka Materi
                                </a>

                            </div>

                        </div>
                    `;
                }


                /* ======================================================
                   RENDER GENERIC FILE
                   ====================================================== */

                function renderFileMaterial(
                    material
                ) {

                    const fileUrl =
                        buildLocalFileUrl(
                            material.file_path
                        );


                    if (
                        !fileUrl
                    ) {

                        return `
                            <div class="
                                mt-4
                                rounded-xl
                                border border-amber-200
                                bg-amber-50
                                p-5
                                text-sm
                                text-amber-800
                            ">
                                File materi belum tersedia
                                pada perangkat.
                            </div>
                        `;
                    }


                    const fileName =
                        material.file_name ||
                        'File Materi';

                    const fileSize =
                        formatFileSize(
                            material.file_size
                        );


                    return `
                        <div class="
                            mt-4
                            rounded-2xl
                            border border-gray-200
                            bg-gray-50
                            p-5
                        ">

                            <div class="
                                flex
                                items-center
                                justify-between
                                gap-4
                                flex-wrap
                            ">

                                <div class="
                                    flex
                                    items-center
                                    gap-3
                                    min-w-0
                                ">

                                    <div class="
                                        w-11 h-11
                                        rounded-xl
                                        bg-gray-100
                                        flex
                                        items-center
                                        justify-center
                                        text-xl
                                        shrink-0
                                    ">
                                        📎
                                    </div>

                                    <div class="min-w-0">

                                        <p class="
                                            text-sm
                                            font-bold
                                            text-gray-800
                                            break-words
                                        ">
                                            ${escapeHtml(
                                                fileName
                                            )}
                                        </p>

                                        <p class="
                                            text-xs
                                            text-gray-400
                                            mt-0.5
                                        ">
                                            File
                                            ${
                                                fileSize
                                                    ? ' • ' +
                                                      escapeHtml(
                                                          fileSize
                                                      )
                                                    : ''
                                            }
                                        </p>

                                    </div>

                                </div>

                                <a
                                    href="${escapeAttribute(fileUrl)}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="
                                        inline-flex
                                        items-center
                                        gap-2
                                        bg-indigo-600
                                        text-white
                                        px-5 py-3
                                        rounded-xl
                                        text-sm
                                        font-bold
                                        hover:bg-indigo-700
                                        transition
                                        shrink-0
                                    "
                                >
                                    📖
                                    Buka Materi
                                </a>

                            </div>

                        </div>
                    `;
                }


                /* ======================================================
                   RENDER LINK
                   ====================================================== */

                function renderLinkMaterial(
                    material
                ) {

                    const url =
                        material.external_url ||
                        material.file_path ||
                        '';


                    if (
                        !url
                    ) {

                        return `
                            <div class="
                                mt-4
                                rounded-xl
                                border border-amber-200
                                bg-amber-50
                                p-5
                                text-sm
                                text-amber-800
                            ">
                                Tautan materi belum tersedia.
                            </div>
                        `;
                    }


                    return `
                        <div class="
                            mt-4
                            rounded-2xl
                            border border-gray-200
                            bg-gray-50
                            p-5
                        ">

                            <div class="
                                flex
                                items-center
                                gap-3
                            ">

                                <div class="
                                    w-11 h-11
                                    rounded-xl
                                    bg-indigo-50
                                    text-indigo-600
                                    flex
                                    items-center
                                    justify-center
                                    text-xl
                                    shrink-0
                                ">
                                    🔗
                                </div>

                                <div class="min-w-0 flex-1">

                                    <p class="
                                        text-sm
                                        font-bold
                                        text-gray-800
                                    ">
                                        Tautan Materi
                                    </p>

                                    <p class="
                                        text-xs
                                        text-gray-400
                                        mt-1
                                        break-all
                                    ">
                                        ${escapeHtml(url)}
                                    </p>

                                </div>

                            </div>

                            <div class="
                                mt-4
                                flex
                                justify-end
                            ">

                                <a
                                    href="${escapeAttribute(url)}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="
                                        inline-flex
                                        items-center
                                        gap-2
                                        bg-indigo-600
                                        text-white
                                        px-5 py-3
                                        rounded-xl
                                        text-sm
                                        font-bold
                                        hover:bg-indigo-700
                                        transition
                                    "
                                >
                                    📖
                                    Buka Materi
                                </a>

                            </div>

                        </div>
                    `;
                }


                /* ======================================================
                   RENDER MATERIAL
                   ====================================================== */

                function renderMaterial(
                    material,
                    index
                ) {

                    const type =
                        normalizeType(
                            material
                        );

                    const title =
                        material.title ||
                        'Materi ' + (index + 1);

                    const description =
                        material.description ||
                        '';


                    let body = '';


                    /*
                     * ==================================================
                     * TEXT
                     * ==================================================
                     */

                    if (
                        type === 'text'
                    ) {

                        const html =
                            sanitizeRichHtml(
                                material.content ||
                                ''
                            );


                        body = html
                            ? `
                                <div class="
                                    mt-4
                                    prose
                                    prose-sm
                                    max-w-none
                                    text-gray-700
                                    leading-relaxed
                                ">
                                    ${html}
                                </div>
                            `
                            : `
                                <div class="
                                    mt-4
                                    rounded-xl
                                    bg-gray-50
                                    border border-gray-200
                                    p-5
                                    text-sm
                                    text-gray-500
                                ">
                                    Materi teks belum memiliki isi.
                                </div>
                            `;

                    }


                    /*
                     * ==================================================
                     * PDF
                     * ==================================================
                     */

                    else if (
                        type === 'pdf'
                    ) {

                        body =
                            renderPdfMaterial(
                                material
                            );

                    }


                    /*
                     * ==================================================
                     * IMAGE
                     * ==================================================
                     */

                    else if (
                        type === 'image'
                    ) {

                        body =
                            renderImageMaterial(
                                material
                            );

                    }


                    /*
                     * ==================================================
                     * VIDEO
                     * ==================================================
                     */

                    else if (
                        type === 'video'
                    ) {

                        body =
                            renderVideoMaterial(
                                material
                            );

                    }


                    /*
                     * ==================================================
                     * AUDIO
                     * ==================================================
                     */

                    else if (
                        type === 'audio'
                    ) {

                        body =
                            renderAudioMaterial(
                                material
                            );

                    }


                    /*
                     * ==================================================
                     * LINK
                     * ==================================================
                     */

                    else if (
                        type === 'link'
                    ) {

                        body =
                            renderLinkMaterial(
                                material
                            );

                    }


                    /*
                     * ==================================================
                     * FILE
                     * ==================================================
                     */

                    else {

                        body =
                            renderFileMaterial(
                                material
                            );

                    }


                    return `
                        <article
                            class="
                                bg-white
                                border border-gray-200
                                rounded-2xl
                                shadow-sm
                                overflow-hidden
                            "
                        >

                            <div class="
                                px-5 py-5
                                border-b border-gray-100
                            ">

                                <div class="
                                    flex
                                    items-start
                                    justify-between
                                    gap-4
                                ">

                                    <div class="
                                        flex
                                        items-start
                                        gap-3
                                        min-w-0
                                    ">

                                        <div class="
                                            w-11 h-11
                                            rounded-xl
                                            bg-indigo-50
                                            flex
                                            items-center
                                            justify-center
                                            text-xl
                                            shrink-0
                                        ">
                                            ${getMaterialIcon(
                                                material
                                            )}
                                        </div>

                                        <div class="min-w-0">

                                            <div class="
                                                flex
                                                items-center
                                                gap-2
                                                flex-wrap
                                            ">

                                                <h3 class="
                                                    text-lg
                                                    font-bold
                                                    text-gray-800
                                                    break-words
                                                ">
                                                    ${escapeHtml(
                                                        title
                                                    )}
                                                </h3>

                                                <span class="
                                                    inline-flex
                                                    items-center
                                                    rounded-full
                                                    bg-gray-100
                                                    px-2.5 py-1
                                                    text-[10px]
                                                    font-bold
                                                    uppercase
                                                    tracking-wide
                                                    text-gray-500
                                                ">
                                                    ${escapeHtml(
                                                        getMaterialTypeLabel(
                                                            material
                                                        )
                                                    )}
                                                </span>

                                            </div>

                                            ${
                                                description
                                                    ? `
                                                        <p class="
                                                            mt-1
                                                            text-sm
                                                            text-gray-500
                                                            whitespace-pre-line
                                                        ">
                                                            ${escapeHtml(
                                                                description
                                                            )}
                                                        </p>
                                                      `
                                                    : ''
                                            }

                                        </div>

                                    </div>

                                </div>

                            </div>


                            <div class="
                                px-5 pb-5
                            ">

                                ${body}

                            </div>

                        </article>
                    `;
                }


                /* ======================================================
                   RENDER ASSIGNMENT
                   ====================================================== */

                function renderAssignment(
                    assignment,
                    index
                ) {

                    const title =
                        assignment.title ||
                        'Tugas ' + (index + 1);

                    const description =
                        assignment.description ||
                        assignment.instructions ||
                        '';


                    /*
                     * Beberapa implementasi lama mungkin
                     * menggunakan field berbeda.
                     */
                    const assignmentId =
                        assignment.id ??
                        assignment.sync_id ??
                        '';

                    return `
                        <article
                            class="
                                bg-white
                                border border-purple-200
                                rounded-2xl
                                shadow-sm
                                overflow-hidden
                            "
                        >

                            <div class="
                                px-5 py-5
                                bg-purple-50
                                border-b border-purple-100
                            ">

                                <div class="
                                    flex
                                    items-start
                                    gap-3
                                ">

                                    <div class="
                                        w-11 h-11
                                        rounded-xl
                                        bg-white
                                        text-purple-600
                                        flex
                                        items-center
                                        justify-center
                                        text-xl
                                        shrink-0
                                        shadow-sm
                                    ">
                                        📝
                                    </div>

                                    <div class="min-w-0">

                                        <div class="
                                            flex
                                            items-center
                                            gap-2
                                            flex-wrap
                                        ">

                                            <h3 class="
                                                text-lg
                                                font-bold
                                                text-purple-950
                                                break-words
                                            ">
                                                ${escapeHtml(
                                                    title
                                                )}
                                            </h3>

                                            <span class="
                                                inline-flex
                                                items-center
                                                rounded-full
                                                bg-purple-100
                                                px-2.5 py-1
                                                text-[10px]
                                                font-bold
                                                uppercase
                                                tracking-wide
                                                text-purple-700
                                            ">
                                                Tugas
                                            </span>

                                        </div>

                                        ${
                                            description
                                                ? `
                                                    <p class="
                                                        text-sm
                                                        text-purple-700
                                                        mt-1
                                                        whitespace-pre-line
                                                    ">
                                                        ${escapeHtml(
                                                            description
                                                        )}
                                                    </p>
                                                  `
                                                : ''
                                        }

                                    </div>

                                </div>

                            </div>


                            <div class="p-5">

                                <a
                                    href="/siswa/lms/assignments/${encodeURIComponent(
                                        assignmentId
                                    )}"
                                    class="
                                        inline-flex
                                        items-center
                                        justify-center
                                        gap-2
                                        bg-purple-600
                                        text-white
                                        px-5 py-3
                                        rounded-xl
                                        text-sm
                                        font-bold
                                        hover:bg-purple-700
                                        transition
                                    "
                                >
                                    📝
                                    Buka Tugas
                                </a>

                            </div>

                        </article>
                    `;
                }


                /* ======================================================
                   EMPTY STATE
                   ====================================================== */

                function renderEmptyState() {

                    content.innerHTML = `
                        <div class="
                            bg-white
                            border border-gray-200
                            rounded-2xl
                            shadow-sm
                            p-10
                            text-center
                        ">

                            <div class="
                                w-16 h-16
                                mx-auto
                                rounded-2xl
                                bg-gray-100
                                flex
                                items-center
                                justify-center
                                text-3xl
                                mb-4
                            ">
                                📚
                            </div>

                            <h3 class="
                                text-lg
                                font-bold
                                text-gray-800
                            ">
                                Belum Ada Materi
                            </h3>

                            <p class="
                                text-sm
                                text-gray-500
                                mt-2
                                max-w-md
                                mx-auto
                            ">
                                Belum ada materi atau tugas yang
                                dipublikasikan pada topik ini.
                            </p>

                        </div>
                    `;

                }


                /* ======================================================
                   LOAD TOPIC
                   ====================================================== */

                async function loadTopic() {

                    try {

                        setStatus(
                            'Memuat LMS...',
                            'Membaca data pembelajaran dari perangkat.',
                            '📚'
                        );


                        /*
                         * IndexedDB LMS Murid.
                         */
                        const db =
                            window.eduXidLmsDb;


                        if (
                            !db
                        ) {

                            throw new Error(
                                'Database LMS lokal tidak tersedia.'
                            );

                        }


                        /*
                         * Ambil topic.
                         */
                        const localTopic =
                            await findLocalTopic(
                                db,
                                topicId,
                                topicSyncId
                            );


                        console.log(
                            '[eduXid] LOCAL TOPIC:',
                            localTopic
                        );


                        /*
                         * Ambil semua data lokal.
                         */
                        const coursesRaw =
                            await getAllFromDb(
                                db,
                                [
                                    'getAllCourses',
                                    'getCourses',
                                    'courses'
                                ]
                            );


                        const topicsRaw =
                            await getAllFromDb(
                                db,
                                [
                                    'getAllTopics',
                                    'getTopics',
                                    'topics'
                                ]
                            );


                        const materialsRaw =
                            await getAllFromDb(
                                db,
                                [
                                    'getAllMaterials',
                                    'getMaterials',
                                    'materials'
                                ]
                            );


                        const assignmentsRaw =
                            await getAllFromDb(
                                db,
                                [
                                    'getAllAssignments',
                                    'getAssignments',
                                    'assignments'
                                ]
                            );


                        console.log(
                            '[eduXid] TOPIC PAGE courses:',
                            coursesRaw
                        );

                        console.log(
                            '[eduXid] TOPIC PAGE topics:',
                            topicsRaw
                        );

                        console.log(
                            '[eduXid] TOPIC PAGE materials:',
                            materialsRaw
                        );

                        console.log(
                            '[eduXid] TOPIC PAGE assignments:',
                            assignmentsRaw
                        );


                        /*
                         * ==================================================
                         * TOPIC
                         * ==================================================
                         */

                        let topic =
                            localTopic;


                        if (
                            !topic
                        ) {

                            topic =
                                topicsRaw.find(
                                    function (
                                        item
                                    ) {

                                        const sameId =
                                            topicId &&
                                            item.id !== null &&
                                            item.id !== undefined &&
                                            String(
                                                item.id
                                            ) ===
                                            String(
                                                topicId
                                            );

                                        const sameSyncId =
                                            topicSyncId &&
                                            item.sync_id !== null &&
                                            item.sync_id !== undefined &&
                                            String(
                                                item.sync_id
                                            ) ===
                                            String(
                                                topicSyncId
                                            );

                                        return (
                                            sameId ||
                                            sameSyncId
                                        );

                                    }
                                );

                        }


                        if (
                            !topic
                        ) {

                            throw new Error(
                                'Topik tidak ditemukan pada data lokal perangkat.'
                            );

                        }


                        /*
                         * ==================================================
                         * COURSE
                         * ==================================================
                         */

                        let course =
                            null;


                        if (
                            topic.course_id !== null &&
                            topic.course_id !== undefined
                        ) {

                            course =
                                coursesRaw.find(
                                    function (
                                        item
                                    ) {

                                        return (
                                            item.id !== null &&
                                            item.id !== undefined &&
                                            String(
                                                item.id
                                            ) ===
                                            String(
                                                topic.course_id
                                            )
                                        );

                                    }
                                ) ||
                                null;

                        }


                        if (
                            !course &&
                            topic.course_sync_id
                        ) {

                            course =
                                coursesRaw.find(
                                    function (
                                        item
                                    ) {

                                        return (
                                            item.sync_id !== null &&
                                            item.sync_id !== undefined &&
                                            String(
                                                item.sync_id
                                            ) ===
                                            String(
                                                topic.course_sync_id
                                            )
                                        );

                                    }
                                ) ||
                                null;

                        }


                        if (
                            !course &&
                            courseId
                        ) {

                            course =
                                coursesRaw.find(
                                    function (
                                        item
                                    ) {

                                        return (
                                            item.id !== null &&
                                            item.id !== undefined &&
                                            String(
                                                item.id
                                            ) ===
                                            String(
                                                courseId
                                            )
                                        );

                                    }
                                ) ||
                                null;

                        }


                        if (
                            !course &&
                            courseSyncId
                        ) {

                            course =
                                coursesRaw.find(
                                    function (
                                        item
                                    ) {

                                        return (
                                            item.sync_id !== null &&
                                            item.sync_id !== undefined &&
                                            String(
                                                item.sync_id
                                            ) ===
                                            String(
                                                courseSyncId
                                            )
                                        );

                                    }
                                ) ||
                                null;

                        }


                        /*
                         * ==================================================
                         * HEADER
                         * ==================================================
                         */

                        if (
                            courseName
                        ) {

                            courseName.textContent =
                                course?.name ||
                                course?.title ||
                                'Kelas';

                        }


                        if (
                            topicTitle
                        ) {

                            topicTitle.textContent =
                                topic.title ||
                                'Materi Pembelajaran';

                        }


                        /*
                         * ==================================================
                         * MATERIAL FILTER
                         * ==================================================
                         */

                        const materials =
                            materialsRaw.filter(
                                function (
                                    material
                                ) {

                                    if (
                                        !isPublished(
                                            material
                                        )
                                    ) {
                                        return false;
                                    }

                                    return belongsToTopic(
                                        material,
                                        topic
                                    );

                                }
                            );


                        /*
                         * ==================================================
                         * ASSIGNMENT FILTER
                         * ==================================================
                         */

                        const assignments =
                            assignmentsRaw.filter(
                                function (
                                    assignment
                                ) {

                                    if (
                                        !isPublished(
                                            assignment
                                        )
                                    ) {
                                        return false;
                                    }

                                    return belongsToTopic(
                                        assignment,
                                        topic
                                    );

                                }
                            );


                        console.log(
                            '[eduXid] TOPIC PAGE filtered materials:',
                            materials
                        );

                        console.log(
                            '[eduXid] TOPIC PAGE filtered assignments:',
                            assignments
                        );


                        /*
                         * ==================================================
                         * COUNTS
                         * ==================================================
                         */

                        if (
                            materialCount
                        ) {

                            materialCount.textContent =
                                materials.length;

                        }


                        if (
                            assignmentCount
                        ) {

                            assignmentCount.textContent =
                                assignments.length;

                        }


                        /*
                         * ==================================================
                         * RENDER
                         * ==================================================
                         */

                        content.innerHTML =
                            '';


                        if (
                            materials.length === 0 &&
                            assignments.length === 0
                        ) {

                            renderEmptyState();

                        }
                        else {

                            /*
                             * Intro topic.
                             */
                            if (
                                topic.description
                            ) {

                                const intro =
                                    document.createElement(
                                        'div'
                                    );

                                intro.className =
                                    'bg-indigo-50 border border-indigo-100 rounded-2xl p-5';

                                intro.innerHTML = `
                                    <div class="
                                        flex
                                        items-start
                                        gap-3
                                    ">

                                        <div class="
                                            w-10 h-10
                                            rounded-xl
                                            bg-white
                                            flex
                                            items-center
                                            justify-center
                                            text-lg
                                            shrink-0
                                        ">
                                            📘
                                        </div>

                                        <div>

                                            <p class="
                                                text-sm
                                                font-bold
                                                text-indigo-900
                                            ">
                                                Tentang Topik
                                            </p>

                                            <p class="
                                                text-sm
                                                text-indigo-700
                                                mt-1
                                                whitespace-pre-line
                                            ">
                                                ${escapeHtml(
                                                    topic.description
                                                )}
                                            </p>

                                        </div>

                                    </div>
                                `;

                                content.appendChild(
                                    intro
                                );

                            }


                            /*
                             * Materi.
                             */
                            if (
                                materials.length > 0
                            ) {

                                const materialSection =
                                    document.createElement(
                                        'section'
                                    );

                                materialSection.innerHTML = `
                                    <div class="
                                        flex
                                        items-center
                                        justify-between
                                        gap-4
                                        mb-4
                                        flex-wrap
                                    ">

                                        <div>

                                            <h3 class="
                                                text-lg
                                                font-bold
                                                text-gray-800
                                            ">
                                                📚 Materi Pembelajaran
                                            </h3>

                                            <p class="
                                                text-sm
                                                text-gray-500
                                                mt-1
                                            ">
                                                Baca materi yang telah
                                                dipublikasikan oleh guru.
                                            </p>

                                        </div>

                                        <span class="
                                            inline-flex
                                            items-center
                                            rounded-full
                                            bg-indigo-50
                                            px-3 py-1.5
                                            text-xs
                                            font-bold
                                            text-indigo-700
                                        ">
                                            ${materials.length}
                                            materi
                                        </span>

                                    </div>

                                    <div
                                        id="topic-material-list"
                                        class="space-y-5"
                                    ></div>
                                `;

                                const materialList =
                                    materialSection.querySelector(
                                        '#topic-material-list'
                                    );


                                materials.forEach(
                                    function (
                                        material,
                                        index
                                    ) {

                                        materialList.insertAdjacentHTML(
                                            'beforeend',
                                            renderMaterial(
                                                material,
                                                index
                                            )
                                        );

                                    }
                                );


                                content.appendChild(
                                    materialSection
                                );


                                /*
                                 * Render KaTeX setelah
                                 * materi masuk DOM.
                                 */
                                renderKatex(
                                    materialSection
                                );

                            }


                            /*
                             * Tugas.
                             */
                            if (
                                assignments.length > 0
                            ) {

                                const assignmentSection =
                                    document.createElement(
                                        'section'
                                    );


                                assignmentSection.innerHTML = `
                                    <div class="
                                        flex
                                        items-center
                                        justify-between
                                        gap-4
                                        mb-4
                                        flex-wrap
                                    ">

                                        <div>

                                            <h3 class="
                                                text-lg
                                                font-bold
                                                text-gray-800
                                            ">
                                                📝 Tugas
                                            </h3>

                                            <p class="
                                                text-sm
                                                text-gray-500
                                                mt-1
                                            ">
                                                Tugas yang tersedia
                                                pada topik ini.
                                            </p>

                                        </div>

                                        <span class="
                                            inline-flex
                                            items-center
                                            rounded-full
                                            bg-purple-50
                                            px-3 py-1.5
                                            text-xs
                                            font-bold
                                            text-purple-700
                                        ">
                                            ${assignments.length}
                                            tugas
                                        </span>

                                    </div>

                                    <div
                                        id="topic-assignment-list"
                                        class="space-y-5"
                                    ></div>
                                `;


                                const assignmentList =
                                    assignmentSection.querySelector(
                                        '#topic-assignment-list'
                                    );


                                assignments.forEach(
                                    function (
                                        assignment,
                                        index
                                    ) {

                                        assignmentList.insertAdjacentHTML(
                                            'beforeend',
                                            renderAssignment(
                                                assignment,
                                                index
                                            )
                                        );

                                    }
                                );


                                content.appendChild(
                                    assignmentSection
                                );

                            }

                        }


                        /*
                         * ==================================================
                         * SHOW
                         * ==================================================
                         */

                        if (
                            loading
                        ) {
                            loading.classList.add(
                                'hidden'
                            );
                        }


                        if (
                            errorBox
                        ) {
                            errorBox.classList.add(
                                'hidden'
                            );
                        }


                        if (
                            content
                        ) {
                            content.classList.remove(
                                'hidden'
                            );
                        }


                        setStatus(
                            navigator.onLine
                                ? 'LMS siap digunakan'
                                : 'LMS offline',
                            navigator.onLine
                                ? 'Materi dibaca dari data LMS yang tersimpan di perangkat.'
                                : 'Materi tetap dapat dibuka dari data lokal perangkat.',
                            navigator.onLine
                                ? '🟢'
                                : '📦'
                        );


                    }
                    catch (
                        error
                    ) {

                        console.error(
                            '[eduXid] TOPIC PAGE ERROR:',
                            error
                        );


                        showError(
                            error?.message ||
                            'Terjadi kesalahan saat memuat materi.'
                        );

                    }

                }


                /* ======================================================
                   SYNC STATUS EVENT
                   ====================================================== */

                window.addEventListener(
                    'eduxid:sync-status',
                    function (
                        event
                    ) {

                        const state =
                            event.detail ||
                            {};


                        if (
                            state.status === 'syncing'
                        ) {

                            setStatus(
                                'Menyinkronkan LMS...',
                                'Data terbaru sedang diterima dari server.',
                                '🔄'
                            );

                        }
                        else if (
                            state.status === 'offline'
                        ) {

                            setStatus(
                                'LMS offline',
                                'Data lokal tetap dapat digunakan.',
                                '📦'
                            );

                        }
                        else if (
                            state.status === 'synced'
                        ) {

                            setStatus(
                                'LMS tersinkron',
                                'Data LMS pada perangkat sudah diperbarui.',
                                '🟢'
                            );

                        }
                        else if (
                            state.status === 'error'
                        ) {

                            setStatus(
                                'Sinkronisasi bermasalah',
                                'Data lokal tetap tersedia.',
                                '⚠️'
                            );

                        }

                    }
                );


                /* ======================================================
                   INITIAL LOAD
                   ====================================================== */

                loadTopic();

            }
        );

    </script>

</x-app-layout>
