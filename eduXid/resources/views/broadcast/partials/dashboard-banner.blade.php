{{-- V5: Navigasi pengumuman berada di dalam banner, termasuk saat inbox kosong. --}}
@php
    $bannerUnreadCount = max(0, (int) ($unreadCount ?? 0));
@endphp
<section class="mb-6 overflow-hidden rounded-xl border border-indigo-200 bg-indigo-50 shadow-sm" role="region" aria-label="Pesan dan pengumuman terbaru">
    <div class="flex flex-wrap items-center justify-between gap-3 bg-indigo-100 px-4 py-3">
        <div class="flex flex-wrap items-center gap-2 font-bold text-indigo-900">
            <span aria-hidden="true">📬</span>
            <span>Pesan &amp; Pengumuman Terbaru</span>
            @if($bannerUnreadCount > 0)
                <span class="rounded-full bg-red-600 px-2 py-0.5 text-xs font-bold text-white">Ada pesan baru</span>
            @endif
        </div>
        <a href="{{ route('broadcast.index') }}"
           class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            <span aria-hidden="true">✉️</span>
            <span>Buat Pengumuman</span>
            <span id="dashboard-broadcast-unread"
                  class="{{ $bannerUnreadCount > 0 ? '' : 'hidden' }} rounded-full bg-white px-2 py-0.5 text-xs font-bold text-blue-700">{{ $bannerUnreadCount }}</span>
        </a>
    </div>
    @if($messages->isNotEmpty())
        <div class="divide-y divide-indigo-100">
            @foreach($messages as $item)
                <article class="px-4 py-3 {{ $item->priority === 'important' ? 'border-l-4 border-red-500' : '' }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                @if($item->read_at === null)
                                    <span class="rounded bg-indigo-700 px-2 py-0.5 text-xs font-bold text-white">Baru</span>
                                @endif
                                <h3 class="font-semibold text-gray-900">{{ $item->title }}</h3>
                                @if($item->priority === 'important')
                                    <span class="rounded bg-red-100 px-2 py-0.5 text-xs font-bold text-red-700">Penting</span>
                                @endif
                                <span class="text-xs text-gray-500">{{ $item->issued_at }}</span>
                            </div>
                            <p class="mt-1 whitespace-pre-wrap break-words text-sm text-gray-800">{{ \Illuminate\Support\Str::limit((string) $item->body, 350) }}</p>
                        </div>
                        <form method="POST" action="{{ route('broadcast.destroy', ['messageId' => $item->message_id]) }}"
                              onsubmit="return confirm('Hapus pesan ini dari perangkat Anda? Penghapusan tidak memengaruhi GTK lain.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" aria-label="Hapus pesan {{ $item->title }}"
                                    class="inline-flex items-center rounded-md border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500">
                                🗑 Hapus
                            </button>
                        </form>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <div class="px-4 py-4">
            <p class="text-sm text-gray-700">Belum ada pesan yang ditampilkan di perangkat ini.</p>
        </div>
    @endif
</section>
