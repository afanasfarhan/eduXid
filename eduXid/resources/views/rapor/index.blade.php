<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Cetak Rapor Murid
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-100">
                <div class="p-6 text-gray-900">
                    
                    <!-- Baris Atas: Dropdown & Setting Kiri | Tombol Aksi Kanan -->
                    <div class="mb-6 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                        
                        <!-- Kiri: Pilih Kelas & Setting Cetak -->
                        <div class="flex flex-col gap-4 w-full md:w-auto">
                            <div class="flex items-center gap-4">
                                <label class="text-sm font-medium text-gray-700 whitespace-nowrap">Pilih Kelas:</label>
                                <select id="kelas-select" class="border-gray-300 rounded-md shadow-sm p-2 text-sm w-full md:w-[600px]">
                                    <option value="">-- Pilih Kelas --</option>
                                    @foreach ($rombels as $r)
                                        <option value="{{ $r->id }}">{{ $r->nama }}</option>
                                    @endforeach
                                </select>
                            </div>
                            
                            <!-- Input Tempat & Tanggal -->
                            <div class="flex items-center gap-4 flex-wrap">
                                <div class="flex items-center gap-2">
                                    <label class="text-sm font-medium text-gray-700 whitespace-nowrap">Tempat:</label>
                                    <input type="text" id="tempat-cetak" class="border-gray-300 rounded-md shadow-sm p-2 text-sm" placeholder="Cth: Bekasi" value="{{ DB::table('schools')->value('kabupaten_kota') ?? '' }}" autocomplete="off">
                                </div>
                                <div class="flex items-center gap-2">
                                    <label class="text-sm font-medium text-gray-700 whitespace-nowrap">Tanggal:</label>
                                    <input type="date" id="tanggal-cetak" class="border-gray-300 rounded-md shadow-sm p-2 text-sm" value="{{ date('Y-m-d') }}">
                                </div>
                            </div>
                        </div>

                        <!-- Kanan: Tombol Aksi (Urut ke Bawah) -->
                        <div class="flex flex-col gap-3 w-full sm:w-auto">
                            @if(auth()->user()->isAdmin())
                            <a href="{{ route('admin.layoutMapel') }}" class="w-full sm:w-auto inline-flex justify-center items-center bg-orange-500 text-white px-4 py-2 rounded-md hover:bg-orange-600 font-bold text-sm shadow-sm whitespace-nowrap transition-colors">
                                Layout Mapel Rapor
                            </a>
                            @endif
                            <a href="#" id="btn-legger" class="hidden w-full sm:w-auto inline-flex justify-center items-center bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 font-bold text-sm shadow-sm whitespace-nowrap transition-colors">
                                Download Leger (Excel)
                            </a>
                            <button type="button" id="btn-massal" class="hidden w-full sm:w-auto inline-flex justify-center items-center bg-purple-600 text-white px-4 py-2 rounded-md hover:bg-purple-700 font-bold text-sm shadow-sm whitespace-nowrap transition-colors">
                                Download Rapor Semua Murid (PDF)
                            </button>
                            <button type="button" id="btn-pelengkap-massal" class="hidden w-full sm:w-auto inline-flex justify-center items-center bg-emerald-600 text-white px-4 py-2 rounded-md hover:bg-emerald-700 font-bold text-sm shadow-sm whitespace-nowrap transition-colors">
                                Cetak Pelengkap Rapor Seluruh Murid
                            </button>
                        </div>
                    </div>

                    <!-- Tabel Siswa (Akan diisi via AJAX) -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">NISN</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nama Murid</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="siswa-body" class="bg-white divide-y divide-gray-200">
                                <tr>
                                    <td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500">
                                        Silakan pilih kelas terlebih dahulu untuk menampilkan daftar murid.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Modal Peringatan Nilai Belum Lengkap -->
    <div id="modalNilaiBelumLengkap" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50 flex items-center justify-center">
        <div class="bg-white p-6 rounded-lg shadow-xl w-full max-w-md mx-4">
            <h3 class="text-lg font-bold text-red-600 mb-4 flex items-center gap-2">
                ⚠️ Peringatan Nilai Belum Lengkap
            </h3>
            <p class="text-sm text-gray-600 mb-4">Rapor tidak dapat dicetak karena ada mata pelajaran yang belum diinput nilai akhirnya:</p>
            <div class="bg-red-50 border border-red-200 rounded-md p-4 mb-4 max-h-48 overflow-y-auto">
                <ul id="listMapelBelumIsi" class="list-disc list-inside text-sm text-gray-700 space-y-1">
                    <!-- Diisi via JavaScript -->
                </ul>
            </div>
            
            <!-- Tombol Aksi Modal -->
            <div class="flex justify-between items-center pt-2 gap-2">
                <!-- Tombol Tetap Cetak (Force Print) -->
                <button type="button" id="btnForcePrint" class="bg-yellow-500 text-white px-4 py-2 rounded-md text-sm font-bold hover:bg-yellow-600 transition-colors">
                    Tetap Cetak (Preview)
                </button>
                <!-- Tombol Tutup -->
                <button type="button" onclick="document.getElementById('modalNilaiBelumLengkap').classList.add('hidden')" class="bg-gray-200 text-gray-800 px-4 py-2 rounded-md text-sm font-bold hover:bg-gray-300 transition-colors">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    <script>
        // Variabel global untuk menyimpan konteks cetak (digunakan oleh tombol Force Print)
        let printContext = { type: null, studentId: null, kelasId: null, tempat: null, tanggal: null };

        document.getElementById('kelas-select').addEventListener('change', function() {
            let kelasId = this.value;
            
            let btnLegger = document.getElementById('btn-legger');
            let btnMassal = document.getElementById('btn-massal');
            let btnPelengkapMassal = document.getElementById('btn-pelengkap-massal');
            
            if (!kelasId) {
                btnLegger.classList.add('hidden');
                btnMassal.classList.add('hidden');
                btnPelengkapMassal.classList.add('hidden');
                btnLegger.href = "#";
            } else {
                btnLegger.href = `/rapor/cetak-legger/${kelasId}`;
                btnLegger.classList.remove('hidden');
                btnMassal.classList.remove('hidden');
                btnPelengkapMassal.classList.remove('hidden');
            }

            let tbody = document.getElementById('siswa-body');
            
            if (!kelasId) {
                tbody.innerHTML = '<tr><td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500">Silakan pilih kelas terlebih dahulu.</td></tr>';
                return;
            }

            tbody.innerHTML = '<tr><td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500">Memuat data murid...</td></tr>';

            // Request GET tidak membutuhkan CSRF Token
            fetch(`/rapor/get-siswa/${kelasId}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500">Tidak ada murid di kelas ini.</td></tr>';
                    return;
                }

                let html = '';
                data.forEach(s => {
                    html += `
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${s.nisn ?? '-'}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${s.nama}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                <div class="flex items-center justify-center gap-3 flex-wrap">
                                    <button onclick="checkAndPrintIndividual('${s.id}', '${kelasId}')" class="text-indigo-600 hover:text-indigo-900 font-bold">Cetak Rapor</button>
                                    <button onclick="printPelengkapRapor('${s.id}', '${kelasId}')" class="text-emerald-600 hover:text-emerald-900 font-bold">Pelengkap Rapor</button>
                                </div>
                            </td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
            })
            .catch(error => {
                console.error('Error:', error);
                tbody.innerHTML = '<tr><td colspan="3" class="px-6 py-4 text-center text-sm text-red-500">Gagal memuat data. Cek console (F12) untuk detail error.</td></tr>';
            });
        });

        // Fungsi Cek Kelengkapan Nilai untuk Cetak Massal
        document.getElementById('btn-massal').addEventListener('click', function(e) {
            let kelasId = document.getElementById('kelas-select').value;
            if (!kelasId) return;

            let tempat = document.getElementById('tempat-cetak').value;
            let tanggal = document.getElementById('tanggal-cetak').value;
            
            let btn = this;
            let originalText = btn.innerText;
            btn.innerText = 'Mengecek Data...';
            btn.classList.add('opacity-50', 'pointer-events-none');

            fetch(`/rapor/check-status/${kelasId}`, {
                headers: {
                    'Accept': 'application/json'
                }
            })
            .then(res => res.json())
            .then(data => {
                if(data.is_complete) {
                    window.location.href = `/rapor/cetak-massal/${kelasId}?tempat=${encodeURIComponent(tempat)}&tanggal=${tanggal}`;
                } else {
                    // Simpan konteks untuk Force Print
                    printContext = { type: 'massal', studentId: null, kelasId: kelasId, tempat: tempat, tanggal: tanggal };
                    showMissingGradesModal(data.missing);
                    btn.innerText = originalText;
                    btn.classList.remove('opacity-50', 'pointer-events-none');
                }
            })
            .catch(error => {
                alert('Terjadi kesalahan saat mengecek data.');
                btn.innerText = originalText;
                btn.classList.remove('opacity-50', 'pointer-events-none');
            });
        });

        // Cetak Pelengkap Rapor massal tidak bergantung pada kelengkapan nilai.
        document.getElementById('btn-pelengkap-massal').addEventListener('click', function() {
            let kelasId = document.getElementById('kelas-select').value;
            if (!kelasId) {
                alert('Silakan pilih kelas terlebih dahulu.');
                return;
            }

            let tempat = document.getElementById('tempat-cetak').value;
            let tanggal = document.getElementById('tanggal-cetak').value;

            const url = `/rapor/cetak-massal/${kelasId}?pelengkap=1&tempat=${encodeURIComponent(tempat)}&tanggal=${encodeURIComponent(tanggal)}`;
            window.location.href = url;
        });

        // Fungsi Cek Kelengkapan Nilai untuk Cetak Individu
        function checkAndPrintIndividual(studentId, kelasId) {
            let tempat = document.getElementById('tempat-cetak').value;
            let tanggal = document.getElementById('tanggal-cetak').value;

            fetch(`/rapor/check-status/${kelasId}`, {
                headers: {
                    'Accept': 'application/json'
                }
            })
            .then(res => res.json())
            .then(data => {
                if(data.is_complete) {
                    window.open(`/rapor/cetak/${studentId}?tempat=${encodeURIComponent(tempat)}&tanggal=${tanggal}`, '_blank');
                } else {
                    // Simpan konteks untuk Force Print
                    printContext = { type: 'individual', studentId: studentId, kelasId: kelasId, tempat: tempat, tanggal: tanggal };
                    showMissingGradesModal(data.missing);
                }
            })
            .catch(error => {
                alert('Terjadi kesalahan saat mengecek data.');
            });
        }

        // Cetak Pelengkap Rapor tidak bergantung pada kelengkapan nilai.
        function printPelengkapRapor(studentId, kelasId) {
            let tempat = document.getElementById('tempat-cetak').value;
            let tanggal = document.getElementById('tanggal-cetak').value;

            const url = `/rapor/cetak/${studentId}?pelengkap=1&kelas_id=${encodeURIComponent(kelasId)}&tempat=${encodeURIComponent(tempat)}&tanggal=${encodeURIComponent(tanggal)}`;
            window.open(url, '_blank');
        }

        // Fungsi reusable untuk menampilkan modal daftar mapel yang belum diisi
        function showMissingGradesModal(missingData) {
            let list = document.getElementById('listMapelBelumIsi');
            list.innerHTML = ''; 
            missingData.forEach(function(item) {
                list.innerHTML += `<li><strong>${item.mapel}</strong> (${item.guru})</li>`;
            });
            document.getElementById('modalNilaiBelumLengkap').classList.remove('hidden');
        }

        // Fungsi Tombol Force Print (Tetap Cetak)
        document.getElementById('btnForcePrint').addEventListener('click', function() {
            document.getElementById('modalNilaiBelumLengkap').classList.add('hidden'); // Tutup modal
            
            if (printContext.type === 'massal') {
                window.location.href = `/rapor/cetak-massal/${printContext.kelasId}?tempat=${encodeURIComponent(printContext.tempat)}&tanggal=${printContext.tanggal}`;
            } else if (printContext.type === 'individual') {
                window.open(`/rapor/cetak/${printContext.studentId}?tempat=${encodeURIComponent(printContext.tempat)}&tanggal=${printContext.tanggal}`, '_blank');
            }
        });
    </script>
</x-app-layout>