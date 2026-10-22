<x-app-layout>

<x-slot name="header">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Kelas LMS</h2>
    </div>
</x-slot>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

        {{-- [DUP-FIX] $eduxidPaired dikirim dari controller — tidak query DB di view. --}}

        @if(session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">{{ session('success') }}</div>
        @endif

        @if(session('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">{{ session('error') }}</div>
        @endif

        @if(session('warning'))
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-800 px-4 py-3 rounded mb-6">{{ session('warning') }}</div>
        @endif

        @if ($errors->any())
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                <p class="font-bold mb-1">Terjadi kesalahan:</p>
                <ul class="list-disc list-inside text-sm space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(!($eduxidPaired ?? false))
            {{-- ================================================= --}}
            {{-- [PAIR-UI] Kartu pairing eduXid Cloud              --}}
            {{-- Semua ID yang dicari script ada di sini:          --}}
            {{--   eduxid-pair-toggle / eduxid-pair-form /         --}}
            {{--   eduxid-enrollment / eduxid-pair-submit /        --}}
            {{--   eduxid-pair-status                              --}}
            {{-- ================================================= --}}
            <div class="bg-white rounded-xl shadow-md border border-amber-200 p-6 mb-6">
                <div class="flex items-start justify-between flex-wrap gap-3">
                    <div>
                        <h3 class="font-bold text-gray-800">🔗 eduXid Cloud</h3>
                        <p class="text-sm text-gray-500 mt-1">
                            Laptop ini belum terpasangkan dengan eduXid Cloud.
                            Materi, tugas, dan CBT belum bisa tersinkron ke perangkat murid.
                        </p>
                    </div>
                    <button type="button" id="eduxid-pair-toggle"
                        class="shrink-0 inline-flex items-center gap-2 bg-amber-500 hover:bg-amber-600 text-white text-sm font-bold px-4 py-2 rounded-lg shadow-sm transition-colors">
                        Pairing Sekarang
                    </button>
                </div>

                <form id="eduxid-pair-form" class="hidden mt-4 space-y-3" onsubmit="return false;">
                    @csrf
                    <div>
                        <label for="eduxid-enrollment" class="block text-sm font-semibold text-gray-700 mb-1">Kode Guru (Enrollment Token)</label>
                        <input type="text" id="eduxid-enrollment" name="enrollment_token" autocomplete="off"
                            placeholder="Tempel kode enrollment dari admin sekolah"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="button" id="eduxid-pair-submit"
                            class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold px-4 py-2 rounded-lg shadow-sm transition-colors">
                            🔗 Pasangkan
                        </button>
                    </div>
                    <div id="eduxid-pair-status" class="hidden"></div>
                </form>
            </div>
        @endif

        @if(count($courses) > 0)

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

                @foreach ($courses as $course)

                    <div class="bg-white rounded-xl shadow-md overflow-hidden flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1 border border-gray-100">

                        <div class="bg-indigo-50 p-4 border-b border-indigo-100">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-lg font-bold text-indigo-900 leading-tight break-words">{{ $course->name }}</h3>
                                </div>
                                @if ($course->homeroom)
                                    <span class="shrink-0 whitespace-nowrap bg-white text-indigo-600 border border-indigo-200 px-3 py-1 rounded-full text-lg font-bold shadow-sm">{{ $course->homeroom->nama }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="p-4 flex-grow flex flex-col justify-center gap-3">

                            @if ($course->subject)
                                <p class="text-sm text-indigo-500 mt-1 font-bold">{{ $course->subject->nama_mata_pelajaran }}</p>
                            @endif

                            <div class="bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 flex items-center justify-between">
                                <span class="text-sm font-semibold text-gray-500">Status Kelas</span>
                                @if ($course->is_published)
                                    <span class="bg-green-50 text-green-700 border border-green-200 px-3 py-1 rounded-full text-xs font-bold">Dipublikasikan</span>
                                @else
                                    <span class="bg-gray-100 text-gray-600 border border-gray-200 px-3 py-1 rounded-full text-xs font-bold">Draft</span>
                                @endif
                            </div>

                            <div class="bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 flex items-center justify-between">
                                <span class="text-sm font-semibold text-gray-500">Topik Pembelajaran</span>
                                <span class="bg-indigo-50 text-indigo-600 border border-indigo-200 px-3 py-1 rounded-full text-xs font-bold">{{ $course->topics->count() }} topik</span>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <a href="{{ route('lms.courses.show', $course) }}" class="flex items-center justify-center gap-2 bg-purple-50 text-purple-700 px-4 py-3 rounded-lg hover:bg-purple-100 transition-colors text-sm border border-purple-100">Buka Kelas</a>
                                <a href="{{ route('lms.courses.edit', $course) }}" class="flex items-center justify-center gap-2 bg-purple-50 text-purple-700 px-4 py-3 rounded-lg hover:bg-purple-100 transition-colors text-sm border border-purple-100">Edit Kelas</a>
                                <a href="{{ route('lms.courses.duplicate.form', $course) }}" class="flex items-center justify-center gap-2 bg-indigo-50 text-indigo-700 px-4 py-3 rounded-lg hover:bg-indigo-100 transition-colors text-sm border border-indigo-100">Gandakan</a>
                                <form method="POST" action="{{ route('lms.courses.destroy', $course) }}" onsubmit="return confirm('Hapus kelas LMS ini? Semua topik, materi, dan tugas akan dihapus dan perintah penghapusan diteruskan ke perangkat murid.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="flex items-center justify-center gap-2 w-full bg-red-50 text-red-600 px-4 py-3 rounded-lg hover:bg-red-100 transition-colors text-sm border border-red-100">Hapus Kelas</button>
                                </form>
                            </div>

                        </div>

                    </div>

                @endforeach

            </div>

            {{-- Satu tombol pembuatan di bawah daftar kelas; tidak lagi duplikat di header. --}}
            <div class="mt-6 flex justify-end">
                <a href="{{ route('lms.courses.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-indigo-700">➕ Buat Kelas LMS</a>
            </div>

        @else

            <div class="bg-white rounded-xl shadow-sm p-12 text-center">
                <div class="text-6xl mb-4">🎓</div>
                <h3 class="text-xl font-bold text-gray-800 mb-2">Belum Ada Kelas LMS</h3>
                <p class="text-gray-500">Saat ini belum ada kelas LMS yang terdaftar pada akun Anda. Silakan buat kelas LMS untuk mulai mengelola materi dan tugas murid.</p>
                <div class="mt-6">
                    <a href="{{ route('lms.courses.create') }}" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-lg text-sm font-bold shadow-sm transition-colors">➕ Buat Kelas LMS Pertama</a>
                </div>
            </div>

        @endif

    </div>
</div>

@if(!($eduxidPaired ?? false))
<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('eduxid-pair-toggle');
    if (!toggle) { return; }

    /*
     * [ROUTE-FIX] URL pairing di-resolve di server dengan guard
     * Route::has() — memakai route terdaftar `cloud.pair`
     * (alias `lms.cloud.pair` juga terdaftar di routes/web.php).
     * Route tidak terdaftar -> null -> pesan di UI, BUKAN 500
     * (ini baris 151 yang dulu memicu error).
     */
    const pairUrl = @json(Route::has('cloud.pair') ? route('cloud.pair') : null);

    toggle.addEventListener('click', function () {
        document.getElementById('eduxid-pair-form').classList.toggle('hidden');
    });

    document.getElementById('eduxid-pair-submit').addEventListener('click', async function () {
        const btn = this,
            code = document.getElementById('eduxid-enrollment').value.trim(),
            status = document.getElementById('eduxid-pair-status');

        const showStatus = function (text, type) {
            status.textContent = text;
            status.className = 'mt-3 rounded-xl px-4 py-3 text-sm border ' + (type === 'ok'
                ? 'bg-green-50 border-green-200 text-green-700'
                : 'bg-red-50 border-red-200 text-red-700');
        };

        if (!pairUrl) {
            showStatus('⚠️ Endpoint pairing tidak tersedia (route cloud.pair tidak terdaftar).', 'err');
            return;
        }

        if (!code) {
            showStatus('⚠️ Kode Guru wajib diisi.', 'err');
            return;
        }

        btn.disabled = true;
        btn.textContent = '⏳ Memasangkan...';
        status.classList.add('hidden');

        /*
         * [CSRF-FALLBACK] meta tag dulu, lalu input @csrf dalam form.
         */
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrfInput = document.querySelector('#eduxid-pair-form input[name="_token"]');
        const token = (csrfMeta && csrfMeta.getAttribute('content')) || (csrfInput ? csrfInput.value : '');

        try {
            const response = await fetch(pairUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify({ enrollment_token: code })
            });

            /*
             * Parse manual: kalau server balas HTML (419/500),
             * response.json() langsung melempar error samar.
             */
            const raw = await response.text();
            let data;
            try {
                data = JSON.parse(raw);
            } catch (e) {
                throw new Error('Respon server tidak valid (HTTP ' + response.status + ').');
            }

            if (data.success !== true) {
                throw new Error(data.message || 'Pairing gagal.');
            }

            showStatus('✓ ' + (data.message || 'Perangkat berhasil dipasangkan.') + ' Halaman akan dimuat ulang.', 'ok');
            setTimeout(function () { window.location.reload(); }, 1500);
        } catch (error) {
            showStatus('⚠️ ' + error.message, 'err');
            btn.disabled = false;
            btn.textContent = '🔗 Pasangkan';
        }
    });
});
</script>
@endif

</x-app-layout>