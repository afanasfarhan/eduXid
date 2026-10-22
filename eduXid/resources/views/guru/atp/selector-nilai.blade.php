<div class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-4">
    <label for="atp_nilai_selector" class="block text-sm font-bold text-indigo-800 mb-1">
        ATP / Materi Pertemuan
    </label>
    <select id="atp_nilai_selector"
            class="w-full rounded-lg border-gray-300 text-sm">
        <option value="">-- Pilih ATP sesuai tingkat kelas --</option>
    </select>
    <div id="atp_nilai_detail" class="hidden mt-3 text-xs text-indigo-800 space-y-1">
        <div><strong>Tujuan Pembelajaran:</strong> <span data-tp></span></div>
        <div><strong>Indikator Penilaian:</strong> <span data-indikator></span></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selector = document.getElementById('atp_nilai_selector');
    const materi = document.querySelector('[name="materi"]');
    const detail = document.getElementById('atp_nilai_detail');
    if (!selector || !materi) return;

    let items = [];

    fetch(@json(route('guru.atp.assignment.options', $assignment->id)), {
        headers: { 'Accept': 'application/json' }
    })
    .then(function (response) { return response.ok ? response.json() : {items: []}; })
    .then(function (data) {
        items = Array.isArray(data.items) ? data.items : [];

        items.forEach(function (item) {
            const option = document.createElement('option');
            option.value = item.id;
            option.textContent =
                'Kelas ' + (item.grade_level || '-') + ' · ' +
                (item.materi || '-') + ' — ' +
                (item.tujuan_pembelajaran || '');
            selector.appendChild(option);

            if (
                String(materi.value || '').trim().toLowerCase()
                === String(item.materi || '').trim().toLowerCase()
            ) {
                selector.value = String(item.id);
            }
        });

        updateDetail(false);
    })
    .catch(function (e) {
        console.warn('[eduXid ATP] Gagal memuat ATP Buku Nilai', e);
    });

    function updateDetail(fill) {
        const item = items.find(function (x) {
            return String(x.id) === String(selector.value);
        });

        if (!item) {
            if (detail) detail.classList.add('hidden');
            return;
        }

        if (fill) materi.value = item.materi || '';

        if (detail) {
            detail.querySelector('[data-tp]').textContent = item.tujuan_pembelajaran || '-';
            detail.querySelector('[data-indikator]').textContent = item.indikator_penilaian || '-';
            detail.classList.remove('hidden');
        }
    }

    selector.addEventListener('change', function () { updateDetail(true); });
});
</script>
