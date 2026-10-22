<script>
document.addEventListener('DOMContentLoaded', function () {
    const assignmentId = @json((int) $assignment->id);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || document.querySelector('input[name="_token"]')?.value
        || '';

    function findStudentId(textarea) {
        let scope = textarea.closest('tr');
        if (!scope) scope = textarea.closest('[data-student-id]');
        if (!scope) scope = textarea.parentElement?.parentElement;

        const input = scope?.querySelector('input[name$="[student_id]"]')
            || textarea.form?.querySelector(
                'input[name="' + textarea.name.replace('[deskripsi]', '[student_id]') + '"]'
            );

        return input ? String(input.value || '') : '';
    }

    function endpoint(studentId) {
        return @json(url('/guru/atp/final-description/' . (int) $assignment->id))
            + '/' + encodeURIComponent(studentId);
    }

    document.querySelectorAll('textarea[name^="students"][name$="[deskripsi]"]').forEach(function (textarea) {
        if (textarea.dataset.atpActions === '1') return;

        const studentId = findStudentId(textarea);
        if (!studentId) return;

        textarea.dataset.atpActions = '1';

        const wrap = document.createElement('div');
        wrap.className = 'mt-2 flex flex-wrap gap-2';

        const auto = document.createElement('button');
        auto.type = 'button';
        auto.className = 'px-3 py-1.5 rounded-lg bg-indigo-50 border border-indigo-200 text-indigo-700 text-xs font-semibold hover:bg-indigo-100';
        auto.textContent = '✨ Deskripsi Otomatis';

        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'px-3 py-1.5 rounded-lg bg-red-50 border border-red-200 text-red-700 text-xs font-semibold hover:bg-red-100';
        del.textContent = '🗑️ Hapus Deskripsi';

        const msg = document.createElement('span');
        msg.className = 'text-xs text-gray-500 self-center';

        auto.addEventListener('click', async function () {
            auto.disabled = true;
            msg.textContent = 'Menyusun dari ATP dan nilai harian...';

            try {
                const response = await fetch(endpoint(studentId), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Gagal membuat deskripsi.');
                }

                textarea.value = data.description || '';
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
                msg.textContent = 'Tersimpan & masuk sinkron PWA Guru.';
            } catch (e) {
                msg.textContent = e.message || 'Gagal membuat deskripsi.';
            } finally {
                auto.disabled = false;
            }
        });

        del.addEventListener('click', async function () {
            if (!confirm('Hapus Deskripsi Capaian Kompetensi murid ini? Nilai Akhir tidak akan dihapus.')) {
                return;
            }

            del.disabled = true;
            msg.textContent = 'Menghapus deskripsi...';

            try {
                const response = await fetch(endpoint(studentId), {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Gagal menghapus deskripsi.');
                }

                textarea.value = '';
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
                msg.textContent = 'Deskripsi dihapus & disinkronkan.';
            } catch (e) {
                msg.textContent = e.message || 'Gagal menghapus deskripsi.';
            } finally {
                del.disabled = false;
            }
        });

        wrap.append(auto, del, msg);
        textarea.insertAdjacentElement('afterend', wrap);
    });
});
</script>
