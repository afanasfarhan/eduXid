{{-- resources/views/tu/kartu-pelajar/index.blade.php --}}  
  
<x-app-layout>  
  
    <x-slot name="header">  
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Kartu Pelajar</h2>  
        <p class="text-sm text-gray-500 mt-1">Membuat dan mencetak kartu pelajar murid.</p>  
    </x-slot>  
  
    <div class="py-8">  
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">  
  
            @if(session('success'))  
                <div class="mb-6 rounded-lg bg-green-100 border border-green-300 text-green-800 px-4 py-3">{{ session('success') }}</div>  
            @endif  
            @if(session('error'))  
                <div class="mb-6 rounded-lg bg-red-100 border border-red-300 text-red-800 px-4 py-3">{{ session('error') }}</div>  
            @endif  
  
            {{-- =========================================================  
                 PANEL KOP SURAT & TANDA TANGAN (GAMBAR)  
            ========================================================== --}}  
            <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6 mb-6">  
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    {{-- KOP SURAT --}}
                    <div class="border rounded-lg p-4">
                        <h3 class="text-lg font-bold text-gray-800 mb-2">Kop Kartu Pelajar</h3>  
                        <p class="text-sm text-gray-500 mt-1 mb-3">Unggah gambar kop surat.</p>  
                        <div id="kop-preview" class="bg-gray-100 rounded-md flex items-center justify-center h-28 overflow-hidden mb-3">  
                            @if(!empty($kopSuratPath))  
                                <img src="{{ $kopSuratUrl ?? '' }}" alt="Kop Kartu" class="max-h-full max-w-full object-contain">  
                            @else  
                                <span class="text-gray-500 text-sm">BELUM ADA KOP</span>  
                            @endif  
                        </div>  
                        <label class="block text-sm font-medium text-gray-700 mb-1">Unggah Kop</label>  
                        <input type="file" id="inputKopKartu" accept="image/png, image/jpeg, image/jpg, image/webp" class="w-full text-sm border border-gray-300 rounded-md p-2">
                        <p class="text-xs text-gray-500 mt-2">Tersimpan otomatis di browser dan server.</p>
                    </div>

                    {{-- TANDA TANGAN --}}
                    <div class="border rounded-lg p-4">
                        <h3 class="text-lg font-bold text-gray-800 mb-2">Tanda Tangan Kepala Sekolah</h3>  
                        <p class="text-sm text-gray-500 mt-1 mb-3">Unggah gambar scan tanda tangan.</p>  
                        <div class="bg-gray-100 rounded-md flex items-center justify-center h-28 overflow-hidden mb-3">  
                            @if(!empty($tandaTanganPath))  
                                <img src="{{ $tandaTanganUrl ?? '' }}" alt="Tanda Tangan" class="max-h-full max-w-full object-contain">  
                            @else  
                                <span class="text-gray-500 text-sm">BELUM ADA TANDA TANGAN</span>  
                            @endif  
                        </div>  
                        <form action="{{ route('tu.kartu-pelajar.uploadTandaTangan') }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            <label class="block text-sm font-medium text-gray-700 mb-1">Unggah Gambar</label>  
                            <input type="file" name="tanda_tangan" accept="image/png, image/jpeg, image/jpg, image/webp" class="w-full text-sm border border-gray-300 rounded-md p-2" required>
                            <button type="submit" class="mt-2 w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-4 rounded-md">Simpan Tanda Tangan</button>
                        </form>
                    </div>

                </div>  
            </div>  
  
            {{-- =========================================================  
                 PANEL PENANDA TANGAN (TEKS)  
            ========================================================== --}}  
            <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6 mb-6">  
                <div class="mb-4">  
                    <h4 class="font-semibold text-gray-700">Penanda Tangan Kartu Pelajar</h4>  
                    <p class="text-sm text-gray-500 mt-1">Data Kepala Sekolah diambil dari data Dapodik.</p>  
                </div>  
  
                <form action="{{ route('tu.kartu-pelajar.settings') }}" method="POST">  
                    @csrf  
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">  
                        <div>  
                            <label class="block text-sm font-medium text-gray-700 mb-1">Jabatan</label>  
                            <input type="text" name="jabatan_penanda_tangan" value="{{ old('jabatan_penanda_tangan', $signatureData['jabatan'] ?? 'Kepala Sekolah') }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Kepala Sekolah" readonly>  
                        </div>  
                        <div>  
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nama</label>  
                            <input type="text" name="nama_penanda_tangan" value="{{ old('nama_penanda_tangan', $signatureData['nama'] ?? (isset($kepalaSekolah->nama_lengkap) ? $kepalaSekolah->nama_lengkap : (isset($kepalaSekolah->nama) ? $kepalaSekolah->nama : ''))) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Nama Kepala Sekolah" readonly>  
                        </div>  
                        <div>  
                            <label class="block text-sm font-medium text-gray-700 mb-1">NIP</label>  
                            <input type="text" name="nip_penanda_tangan" value="{{ old('nip_penanda_tangan', $signatureData['nip'] ?? (isset($kepalaSekolah->nip) ? $kepalaSekolah->nip : '')) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="NIP" readonly>  
                        </div>  
                        <div>  
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tempat</label>  
                            <input type="text" name="tempat_penanda_tangan" value="{{ old('tempat_penanda_tangan', $signatureData['tempat'] ?? '') }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Nanga Tepuai" required>  
                        </div>  
                        <div>  
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal</label>  
                            <input type="date" name="tanggal_penanda_tangan" value="{{ old('tanggal_penanda_tangan', !empty($signatureData['tanggal']) ? \Carbon\Carbon::parse($signatureData['tanggal'])->format('Y-m-d') : now()->format('Y-m-d')) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>  
                        </div>  
                    </div>  
                    <div class="mt-4">  
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2.5 px-5 rounded-md shadow">💾 Simpan Pengaturan Kartu</button>  
                    </div>  
                </form>  
            </div>  
  
            {{-- =========================================================  
                 PANEL PILIH CETAK  
            ========================================================== --}}  
            <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6 mb-6">  
                <div class="mb-5">  
                    <h3 class="text-lg font-bold text-gray-800">Pilihan Cetak</h3>  
                    <p class="text-sm text-gray-500 mt-1">Pilih murid atau kelas yang akan dicetak.</p>  
                </div>  
  
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">  
                    <div>  
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mode Cetak</label>  
                        <select id="print_mode" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">  
                            <option value="student">Satu Murid</option>  
                            <option value="class">Satu Kelas</option>  
                            <option value="all">Semua Murid</option>  
                        </select>  
                    </div>  
                    <div id="class-container">  
                        <label class="block text-sm font-medium text-gray-700 mb-1">Kelas</label>  
                        <select id="homeroom_id" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">  
                            <option value="">-- Pilih Kelas --</option>  
                            @foreach($homerooms as $homeroom)  
                                <option value="{{ $homeroom->id }}">{{ $homeroom->nama }}</option>  
                            @endforeach  
                        </select>  
                    </div>  
                    <div id="student-container">  
                        <label class="block text-sm font-medium text-gray-700 mb-1">Murid</label>  
                        <select id="student_id" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">  
                            <option value="">-- Pilih Murid --</option>  
                            @foreach($students as $student)  
                                @php
                                    $fotoSiswa = '';
                                    $rawFoto = $student->foto_path ?? $student->foto ?? $student->photo ?? '';
                                    if (!empty($rawFoto)) {
                                        $fotoSiswa = $student->foto_url ?? '';
                                    }
                                @endphp
                                <option value="{{ $student->id }}" data-kelas="{{ $student->kelas_rombel ?? '' }}" data-nipd="{{ $student->nipd ?? $student->nis ?? '' }}" data-nisn="{{ $student->nisn ?? '' }}" data-nama="{{ $student->nama ?? '' }}" data-jenis-kelamin="{{ $student->jenis_kelamin ?? '' }}" data-tempat-lahir="{{ $student->tempat_lahir ?? '' }}" data-tanggal-lahir="{{ $student->tanggal_lahir ?? '' }}" data-agama="{{ $student->agama ?? '' }}" data-alamat="{{ $student->alamat ?? $student->alamat_jalan ?? '' }}" data-foto="{{ $fotoSiswa }}">  
                                    {{ $student->nama }} @if(!empty($student->kelas_rombel)) — {{ $student->kelas_rombel }} @endif  
                                </option>  
                            @endforeach  
                        </select>  
                    </div>  
                </div>  
  
                <div class="mt-5 p-4 bg-blue-50 border border-blue-200 rounded-lg">  
                    <div class="flex items-start gap-3">  
                        <div class="text-blue-600 text-xl">ℹ️</div>  
                        <div>  
                            <div class="font-semibold text-blue-800">Format cetak A4 ukuran sesungguhnya</div>
                            <p class="text-xs text-blue-700 mt-1">Kartu 9 × 6 cm (landscape), 8 kartu/halaman (2 kolom × 4 baris). Saat mencetak: ukuran kertas A4 portrait; skala 100% / Actual size; margin None / Tanpa margin; nonaktifkan Fit to Page serta header/footer. Untuk cetak dua sisi, gunakan balik tepi panjang (flip on long edge).</p>  
                            <div id="print-info" class="text-sm text-blue-700 mt-1">Pilih satu murid untuk melihat preview kartu.</div>  
                        </div>  
                    </div>  
                </div>  
  
                <div class="mt-5 flex flex-wrap gap-3">  
                    <button type="button" onclick="preparePrint()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 px-6 rounded-md shadow">🖨️ Cetak Kartu</button>

                    <button type="button" onclick="selectAllStudents()" class="bg-gray-600 hover:bg-gray-700 text-white font-semibold py-2.5 px-6 rounded-md shadow">Pilih Semua</button>  
                </div>  
            </div>  
  
            {{-- =========================================================  
                 PREVIEW  
            ========================================================== --}}  
            <div class="bg-gray-100 rounded-xl border border-gray-200 p-8">  
                <div class="mb-6">  
                    <h3 class="font-bold text-gray-800 text-lg">Preview Kartu Pelajar</h3>  
                    <p class="text-sm text-gray-500 mt-1">Ukuran riil kartu pelajar: 90 × 60 mm (9 × 6 cm, landscape).</p>  
                </div>  
  
                <div class="flex justify-center overflow-auto">  
                    <div id="preview-wrapper">  
                        <div id="student-card" class="student-card">  
  
                            <div class="card-header">  
                                @if(!empty($kopSuratPath))  
                                    <img src="{{ $kopSuratUrl ?? '' }}" alt="Kop Kartu">  
                                @else  
                                    <div class="kop-teks-fallback">  
                                        <div class="school-name">{{ strtoupper($schoolData['nama'] ?? 'NAMA SEKOLAH') }}</div>  
                                        <div class="school-address">{{ $schoolData['alamat_jalan'] ?? '' }} {{ $schoolData['kabupaten_kota'] ?? '' }}</div>  
                                    </div>  
                                @endif  
                            </div>  
                            
                            <div class="header-line-gold"></div>
                            
                            <div class="card-title">KARTU PELAJAR</div>  
  
                            <div class="student-content">  
                                <div class="photo-area">  
                                    <img id="preview-photo" src="" alt="Foto Murid" style="display: none;">  
                                    <span id="preview-photo-placeholder">FOTO</span>  
                                </div>  
  
                                <div class="student-data">  
                                    <div class="data-row"><div class="data-label">NIS / NISN</div><div class="data-separator">:</div><div id="preview-nipdnisn" class="data-value">-</div></div>  
                                    <div class="data-row"><div class="data-label">Nama</div><div class="data-separator">:</div><div id="preview-nama" class="data-value">-</div></div>  
                                    <div class="data-row"><div class="data-label">Jenis Kelamin</div><div class="data-separator">:</div><div id="preview-jenis-kelamin" class="data-value">-</div></div>  
                                    <div class="data-row"><div class="data-label">Tempat, Tgl Lahir</div><div class="data-separator">:</div><div id="preview-tempat-tanggal-lahir" class="data-value">-</div></div>  
                                    <div class="data-row"><div class="data-label">Agama</div><div class="data-separator">:</div><div id="preview-agama" class="data-value">-</div></div>  
                                    <div class="data-row"><div class="data-label">Alamat</div><div class="data-separator">:</div><div id="preview-alamat" class="data-value">-</div></div>  
                                </div>  
  
                                @php
                                    $ttdSrc = $tandaTanganUrl ?? '';
                                @endphp
                                <div class="signature">  
                                    <div class="signature-date">{{ $signatureData['tempat'] ?? '' }}, {{ \Carbon\Carbon::parse($signatureData['tanggal'] ?? now())->translatedFormat('j F Y') }}</div>  
                                    <div class="signature-position">{{ $signatureData['jabatan'] ?? 'Kepala Sekolah' }},</div>  
                                    <div class="signature-space">
                                        <img id="preview-ttd" src="{{ $ttdSrc }}" alt="Tanda Tangan" class="ttd-img" style="{{ empty($ttdSrc) ? 'display: none;' : '' }}" onerror="this.style.display='none'">
                                    </div>  
                                    <div class="signature-name">{{ $signatureData['nama'] ?? (isset($kepalaSekolah->nama_lengkap) ? $kepalaSekolah->nama_lengkap : (isset($kepalaSekolah->nama) ? $kepalaSekolah->nama : 'NAMA KEPALA SEKOLAH')) }}</div>  
                                    @php $signatureNip = $signatureData['nip'] ?? (isset($kepalaSekolah->nip) ? $kepalaSekolah->nip : ''); @endphp  
                                    @if(!empty($signatureNip))<div class="signature-nip">NIP {{ $signatureNip }}</div>@endif  
                                </div>  
                            </div>  
  
                            <div class="barcode-area">  
                                <div id="student-barcode" class="barcode"></div>  
                                <div id="barcode-number" class="barcode-number">-</div>  
                            </div>  
                            
                            <div class="bottom-decoration"></div>  
                            <div class="bottom-accent"></div>
                        </div>  
                    </div>  
                </div>  
            </div>  
        </div>  
    </div>  
  
    <style>  
        .student-card {  
            position: relative;  
            width: 90mm;  
            height: 60mm;  
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 50%, #f1f5f9 100%);  
            border: 0.5mm solid #1e3a8a;
            overflow: hidden;  
            font-family: Arial, Helvetica, sans-serif;  
            color: #111827;  
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        
            box-sizing: border-box; }  

        .card-header {  
            height: 15mm;   
            display: flex;  
            align-items: center;  
            justify-content: center;  
            background: white;  
            overflow: hidden;  
            padding: 1mm 3mm;  
        }  
        .card-header img {  
            max-width: 100%;  
            max-height: 100%;  
            object-fit: contain;  
        }  
        .kop-teks-fallback { text-align: center; width: 100%; line-height: 1.1; }  
        .kop-teks-fallback .school-name { font-size: 3.2mm; font-weight: 700; text-transform: uppercase; color: #1e3a8a; }  
        .kop-teks-fallback .school-address { font-size: 1.8mm; margin-top: 0.5mm; color: #475569; }  

        .header-line-gold {
            height: 0.5mm;
            background: linear-gradient(90deg, transparent, #d4af37 20%, #d4af37 80%, transparent);
            margin: 0 0;
        }
  
        .card-title { 
            text-align: center;  
            font-size: 2.8mm;  
            font-weight: 800;  
            color: #1e3a8a;
            margin-top: 0.5mm;  
            margin-bottom: 1.5mm;
            letter-spacing: 0.5mm;
            text-transform: uppercase;
        }  
  
        .student-content { position: relative; padding: 0 2mm 0 2mm; }  

        .photo-area {   
            position: absolute;   
            left: 1.5mm;   
            top: 0mm;   
            width: 12mm;   
            height: 16mm;   
            border: 0.2mm solid #d4af37;
            border-radius: 0.5mm;
            background: #ffffff;   
            display: flex;   
            align-items: center;   
            justify-content: center;   
            font-size: 1.5mm;   
            color: #94a3b8;   
            overflow: hidden;   
            z-index: 2;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }  
        .photo-area img { width: 100%; height: 100%; object-fit: contain; object-position: center center; background:#fff; }  

        .student-data {   
            width: 40mm;   
            margin-left: 13mm;
            border-left: 0.2mm solid #d4af37;
            padding-left: 1.5mm;
        }  

        .data-row { display: flex; min-height: 2.8mm; font-size: 1.6mm; line-height: 1.1; }  
        .data-label { width: 14mm; flex-shrink: 0; color: #475569; font-weight: 600; }  
        .data-separator { width: 2mm; flex-shrink: 0; text-align: center; color: #475569; }  
        .data-value { 
            flex: 1; 
            word-break: break-word; 
            color: #0f172a; 
            font-weight: 500; 
            text-transform: uppercase; 
        }  
        .uppercase { text-transform: uppercase; }  
  
        .signature {   
            position: absolute;   
            right: 0.5mm;   
            top: 6mm;   
            width: 28mm;   
            text-align: center;   
            font-size: 1.5mm;   
            line-height: 1.2;
            color: #475569;
            border-top: 0.2mm dashed #cbd5e1;
            padding-top: 1mm;
        }  
        .signature-date { margin-bottom: 0.5mm; }  
        .signature-position { margin-bottom: 0.5mm; font-weight: 600; color: #1e3a8a; }  
        .signature-space { height: 10mm; display: flex; align-items: center; justify-content: center; overflow: hidden; }  
        .ttd-img { max-height: 100%; max-width: 100%; object-fit: contain; opacity: 1; }
        .signature-name { font-weight: 700; text-decoration: underline; font-size: 1.6mm; color: #1e3a8a; }  
        .signature-nip { font-size: 1.5mm; color: #475569; }  

        .barcode-area {   
            position: absolute;   
            left: 1mm;   
            bottom: 4mm;   
            width: 20mm;   
            text-align: center;
            background: white;
            padding: 0.5mm;
            border: 0.2mm solid #e2e8f0;
            border-radius: 0.5mm;
        }  
        .barcode { height: 5mm; width: 100%; background: repeating-linear-gradient(90deg, #000 0mm, #000 0.5mm, #fff 0.5mm, #fff 1mm); }  
        .barcode-number { background: white; font-size: 1.5mm; font-weight: 700; padding: 0 0.5mm; color: #1e3a8a; }  

        .bottom-decoration {   
            position: absolute;   
            left: 0;   
            right: 0;   
            bottom: 0;   
            height: 4.5mm;   
            background: #1e3a8a;
            border-top: 0.3mm solid #d4af37;
        }

        .bottom-accent {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 20mm;
            height: 4.5mm;
            background: #d4af37;
            clip-path: polygon(30% 0, 100% 0, 100% 100%, 0% 100%);
        }
        .student-card-back {
            position: relative;
            width: 90mm;
            height: 60mm;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            border: 0.5mm solid #1e3a8a;
            overflow: hidden;
            font-family: Arial, Helvetica, sans-serif;
            color: #111827;
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        .student-card-back .back-title {
            text-align: center;
            font-size: 2.8mm;
            font-weight: 800;
            color: #1e3a8a;
            margin: 1mm 0 0.8mm;
            letter-spacing: 0.4mm;
            text-transform: uppercase;
        }
        .student-card-back .back-body {
            padding: 0 3mm;
            font-size: 1.7mm;
            line-height: 1.25;
            color: #1f2937;
        }
        .student-card-back .back-school-name {
            font-size: 2.1mm;
            font-weight: 700;
            color: #1e3a8a;
            text-align: center;
            text-transform: uppercase;
            margin-bottom: 0.6mm;
        }
        .student-card-back .back-school-meta {
            text-align: center;
            font-size: 1.55mm;
            color: #475569;
            margin-bottom: 1.2mm;
        }
        .student-card-back .back-notes {
            border: 0.2mm solid #cbd5e1;
            border-radius: 0.8mm;
            padding: 1.2mm 1.5mm;
            background: rgba(255,255,255,0.9);
            min-height: 18mm;
        }
        .student-card-back .back-note-item {
            margin-bottom: 0.8mm;
        }
        .student-card-back .back-note-item:last-child {
            margin-bottom: 0;
        }
        .student-card-back .back-footer {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 6.5mm;
            padding: 1mm 3mm 1.2mm;
            box-sizing: border-box;
            background: #1e3a8a;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 1.5mm;
        }
        .student-card-back .back-validity {
            font-weight: 700;
            letter-spacing: 0.15mm;
        }
        .student-card-back .back-contact {
            text-align: right;
            max-width: 44mm;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .print-placeholder {
            width: 90mm;
            height: 60mm;
            visibility: hidden;
        }

        /* V21R147: geometri fisik A4 210 × 297 mm, kartu landscape 90 × 60 mm. */
        @media print {
            @page { size: A4 portrait; margin: 0; }
            html, body {
                margin: 0 !important;
                padding: 0 !important;
                width: 210mm !important;
                min-width: 210mm !important;
                background: white !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            body * { visibility: hidden !important; }
            #print-sheet, #print-sheet * { visibility: visible !important; }
            #print-sheet {
                position: absolute !important;
                top: 0 !important;
                left: 0 !important;
                display: block !important;
                width: 210mm !important;
                margin: 0 !important;
                padding: 0 !important;
                zoom: 1 !important;
                transform: none !important;
            }
            .print-page {
                width: 210mm !important;
                height: 297mm !important;
                display: grid !important;
                grid-template-columns: repeat(2, 90mm) !important;
                grid-template-rows: repeat(4, 60mm) !important;
                column-gap: 10mm !important;
                row-gap: 6mm !important;
                padding: 0 !important;
                margin: 0 !important;
                box-sizing: border-box !important;
                justify-content: center !important;
                align-content: center !important;
                overflow: hidden !important;
                page-break-after: always !important;
                break-after: page !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            .print-page:last-child { page-break-after: auto !important; break-after: auto !important; }
            .print-page > .student-card,
            .print-page > .student-card-back,
            .print-page > .print-placeholder {
                width: 90mm !important;
                min-width: 90mm !important;
                max-width: 90mm !important;
                height: 60mm !important;
                min-height: 60mm !important;
                max-height: 60mm !important;
                margin: 0 !important;
                box-sizing: border-box !important;
                box-shadow: none !important;
                transform: none !important;
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }
            .ttd-img { opacity: 1 !important; }
        }
        #print-sheet { display: none; }  
    </style>  
  
    <script>  
        const studentData = @json($students);  
        const ttdUrl = '{{ $ttdSrc }}';
  
        document.addEventListener('DOMContentLoaded', function () {  
            const printMode = document.getElementById('print_mode');  
            const classContainer = document.getElementById('class-container');  
            const studentContainer = document.getElementById('student-container');  
            const studentSelect = document.getElementById('student_id');  
            const classSelect = document.getElementById('homeroom_id');  
  
            printMode.addEventListener('change', function () {  
                if (this.value === 'student') { classContainer.style.display = 'none'; studentContainer.style.display = 'block'; updatePrintInfo(); }  
                if (this.value === 'class') { classContainer.style.display = 'block'; studentContainer.style.display = 'none'; updatePrintInfo(); }  
                if (this.value === 'all') { classContainer.style.display = 'none'; studentContainer.style.display = 'none'; updatePrintInfo(); }  
            });  
            printMode.dispatchEvent(new Event('change'));  
  
            studentSelect.addEventListener('change', function () {  
                const option = this.options[this.selectedIndex];  
                if (!option || !option.value) { resetPreview(); return; }  
                showStudent(option); updatePrintInfo();  
            });  
  
            classSelect.addEventListener('change', function () {  
                updatePrintInfo();  
                const classId = this.value;  
                if (!classId) { resetPreview(); return; }  
                const students = studentData.filter(function (student) { return String(student.homeroom_id) === String(classId); });  
                if (students.length) { showStudentObject(students[0]); } else { resetPreview(); }  
            });  
  
            // ==========================================================  
            // LOGIKA KOP SURAT (INDEXED DB)  
            // ==========================================================  
            const KOP_DB_NAME = 'eduXidSuratDB';  
            const KOP_DB_VERSION = 1;  
            const KOP_STORE_NAME = 'kop_surat';  
            const KOP_KEY = 'kop_utama';  
  
            function bukaDatabaseKop() {  
                return new Promise(function (resolve, reject) {  
                    const request = indexedDB.open(KOP_DB_NAME, KOP_DB_VERSION);  
                    request.onupgradeneeded = function (event) {  
                        const db = event.target.result;  
                        if (!db.objectStoreNames.contains(KOP_STORE_NAME)) { db.createObjectStore(KOP_STORE_NAME); }  
                    };  
                    request.onsuccess = function (event) { resolve(event.target.result); };  
                    request.onerror = function () { reject(request.error); };  
                });  
            }  
  
            async function simpanKopKartu(dataUrl) {  
                try {  
                    const db = await bukaDatabaseKop();  
                    return new Promise(function (resolve, reject) {  
                        const transaction = db.transaction(KOP_STORE_NAME, 'readwrite');  
                        const store = transaction.objectStore(KOP_STORE_NAME);  
                        const request = store.put(dataUrl, KOP_KEY);  
                        request.onsuccess = function () { resolve(true); };  
                        request.onerror = function () { reject(request.error); };  
                    });  
                } catch (error) { return false; }  
            }  
  
            async function ambilKopKartu() {  
                try {  
                    const db = await bukaDatabaseKop();  
                    return new Promise(function (resolve, reject) {  
                        const transaction = db.transaction(KOP_STORE_NAME, 'readonly');  
                        const store = transaction.objectStore(KOP_STORE_NAME);  
                        const request = store.get(KOP_KEY);  
                        request.onsuccess = function () { resolve(request.result || null); };  
                        request.onerror = function () { reject(request.error); };  
                    });  
                } catch (error) { return null; }  
            }  
  
            function tampilkanKopKartu(kopUrl) {  
                if (!kopUrl) return;  
                const cardHeader = document.querySelector('#student-card .card-header');  
                if (!cardHeader) return;  

                cardHeader.innerHTML = '';
                const img = document.createElement('img');  
                img.src = kopUrl;  
                img.alt = 'Kop';  
                img.style.display = 'block';  
                img.style.width = '100%';  
                img.style.maxWidth = '100%';  
                img.style.maxHeight = '100%';  
                img.style.objectFit = 'contain';  
                img.style.margin = '0';  
                img.style.padding = '0';  
                cardHeader.appendChild(img);  

                const kopPreview = document.getElementById('kop-preview');  
                if (kopPreview) { kopPreview.innerHTML = '<img src="' + kopUrl + '" class="max-h-full max-w-full object-contain">'; }  
            }  
  
            ambilKopKartu().then(kop => { if (kop) tampilkanKopKartu(kop); });  
  
            const inputKop = document.getElementById('inputKopKartu');
            if (inputKop) {
                inputKop.addEventListener('change', async function (event) {
                    const file = event.target.files[0];
                    if (!file) return;

                    const allowedTypes = ['image/png', 'image/jpeg', 'image/webp'];
                    const maxSize = 5 * 1024 * 1024;

                    if (!allowedTypes.includes(file.type)) {
                        alert('Format kop harus PNG, JPG/JPEG, atau WEBP.');
                        event.target.value = '';
                        return;
                    }

                    if (file.size > maxSize) {
                        alert('Ukuran gambar kop maksimal 5 MB.');
                        event.target.value = '';
                        return;
                    }

                    const reader = new FileReader();
                    reader.onload = async function (e) {
                        const kopUrl = e.target.result;
                        tampilkanKopKartu(kopUrl);

                        const tersimpanLokal = await simpanKopKartu(kopUrl);
                        if (!tersimpanLokal) {
                            console.warn('[eduXid] Kop kartu tidak dapat disimpan ke IndexedDB.');
                        }
                    };
                    reader.onerror = function () {
                        alert('Gambar kop tidak dapat dibaca.');
                    };
                    reader.readAsDataURL(file);

                    try {
                        const formData = new FormData();
                        formData.append('kop_kartu', file);

                        const response = await fetch('{{ route("tu.kartu-pelajar.uploadKop") }}', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: formData
                        });

                        if (!response.ok) {
                            throw new Error('HTTP ' + response.status);
                        }
                    } catch (error) {
                        console.error('[eduXid] Gagal menyimpan kop kartu ke server.', error);
                        alert('Kop tampil di perangkat ini, tetapi gagal disimpan ke server. Periksa koneksi atau coba unggah kembali.');
                    } finally {
                        event.target.value = '';
                    }
                });
            }
        });  
  
        function showStudent(option) {  
            const student = {  
                id: option.value, nama: option.dataset.nama || '', nipd: option.dataset.nipd || '',  
                nisn: option.dataset.nisn || '', jenis_kelamin: option.dataset.jenisKelamin || '',  
                tempat_lahir: option.dataset.tempatLahir || '', tanggal_lahir: option.dataset.tanggalLahir || '',  
                agama: option.dataset.agama || '', alamat: option.dataset.alamat || '',  
                kelas_rombel: option.dataset.kelas || '', foto: option.dataset.foto || ''  
            };  
            showStudentObject(student);  
        }  
  
        function showStudentObject(student) {  
            const nipd = student.nipd || '';  
            const nisn = student.nisn || '';  
            let nomor = '-';  
            if (nipd && nisn) { nomor = nipd + ' / ' + nisn; } else if (nipd) { nomor = nipd; } else if (nisn) { nomor = nisn; }  
  
            document.getElementById('preview-nipdnisn').textContent = nomor;  
            document.getElementById('preview-nama').textContent = student.nama || '-';  
            document.getElementById('preview-jenis-kelamin').textContent = formatGender(student.jenis_kelamin);  
            document.getElementById('preview-tempat-tanggal-lahir').textContent = formatBirthPlace(student.tempat_lahir, student.tanggal_lahir);  
            document.getElementById('preview-agama').textContent = student.agama || '-';  
            document.getElementById('preview-alamat').textContent = student.alamat || '-';  
  
            const photoImg = document.getElementById('preview-photo');  
            const photoPlaceholder = document.getElementById('preview-photo-placeholder');  
            if (student.foto) { photoImg.src = student.foto; photoImg.style.display = 'block'; photoPlaceholder.style.display = 'none'; }  
            else { photoImg.style.display = 'none'; photoPlaceholder.style.display = 'flex'; photoPlaceholder.textContent = 'FOTO'; }  
  
            const barcodeValue = nisn || nipd || '-';  
            document.getElementById('barcode-number').textContent = barcodeValue;  
            generateBarcode(barcodeValue);  
        }  
  
        function formatGender(value) {  
            const text = String(value || '').toLowerCase().trim();  
            if (text === 'l' || text === 'laki-laki' || text === 'laki laki' || text === 'male') return 'Laki-laki';  
            if (text === 'p' || text === 'perempuan' || text === 'female') return 'Perempuan';  
            return value || '-';  
        }  
  
        function formatBirthPlace(tempat, tanggal) {  
            if (!tempat && !tanggal) return '-';  
            if (!tanggal) return tempat || '-';  
            const date = new Date(tanggal);  
            if (isNaN(date.getTime())) return (tempat ? tempat + ', ' : '') + tanggal;  
            const months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];  
            const formatted = date.getDate() + ' ' + months[date.getMonth()] + ' ' + date.getFullYear();  
            return tempat ? tempat + ', ' + formatted : formatted;  
        }  
  
        function resetPreview() {  
            document.getElementById('preview-nipdnisn').textContent = '-';  
            document.getElementById('preview-nama').textContent = '-';  
            document.getElementById('preview-jenis-kelamin').textContent = '-';  
            document.getElementById('preview-tempat-tanggal-lahir').textContent = '-';  
            document.getElementById('preview-agama').textContent = '-';  
            document.getElementById('preview-alamat').textContent = '-';  
            document.getElementById('preview-photo').style.display = 'none';  
            document.getElementById('preview-photo-placeholder').style.display = 'flex';  
            document.getElementById('preview-photo-placeholder').textContent = 'FOTO';  
            document.getElementById('barcode-number').textContent = '-';  
            generateBarcode('-');  
        }  
  
        function buildBarcodeBackground(value) {
            if (!value || value === '-') {
                return 'repeating-linear-gradient(90deg, #000 0mm, #000 0.5mm, #fff 0.5mm, #fff 1mm)';
            }

            let pattern = '';
            const text = String(value);

            for (let i = 0; i < text.length; i++) {
                const code = text.charCodeAt(i);
                const width = (code % 4) + 2;
                pattern += '#000 ' + width + 'px, ';
                pattern += '#fff ' + width + 'px, ';
            }

            return 'repeating-linear-gradient(90deg, ' + pattern + '#000 0px, #000 2px)';
        }

        function generateBarcode(value) {
            const barcode = document.getElementById('student-barcode');
            if (!barcode) return;
            barcode.style.background = buildBarcodeBackground(value);
        }  
  
        function updatePrintInfo() {  
            const mode = document.getElementById('print_mode').value;  
            const info = document.getElementById('print-info');  
            if (mode === 'student') { info.textContent = 'Mode satu murid. Sistem menyiapkan lembar depan dan lembar belakang kartu pada A4.'; return; }  
            if (mode === 'class') {  
                const select = document.getElementById('homeroom_id');  
                if (!select.value) { info.textContent = 'Silakan pilih kelas. Semua murid pada kelas tersebut akan dicetak secara massal.'; return; }  
                const students = studentData.filter(function (student) { return String(student.homeroom_id) === String(select.value); });  
                info.textContent = students.length + ' murid ditemukan pada kelas ' + select.options[select.selectedIndex].text + '. Sistem menyiapkan lembar depan dan lembar belakang kartu.'; return;  
            }  
            if (mode === 'all') { info.textContent = studentData.length + ' murid akan dicetak dengan lembar depan dan lembar belakang.'; }  
        }  
  
        function selectAllStudents() {  
            const mode = document.getElementById('print_mode');  
            mode.value = 'all'; mode.dispatchEvent(new Event('change'));  
        }  
  
        function preparePrint() {  
            const mode = document.getElementById('print_mode').value;  
            if (mode === 'student') {  
                const studentSelect = document.getElementById('student_id');  
                if (!studentSelect.value) { alert('Silakan pilih murid terlebih dahulu.'); return; }  
                const selectedStudent = studentData.find(function (s) { return String(s.id) === String(studentSelect.value); });  
                if (selectedStudent) { buildPrintSheet([selectedStudent]); } else { alert('Data murid tidak ditemukan.'); }  
                return;  
            }  
            if (mode === 'class') {  
                const classId = document.getElementById('homeroom_id').value;  
                if (!classId) { alert('Silakan pilih kelas terlebih dahulu.'); return; }  
                const students = studentData.filter(function (student) { return String(student.homeroom_id) === String(classId); });  
                if (!students.length) { alert('Tidak ada murid pada kelas tersebut.'); return; }  
                buildPrintSheet(students); return;  
            }  
            if (mode === 'all') {  
                if (!studentData.length) { alert('Tidak ada data murid.'); return; }  
                buildPrintSheet(studentData);  
            }  
        }  
  
        function buildPrintSheet(students) {  
            const oldSheet = document.getElementById('print-sheet');  
            if (oldSheet) { oldSheet.remove(); }  
            const sheet = document.createElement('div');  
            sheet.id = 'print-sheet';  

            const chunkSize = 8; // V21R147: 2 kolom x 4 baris, setiap kartu 9 x 6 cm.  
            for (let i = 0; i < students.length; i += chunkSize) {  
                const chunk = students.slice(i, i + chunkSize);  
                const frontPage = document.createElement('div');  
                frontPage.className = 'print-page';  
                chunk.forEach(function (student) {  
                    const card = createPrintCard(student);  
                    frontPage.appendChild(card);  
                });  
                sheet.appendChild(frontPage);  

                const backPage = createBackPage(chunk);  
                sheet.appendChild(backPage);  
            }  
            document.body.appendChild(sheet);

            const previewCard = document.getElementById('student-card');
            if (previewCard) {
                previewCard.style.display = 'none';
            }

            let cleaned = false;
            const cleanupPrintSheet = function () {
                if (cleaned) return;
                cleaned = true;

                if (sheet.isConnected) {
                    sheet.remove();
                }

                if (previewCard) {
                    previewCard.style.display = '';
                }

                window.removeEventListener('afterprint', cleanupPrintSheet);
            };

            window.addEventListener('afterprint', cleanupPrintSheet, { once: true });
            const images = Array.from(sheet.querySelectorAll('img'));
            const printStatus = document.createElement('div');
            printStatus.setAttribute('role', 'status');
            printStatus.style.cssText = 'position:fixed;bottom:12px;right:12px;background:#1f2937;color:white;padding:10px 14px;border-radius:6px;z-index:9999';
            printStatus.textContent = 'Memuat foto untuk cetak...';
            document.body.appendChild(printStatus);
            let completed = 0;
            const ready = images.map(function (img) {
                return new Promise(function (resolve) {
                    const done = () => { completed++; printStatus.textContent = 'Foto siap: ' + completed + '/' + images.length; resolve(img.naturalWidth > 0); };
                    if (img.complete) { done(); return; }
                    img.addEventListener('load', done, { once: true });
                    img.addEventListener('error', done, { once: true });
                });
            });
            Promise.race([
                Promise.all(ready),
                new Promise(resolve => setTimeout(() => resolve(null), 60000))
            ]).then(function (results) {
                printStatus.remove();
                if (!sheet.isConnected) return;
                const failed = results === null ? 'Beberapa gambar belum selesai dimuat' :
                    results.filter(ok => !ok).length + ' gambar gagal dimuat';
                if (results === null || results.some(ok => !ok)) {
                    if (!confirm(failed + '. Cetak tanpa foto tersebut? Pilih Batal untuk mencoba kembali.')) return;
                }
                window.print();
            });  
        }  


        function createBackPage(students) {
            // A4 portrait, cetak bolak-balik tepi panjang: posisi kiri/kanan
            // pada lembar belakang harus dicerminkan pada setiap baris.
            const page = document.createElement('div');
            page.className = 'print-page';

            const slots = new Array(8).fill(null);
            students.forEach(function (student, index) {
                slots[index] = student;
            });

            for (let row = 0; row < 4; row++) {
                const left = slots[row * 2];
                const right = slots[row * 2 + 1];
                page.appendChild(right ? createBackCard(right) : createPlaceholderCard());
                page.appendChild(left ? createBackCard(left) : createPlaceholderCard());
            }

            return page;
        }

        function createPlaceholderCard() {
            const placeholder = document.createElement('div');
            placeholder.className = 'print-placeholder';
            return placeholder;
        }

        function createBackCard(student) {
            const card = document.createElement('div');
            card.className = 'student-card-back';

            const schoolName = @json($schoolData['nama'] ?? 'NAMA SEKOLAH');
            const schoolAddress = @json(trim(($schoolData['alamat_jalan'] ?? '') . ' ' . ($schoolData['kabupaten_kota'] ?? '')));
            const schoolMeta = [
                @json($schoolData['npsn'] ?? ''),
                @json($schoolData['provinsi'] ?? ''),
                @json($schoolData['kode_pos'] ?? '')
            ].filter(Boolean).join(' · ');
            const schoolWebsite = @json($schoolData['website'] ?? '');
            const schoolEmail = @json($schoolData['email'] ?? '');
            const validityText = 'Berlaku selama menjadi peserta didik';
            const contactPieces = [schoolWebsite, schoolEmail].filter(Boolean);
            const footerContact = contactPieces.length ? contactPieces.join(' | ') : (schoolAddress || 'Jika ditemukan harap dikembalikan ke sekolah');

            card.innerHTML = '' +
                '<div class="back-title">Bagian Belakang Kartu Pelajar</div>' +
                '<div class="back-body">' +
                    '<div class="back-school-name">' + escapeHtml(schoolName || 'Nama Sekolah') + '</div>' +
                    '<div class="back-school-meta">' + escapeHtml([schoolAddress, schoolMeta].filter(Boolean).join(' · ')) + '</div>' +
                    '<div class="back-notes">' +
                        '<div class="back-note-item">1. Kartu ini wajib dibawa saat diperlukan untuk layanan sekolah.</div>' +
                        '<div class="back-note-item">2. Tidak boleh dipindahtangankan kepada pihak lain.</div>' +
                        '<div class="back-note-item">3. Jika ditemukan, harap dikembalikan ke ' + escapeHtml(schoolName || 'sekolah') + '.</div>' +
                        '<div class="back-note-item">4. Pemilik: <strong>' + escapeHtml(student.nama || '-') + '</strong>.</div>' +
                    '</div>' +
                '</div>' +
                '<div class="back-footer">' +
                    '<div class="back-validity">' + validityText + '</div>' +
                    '<div class="back-contact">' + escapeHtml(footerContact) + '</div>' +
                '</div>';

            return card;
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function createPrintCard(student) {  
            const card = document.getElementById('student-card').cloneNode(true);  
            card.removeAttribute('id');  
            card.style.display = 'block';  
            card.style.boxShadow = 'none';  

            const photoImg = card.querySelector('#preview-photo');  
            const photoPlaceholder = card.querySelector('#preview-photo-placeholder');  

            let rawFoto = student.foto_path || student.foto || student.photo || '';
            let fotoSrc = student.foto_url || '';
            if (!fotoSrc && rawFoto) {
                if (rawFoto.startsWith('http') || rawFoto.startsWith('/media/')) {
                    fotoSrc = rawFoto;
                } else if (rawFoto.startsWith('/storage/')) {
                    fotoSrc = '/media/' + rawFoto.substring('/storage/'.length);
                } else {
                    fotoSrc = '/media/' + rawFoto.replace(/^\/+/, '');
                }
            }

            if (fotoSrc) {  
                photoImg.src = fotoSrc;  
                photoImg.style.display = 'block';  
                photoPlaceholder.style.display = 'none';  
            } else {  
                photoImg.style.display = 'none';  
                photoPlaceholder.style.display = 'flex';  
                photoPlaceholder.textContent = 'FOTO';  
            }  

            // FIX TANDA TANGAN UNTUK CETAK MASSAL
            const ttdImg = card.querySelector('#preview-ttd');
            if (ttdImg) {
                if (ttdUrl) {
                    ttdImg.src = ttdUrl; // Paksa URL absolut dari server
                    ttdImg.style.display = 'block';
                    ttdImg.style.opacity = '1';
                } else {
                    ttdImg.style.display = 'none';
                }
            }

            const nipd = student.nipd || student.nis || '';  
            const nisn = student.nisn || '';  
            let nomor = '-';  
            if (nipd && nisn) { nomor = nipd + ' / ' + nisn; } else if (nipd) { nomor = nipd; } else if (nisn) { nomor = nisn; }  

            card.querySelector('#preview-nipdnisn').textContent = nomor;  
            card.querySelector('#preview-nama').textContent = student.nama || '-';  
            card.querySelector('#preview-jenis-kelamin').textContent = formatGender(student.jenis_kelamin);  
            card.querySelector('#preview-tempat-tanggal-lahir').textContent = formatBirthPlace(student.tempat_lahir, student.tanggal_lahir);  
            card.querySelector('#preview-agama').textContent = student.agama || '-';  
            card.querySelector('#preview-alamat').textContent = student.alamat || student.alamat_jalan || '-';  

            const barcodeValue = nisn || nipd || '-';
            const barcodeNumber = card.querySelector('#barcode-number');
            const barcode = card.querySelector('#student-barcode');

            if (barcodeNumber) {
                barcodeNumber.textContent = barcodeValue;
            }

            if (barcode) {
                barcode.style.background = buildBarcodeBackground(barcodeValue);
            }

            card.querySelectorAll('[id]').forEach(function (element) { element.removeAttribute('id'); });  
            return card;  
        }
    </script>  
</x-app-layout>