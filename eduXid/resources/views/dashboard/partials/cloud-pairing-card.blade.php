{{-- ============================================================
     KARTU HUBUNGKAN EDUXID CLOUD
     Dipakai di: setup guru, setup tendik, GTK normal.
     Data: $stats['cloud_pairing'] dari DashboardController.
     ============================================================ --}}
<div class="bg-white p-6 rounded-xl shadow-md border-l-4 mb-8 transition-all duration-300 hover:shadow-lg
    {{ ($stats['cloud_pairing']['is_paired'] ?? false) ? 'border-green-500' : 'border-yellow-500' }}">

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">

        <div class="flex items-start gap-4">
            <div class="text-4xl">
                {{ ($stats['cloud_pairing']['is_paired'] ?? false) ? '☁️' : '⚠️' }}
            </div>

            <div>
                <h3 class="text-lg font-bold text-gray-800 mb-1">
                    eduXid Cloud
                    <span class="ml-2 text-xs font-bold px-2 py-1 rounded-full align-middle
                        {{ ($stats['cloud_pairing']['is_paired'] ?? false)
                            ? 'bg-green-100 text-green-700'
                            : 'bg-yellow-100 text-yellow-700' }}">
                        {{ ($stats['cloud_pairing']['is_paired'] ?? false) ? 'TERHUBUNG' : 'BELUM TERHUBUNG' }}
                    </span>
                </h3>

                @if ($stats['cloud_pairing']['is_paired'] ?? false)
                    <p class="text-sm text-gray-500">
                        Laptop ini terhubung ke eduXid Cloud.
                        Sinkronisasi konten &amp; nilai ke perangkat murid <b>aktif</b>.
                    </p>
                @else
                    <p class="text-sm text-gray-500">
                        Sinkronisasi konten &amp; nilai ke perangkat murid <b>belum aktif</b>.
                        Masukkan <b>Kode Guru</b> dari Admin sekolah.
                    </p>
                @endif
            </div>
        </div>

        @if (!($stats['cloud_pairing']['is_paired'] ?? false))
            <div class="shrink-0 w-full sm:w-auto">
                <form id="cloud-pair-form" onsubmit="return false;" class="flex flex-col sm:flex-row gap-2">
                    @csrf
                    <input type="text" id="cloud-pair-code" name="enrollment_token"
                           placeholder="Tempel Kode Guru dari Admin"
                           autocomplete="off"
                           class="w-full sm:w-64 border-gray-300 rounded-md shadow-sm text-sm font-mono p-2.5 focus:border-indigo-500 focus:ring-indigo-500">
                    <button type="button" id="btn-cloud-pair"
                            class="inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-4 py-2.5 rounded-md hover:bg-indigo-700 text-sm font-bold shadow-sm whitespace-nowrap">
                        🔗 Hubungkan
                    </button>
                </form>
                <div id="cloud-pair-msg" class="hidden mt-2 text-sm"></div>
            </div>
        @endif

    </div>

    <div id="cloud-pair-result" class="hidden mt-4 text-sm rounded-lg px-4 py-3"></div>
</div>