<x-app-layout>
    <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100">
        <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
            <h2 class="text-center text-2xl font-bold text-gray-800 mb-2">Selamat Datang, {{ auth()->user()->name }}!</h2>
            <p class="text-center text-sm text-gray-500 mb-6">Silakan pilih Tahun Pelajaran & Semester yang akan Anda kelola hari ini.</p>

            <form method="POST" action="{{ route('pilih.semester.store') }}">
                @csrf
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Tahun Pelajaran & Semester</label>
                    <select name="semester_id" class="w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2" required>
                        <option value="" disabled selected>-- Pilih Periode --</option>
                        @foreach ($semesters as $s)
                            @php
                                $tahun1 = substr($s->semester_id, 0, 4);
                                $tahun2 = $tahun1 + 1;
                                $sem = substr($s->semester_id, 4, 1) == 1 ? 'Ganjil' : 'Genap';
                                $label = $tahun1 . '/' . $tahun2 . ' - ' . $sem;
                            @endphp
                            <option value="{{ $s->semester_id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <button type="submit" class="w-full bg-indigo-600 text-white py-2 rounded-md hover:bg-indigo-700 font-bold">
                    Mulai Bekerja
                </button>
            </form>

            <div class="mt-4 text-center">
                <a href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" class="text-xs text-gray-500 hover:text-gray-700">Logout</a>
                <form id="logout-form" action="{{ route('logout') }}" method="POST" class="hidden">@csrf</form>
            </div>
        </div>
    </div>
</x-app-layout>