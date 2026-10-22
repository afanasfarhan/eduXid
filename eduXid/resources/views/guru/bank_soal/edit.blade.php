<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-lg text-gray-800 leading-tight">
                Edit Soal
            </h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                
                @if ($errors->any())
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 text-sm">
                        <strong>Gagal Update!</strong> Periksa kembali input Anda.
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

                @php
                    // 1. PROSES DATA OPSI JAWABAN
                    $opsiRaw = $soal->opsi_jawaban;
                    $opsiArray = [];
                    
                    if (is_string($opsiRaw)) {
                        $decoded = json_decode($opsiRaw, true);
                        if (is_string($decoded)) { $decoded = json_decode($decoded, true); }
                        $opsiArray = is_array($decoded) ? $decoded : [];
                    } elseif (is_array($opsiRaw)) {
                        $opsiArray = $opsiRaw;
                    }

                    $alpineOptions = [];
                    if (!empty($opsiArray)) {
                        // Cek apakah ini Array of Objects (dari import)
                        if (isset($opsiArray[0]) && is_array($opsiArray[0])) {
                            foreach ($opsiArray as $opt) {
                                $alpineOptions[] = ['text' => (string)($opt['text'] ?? '')];
                            }
                        } else {
                            // Jika Associative Array (A => Teks A)
                            foreach ($opsiArray as $key => $val) {
                                $alpineOptions[] = ['text' => (string)$val];
                            }
                        }
                    } else {
                        $alpineOptions = [['text' => ''], ['text' => ''], ['text' => '']];
                    }

                    // 2. PROSES DATA KUNCI JAWABAN
                    $kunciRaw = $soal->kunci_jawaban;
                    $kunciArr = [];
                    $kunciStr = '';

                    if (is_string($kunciRaw)) {
                        $decodedK = json_decode($kunciRaw, true);
                        if (is_array($decodedK)) {
                            $kunciArr = $decodedK;
                            $kunciStr = implode(',', $decodedK);
                        } else {
                            $kunciStr = $kunciRaw;
                            if (!empty($kunciStr)) $kunciArr = [$kunciStr];
                        }
                    } elseif (is_array($kunciRaw)) {
                        $kunciArr = $kunciRaw;
                        $kunciStr = implode(',', $kunciRaw);
                    }
                @endphp

                {{-- M2: enctype WAJIB agar file gambar_soal terkirim --}}
                <form action="{{ route('guru.bankSoal.update', $soal->id) }}" method="POST" enctype="multipart/form-data" x-data="soalForm()">
                    @csrf
                    @method('PUT')
                    
                                        @include('guru.atp.selector-bank-soal', ['selectedAtpId' => old('atp_item_id', $soal->atp_item_id ?? null)])

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <x-input-label for="subject_id" value="Mata Pelajaran" class="text-xs font-bold" />
                            <select name="subject_id" id="subject_id" required class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">-- Pilih Mata Pelajaran --</option>
                                @foreach($mapels as $mapel)
                                    <option value="{{ $mapel->id }}" @selected((string) old('subject_id', $soal->subject_id) === (string) $mapel->id)>
                                        {{ $mapel->nama_mata_pelajaran }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('subject_id')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="tingkat_kelas" value="Tingkat Kelas" class="text-xs font-bold" />
                            <select name="tingkat_kelas" id="tingkat_kelas" required
                                    data-selected="{{ old('tingkat_kelas', $soal->tingkat_kelas) }}"
                                    class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">-- Pilih Tingkat --</option>
                            </select>
                            @if(empty($soal->tingkat_kelas))
                                <p class="text-[11px] text-amber-600 mt-1">Soal lama ini belum memiliki tingkat kelas. Pilih tingkat sebelum menyimpan.</p>
                            @else
                                <p class="text-[11px] text-gray-500 mt-1">Hanya tingkat yang Anda ampu untuk mata pelajaran terpilih pada semester aktif.</p>
                            @endif
                            <x-input-error :messages="$errors->get('tingkat_kelas')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="jenis_soal" value="Jenis Soal" class="text-xs font-bold" />
                            <select name="jenis_soal" id="jenis_soal" x-model="jenisSoal" required class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="pilihan_ganda">Pilihan Ganda (Satu Jawaban)</option>
                                <option value="majemuk">Pilihan Majemuk (Multi Jawaban)</option>
                                <option value="benar_salah">Benar / Salah</option>
                                <option value="jawaban_singkat">Jawaban Singkat / Isian</option>
                            </select>
                            <x-input-error :messages="$errors->get('jenis_soal')" class="mt-2" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <x-input-label for="bobot_nilai" value="Bobot Nilai" class="text-xs font-bold" />
                            <x-text-input name="bobot_nilai" type="number" step="0.1" value="{{ $soal->bobot_nilai }}" required class="mt-1 block w-full text-xs py-1.5" />
                        </div>
                        <div>
                            <x-input-label for="level_kognitif" value="Level Kognitif (Bloom)" class="text-xs font-bold" />
                            <select name="level_kognitif" id="level_kognitif" required class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="C1" @selected($soal->level_kognitif == 'C1')>C1 - Mengingat</option>
                                <option value="C2" @selected($soal->level_kognitif == 'C2')>C2 - Memahami</option>
                                <option value="C3" @selected($soal->level_kognitif == 'C3')>C3 - Menerapkan</option>
                                <option value="C4" @selected($soal->level_kognitif == 'C4')>C4 - Menganalisis</option>
                                <option value="C5" @selected($soal->level_kognitif == 'C5')>C5 - Mengevaluasi</option>
                                <option value="C6" @selected($soal->level_kognitif == 'C6')>C6 - Mencipta</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="judul_materi" value="Materi / Submateri" class="text-xs font-bold" />
                            <x-text-input name="judul_materi" type="text" value="{{ $soal->judul_materi }}" class="mt-1 block w-full text-xs py-1.5" />
                        </div>
                    </div>

                    <div class="mb-4">
                        <x-input-label for="pertanyaan" value="Pertanyaan" class="text-xs font-bold" />
                        <textarea name="pertanyaan" id="pertanyaan" required rows="5" class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 font-mono">{{ $soal->pertanyaan }}</textarea>
                        <p class="text-xs mt-1">Penulisan rumus matematika gunakan tanda dolar. Contoh: <code>$x^2 + y^2 = z^2$.</code><a href="https://katex.org/docs/supported.html" target="_blank"> Untuk bantuan, klik link ini.</a></p>
                        
                        <div class="mt-2 flex items-center gap-2">
                            <button type="button" id="btnUploadPertanyaan" class="bg-gray-200 hover:bg-gray-300 text-gray-700 text-xs px-3 py-1 rounded-md font-bold">
                                🖼️ Sisipkan Gambar
                            </button>
                            <input type="file" id="filePertanyaan" class="hidden" accept="image/*">
                            <span id="uploadStatus" class="text-xs text-gray-500"></span>
                        </div>
                    </div>

                    <div class="mb-4">
                        <x-input-label for="indikator" value="Indikator / Kisi-kisi (Opsional)" class="text-xs font-bold" />
                        <textarea name="indikator" id="indikator" rows="2" class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ $soal->indikator }}</textarea>
                    </div>

                    <!-- ===== M2: MEDIA SOAL (gambar utuh + video, ikut ke PWA) ===== -->
                    <div class="border-t pt-4 mt-4 mb-4">
                        <h3 class="font-bold text-gray-700 text-sm mb-3">🖼️ Media Soal (Opsional)</h3>

                        <div class="mb-4">
                            <x-input-label for="gambar_soal" value="Ganti Gambar Soal (jpg/png/webp — maks 200 KB)" class="text-xs font-bold" />
                            <input type="file" name="gambar_soal" id="gambar_soal" accept="image/jpeg,image/png,image/webp"
                                   class="mt-1 block w-full text-xs border-gray-300 rounded-md shadow-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-indigo-50 file:text-indigo-700 file:font-bold file:cursor-pointer">

                            @if($soal->gambar_soal)
                                <div class="mt-2">
                                    <p class="text-[11px] font-bold text-gray-500 mb-1">Gambar saat ini:</p>
                                    <img src="{{ $soal->gambar_soal }}" alt="Gambar soal saat ini" class="max-h-36 rounded-lg border border-gray-200">
                                </div>
                            @endif

                            {{-- Preview file BARU yang dipilih (menggantikan preview lama secara visual) --}}
                            <div id="preview-gambar" class="mt-2 hidden">
                                <p class="text-[11px] font-bold text-gray-500 mb-1">Gambar baru:</p>
                                <img id="img-preview" src="" alt="Preview gambar baru" class="max-h-36 rounded-lg border border-indigo-200">
                                <div>
                                    <button type="button" id="btn-remove-gambar" class="mt-1 text-red-500 text-xs font-medium hover:text-red-700">✕ Batalkan gambar baru ini</button>
                                </div>
                            </div>

                            {{-- Hapus gambar existing (hanya tampil jika ada gambar lama) --}}
                            @if($soal->gambar_soal)
                                <label class="mt-2 inline-flex items-center gap-2 text-xs text-red-600 cursor-pointer">
                                    <input type="checkbox" name="remove_gambar" value="1" class="h-4 w-4 text-red-600 border-gray-300 rounded">
                                    Hapus gambar soal ini
                                </label>
                            @endif

                            <p class="text-[11px] text-gray-500 mt-1">Tanpa mengunggah/menghapus apa pun, gambar lama dipertahankan.</p>
                            <x-input-error :messages="$errors->get('gambar_soal')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="video_url" value="Link Video (opsional — YouTube / URL video)" class="text-xs font-bold" />
                            <input type="url" name="video_url" id="video_url" value="{{ $soal->video_url }}"
                                   placeholder="https://youtube.com/watch?v=..."
                                   class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <p class="text-[11px] text-gray-500 mt-1">Video diputar streaming — murid butuh internet untuk membukanya.</p>
                            <x-input-error :messages="$errors->get('video_url')" class="mt-2" />
                        </div>
                    </div>

                    <!-- Bagian Dinamis Berdasarkan Jenis Soal -->
                    <div class="border-t pt-4 mt-4">
                        
                        <!-- Jika Pilihan Ganda / Majemuk -->
                        <div class="border border-indigo-200 p-3 mb-4 rounded-lg bg-indigo-50">
                            <div class="font-semibold text-sm text-indigo-900 mb-2">Stimulus Kontekstual Bersama</div>
                            <label class="text-xs font-bold">Kode grup (opsional)</label>
                            <input type="text" name="tka_group_code" maxlength="96" value="{{ old('tka_group_code', $soal->tka_group_code ?? '') }}" class="w-full mt-1 mb-2 border-gray-300 rounded-md text-sm">
                            <label class="text-xs font-bold">Stimulus kontekstual bersama</label>
                            <textarea name="tka_stimulus" rows="3" class="w-full mt-1 border-gray-300 rounded-md text-sm">{{ old('tka_stimulus', $soal->tka_stimulus ?? '') }}</textarea>
                        </div>
                        <div x-show="jenisSoal === 'pilihan_ganda' || jenisSoal === 'majemuk'" x-cloak>
                            <div class="flex justify-between items-center mb-3">
                                <h3 class="font-bold text-gray-700 text-sm">Opsi Jawaban</h3>
                                <button type="button" @click="addOption()" x-show="options.length < 10" class="text-indigo-600 text-xs font-bold hover:text-indigo-800">+ Tambah Opsi</button>
                            </div>
                            
                            <template x-for="(option, index) in options" :key="index">
                                <div class="flex items-center gap-3 mb-3">
                                    <span class="font-bold text-gray-500 w-6 text-xs" x-text="getLetter(index) + '.'"></span>
                                    <input type="text" :name="`opsi[${getLetter(index)}]`" x-model="option.text" class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Teks opsi">
                                    
                                    <input type="radio" name="kunci_pg" :value="getLetter(index)" :checked="kunci.includes(getLetter(index))" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" x-show="jenisSoal === 'pilihan_ganda'">
                                    <input type="checkbox" name="kunci_majemuk[]" :value="getLetter(index)" :checked="kunci.includes(getLetter(index))" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" x-show="jenisSoal === 'majemuk'">
                                    
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
                                    <input type="radio" name="kunci_pg" value="B" @checked($kunciStr == 'B') class="h-4 w-4 text-green-600"> 
                                    <span class="font-medium text-green-700">Benar</span>
                                </label>
                                <label class="flex items-center gap-2 bg-red-50 px-4 py-1.5 rounded-md border border-red-200 cursor-pointer text-xs">
                                    <input type="radio" name="kunci_pg" value="S" @checked($kunciStr == 'S') class="h-4 w-4 text-red-600"> 
                                    <span class="font-medium text-red-700">Salah</span>
                                </label>
                            </div>
                        </div>

                        <!-- Jika Jawaban Singkat -->
                        <div x-show="jenisSoal === 'jawaban_singkat'" x-cloak>
                            <h3 class="font-bold text-gray-700 mb-2 text-sm">Kunci Jawaban</h3>
                            <input type="text" name="kunci_singkat" value="{{ $kunciStr }}" class="mt-1 block w-full md:w-1/2 text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            <p class="text-[11px] text-gray-500 mt-1">Sistem akan mencocokkan teks ini (tidak case-sensitive) saat mengoreksi CBT.</p>
                        </div>

                    </div>

                    <div class="flex items-center justify-end mt-6 border-t pt-4">
                        <a href="{{ route('guru.bankSoal') }}" class="text-gray-600 mr-4 hover:text-gray-900 text-xm">Batal</a>
                        <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 font-bold text-xm">Update Soal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <style>
        [x-cloak] { display: none !important; }
    </style>

    <script>
        function soalForm() {
            return {
                jenisSoal: @json($soal->jenis_soal),
                options: @json($alpineOptions),
                kunci: @json($kunciArr),
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

        // Logika Upload Gambar (rich-text)
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

                    e.target.value = '';
                });
            }

            // ===== M2: Preview gambar BARU + batalkan pilihan =====
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