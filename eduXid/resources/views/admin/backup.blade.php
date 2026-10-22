<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Backup & Restore Database
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            @if (session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">{{ session('error') }}</div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                
                <!-- Kartu Backup -->
                <div class="bg-white p-6 rounded-lg shadow-sm border-t-4 border-green-500">
                    <div class="text-3xl mb-2">💾</div>
                    <h4 class="font-bold text-gray-800 mb-2 text-lg">Backup Database</h4>
                    <p class="text-sm text-gray-500 mb-4">Unduh seluruh data eduXid (Master dan Transaksional) dalam format file .sql. Simpan file ini di tempat aman.</p>
                    <a href="{{ route('admin.backup.create') }}" class="inline-block bg-green-600 text-white px-6 py-2 rounded-md hover:bg-green-700 text-sm font-bold">
                        Download Backup (.sql)
                    </a>
                </div>

             <!-- Kartu Restore -->
             <div class="bg-white p-6 rounded-lg shadow-sm border-t-4 border-yellow-500">
                 <div class="text-3xl mb-2">♻️</div>
                 <h4 class="font-bold text-gray-800 mb-2 text-lg">Restore Database</h4>
                 <p class="text-sm text-gray-500 mb-4">Unggah file .sql untuk mengembalikan data ke kondisi sebelumnya. <br><span class="text-red-500 font-bold">Peringatan: Data saat ini akan ditimpa!</span></p>
                 
                 <form action="{{ route('admin.backup.restore') }}" method="POST" enctype="multipart/form-data">
                     @csrf
                     <div class="mb-4">
                         <input type="file" name="sql_file" accept=".sql,.txt" required class="block w-full border border-gray-300 rounded-md shadow-sm text-sm text-gray-600 p-2">
                     </div>
                     <button type="submit" class="w-full bg-yellow-500 text-white py-2 rounded-md hover:bg-yellow-600 text-sm font-bold" onclick="return confirm('Apakah Anda yakin? Data saat ini akan ditimpa!')">
                         Upload & Restore
                     </button>
                 </form>
             </div>
            </div>
        </div>
    </div>
</x-app-layout>