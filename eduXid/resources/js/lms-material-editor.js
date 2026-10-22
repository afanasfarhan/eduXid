/*
|--------------------------------------------------------------------------
| eduXid - LMS Material Editor
|--------------------------------------------------------------------------
|
| Editor materi offline-first tanpa dependency tambahan.
|
| Fitur dasar:
| - Bold
| - Italic
| - Underline
| - Heading
| - Bullet list
| - Numbered list
| - Alignment
| - Link
| - Table
| - KaTeX formula
| - Preview
| - Sinkronisasi isi ke textarea[name="content"]
|
| File ini sengaja berdiri sendiri.
| Tidak mencampur logic dengan lms-guru.js.
|--------------------------------------------------------------------------
*/

(() => {
    'use strict';

    /*
    |--------------------------------------------------------------------------
    | STATE
    |--------------------------------------------------------------------------
    */

    const editors = new Set();

    /*
    |--------------------------------------------------------------------------
    | UTILITIES
    |--------------------------------------------------------------------------
    */

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }

    function getSelectionRange() {
        const selection = window.getSelection();

        if (!selection || selection.rangeCount === 0) {
            return null;
        }

        return selection.getRangeAt(0);
    }

    function restoreEditorFocus(editor) {
        if (!editor) {
            return;
        }

        editor.focus();
    }

    function exec(command, value = null) {
        try {
            document.execCommand(command, false, value);
        } catch (error) {
            console.error(
                '[eduXid] Editor command gagal:',
                command,
                error
            );
        }
    }

    function syncTextarea(instance) {
        if (!instance || !instance.editor || !instance.textarea) {
            return;
        }

        instance.textarea.value = instance.editor.innerHTML;
    }

    function renderKatex(container) {
        if (
            !container ||
            typeof window.renderMathInElement !== 'function'
        ) {
            return;
        }

        try {
            window.renderMathInElement(container, {
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
            });
        } catch (error) {
            console.error(
                '[eduXid] KaTeX render gagal:',
                error
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | TOOLBAR
    |--------------------------------------------------------------------------
    */

    function createToolbar(instance) {
        const toolbar = document.createElement('div');

        toolbar.className =
            'flex flex-wrap items-center gap-1 p-2 bg-gray-50 border border-gray-300 border-b-0 rounded-t-xl';

        const groups = [
            [
                {
                    label: 'B',
                    title: 'Tebal',
                    command: 'bold',
                    className: 'font-bold'
                },
                {
                    label: 'I',
                    title: 'Miring',
                    command: 'italic',
                    className: 'italic'
                },
                {
                    label: 'U',
                    title: 'Garis bawah',
                    command: 'underline',
                    className: 'underline'
                }
            ],

            [
                {
                    label: 'H1',
                    title: 'Heading 1',
                    command: 'formatBlock',
                    value: 'H1'
                },
                {
                    label: 'H2',
                    title: 'Heading 2',
                    command: 'formatBlock',
                    value: 'H2'
                },
                {
                    label: 'H3',
                    title: 'Heading 3',
                    command: 'formatBlock',
                    value: 'H3'
                },
                {
                    label: '¶',
                    title: 'Paragraf',
                    command: 'formatBlock',
                    value: 'P'
                }
            ],

            [
                {
                    label: '•',
                    title: 'Daftar bullet',
                    command: 'insertUnorderedList'
                },
                {
                    label: '1.',
                    title: 'Daftar bernomor',
                    command: 'insertOrderedList'
                }
            ],

            [
                {
                    label: '←',
                    title: 'Rata kiri',
                    command: 'justifyLeft'
                },
                {
                    label: '↔',
                    title: 'Rata tengah',
                    command: 'justifyCenter'
                },
                {
                    label: '→',
                    title: 'Rata kanan',
                    command: 'justifyRight'
                },
                {
                    label: '↔↔',
                    title: 'Rata penuh',
                    command: 'justifyFull'
                }
            ]
        ];

        groups.forEach((group, groupIndex) => {
            const groupElement = document.createElement('div');

            groupElement.className =
                'flex items-center gap-1';

            if (groupIndex > 0) {
                groupElement.classList.add(
                    'pl-2',
                    'ml-1',
                    'border-l',
                    'border-gray-300'
                );
            }

            group.forEach(item => {
                const button = document.createElement('button');

                button.type = 'button';

                button.className =
                    'inline-flex items-center justify-center min-w-[38px] h-[36px] px-2 rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 transition text-sm';

                if (item.className) {
                    button.classList.add(item.className);
                }

                button.textContent = item.label;
                button.title = item.title;

                button.addEventListener('mousedown', event => {
                    event.preventDefault();
                });

                button.addEventListener('click', () => {
                    restoreEditorFocus(instance.editor);

                    if (item.command === 'formatBlock') {
                        exec(
                            'formatBlock',
                            `<${item.value}>`
                        );
                    } else {
                        exec(
                            item.command,
                            item.value ?? null
                        );
                    }

                    syncTextarea(instance);
                });

                groupElement.appendChild(button);
            });

            toolbar.appendChild(groupElement);
        });

        /*
        |--------------------------------------------------------------------------
        | LINK
        |--------------------------------------------------------------------------
        */

        const linkGroup = document.createElement('div');

        linkGroup.className =
            'flex items-center gap-1 pl-2 ml-1 border-l border-gray-300';

        const linkButton = document.createElement('button');

        linkButton.type = 'button';
        linkButton.textContent = '🔗';
        linkButton.title = 'Sisipkan link';

        linkButton.className =
            'inline-flex items-center justify-center min-w-[38px] h-[36px] px-2 rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 transition text-sm';

        linkButton.addEventListener('mousedown', event => {
            event.preventDefault();
        });

        linkButton.addEventListener('click', () => {
            restoreEditorFocus(instance.editor);

            const url = window.prompt(
                'Masukkan URL:',
                'https://'
            );

            if (!url) {
                return;
            }

            exec('createLink', url.trim());

            syncTextarea(instance);
        });

        linkGroup.appendChild(linkButton);

        /*
        |--------------------------------------------------------------------------
        | KATE X
        |--------------------------------------------------------------------------
        */

        const katexButton = document.createElement('button');

        katexButton.type = 'button';
        katexButton.textContent = 'Σ';
        katexButton.title = 'Sisipkan rumus KaTeX';

        katexButton.className =
            'inline-flex items-center justify-center min-w-[38px] h-[36px] px-2 rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 transition text-sm font-bold';

        katexButton.addEventListener('mousedown', event => {
            event.preventDefault();
        });

        katexButton.addEventListener('click', () => {
            insertKatex(instance);
        });

        linkGroup.appendChild(katexButton);

        /*
        |--------------------------------------------------------------------------
        | TABLE
        |--------------------------------------------------------------------------
        */

        const tableButton = document.createElement('button');

        tableButton.type = 'button';
        tableButton.textContent = '▦';
        tableButton.title = 'Sisipkan tabel';

        tableButton.className =
            'inline-flex items-center justify-center min-w-[38px] h-[36px] px-2 rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 transition text-sm';

        tableButton.addEventListener('mousedown', event => {
            event.preventDefault();
        });

        tableButton.addEventListener('click', () => {
            insertTable(instance);
        });

        linkGroup.appendChild(tableButton);

        /*
        |--------------------------------------------------------------------------
        | PREVIEW
        |--------------------------------------------------------------------------
        */

        const previewButton = document.createElement('button');

        previewButton.type = 'button';
        previewButton.textContent = '👁';
        previewButton.title = 'Preview materi';

        previewButton.className =
            'inline-flex items-center justify-center min-w-[38px] h-[36px] px-2 rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 transition text-sm';

        previewButton.addEventListener('click', () => {
            syncTextarea(instance);
            showPreview(instance);
        });

        linkGroup.appendChild(previewButton);

        toolbar.appendChild(linkGroup);

        return toolbar;
    }

    /*
    |--------------------------------------------------------------------------
    | KATEX INSERT
    |--------------------------------------------------------------------------
    */

    function insertKatex(instance) {
        restoreEditorFocus(instance.editor);

        const formula = window.prompt(
            'Masukkan rumus KaTeX.\n\n' +
            'Contoh:\n' +
            'F = m a\n\n' +
            'atau:\n' +
            '\\\\frac{-b \\\\pm \\\\sqrt{b^2-4ac}}{2a}',
            ''
        );

        if (!formula) {
            return;
        }

        const selection = getSelectionRange();

        const span = document.createElement('span');

        span.textContent = `$${formula}$`;

        if (selection) {
            selection.deleteContents();
            selection.insertNode(span);

            selection.setStartAfter(span);
            selection.collapse(true);

            const sel = window.getSelection();

            if (sel) {
                sel.removeAllRanges();
                sel.addRange(selection);
            }
        } else {
            instance.editor.appendChild(span);
        }

        syncTextarea(instance);
    }

    /*
    |--------------------------------------------------------------------------
    | TABLE INSERT
    |--------------------------------------------------------------------------
    */

    function insertTable(instance) {
        const rowsInput = window.prompt(
            'Jumlah baris:',
            '3'
        );

        if (!rowsInput) {
            return;
        }

        const colsInput = window.prompt(
            'Jumlah kolom:',
            '3'
        );

        if (!colsInput) {
            return;
        }

        let rows = parseInt(rowsInput, 10);
        let cols = parseInt(colsInput, 10);

        if (!Number.isFinite(rows) || rows < 1) {
            rows = 1;
        }

        if (!Number.isFinite(cols) || cols < 1) {
            cols = 1;
        }

        rows = Math.min(rows, 20);
        cols = Math.min(cols, 10);

        const table = document.createElement('table');

        table.className =
            'w-full border-collapse border border-gray-300 my-4';

        const tbody = document.createElement('tbody');

        for (let r = 0; r < rows; r++) {
            const tr = document.createElement('tr');

            for (let c = 0; c < cols; c++) {
                const cell =
                    r === 0
                        ? document.createElement('th')
                        : document.createElement('td');

                cell.className =
                    'border border-gray-300 px-3 py-2 text-left';

                if (r === 0) {
                    cell.classList.add(
                        'font-bold',
                        'bg-gray-50'
                    );
                }

                cell.innerHTML =
                    r === 0
                        ? `Kolom ${c + 1}`
                        : '';

                tr.appendChild(cell);
            }

            tbody.appendChild(tr);
        }

        table.appendChild(tbody);

        const selection = getSelectionRange();

        if (selection) {
            selection.deleteContents();
            selection.insertNode(table);

            const paragraph =
                document.createElement('p');

            paragraph.innerHTML = '<br>';

            table.parentNode.insertBefore(
                paragraph,
                table.nextSibling
            );

            selection.setStartAfter(paragraph);
            selection.collapse(true);

            const sel = window.getSelection();

            if (sel) {
                sel.removeAllRanges();
                sel.addRange(selection);
            }
        } else {
            instance.editor.appendChild(table);
        }

        syncTextarea(instance);
    }

    /*
    |--------------------------------------------------------------------------
    | PREVIEW
    |--------------------------------------------------------------------------
    */

    function showPreview(instance) {
        const modal =
            document.createElement('div');

        modal.className =
            'fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4';

        const panel =
            document.createElement('div');

        panel.className =
            'bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[90vh] overflow-hidden flex flex-col';

        const header =
            document.createElement('div');

        header.className =
            'flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200 bg-indigo-50';

        const title =
            document.createElement('h3');

        title.className =
            'font-bold text-lg text-indigo-950';

        title.textContent =
            'Preview Materi';

        const closeButton =
            document.createElement('button');

        closeButton.type = 'button';
        closeButton.textContent = '✕';
        closeButton.title = 'Tutup preview';

        closeButton.className =
            'inline-flex items-center justify-center w-10 h-10 rounded-lg border border-gray-300 bg-white text-gray-600 hover:bg-gray-50';

        closeButton.addEventListener(
            'click',
            () => modal.remove()
        );

        header.appendChild(title);
        header.appendChild(closeButton);

        const content =
            document.createElement('div');

        content.className =
            'p-6 overflow-y-auto';

        const article =
            document.createElement('article');

        article.className =
            'prose max-w-none text-gray-800';

        article.innerHTML =
            instance.editor.innerHTML;

        content.appendChild(article);

        panel.appendChild(header);
        panel.appendChild(content);

        modal.appendChild(panel);

        document.body.appendChild(modal);

        renderKatex(article);

        modal.addEventListener('click', event => {
            if (event.target === modal) {
                modal.remove();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE EDITOR
    |--------------------------------------------------------------------------
    */

    function initializeEditor(textarea) {
        if (!textarea) {
            return null;
        }

        if (textarea.dataset.eduxidEditorInitialized === '1') {
            return null;
        }

        textarea.dataset.eduxidEditorInitialized = '1';

        const initialContent =
            textarea.value || '';

        /*
        |--------------------------------------------------------------------------
        | WRAPPER
        |--------------------------------------------------------------------------
        */

        const wrapper =
            document.createElement('div');

        wrapper.className =
            'eduxid-material-editor';

        /*
        |--------------------------------------------------------------------------
        | EDITOR
        |--------------------------------------------------------------------------
        */

        const editor =
            document.createElement('div');

        editor.className =
            'eduxid-material-editor-content w-full min-h-[320px] border border-gray-300 rounded-b-xl bg-white px-4 py-4 text-sm text-gray-800 leading-relaxed focus:outline-none focus:ring-2 focus:ring-indigo-200 focus:border-indigo-500';

        editor.contentEditable = 'true';

        editor.setAttribute(
            'role',
            'textbox'
        );

        editor.setAttribute(
            'aria-multiline',
            'true'
        );

        editor.dataset.placeholder =
            textarea.getAttribute(
                'placeholder'
            ) ||
            'Isi materi pembelajaran...';

        if (initialContent.trim() !== '') {
            editor.innerHTML =
                initialContent;
        }

        /*
        |--------------------------------------------------------------------------
        | PLACEHOLDER
        |--------------------------------------------------------------------------
        */

        function updatePlaceholder() {
            const empty =
                editor.innerHTML.trim() === '' ||
                editor.textContent.trim() === '';

            if (empty) {
                editor.classList.add(
                    'relative'
                );

                editor.dataset.empty = '1';
            } else {
                editor.dataset.empty = '0';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | INSTANCE
        |--------------------------------------------------------------------------
        */

        const instance = {
            textarea,
            editor,
            wrapper,
            updatePlaceholder
        };

        /*
        |--------------------------------------------------------------------------
        | TOOLBAR
        |--------------------------------------------------------------------------
        */

        const toolbar =
            createToolbar(instance);

        /*
        |--------------------------------------------------------------------------
        | ASSEMBLY
        |--------------------------------------------------------------------------
        */

        wrapper.appendChild(toolbar);
        wrapper.appendChild(editor);

        textarea.parentNode.insertBefore(
            wrapper,
            textarea
        );

        textarea.style.display = 'none';

        /*
        |--------------------------------------------------------------------------
        | EVENTS
        |--------------------------------------------------------------------------
        */

        editor.addEventListener(
            'input',
            () => {
                syncTextarea(instance);
                updatePlaceholder();
            }
        );

        editor.addEventListener(
            'blur',
            () => {
                syncTextarea(instance);
            }
        );

        editor.addEventListener(
            'paste',
            event => {
                /*
                | Untuk sementara kita mempertahankan
                | paste sebagai plain text agar HTML
                | eksternal tidak masuk sembarangan.
                */

                event.preventDefault();

                const text =
                    event.clipboardData
                        ?.getData('text/plain') || '';

                exec(
                    'insertText',
                    text
                );

                syncTextarea(instance);
            }
        );

        /*
        |--------------------------------------------------------------------------
        | FORM SUBMIT
        |--------------------------------------------------------------------------
        */

        const form =
            textarea.closest('form');

        if (form) {
            form.addEventListener(
                'submit',
                () => {
                    syncTextarea(instance);
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | INITIAL STATE
        |--------------------------------------------------------------------------
        */

        syncTextarea(instance);
        updatePlaceholder();

        editors.add(instance);

        return instance;
    }

    /*
    |--------------------------------------------------------------------------
    | AUTO INITIALIZE
    |--------------------------------------------------------------------------
    */

    function initializeAll() {
        const textareas =
            document.querySelectorAll(
                'textarea[name="content"]'
            );

        textareas.forEach(textarea => {
            initializeEditor(textarea);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | GLOBAL API
    |--------------------------------------------------------------------------
    */

    window.eduXidMaterialEditor = {
        initialize: initializeAll,

        refreshKatex() {
            editors.forEach(instance => {
                renderKatex(instance.editor);
            });
        }
    };

    /*
    |--------------------------------------------------------------------------
    | DOM READY
    |--------------------------------------------------------------------------
    */

    if (
        document.readyState ===
        'loading'
    ) {
        document.addEventListener(
            'DOMContentLoaded',
            initializeAll
        );
    } else {
        initializeAll();
    }

})();