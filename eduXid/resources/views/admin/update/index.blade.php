<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Update & Patch eduXid
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    Kelola pembaruan sistem eduXid secara aman.
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if (session('success'))
                <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg">
                    {{ session('error') }}
                </div>
            @endif

            {{-- Ringkasan versi --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-md border border-gray-100 border-t-4 border-blue-500 p-6">
                    <div class="flex items-center mb-3">
                        <span class="text-3xl mr-3">💻</span>
                        <div>
                            <h3 class="font-bold text-gray-800 text-lg">Versi Saat Ini</h3>
                            <p class="text-sm text-gray-500">Versi eduXid yang sedang berjalan.</p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <span class="text-3xl font-bold text-blue-600">{{ $currentVersion ?? '1.0.0' }}</span>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-md border border-gray-100 border-t-4 border-green-500 p-6">
                    <div class="flex items-center mb-3">
                        <span class="text-3xl mr-3">🚀</span>
                        <div>
                            <h3 class="font-bold text-gray-800 text-lg">Versi Terbaru</h3>
                            <p class="text-sm text-gray-500">Versi terbaru yang tersedia di Server Admin.</p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <span class="text-3xl font-bold {{ ($latestVersion != $currentVersion) ? 'text-green-600' : 'text-gray-400' }}">
                            {{ $latestVersion ?? $currentVersion ?? '1.0.0' }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Form Upload Patch Manual --}}
            <div class="bg-white rounded-xl shadow-md border border-gray-100 p-6 mb-8">
                <h3 class="font-bold text-gray-800 text-lg mb-4">📤 Update Manual Resmi (Offline)</h3>
                <p class="text-sm text-gray-500 mb-4">
                    Unggah <strong>rilis resmi bertanda tangan Ed25519</strong> berformat <code>EDUXID-RELEASE-V2</code>. ZIP Full File Replacement atau patch tanpa tanda tangan tidak bisa dipasang melalui menu ini. Nomor versi dibaca otomatis dari manifest rilis.
                </p>
                <form action="{{ route('admin.update.upload') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="max-w-2xl">
                        <label for="eduxid-manual-update-file" class="block text-sm font-medium text-gray-700 mb-1">ZIP Rilis Resmi</label>
                        <input id="eduxid-manual-update-file" type="file" name="patch_zip" accept=".zip,application/zip" required
                               class="block w-full border border-gray-300 rounded-md shadow-sm text-sm text-gray-600 p-2">
                        @error('patch_zip')
                            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-gray-500">Batas unggahan bergantung pada konfigurasi PHP portable (<code>upload_max_filesize</code>/<code>post_max_size</code>). Untuk ZIP lebih besar, salin rilis resmi ke root instalasi dengan nama <code>eduxid-patch-VERSI.zip</code>, lalu jalankan ulang launcher.</p>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded-md hover:bg-indigo-700 text-sm font-bold"
                                onclick="return confirm('Verifikasi dan simpan rilis resmi untuk pemasangan setelah eduXid dijalankan ulang?')">
                            Verifikasi & Siapkan Update
                        </button>
                    </div>
                </form>
            </div>

            <div class="mb-6 bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-900">
                <strong>Penting:</strong> unggahan tidak langsung mengganti kode aplikasi saat server berjalan.
                Setelah verifikasi berhasil, tutup eduXid dan jalankan ulang <code>eduXid.exe</code>.
                Updater Windows akan memeriksa ulang tanda tangan, versi, status CBT, serta membuat backup sebelum pemasangan.
            </div>

            <div class="mt-6 bg-blue-50 border border-blue-200 rounded-xl p-5">
                <div class="flex items-start">
                    <span class="text-2xl mr-3">ℹ️</span>
                    <div>
                        <h4 class="font-bold text-blue-800">Informasi Update</h4>
                        <p class="text-sm text-blue-700 mt-1">
                            Update offline menggunakan format <strong>EDUXID-RELEASE-V2</strong> yang diverifikasi Ed25519.
                            ZIP manual tidak dipasang melalui <strong>PatchExecutor</strong> lama; pemasangan dilakukan
                            oleh updater Windows resmi setelah pemeriksaan keamanan dan backup.
                        </p>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>