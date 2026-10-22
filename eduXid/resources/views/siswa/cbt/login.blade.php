<x-guest-layout>
    <div class="min-h-screen flex flex-col items-center justify-center bg-gray-100 px-4 py-8">
        <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
            
            <!-- Logo eduXid -->
            <div class="flex justify-center mb-6">
                <img src="{{ asset('img/eduxid-horizontal.png') }}" alt="eduXid Logo" class="h-12 w-auto" onerror="this.style.display='none'">
            </div>

            <h1 class="text-2xl font-bold text-center text-blue-600 mb-2">eduXid CBT</h1>
            <p class="text-center text-gray-500 mb-6">Masukkan NISN, Password, dan Token</p>

            @if (session('error'))
                <div class="bg-red-100 text-red-700 p-3 rounded mb-4 text-sm">
                    {{ session('error') }}
                </div>
            @endif

            <form action="{{ route('cbt.masuk') }}" method="POST">
                @csrf
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2">NISN</label>
                    <input type="text" name="nisn" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" required autofocus value="{{ old('nisn') }}">
                </div>
                
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2">Password <span class="font-normal text-xs text-gray-500">(NPSN Sekolah)</span></label>
                    <input type="password" name="password" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                </div>

                <div class="mb-6">
                    <label class="block text-gray-700 text-sm font-bold mb-2">Token Ujian (6 Digit)</label>
                    <input type="text" name="token" maxlength="6" class="w-full px-3 py-2 border rounded-lg text-center text-2xl tracking-widest focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                </div>
                
                <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-blue-700 transition">
                    Mulai Ujian
                </button>
            </form>
        </div>

        <!-- Footer Login CBT -->
        <footer class="mt-8 text-center text-xs text-gray-400">
            &copy; 2026. Anas Farhan. eduXid untuk Indonesia.
        </footer>
    </div>
</x-guest-layout>