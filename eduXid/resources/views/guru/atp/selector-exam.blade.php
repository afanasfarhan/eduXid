<div class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-4">
    <label for="atp_item_ids" class="block text-gray-700 text-sm font-bold mb-2">Cakupan ATP</label>
    <select name="atp_item_ids[]" id="atp_item_ids" multiple
            class="w-full px-3 py-2 border rounded-lg min-h-28">
    </select>
    <p class="text-xs text-indigo-700 mt-2">
        Pilih satu atau beberapa tujuan pembelajaran. Opsi ditampilkan bersama tingkat kelasnya.
    </p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const subject = document.getElementById('subject_id') || document.querySelector('[name="subject_id"]');
    const select = document.getElementById('atp_item_ids');
    if (!subject || !select) return;

    async function loadAtp() {
        select.innerHTML = '';
        if (!subject.value) return;

        try {
            const response = await fetch(@json(route('guru.atp.options')) + '?subject_id=' + encodeURIComponent(subject.value), {
                headers: { 'Accept': 'application/json' }
            });
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
        } catch (e) {
            console.warn('[eduXid ATP] Gagal memuat cakupan ATP', e);
        }
    }

    subject.addEventListener('change', loadAtp);
    loadAtp();
});
</script>
