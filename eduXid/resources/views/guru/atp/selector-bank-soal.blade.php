<div class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-4">
    <label for="atp_item_id" class="block text-xs font-bold text-indigo-800 mb-1">
        ATP / Tujuan Pembelajaran
    </label>

    <select name="atp_item_id"
            id="atp_item_id"
            data-selected-atp="{{ $selectedAtpId ?? '' }}"
            class="block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        <option value="">-- Pilih ATP / tingkat kelas --</option>
    </select>

    <div id="atp_tp_preview" class="mt-2 text-xs text-indigo-800 hidden">
        <div><span class="font-bold">Tujuan Pembelajaran:</span> <span data-atp-tp></span></div>
        <div><span class="font-bold">Tingkat:</span> <span data-atp-grade></span></div>
    </div>
</div>

@once
<script>
document.addEventListener('DOMContentLoaded', function () {
    const subject = document.getElementById('subject_id');
    const atp = document.getElementById('atp_item_id');
    if (!subject || !atp) return;

    const materi = document.querySelector('[name="judul_materi"]');
    const indikator = document.querySelector('[name="indikator"]');
    const preview = document.getElementById('atp_tp_preview');
    const previewText = preview ? preview.querySelector('[data-atp-tp]') : null;
    const previewGrade = preview ? preview.querySelector('[data-atp-grade]') : null;
    const selectedInitial = String(atp.dataset.selectedAtp || '');

    let items = [];

    async function loadAtp(keepSelected) {
        const subjectId = subject.value;
        atp.innerHTML = '<option value="">-- Pilih ATP / tingkat kelas --</option>';
        items = [];
        if (!subjectId) return;

        try {
            const response = await fetch(@json(route('guru.atp.options')) + '?subject_id=' + encodeURIComponent(subjectId), {
                headers: { 'Accept': 'application/json' }
            });
            if (!response.ok) return;

            const data = await response.json();
            items = Array.isArray(data.items) ? data.items : [];

            items.forEach(function (item) {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent =
                    'Kelas ' + (item.grade_level || '-') + ' · ' +
                    (item.materi || '-') + ' — ' +
                    (item.tujuan_pembelajaran || '');
                atp.appendChild(option);
            });

            if (keepSelected && selectedInitial) {
                atp.value = selectedInitial;
                showPreview(false);
            }
        } catch (e) {
            console.warn('[eduXid ATP] Gagal memuat ATP', e);
        }
    }

    function showPreview(fillFields) {
        const selected = items.find(function (item) {
            return String(item.id) === String(atp.value);
        });

        if (!selected) {
            if (preview) preview.classList.add('hidden');
            return;
        }

        if (preview && previewText) {
            previewText.textContent = selected.tujuan_pembelajaran || '-';
            if (previewGrade) previewGrade.textContent = 'Kelas ' + (selected.grade_level || '-');
            preview.classList.remove('hidden');
        }

        if (fillFields) {
            if (materi) materi.value = selected.materi || '';
            if (indikator) indikator.value = selected.indikator_penilaian || '';
        }
    }

    subject.addEventListener('change', function () { loadAtp(false); });
    atp.addEventListener('change', function () { showPreview(true); });
    loadAtp(true);
});
</script>
@endonce
