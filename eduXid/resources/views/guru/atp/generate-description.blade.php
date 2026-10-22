<div class="mb-5 rounded-xl border border-indigo-200 bg-indigo-50 p-4 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
    <div>
        <h3 class="font-bold text-indigo-900">Deskripsi Capaian Kompetensi dari ATP</h3>
        <p class="text-sm text-indigo-700 mt-1">
            Sistem membandingkan capaian setiap Materi/Tujuan Pembelajaran dari penilaian harian,
            lalu menuliskan tujuan yang paling menonjol dan tujuan yang masih perlu ditingkatkan.
        </p>
    </div>

    <form method="POST"
          action="{{ route('guru.atp.generateDescriptions', $assignment->id) }}"
          onsubmit="return confirm('Generate ulang deskripsi seluruh murid berdasarkan ATP dan penilaian harian? Deskripsi manual yang sudah ada dapat tergantikan.')">
        @csrf
        <button class="whitespace-nowrap px-4 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700">
            Generate Semua Deskripsi
        </button>
    </form>
</div>
