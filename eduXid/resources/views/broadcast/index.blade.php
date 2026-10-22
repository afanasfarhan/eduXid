<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">Pesan &amp; Pengumuman</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm rounded-lg p-6">
            <h3 class="font-semibold text-lg mb-2">Buat Pengumuman</h3>
            <p class="text-gray-600 text-sm mb-4">{{ $role === 'admin' ? 'Pengumuman hanya dikirim ke GTK sekolah Anda.' : 'Pengumuman hanya dikirim kepada murid rombel penugasan Anda.' }} Perangkat offline menerima saat terhubung kembali.</p>
            @if(session('status'))<div class="bg-green-50 text-green-800 p-3 mb-3 rounded">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="bg-red-50 text-red-800 p-3 mb-3 rounded">{{ $errors->first() }}</div>@endif
            <form method="POST" action="{{ route('broadcast.send') }}" class="space-y-4">@csrf
                @if($role !== 'admin')
                    <label class="block font-medium text-sm">Rombel tujuan
                        <select name="target_homeroom_id" required class="block mt-1 w-full border-gray-300 rounded">
                            <option value="">Pilih rombel penugasan</option>
                            @foreach($assignments as $item)<option value="{{ $item->id }}" @selected(old('target_homeroom_id')==$item->id)>{{ $item->name }}</option>@endforeach
                        </select>
                    </label>
                @else
                    <div class="bg-blue-50 text-blue-800 px-3 py-2 rounded text-sm">Penerima: seluruh GTK dengan perangkat aktif pada NPSN sekolah ini</div>
                @endif
                <label class="block text-sm font-medium">Judul<input required minlength="3" maxlength="160" name="title" value="{{ old('title') }}" class="mt-1 block w-full border-gray-300 rounded" /></label>
                <label class="block text-sm font-medium">Isi pengumuman<textarea required minlength="3" maxlength="4000" rows="6" name="body" class="mt-1 block w-full border-gray-300 rounded">{{ old('body') }}</textarea></label>
                <label class="block text-sm font-medium">Prioritas<select name="priority" class="mt-1 block w-full border-gray-300 rounded"><option value="normal">Biasa</option><option value="important">Penting</option></select></label>
                <button type="submit" class="px-5 py-2 rounded bg-indigo-600 text-white">Kirim Pengumuman</button>
            </form>
        </div>
        <div class="mt-6 bg-white shadow-sm rounded-lg p-6">
            <h3 class="font-semibold text-lg mb-3">Kotak Masuk & Pesan Resmi eduXid</h3>
            <p class="text-sm text-gray-500 mb-3">Pesan yang sudah diterima disimpan lokal dan tersedia ketika offline. Muat ulang halaman saat online untuk menerima pesan terbaru.</p>
            <p class="text-sm text-gray-500 mb-3">Hapus menyembunyikan pesan secara permanen pada perangkat ini saja. Pesan di perangkat GTK lain tetap ada.</p>
            @if(($inboxStatus['status'] ?? 'ok') !== 'ok')
                <p class="mb-3 text-sm font-medium text-amber-800" role="status">⚠ {{ $inboxStatus['message'] ?? 'Kotak masuk Cloud belum dapat diperiksa.' }}</p>
            @else
                <p class="mb-3 text-sm text-green-800" role="status">{{ $inboxStatus['message'] }}</p>
            @endif
            @forelse($messages as $item)
                <article class="border-t py-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap gap-2"><strong>{{ $item->title }}</strong>@if($item->priority === 'important')<span class="text-red-700">Penting</span>@endif</div>
                            <p class="text-gray-500 text-xs">{{ $item->issued_at }}</p>
                            <p class="text-gray-800 whitespace-pre-wrap break-words mt-2">{{ $item->body }}</p>
                        </div>
                        <form method="POST" action="{{ route('broadcast.destroy', ['messageId' => $item->message_id]) }}"
                              onsubmit="return confirm('Hapus pesan ini dari perangkat Anda? Penghapusan tidak memengaruhi GTK lain.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" aria-label="Hapus pesan {{ $item->title }}"
                                    class="rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 focus:ring-2 focus:ring-red-500">🗑 Hapus</button>
                        </form>
                    </div>
                </article>
            @empty<p class="text-gray-500">Belum ada pengumuman diterima.</p>@endforelse
        </div>
    </div>
</x-app-layout>
