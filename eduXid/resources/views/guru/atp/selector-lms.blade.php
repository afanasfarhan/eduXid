@php
    $atpCourse = $topic->course ?? null;
@endphp

@if($atpCourse)
    <div class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-4">
        <label class="block text-sm font-bold text-indigo-800 mb-1">
            ATP / Tujuan Pembelajaran
        </label>

        <select name="atp_item_id"
                class="js-atp-lms w-full rounded-lg border-gray-300 text-sm"
                data-subject-id="{{ $atpCourse->subject_id }}"
                data-homeroom-id="{{ $atpCourse->homeroom_id }}"
                data-selected="{{ old('atp_item_id', $selectedAtpId ?? '') }}">
            <option value="">-- Tidak terhubung ke ATP --</option>
        </select>

        <p class="text-xs text-indigo-700 mt-2">
            ATP difilter otomatis menurut mata pelajaran dan tingkat kelas LMS ini.
        </p>
    </div>

    @once
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.js-atp-lms').forEach(async function (select) {
            if (select.dataset.loaded === '1') return;
            select.dataset.loaded = '1';

            const subjectId = select.dataset.subjectId || '';
            const homeroomId = select.dataset.homeroomId || '';
            const selected = select.dataset.selected || '';

            if (!subjectId) return;

            try {
                const url = @json(route('guru.atp.options'))
                    + '?subject_id=' + encodeURIComponent(subjectId)
                    + '&homeroom_id=' + encodeURIComponent(homeroomId);

                const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!response.ok) return;

                const data = await response.json();

                (data.items || []).forEach(function (item) {
                    const option = document.createElement('option');
                    option.value = item.id;
                    option.textContent =
                        'Kelas ' + (item.grade_level || '-') + ' · ' +
                        (item.materi || '-') + ' — ' +
                        (item.tujuan_pembelajaran || '');
                    select.appendChild(option);
                });

                if (selected) select.value = selected;
            } catch (e) {
                console.warn('[eduXid ATP LMS] Gagal memuat ATP', e);
            }
        });
    });
    </script>
    @endonce
@endif
