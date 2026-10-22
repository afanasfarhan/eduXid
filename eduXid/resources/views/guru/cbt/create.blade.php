<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Buat Paket Ujian</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-md rounded-lg p-6">

                @if ($errors->any())
                    <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <div class="font-bold mb-1">Data ujian belum dapat disimpan.</div>
                        <ul class="list-disc list-inside space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if(session('error'))
                    <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {{ session('error') }}
                    </div>
                @endif

                <form action="{{ route('guru.cbt.store') }}" method="POST" id="cbt-create-form">
                    @csrf

                    <div class="mb-4">
                        <label for="judul_ujian" class="block text-gray-700 text-sm font-bold mb-2">Judul Ujian</label>
                        <input
                            id="judul_ujian"
                            type="text"
                            name="judul_ujian"
                            value="{{ old('judul_ujian') }}"
                            class="w-full px-3 py-2 border rounded-lg"
                            placeholder="Contoh: PSAS Fisika Kelas XII"
                            required
                        >
                    </div>

                    <div class="mb-4">
                        <label for="subject_id" class="block text-gray-700 text-sm font-bold mb-2">Mata Pelajaran</label>
                        <select id="subject_id" name="subject_id" class="w-full px-3 py-2 border rounded-lg" required>
                            <option value="">-- Pilih Mapel --</option>
                            @foreach($subjects as $subject)
                                <option value="{{ $subject->id }}" @selected((string) old('subject_id') === (string) $subject->id)>
                                    {{ $subject->nama_mata_pelajaran }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-4">
                        <label for="tingkat_kelas" class="block text-gray-700 text-sm font-bold mb-2">Tingkat Kelas</label>
                        <select
                            id="tingkat_kelas"
                            name="tingkat_kelas"
                            class="w-full px-3 py-2 border rounded-lg disabled:bg-gray-100 disabled:text-gray-400"
                            required
                            disabled
                        >
                            <option value="">-- Pilih Mata Pelajaran Terlebih Dahulu --</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">
                            Hanya tingkat kelas yang Anda ampu untuk mata pelajaran tersebut pada semester aktif yang tersedia.
                            Murid dari tingkat lain otomatis ditolak saat mengikuti CBT.
                        </p>
                    </div>

                    <div class="mb-4">
                        <label for="jenis_penilaian" class="block text-gray-700 text-sm font-bold mb-2">Jenis Penilaian</label>
                        <select id="jenis_penilaian" name="jenis_penilaian" class="w-full px-3 py-2 border rounded-lg" required>
                            <option value="">-- Pilih Jenis --</option>
                            <option value="diagnostik" @selected(old('jenis_penilaian') === 'diagnostik')>Diagnostik</option>
                            <option value="formatif" @selected(old('jenis_penilaian') === 'formatif')>Formatif</option>
                            <option value="sumatif" @selected(old('jenis_penilaian') === 'sumatif')>Sumatif</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Nilai CBT akan otomatis masuk ke kolom ini di menu Input Nilai Harian.</p>
                    </div>

                    <div class="mb-6">
                        <label for="durasi_menit" class="block text-gray-700 text-sm font-bold mb-2">Durasi (Menit)</label>
                        <input
                            id="durasi_menit"
                            type="number"
                            min="1"
                            name="durasi_menit"
                            value="{{ old('durasi_menit') }}"
                            class="w-full px-3 py-2 border rounded-lg"
                            placeholder="Contoh: 90"
                            required
                        >
                    </div>

                    <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2 rounded-lg hover:bg-blue-700">
                        Simpan &amp; Generate Token
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const subjectGradeMap = @json($subjectGradeMap ?? []);
            const subjectSelect = document.getElementById('subject_id');
            const gradeSelect = document.getElementById('tingkat_kelas');
            const oldGrade = String(@json((string) old('tingkat_kelas', '')));

            function renderGrades() {
                const subjectId = String(subjectSelect.value || '');
                const grades = Array.isArray(subjectGradeMap[subjectId])
                    ? subjectGradeMap[subjectId]
                    : [];

                gradeSelect.innerHTML = '';

                if (!subjectId) {
                    gradeSelect.disabled = true;
                    gradeSelect.innerHTML = '<option value="">-- Pilih Mata Pelajaran Terlebih Dahulu --</option>';
                    return;
                }

                if (grades.length === 0) {
                    gradeSelect.disabled = true;
                    gradeSelect.innerHTML = '<option value="">-- Tingkat Kelas Tidak Ditemukan --</option>';
                    return;
                }

                gradeSelect.disabled = false;
                gradeSelect.appendChild(new Option('-- Pilih Tingkat Kelas --', ''));

                grades.forEach(function (grade) {
                    const option = new Option('Kelas ' + grade, String(grade));
                    if (oldGrade !== '' && oldGrade === String(grade)) {
                        option.selected = true;
                    }
                    gradeSelect.appendChild(option);
                });
            }

            subjectSelect.addEventListener('change', function () {
                // Setelah guru mengganti mapel, jangan mempertahankan tingkat lama.
                gradeSelect.dataset.userChangedSubject = '1';
                renderGrades();
                if (gradeSelect.dataset.userChangedSubject === '1') {
                    gradeSelect.value = '';
                }
            });

            renderGrades();
        });
    </script>
</x-app-layout>
