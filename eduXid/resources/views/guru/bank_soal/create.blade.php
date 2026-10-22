<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-lg text-gray-800 leading-tight">
                Tambah Soal Baru
            </h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                
                @if ($errors->any())
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 text-sm">
                        <strong>Gagal Menyimpan!</strong> Periksa kembali input Anda.
                        <ul class="mt-2 list-disc list-inside text-xs">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if(session('error'))
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 text-sm">{{ session('error') }}</div>
                @endif

                @if($mapels->isEmpty() || empty($tingkatDiajar))
                    <div class="bg-amber-50 border border-amber-300 text-amber-800 px-4 py-3 rounded mb-4 text-sm">
                        Belum ada penugasan mengajar dengan tingkat kelas 10/11/12 pada semester aktif.
                        Periksa data <b>Pembelajaran</b> dan <b>Kelas</b> terlebih dahulu.
                    </div>
                @endif

                {{-- M2: enctype WAJIB agar file gambar_soal terkirim ke server --}}
                <form action="{{ route('guru.bankSoal.store') }}" method="POST" enctype="multipart/form-data" x-data="soalForm()">
                    @csrf

                                        @include('guru.atp.selector-bank-soal', ['selectedAtpId' => old('atp_item_id')])

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <x-input-label for="subject_id" value="Mata Pelajaran" class="text-xs font-bold" />
                            <select name="subject_id" id="subject_id" required class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">-- Pilih Mata Pelajaran --</option>
                                @foreach($mapels as $mapel)
                                    <option value="{{ $mapel->id }}" @selected((string) old('subject_id') === (string) $mapel->id)>
                                        {{ $mapel->nama_mata_pelajaran }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('subject_id')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="tingkat_kelas" value="Tingkat Kelas" class="text-xs font-bold" />
                            <select name="tingkat_kelas" id="tingkat_kelas" required
                                    data-selected="{{ old('tingkat_kelas') }}"
                                    class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">-- Pilih Tingkat --</option>
                            </select>
                            <p class="text-[11px] text-gray-500 mt-1">Hanya tingkat yang Anda ampu untuk mata pelajaran terpilih pada semester aktif.</p>
                            <x-input-error :messages="$errors->get('tingkat_kelas')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="jenis_soal" value="Jenis Soal" class="text-xs font-bold" />
                            <select name="jenis_soal" id="jenis_soal" x-model="jenisSoal" required class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="pilihan_ganda">Pilihan Ganda (Satu Jawaban)</option>
                                <option value="majemuk">Pilihan Majemuk (Multi Jawaban)</option>
                                <option value="benar_salah">Benar / Salah</option>
                                <option value="jawaban_singkat">Jawaban Singkat / Isian</option>
                                <option value="tka_kategori">Kategori (Benar/Salah per Pernyataan)</option>
                            </select>
                            <x-input-error :messages="$errors->get('jenis_soal')" class="mt-2" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <x-input-label for="bobot_nilai" value="Bobot Nilai" class="text-xs font-bold" />
                            <x-text-input name="bobot_nilai" type="number" step="0.1" value="1.00" required class="mt-1 block w-full text-xs py-1.5" />
                            <x-input-error :messages="$errors->get('bobot_nilai')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="level_kognitif" value="Level Kognitif (Bloom)" class="text-xs font-bold" />
                            <select name="level_kognitif" id="level_kognitif" required class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="C1">C1 - Mengingat</option>
                                <option value="C2">C2 - Memahami</option>
                                <option value="C3" selected>C3 - Menerapkan</option>
                                <option value="C4">C4 - Menganalisis</option>
                                <option value="C5">C5 - Mengevaluasi</option>
                                <option value="C6">C6 - Mencipta</option>
                            </select>
                            <x-input-error :messages="$errors->get('level_kognitif')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="judul_materi" value="Materi / Submateri" class="text-xs font-bold" />
                            <x-text-input name="judul_materi" type="text" placeholder="Listrik Arus Searah / Hukum Ohm" class="mt-1 block w-full text-xs py-1.5" />
                            <x-input-error :messages="$errors->get('judul_materi')" class="mt-2" />
                        </div>
                    </div>

                    <div class="mb-4">
                        <x-input-label for="indikator" value="Indikator / Kisi-kisi (Opsional)" class="text-xs font-bold" />
                        <textarea name="indikator" id="indikator" rows="2" class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                        <x-input-error :messages="$errors->get('indikator')" class="mt-2" />
                    </div>

                    <div class="mb-4">
                        <x-input-label for="pertanyaan" value="Pertanyaan" class="text-xs font-bold" />
                        <textarea name="pertanyaan" id="pertanyaan" required rows="5" class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 font-mono" placeholder="Tulis pertanyaan di sini..."></textarea>
                        <p class="text-xs mt-1">Penulisan rumus matematika gunakan tanda dolar. Contoh: <code>$x^2 + y^2 = z^2$.</code><a href="https://katex.org/docs/supported.html" target="_blank"> Untuk bantuan, klik link ini.</a></p>
                        
                        <!-- Tombol Sisipkan Gambar (rich-text, untuk <img> di dalam pertanyaan) -->
                        <div class="mt-2 flex items-center gap-2">
                            <button type="button" id="btnUploadPertanyaan" class="bg-gray-200 hover:bg-gray-300 text-gray-700 text-xs px-3 py-1 rounded-md font-bold">
                                🖼️ Sisipkan Gambar
                            </button>
                            <input type="file" id="filePertanyaan" class="hidden" accept="image/*">
                            <span id="uploadStatus" class="text-xs text-gray-500"></span>
                        </div>
                        <x-input-error :messages="$errors->get('pertanyaan')" class="mt-2" />
                    </div>

                    <!-- ===== M2: MEDIA SOAL (gambar utuh + video, ikut ke PWA) ===== -->
                    <div class="border-t pt-4 mt-4 mb-4">
                        <h3 class="font-bold text-gray-700 text-sm mb-3">🖼️ Media Soal (Opsional)</h3>

                        <div class="mb-4">
                            <x-input-label for="gambar_soal" value="Gambar Soal (jpg/png/webp — maks 200 KB)" class="text-xs font-bold" />
                            <input type="file" name="gambar_soal" id="gambar_soal" accept="image/jpeg,image/png,image/webp"
                                   class="mt-1 block w-full text-xs border-gray-300 rounded-md shadow-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-indigo-50 file:text-indigo-700 file:font-bold file:cursor-pointer">
                            <div id="preview-gambar" class="mt-2 hidden">
                                <img id="img-preview" src="" alt="Preview" class="max-h-36 rounded-lg border border-gray-200">
                                <button type="button" id="btn-remove-gambar" class="mt-1 text-red-500 text-xs font-medium hover:text-red-700">✕ Hapus gambar terpilih</button>
                            </div>
                            <p class="text-[11px] text-gray-500 mt-1">Gambar ikut tersimpan <b>offline</b> di HP murid. Sisakan untuk 1 gambar per soal (maks 200 KB).</p>
                            <x-input-error :messages="$errors->get('gambar_soal')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="video_url" value="Link Video (opsional — YouTube / URL video)" class="text-xs font-bold" />
                            <input type="url" name="video_url" id="video_url"
                                   placeholder="https://youtube.com/watch?v=..."
                                   class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <p class="text-[11px] text-gray-500 mt-1">Video diputar streaming — murid butuh internet untuk membukanya.</p>
                            <x-input-error :messages="$errors->get('video_url')" class="mt-2" />
                        </div>
                    </div>

                    <div class="border border-indigo-200 rounded-lg p-4 mb-4 bg-indigo-50">
                        <div class="font-semibold text-sm text-indigo-900 mb-2">Stimulus Kontekstual Bersama</div>
                        <label class="text-xs font-medium">Kode grup (sama untuk soal dengan stimulus sama)</label>
                        <input type="text" name="tka_group_code" value="{{ old('tka_group_code') }}" maxlength="96" placeholder="Contoh: EKSPERIMEN-GERAK-01" class="mt-1 mb-2 block w-full rounded-md text-sm border-gray-300">
                        <label class="text-xs font-medium">Stimulus kontekstual bersama: narasi, data, atau tabel berformat teks/KaTeX</label>
                        <textarea name="tka_stimulus" rows="3" class="mt-1 block w-full rounded-md text-sm border-gray-300" placeholder="Teks/grafik/tabel stimulus untuk grup soal">{{ old('tka_stimulus') }}</textarea>
                        <p class="text-xs text-gray-600 mt-2">Untuk soal kategori, pernyataan dibuat sebagai butir Benar/Salah dalam satu grup otomatis. Setiap pernyataan memakai satu nomor LJK.</p>
                    </div>
                    <div x-show="jenisSoal === 'tka_kategori'" x-cloak class="border rounded-lg p-4 mb-4 bg-amber-50">
                        <label class="font-semibold text-sm">Pernyataan kategori dan kunci (2–10 baris)</label>
                        <p class="text-xs my-2">Satu baris: <code>B|Pernyataan benar</code> atau <code>S|Pernyataan salah</code>. Setiap pernyataan wajib dijawab.</p>
                        <textarea name="tka_pernyataan" rows="6" class="w-full text-sm rounded-md border-gray-300 font-mono" placeholder="B|Kecepatan benda konstan.&#10;S|Percepatan benda selalu nol.">{{ old('tka_pernyataan') }}</textarea>
                    </div>
                    <!-- Bagian Dinamis Berdasarkan Jenis Soal -->
                    <div class="border-t pt-4 mt-4">
                        
                        <!-- Jika Pilihan Ganda / Majemuk -->
                        <div x-show="jenisSoal === 'pilihan_ganda' || jenisSoal === 'majemuk'" x-cloak>
                            <div class="flex justify-between items-center mb-3">
                                <h3 class="font-bold text-gray-700 text-sm">Opsi Jawaban</h3>
                                <button type="button" @click="addOption()" x-show="options.length < 10" class="text-indigo-600 text-xs font-bold hover:text-indigo-800">+ Tambah Opsi</button>
                            </div>
                            
                            <template x-for="(option, index) in options" :key="index">
                                <div class="flex items-center gap-3 mb-3">
                                    <span class="font-bold text-gray-500 w-6 text-xs" x-text="getLetter(index) + '.'"></span>
                                    <input type="text" :name="`opsi[${getLetter(index)}]`" x-model="option.text" class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Teks opsi">
                                    
                                    <input type="radio" name="kunci_pg" :value="getLetter(index)" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" x-show="jenisSoal === 'pilihan_ganda'">
                                    <input type="checkbox" name="kunci_majemuk[]" :value="getLetter(index)" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" x-show="jenisSoal === 'majemuk'">
                                    
                                    <button type="button" @click="removeOption(index)" x-show="options.length > 1" class="text-red-500 hover:text-red-700 text-xs font-medium">Hapus</button>
                                </div>
                            </template>
                            
                            <p class="text-[11px] text-gray-500 mt-2" x-show="jenisSoal === 'majemuk'">Centang semua jawaban yang benar.</p>
                            <p class="text-[11px] text-gray-500 mt-2" x-show="jenisSoal === 'pilihan_ganda'">Pilih satu jawaban yang paling benar.</p>
                        </div>

                        <!-- Jika Benar / Salah -->
                        <div x-show="jenisSoal === 'benar_salah'" x-cloak>
                            <h3 class="font-bold text-gray-700 mb-2 text-sm">Kunci Jawaban</h3>
                            <div class="flex gap-4 mt-2">
                                <label class="flex items-center gap-2 bg-green-50 px-4 py-1.5 rounded-md border border-green-200 cursor-pointer text-xs">
                                    <input type="radio" name="kunci_pg" value="B" class="h-4 w-4 text-green-600"> 
                                    <span class="font-medium text-green-700">Benar</span>
                                </label>
                                <label class="flex items-center gap-2 bg-red-50 px-4 py-1.5 rounded-md border border-red-200 cursor-pointer text-xs">
                                    <input type="radio" name="kunci_pg" value="S" class="h-4 w-4 text-red-600"> 
                                    <span class="font-medium text-red-700">Salah</span>
                                </label>
                            </div>
                        </div>

                        <!-- Jika Jawaban Singkat -->
                        <div x-show="jenisSoal === 'jawaban_singkat'" x-cloak>
                            <h3 class="font-bold text-gray-700 mb-2 text-sm">Kunci Jawaban</h3>
                            <input type="text" name="kunci_singkat" class="mt-1 block w-full md:w-1/2 text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Ketik jawaban yang diharapkan" />
                            <p class="text-[11px] text-gray-500 mt-1">Sistem akan mencocokkan teks ini (tidak case-sensitive) saat mengoreksi CBT.</p>
                        </div>

                    </div>

                    <div class="flex items-center justify-end mt-6 border-t pt-4">
                        <a href="{{ route('guru.bankSoal') }}" class="text-gray-600 mr-4 hover:text-gray-900 text-sm">Batal</a>
                        <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 font-bold text-sm">Simpan Soal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <style>
        [x-cloak] { display: none !important; }
    </style>

    <script>
        // Inisialisasi Alpine.js untuk Form Soal
        function soalForm() {
            return {
                jenisSoal: 'pilihan_ganda',
                // Default 3 opsi (A, B, C)
                options: [
                    { text: '' }, 
                    { text: '' }, 
                    { text: '' }
                ],
                addOption() {
                    if (this.options.length < 10) {
                        this.options.push({ text: '' });
                    }
                },
                removeOption(index) {
                    if (this.options.length > 1) {
                        this.options.splice(index, 1);
                    }
                },
                getLetter(index) {
                    // Mengubah index 0, 1, 2 menjadi huruf A, B, C...
                    return String.fromCharCode(65 + index);
                }
            }
        }

        const tingkatByMapel = @json($tingkatByMapel);

        function refreshTingkatKelas() {
            const subjectSelect = document.getElementById('subject_id');
            const gradeSelect = document.getElementById('tingkat_kelas');

            if (!subjectSelect || !gradeSelect) return;

            const selectedSubject = String(subjectSelect.value || '');
            const requestedGrade = String(gradeSelect.dataset.selected || gradeSelect.value || '');
            const grades = Array.isArray(tingkatByMapel[selectedSubject])
                ? tingkatByMapel[selectedSubject]
                : [];

            gradeSelect.innerHTML = '<option value="">-- Pilih Tingkat --</option>';

            grades.forEach((grade) => {
                const option = document.createElement('option');
                option.value = String(grade);
                option.textContent = 'Kelas ' + grade;
                if (String(grade) === requestedGrade) option.selected = true;
                gradeSelect.appendChild(option);
            });

            gradeSelect.dataset.selected = '';
        }

        // Logika Upload Gambar untuk Pertanyaan (rich-text)
        document.addEventListener('DOMContentLoaded', function() {
            const subjectSelect = document.getElementById('subject_id');
            if (subjectSelect) {
                refreshTingkatKelas();
                subjectSelect.addEventListener('change', function () {
                    const gradeSelect = document.getElementById('tingkat_kelas');
                    if (gradeSelect) gradeSelect.dataset.selected = '';
                    refreshTingkatKelas();
                });
            }
            const btnUploadPertanyaan = document.getElementById('btnUploadPertanyaan');
            const filePertanyaan = document.getElementById('filePertanyaan');
            const uploadStatus = document.getElementById('uploadStatus');

            if(btnUploadPertanyaan) {
                btnUploadPertanyaan.addEventListener('click', () => filePertanyaan.click());

                filePertanyaan.addEventListener('change', (e) => {
                    const file = e.target.files[0];
                    if (!file) return;

                    const formData = new FormData();
                    formData.append('image', file);
                    formData.append('_token', '{{ csrf_token() }}');

                    uploadStatus.textContent = 'Mengunggah gambar...';
                    uploadStatus.style.color = 'blue';

                    fetch('{{ route("guru.bankSoal.uploadImage") }}', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.url) {
                            const textarea = document.getElementById('pertanyaan');
                            const imgTag = `<img src="${data.url}" alt="Gambar Soal" style="max-width: 100%; height: auto; margin: 5px 0;">`;
                            
                            // Sisipkan tag gambar di posisi kursor terakhir
                            const pos = textarea.selectionStart;
                            textarea.value = textarea.value.substring(0, pos) + imgTag + textarea.value.substring(pos);
                            
                            uploadStatus.textContent = 'Gambar berhasil disisipkan!';
                            uploadStatus.style.color = 'green';
                        } else {
                            uploadStatus.textContent = 'Gagal mengunggah gambar.';
                            uploadStatus.style.color = 'red';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        uploadStatus.textContent = 'Terjadi kesalahan sistem.';
                        uploadStatus.style.color = 'red';
                    });

                    // Reset input file agar bisa upload gambar yang sama lagi jika perlu
                    e.target.value = '';
                });
            }

            // ===== M2: Preview gambar soal + tombol hapus pilihan =====
            const inputGambarSoal = document.getElementById('gambar_soal');
            const previewWrap = document.getElementById('preview-gambar');
            const imgPreview = document.getElementById('img-preview');
            const btnRemoveGambar = document.getElementById('btn-remove-gambar');

            if (inputGambarSoal) {
                inputGambarSoal.addEventListener('change', function () {
                    const file = this.files && this.files[0];
                    if (!file) { previewWrap.classList.add('hidden'); return; }

                    if (file.size > 200 * 1024) {
                        alert('Ukuran gambar ' + Math.round(file.size / 1024) + ' KB melebihi batas 200 KB. Kecilkan dulu gambarnya.');
                        this.value = '';
                        previewWrap.classList.add('hidden');
                        return;
                    }

                    imgPreview.src = URL.createObjectURL(file);
                    previewWrap.classList.remove('hidden');
                });

                if (btnRemoveGambar) {
                    btnRemoveGambar.addEventListener('click', function () {
                        inputGambarSoal.value = '';
                        imgPreview.src = '';
                        previewWrap.classList.add('hidden');
                    });
                }
            }
        });
    </script>
</x-app-layout>