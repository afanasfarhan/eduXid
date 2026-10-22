<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Update & Patch eduXid
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    Kelola pembaruan aplikasi dari Server Admin.
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

            {{-- Peringatan jika Admin Offline --}}
            @if (!($status['admin_connected'] ?? false))
                <div class="mb-6 bg-red-50 border border-red-300 text-red-700 px-4 py-3 rounded-lg flex items-center">
                    <span class="text-2xl mr-3">📡</span>
                    <div>
                        <strong>Koneksi Server Admin Terputus!</strong><br>
                        <span class="text-sm">Tidak dapat memeriksa versi terbaru. Pastikan komputer ini terhubung jaringan lokal yang sama dengan Server Admin.</span>
                    </div>
                </div>
            @endif

            {{-- Ringkasan versi --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-md border border-gray-100 border-t-4 border-blue-500 p-6">
                    <div class="flex items-center mb-3">
                        <span class="text-3xl mr-3">💻</span>
                        <div>
                            <h3 class="font-bold text-gray-800 text-lg">Versi Saat Ini</h3>
                            <p class="text-sm text-gray-500">Versi eduXid yang sedang berjalan di laptop Anda.</p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <span class="text-3xl font-bold text-blue-600">{{ $status['current_version'] ?? '1.0.0' }}</span>
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
                        <span class="text-3xl font-bold {{ ($status['update_available'] ?? false) ? 'text-green-600' : 'text-gray-400' }}">
                            {{ $status['latest_version'] ?? '1.0.0' }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Daftar Patch Dinamis --}}
            <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h3 class="font-bold text-gray-800 text-lg">Daftar Patch Tersedia</h3>
                    <p class="text-sm text-gray-500 mt-1">Daftar patch yang dapat dipasang dari Server Admin.</p>
                </div>

                <div class="divide-y divide-gray-200">
                    @forelse ($status['patches'] ?? [] as $patch)
                        <div class="p-6">
                            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
                                
                                <div class="flex items-start">
                                    <div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center text-xl mr-4">
                                        📦
                                    </div>
                                    <div>
                                        <h4 class="font-bold text-gray-800">Patch {{ $patch->version }}</h4>
                                        <p class="text-sm text-gray-500 mt-1">{{ $patch->name ?? 'Patch eduXid' }}</p>
                                        @if (!empty($patch->description))
                                            <p class="text-xs text-gray-400 mt-2">{{ $patch->description }}</p>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex items-center gap-3">
                                    
                                    @if ($patch->status === 'installed')
                                        <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-bold bg-green-100 text-green-700">
                                            ✓ Terpasang
                                        </span>
                                        <span class="text-xs text-gray-400">
                                            {{ optional($patch->installed_at)->format('d M Y H:i') ?? '' }}
                                        </span>

                                    @elseif ($patch->status === 'failed')
                                        <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-bold bg-red-100 text-red-700">
                                            ✕ Gagal
                                        </span>
                                        <form method="POST" action="{{ route('guru.update.install.system', $patch->version) }}">
                                            @csrf
                                            <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 text-sm font-bold" onclick="return confirm('Coba pasang ulang Patch {{ $patch->version }}?')">
                                                Coba Pasang Ulang
                                            </button>
                                        </form>

                                    @else
                                        {{-- Status: Pending atau Available --}}
                                        <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-bold bg-yellow-100 text-yellow-700">
                                            Menunggu
                                        </span>
                                        <form method="POST" action="{{ route('guru.update.install.system', $patch->version) }}">
                                            @csrf
                                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 text-sm font-bold" onclick="return confirm('Pasang Patch {{ $patch->version }} sekarang?')">
                                                Pasang Patch
                                            </button>
                                        </form>
                                    @endif

                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="p-6 text-center text-gray-500">
                            Tidak ada patch yang terdaftar. Pastikan server Admin terhubung.
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="mt-6 bg-blue-50 border border-blue-200 rounded-xl p-5">
                <div class="flex items-start">
                    <span class="text-2xl mr-3">ℹ️</span>
                    <div>
                        <h4 class="font-bold text-blue-800">Informasi Update</h4>
                        <p class="text-sm text-blue-700 mt-1">
                            Patch diunduh dari Server Admin dan diekstrak secara otomatis.
                            Sistem tidak akan menganggap patch berhasil sebelum proses eksekusi selesai tanpa error.
                        </p>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>