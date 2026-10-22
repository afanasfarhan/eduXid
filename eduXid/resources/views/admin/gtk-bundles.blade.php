<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Siapkan Paket GTK</h2></x-slot>
    <style>
        .gtk-package-action {display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border:1px solid transparent;border-radius:6px;background:#4338ca;color:#fff;font-weight:600;text-decoration:none;cursor:pointer;}
        .gtk-package-action--build {background:#15803d;}
        .gtk-package-action:disabled {background:#e5e7eb;color:#4b5563;border-color:#d1d5db;cursor:not-allowed;}
        .gtk-package-action:focus-visible {outline:3px solid #818cf8;outline-offset:3px;}
        .gtk-package-toolbar {display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin:16px 0;}
    </style>
    <div class="py-12"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white p-6 rounded-lg shadow">
            <p class="text-gray-700">Admin menyiapkan Data Pokok dan pairing sebelum aplikasi dibagikan. Setiap GTK menerima paket miliknya, mengekstraknya ke folder baru, lalu login tanpa mengatur sinkronisasi awal.</p>
            <p class="text-sm text-gray-600 mt-3">Paket memuat aplikasi Laravel, PHP portable, Data Pokok sekolah, akun GTK pemilik, akun siswa untuk LAN, dan satu pairing PC. Data pekerjaan guru lain, akun admin, serta kunci Cloud admin tidak dibawa.</p>
            <p class="text-sm text-gray-600 mt-3">Pembuatan ulang memakai pairing yang masih valid. Jika token lama sudah dicabut, admin membuat pairing pengganti. Setelah paket berhasil dibuat, identitas PC GTK tersebut tidak dijalankan lagi oleh daemon pada instalasi admin.</p>
        </div>
        @if($error)
            <div class="p-4 bg-red-50 text-red-800 border rounded">{{ $error }}</div>
        @endif
        <div class="bg-white p-6 rounded-lg shadow">
            <h3 class="font-bold text-lg">1. Siapkan Cloud sekolah</h3>
            <p class="text-sm text-gray-600 mt-2">Pastikan Data Pokok dan konfigurasi Cloud admin sudah benar. Persiapan ini mengirim snapshot sekolah serta direktori akun siswa dan GTK ke Cloud.</p>
            <div class="gtk-package-toolbar"><button id="prepare-cloud" type="button" @disabled($error || !$profiles) class="gtk-package-action">Siapkan Data Pokok dan Cloud</button></div>
            <p id="prepare-status" role="status" aria-live="polite" class="text-sm mt-3"></p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h3 class="font-bold text-lg">2. Pilih GTK dan buat paket</h3>
            <label class="block text-sm mt-4" for="server-ip">IP admin untuk pembaruan LAN, bila digunakan</label>
            <input id="server-ip" type="text" placeholder="Opsional, contoh 192.168.1.10" class="mt-1 border rounded p-2 w-full sm:w-80">
            <p class="text-xs text-gray-600 mt-1">Admin mengisinya satu kali di sini; GTK tidak perlu mengaturnya. Pairing dan sinkronisasi Cloud tetap memakai internet.</p>
            <div class="gtk-package-toolbar">
                <button id="build-packages" type="button" @disabled($error || !$profiles || !$cloudPrepared) class="gtk-package-action gtk-package-action--build">Buat Paket GTK Terpilih</button>
                <span class="text-sm text-gray-600">Centang GTK pada tabel, lalu klik tombol ini.</span>
            </div>
            <p id="build-cloud-hint" class="text-sm mt-2 text-amber-800" @if($cloudPrepared) hidden @endif>Cloud belum disiapkan setelah data aktif dimuat. Klik <strong>Siapkan Data Pokok dan Cloud</strong> sampai berhasil, kemudian buat Paket GTK terpilih.</p>
            <p id="build-status" role="status" aria-live="polite" class="text-sm mt-3"></p>
            <p class="text-sm text-gray-600 mt-2">Tombol Unduh paket aktif setelah paket GTK selesai dibuat. Jika belum tersedia, statusnya ditampilkan pada baris GTK.</p>
            <div class="overflow-x-auto mt-4"><table class="w-full text-sm text-left">
                <thead><tr class="border-b"><th class="p-3"><input id="select-all" type="checkbox" aria-label="Pilih semua GTK"></th><th class="p-3">GTK</th><th class="p-3">Login</th><th class="p-3">Peran</th><th class="p-3">Paket</th></tr></thead>
                <tbody>
                @forelse($profiles as $p)
                    <tr class="border-b" data-teacher="{{ $p['teacher_id'] }}">
                        <td class="p-3"><input type="checkbox" class="gtk-choice" value="{{ $p['teacher_id'] }}" aria-label="Pilih {{ $p['name'] }}"></td>
                        <td class="p-3">{{ $p['name'] }}</td><td class="p-3">{{ \App\Support\SensitiveDataProtector::maskIdentifier($p['login_id']) }}</td><td class="p-3">{{ $p['role'] }}</td>
                        <td class="p-3 package-state" aria-live="polite">
                        @if(isset($exports[$p['teacher_id']]))
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('admin.gtk-bundles.download',$exports[$p['teacher_id']]->id) }}"
                                   class="gtk-package-action"
                                   aria-label="Unduh paket {{ $p['name'] }}">
                                    Unduh paket
                                </a>
                                <button type="button"
                                        class="gtk-package-action gtk-package-action--build gtk-rebuild"
                                        data-teacher="{{ $p['teacher_id'] }}"
                                        data-name="{{ $p['name'] }}">
                                    Buat Ulang
                                </button>
                            </div>
                            <span class="block text-xs text-gray-600 mt-1">
                                Paket siap. Anda dapat membuat ulang tanpa menghapus pairing.
                            </span>
                        @else
                            <button type="button" disabled class="gtk-package-action" title="Buat paket GTK ini terlebih dahulu" aria-label="Unduh paket {{ $p['name'] }} belum tersedia">Unduh paket</button>
                            <span class="block text-xs text-gray-600 mt-1">Belum dibuat</span>
                        @endif
                        </td>
                    </tr>
                @empty <tr><td colspan="5" class="p-3">Lengkapi Data Pokok GTK terlebih dahulu.</td></tr> @endforelse
                </tbody>
            </table></div>
            <p class="text-xs text-gray-600 mt-3">Paket dibuat berurutan untuk membatasi beban laptop. Biarkan halaman terbuka sampai selesai. Paket yang sudah selesai tetap dapat diunduh walaupun GTK berikutnya gagal; Anda dapat mencoba kembali GTK yang gagal.</p>
        </div>
    </div></div>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const prepare = document.getElementById('prepare-cloud');
        const build = document.getElementById('build-packages');
        const choices = Array.from(document.querySelectorAll('.gtk-choice'));
        document.getElementById('select-all').addEventListener('change', event => choices.forEach(c => c.checked = event.target.checked));
        const post = async (url, payload) => {
            let response;
            try {
                response = await fetch(url, {method:'POST', credentials:'same-origin', headers:{
                    'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN':@json(csrf_token()),
                }, body:JSON.stringify(payload)});
            } catch (error) {
                throw new Error('Koneksi dengan Laravel gagal. Periksa server lokal dan coba lagi.');
            }
            let data;
            try { data = await response.json(); }
            catch (error) {
                throw new Error('Server mengirim respons tidak valid (HTTP '+response.status+'). Periksa login dan log Laravel.');
            }
            if (!response.ok || !data?.success) {
                const first = data?.errors && Object.values(data.errors).flat().find(Boolean);
                throw new Error(data?.message && data.message!=='The given data was invalid.'
                    ? data.message : (first || 'Proses gagal (HTTP '+response.status+').'));
            }
            return data;
        };
        let busy = false;
        let cloudPrepared = {{ $cloudPrepared ? 'true' : 'false' }};
        const blocked = {{ ($error || !$profiles) ? 'true' : 'false' }};
        const hint = document.getElementById('build-cloud-hint');
        const toggle = state => {
            busy=state;
            prepare.disabled=state||blocked;
            build.disabled=state||blocked||!cloudPrepared;
            hint.hidden=cloudPrepared;
        };
        prepare.addEventListener('click', async () => {
            if (busy) return;
            toggle(true);
            const status=document.getElementById('prepare-status');
            status.textContent='Menyiapkan Data Pokok dan Cloud…';
            try {
                const data=await post(@json(route('admin.gtk-bundles.prepare')),{});
                cloudPrepared=true;
                status.textContent=data.message;
            } catch(error) {
                cloudPrepared=false;
                status.textContent='Gagal menyiapkan Cloud: '+error.message;
            } finally {toggle(false);}
        });
        const makePackage = async (teacherId, cell, name='GTK') => {
            const serverIp=document.getElementById('server-ip').value.trim();
            const oldHtml=cell.innerHTML;
            cell.textContent='Sedang dibuat ulang…';

            try {
                const data=await post(
                    @json(route('admin.gtk-bundles.create')),
                    {teacher_id:Number(teacherId),server_ip:serverIp||null}
                );

                const wrap=document.createElement('div');
                wrap.className='flex flex-wrap items-center gap-2';

                const link=document.createElement('a');
                link.href=data.result.download_url;
                link.textContent='Unduh paket';
                link.className='gtk-package-action';

                const rebuild=document.createElement('button');
                rebuild.type='button';
                rebuild.textContent='Buat Ulang';
                rebuild.className='gtk-package-action gtk-package-action--build gtk-rebuild';
                rebuild.dataset.teacher=String(teacherId);
                rebuild.dataset.name=name;

                wrap.append(link,rebuild);

                const note=document.createElement('span');
                note.className='block text-xs text-gray-600 mt-1';
                note.textContent='Paket siap. Anda dapat membuat ulang tanpa menghapus pairing.';

                cell.replaceChildren(wrap,note);
                bindRebuild(rebuild);
                return true;
            } catch(error) {
                cell.innerHTML=oldHtml;

                cell.querySelectorAll('.gtk-rebuild').forEach(bindRebuild);
                const note=document.createElement('p');
                note.className='gtk-package-error text-sm mt-2 text-red-700';
                note.setAttribute('role','alert');
                note.textContent='Gagal: '+error.message;
                cell.append(note);
                throw error;
            }
        };

        const bindRebuild = (button) => {
            if (button.dataset.bound==='1') return;
            button.dataset.bound='1';

            button.addEventListener('click', async () => {
                if (busy) return;

                const name=button.dataset.name||'GTK';

                if (!confirm(`Buat ulang Paket GTK untuk ${name}? Paket lama tetap dipertahankan sampai paket baru berhasil.`)) {
                    return;
                }

                const cell=button.closest('.package-state');
                const status=document.getElementById('build-status');

                toggle(true);
                status.textContent=`Membuat ulang Paket GTK ${name}…`;

                try {
                    await makePackage(Number(button.dataset.teacher),cell,name);
                    status.textContent=`Paket GTK ${name} berhasil dibuat ulang.`;
                } catch(error) {
                    status.textContent=error.message;
                } finally {
                    toggle(false);
                }
            });
        };

        document.querySelectorAll('.gtk-rebuild').forEach(bindRebuild);

        build.addEventListener('click', async () => {
            if (busy) return;

            const selected=choices.filter(c=>c.checked);
            const status=document.getElementById('build-status');

            if (!selected.length) {
                status.textContent='Pilih setidaknya satu GTK.';
                return;
            }

            toggle(true);
            let complete=0;
            let failed=0;
            const failures=[];

            try {
                for (const choice of selected) {
                    const row=choice.closest('tr');
                    const cell=row.querySelector('.package-state');
                    const name=row.querySelector('td:nth-child(2)')?.textContent?.trim()||'GTK';

                    status.textContent=`Memproses ${complete+failed+1} dari ${selected.length} GTK…`;

                    try {
                        await makePackage(Number(choice.value),cell,name);
                        complete++;
                        choice.checked=false;
                    } catch(error) {
                        failed++;
                        failures.push(name+': '+error.message);
                    }
                }

                status.textContent=`Selesai: ${complete} paket berhasil, ${failed} gagal.`
                    +(failures.length ? ' Penyebab: '+failures.join(' | ') : '');
            } finally {
                toggle(false);
            }
        });
    });
    </script>
</x-app-layout>
