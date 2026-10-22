{{-- V21R87: modal yang sama dengan Lihat Profil Murid/Denah --}}
    <!-- Modal Info Siswa (Rekap Rata-Rata) -->
    <div id="modal-siswa" data-endpoint="{{ route('student.profile.overlay') }}" role="dialog" aria-label="Profil Murid" aria-modal="true" aria-hidden="true" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden justify-center items-center z-50 p-4 overflow-auto">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-sm p-5 relative my-4" role="document" tabindex="-1">
            
            <button id="close-modal" class="absolute top-0 right-0 text-gray-400 hover:text-red-500 text-3xl font-bold z-10 px-3 py-2 leading-none">&times;</button>
            
            <div class="flex flex-col items-center mb-4">
                <div id="modal-foto-container" class="mb-2 flex justify-center"></div>
                <h3 id="modal-nama" class="text-lg font-bold text-gray-800 text-center"></h3>
                <p id="modal-nisn" class="font-bold text-gray-800 text-center"></p>
                <p id="modal-jk" class="text-xs text-gray-500"></p>
            </div>

            <div class="mb-4 border-y border-gray-100 py-3">
                <div class="text-center font-bold text-gray-800 text-sm mb-3"><span id="eduxid-global-profil-mapel">NILAI MAPEL</span></div>
                <div class="flex justify-between">
                    <div class="flex-1 text-center">
                        <div class="font-bold text-gray-700 text-xs mb-2 tracking-wide">Rekap Nilai Semester</div>
                        <div class="flex justify-center space-x-3 text-xs text-gray-600">
                            <div>
                                <div id="modal-diagnostik" class="font-bold text-gray-900 text-base"></div>
                                <span>Diagnostik</span>
                            </div>
                            <div>
                                <div id="modal-formatif" class="font-bold text-gray-900 text-base"></div>
                                <span>Formatif</span>
                            </div>
                            <div>
                                <div id="modal-sumatif" class="font-bold text-gray-900 text-base"></div>
                                <span>Sumatif</span>
                            </div>
                        </div>
                    </div>
                    <div class="w-px bg-gray-200 mx-2"></div>
                    <div class="flex-1 text-center">
                        <div class="font-bold text-gray-700 text-xs mb-2 tracking-wide">Rekap Absensi</div>
                        <div class="flex justify-center space-x-3 text-xs text-gray-600">
                            <div>
                                <div id="modal-sakit" class="font-bold text-blue-600 text-base"></div>
                                <span>Sakit</span>
                            </div>
                            <div>
                                <div id="modal-izin" class="font-bold text-yellow-600 text-base"></div>
                                <span>Izin</span>
                            </div>
                            <div>
                                <div id="modal-alpa" class="font-bold text-red-600 text-base"></div>
                                <span>Alpa</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rekap Penilaian Sikap 8 Dimensi (Read Only) -->
            <div class="mb-4 border-t pt-4">
                <h4 class="font-bold text-gray-700 mb-3 text-center text-xs">Rekap Nilai Sikap</h4>
                <div class="grid grid-cols-2 gap-2">
                    @php
                        $dimensi = [
                            1 => 'Keimanan', 2 => 'Kewargaan', 3 => 'Penalaran', 4 => 'Kreativitas',
                            5 => 'Kolaborasi', 6 => 'Kemandirian', 7 => 'Kesehatan', 8 => 'Komunikasi'
                        ];
                    @endphp
                    <?php foreach ($dimensi as $i => $nama) { ?>
                        <div class="flex items-center justify-between bg-gray-50 p-2 rounded">
                            <span class="text-[11px] text-gray-600 leading-tight">{{ $i }}. {{ $nama }}</span>
                            <div class="rating-stars-display flex" id="modal-d{{ $i }}"></div>
                        </div>
                    <?php } // [V21R85] Akhir loop dimensi. ?>
                </div>
            </div>
        </div>
    </div>


<script defer src="{{ asset('js/eduxid-student-profile-overlay-v21r87.js') }}?v=21r109"></script>


{{-- V21R109: modal untuk klik menu GTK tanpa pairing; tidak mengubah komponen profil --}}
@php
    $gtkUser109 = auth()->user();
    $gtkUnpaired109 = false;
    if ($gtkUser109 && \App\Services\AcademicPeriodService::isGtkRole($gtkUser109->roleName())) {
        $gtkUnpaired109 = !app(\App\Services\Sync\GtkPairedDeviceV21::class)->isPaired($gtkUser109);
    }
@endphp
@if($gtkUnpaired109)
<div id="eduxid-pairing-v21r109-config" data-unpaired="1" hidden></div>
<div id="eduxid-pairing-v21r109-modal" hidden style="display:none;position:fixed;inset:0;background:#0f172a90;z-index:2147483600;align-items:center;justify-content:center;padding:20px">
    <div role="dialog" tabindex="-1" aria-modal="true" aria-labelledby="eduxid-pairing-title" style="max-width:480px;width:100%;background:white;border-radius:16px;padding:26px;box-shadow:0 24px 60px #11182770;text-align:center;color:#1e293b;font:16px system-ui">
        <h2 id="eduxid-pairing-title" style="font-size:21px;font-weight:700;margin-bottom:12px">Pairing eduXid diperlukan</h2>
        <p style="line-height:1.6;margin-bottom:22px">Anda tidak bisa mengakses menu ini jika belum melakukan pairing. Hubungi admin sekolah atau pengembang eduXid di nomor WhatsApp 082253181899.</p>
        <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:9px">
            <button type="button" data-close style="background:#1d4ed8;color:#fff;border:0;border-radius:9px;padding:10px 15px;font-weight:bold">Tutup</button>
            <a href="{{ route('guru.pengaturan') }}" style="padding:10px 14px;background:#e2e8f0;color:#1e293b;border-radius:9px;text-decoration:none">Pengaturan</a>
            <a href="https://wa.me/6282253181899" target="_blank" rel="noopener noreferrer" style="padding:10px 14px;background:#e2e8f0;color:#1e293b;border-radius:9px;text-decoration:none">WhatsApp</a>
        </div>
    </div>
</div>
<script defer src="{{ asset('js/eduxid-gtk-pairing-v21r109.js') }}?v=21r109"></script>
@endif
