<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Pengaturan Ujian</h2>
            <p class="text-sm text-gray-500 mt-1">Kelola jadwal, ruang, peserta, denah tempat duduk, kartu ujian, daftar hadir, dan berita acara.</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if(session('success'))
                <div class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-green-800">{{ session('success') }}</div>
            @endif

            @if(session('error'))
                <div class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-red-800">{{ session('error') }}</div>
            @endif

            @if($errors->any())
                <div class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-red-800">
                    <div class="font-semibold mb-1">Periksa kembali data berikut:</div>
                    <ul class="list-disc pl-5 text-sm space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-4">Program Ujian</h3>

                    @if($programs->isEmpty())
                        <p class="text-sm text-gray-500 mb-4">Belum ada program ujian.</p>
                    @else
                        <form method="GET" action="{{ route('tu.ujian.index') }}" class="space-y-3">
                            <label class="block text-sm font-medium text-gray-700">Pilih program</label>
                            <select name="program" class="w-full rounded-lg border-gray-300" onchange="this.form.submit()">
                                @foreach($programs as $item)
                                    <option value="{{ $item->id }}" @selected($program && (int) $program->id === (int) $item->id)>
                                        {{ $item->name }}
                                    </option>
                                @endforeach
                            </select>
                        </form>
                    @endif
                </div>

                <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-4">Buat Program Ujian Baru</h3>
                    <form method="POST" action="{{ route('tu.ujian.program.store') }}" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        @csrf
                        <div class="lg:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nama program</label>
                            <input type="text" name="name" value="{{ old('name') }}" required class="w-full rounded-lg border-gray-300" placeholder="Contoh: PAS Ganjil 2026/2027">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select name="status" class="w-full rounded-lg border-gray-300">
                                <option value="draft">Draft</option>
                                <option value="active">Aktif</option>
                                <option value="closed">Selesai</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tahun pelajaran</label>
                            <input type="text" name="academic_year" value="{{ old('academic_year') }}" class="w-full rounded-lg border-gray-300" placeholder="2026/2027">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Semester</label>
                            <input type="text" name="semester" value="{{ old('semester') }}" class="w-full rounded-lg border-gray-300" placeholder="Ganjil / Genap">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Mulai</label>
                                <input type="date" name="start_date" value="{{ old('start_date') }}" class="w-full rounded-lg border-gray-300">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Selesai</label>
                                <input type="date" name="end_date" value="{{ old('end_date') }}" class="w-full rounded-lg border-gray-300">
                            </div>
                        </div>
                        <div class="md:col-span-2 lg:col-span-3 flex items-center justify-between gap-4">
                            <p class="text-xs text-gray-500">
                                Ruang dibuat otomatis dari rombel reguler semester aktif. Kapasitas setiap ruang mengikuti jumlah anggota rombel.
                            </p>
                            <button class="px-4 py-2 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700">Buat Program</button>
                        </div>
                    </form>
                </div>
            </div>

            @if($program)
                @php
                    $totalCapacity = $rooms->sum('capacity_live');
                    $totalAssigned = $participants->count();
                @endphp

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                        <div class="text-xs uppercase text-gray-500">Jadwal</div>
                        <div class="text-2xl font-bold text-gray-900 mt-1">{{ $schedules->count() }}</div>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                        <div class="text-xs uppercase text-gray-500">Ruang</div>
                        <div class="text-2xl font-bold text-gray-900 mt-1">{{ $rooms->count() }}</div>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                        <div class="text-xs uppercase text-gray-500">Kapasitas</div>
                        <div class="text-2xl font-bold text-gray-900 mt-1">{{ $totalCapacity }}</div>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                        <div class="text-xs uppercase text-gray-500">Peserta ditempatkan</div>
                        <div class="text-2xl font-bold text-gray-900 mt-1">{{ $totalAssigned }}</div>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-4">
                        <div>
                            <h3 class="font-bold text-gray-800">Identitas Program</h3>
                            <p class="text-sm text-gray-500">Semester data: {{ $program->semester_id ?: '-' }}</p>
                        </div>
                        <form method="POST" action="{{ route('tu.ujian.program.destroy', $program->id) }}" onsubmit="return confirm('Hapus program ujian beserta jadwal, ruang, dan penempatan pesertanya?')">
                            @csrf
                            @method('DELETE')
                            <button class="px-3 py-2 rounded-lg border border-red-300 text-red-700 hover:bg-red-50 text-sm font-semibold">Hapus Program</button>
                        </form>
                    </div>

                    <form method="POST" action="{{ route('tu.ujian.program.update', $program->id) }}" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        @csrf
                        @method('PATCH')
                        <div class="lg:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nama program</label>
                            <input type="text" name="name" value="{{ $program->name }}" required class="w-full rounded-lg border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select name="status" class="w-full rounded-lg border-gray-300">
                                @foreach(['draft' => 'Draft', 'active' => 'Aktif', 'closed' => 'Selesai'] as $value => $label)
                                    <option value="{{ $value }}" @selected($program->status === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tahun pelajaran</label>
                            <input type="text" name="academic_year" value="{{ $program->academic_year }}" class="w-full rounded-lg border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Semester</label>
                            <input type="text" name="semester" value="{{ $program->semester }}" class="w-full rounded-lg border-gray-300">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Mulai</label>
                                <input type="date" name="start_date" value="{{ $program->start_date }}" class="w-full rounded-lg border-gray-300">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Selesai</label>
                                <input type="date" name="end_date" value="{{ $program->end_date }}" class="w-full rounded-lg border-gray-300">
                            </div>
                        </div>
                        <div class="md:col-span-2 lg:col-span-3 text-right">
                            <button class="px-4 py-2 rounded-lg bg-gray-800 text-white font-semibold hover:bg-gray-900">Simpan Perubahan</button>
                        </div>
                    </form>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="p-5 flex flex-col md:flex-row md:items-center md:justify-between gap-3 border-b">
                        <div>
                            <h3 class="font-bold text-gray-800">Ruang Ujian</h3>
                            <p class="text-sm text-gray-500">Nama ruang mengikuti rombel reguler. Kapasitas kursi dihitung dari jumlah anggota rombel saat ini.</p>
                        </div>
                        <form method="POST" action="{{ route('tu.ujian.rooms.sync', $program->id) }}">
                            @csrf
                            <button class="px-4 py-2 rounded-lg bg-sky-600 text-white font-semibold hover:bg-sky-700">Sinkronkan Rombel & Kapasitas</button>
                        </form>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="px-4 py-3 text-left">Ruang</th>
                                    <th class="px-4 py-3 text-center">Kapasitas</th>
                                    <th class="px-4 py-3 text-center">Terisi</th>
                                    <th class="px-4 py-3 text-center">Sisa Kursi</th>
                                    <th class="px-4 py-3 text-center">Kolom Denah</th>
                                    <th class="px-4 py-3 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @forelse($rooms as $room)
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-gray-900">{{ $room->room_name }}</td>
                                        <td class="px-4 py-3 text-center">{{ $room->capacity_live }}</td>
                                        <td class="px-4 py-3 text-center">{{ $room->assigned_count }}</td>
                                        <td class="px-4 py-3 text-center">{{ $room->remaining }}</td>
                                        <td class="px-4 py-3 text-center">
                                            <form method="POST" action="{{ route('tu.ujian.rooms.layout', [$program->id, $room->id]) }}" class="inline-flex items-center gap-2">
                                                @csrf
                                                <select name="seat_columns" class="rounded-lg border-gray-300 text-sm py-1">
                                                    @for($column = 4; $column <= 10; $column++)
                                                        <option value="{{ $column }}" @selected((int) $room->seat_columns === $column)>{{ $column }}</option>
                                                    @endfor
                                                </select>
                                                <button class="text-sky-700 font-semibold hover:underline">Simpan</button>
                                            </form>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            @if($room->over_capacity)
                                                <span class="inline-flex px-2 py-1 rounded-full bg-red-100 text-red-700 text-xs font-semibold">Melebihi kapasitas</span>
                                            @elseif($room->remaining === 0)
                                                <span class="inline-flex px-2 py-1 rounded-full bg-amber-100 text-amber-700 text-xs font-semibold">Penuh</span>
                                            @else
                                                <span class="inline-flex px-2 py-1 rounded-full bg-green-100 text-green-700 text-xs font-semibold">Tersedia</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">Belum ada ruang. Sinkronkan rombel terlebih dahulu.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                        <h3 class="font-bold text-gray-800 mb-4">Tambah Jadwal</h3>
                        <form method="POST" action="{{ route('tu.ujian.schedule.store', $program->id) }}" class="space-y-3">
                            @csrf
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Mata ujian / kegiatan</label>
                                <input type="text" name="title" required class="w-full rounded-lg border-gray-300" placeholder="Contoh: Matematika">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal</label>
                                <input type="date" name="exam_date" required class="w-full rounded-lg border-gray-300">
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Mulai</label>
                                    <input type="time" name="start_time" required class="w-full rounded-lg border-gray-300">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Selesai</label>
                                    <input type="time" name="end_time" class="w-full rounded-lg border-gray-300">
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Mode</label>
                                <select name="mode" class="w-full rounded-lg border-gray-300">
                                    <option value="paper">Kertas</option>
                                    <option value="cbt">CBT</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Catatan</label>
                                <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300"></textarea>
                            </div>
                            <button class="w-full px-4 py-2 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700">Tambah Jadwal</button>
                        </form>
                    </div>

                    <div class="xl:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                        <div class="p-5 border-b">
                            <h3 class="font-bold text-gray-800">Daftar Jadwal</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="px-4 py-3 text-left">Tanggal</th>
                                        <th class="px-4 py-3 text-left">Mata Ujian</th>
                                        <th class="px-4 py-3 text-left">Waktu</th>
                                        <th class="px-4 py-3 text-left">Mode</th>
                                        <th class="px-4 py-3 text-right">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    @forelse($schedules as $schedule)
                                        <tr>
                                            <td class="px-4 py-3">{{ \Illuminate\Support\Carbon::parse($schedule->exam_date)->format('d-m-Y') }}</td>
                                            <td class="px-4 py-3 font-medium text-gray-900">{{ $schedule->title }}</td>
                                            <td class="px-4 py-3">{{ substr($schedule->start_time, 0, 5) }}{{ $schedule->end_time ? ' - ' . substr($schedule->end_time, 0, 5) : '' }}</td>
                                            <td class="px-4 py-3">{{ strtoupper($schedule->mode) }}</td>
                                            <td class="px-4 py-3 text-right align-top">
                                                <details class="inline-block text-left mr-3">
                                                    <summary class="cursor-pointer text-indigo-600 hover:underline">Edit</summary>
                                                    <div class="absolute right-8 mt-2 z-20 w-80 rounded-xl border border-gray-200 bg-white p-4 shadow-xl">
                                                        <form method="POST" action="{{ route('tu.ujian.schedule.update', $schedule->id) }}" class="space-y-2">
                                                            @csrf
                                                            @method('PATCH')
                                                            <input type="text" name="title" value="{{ $schedule->title }}" required class="w-full rounded-lg border-gray-300 text-sm">
                                                            <input type="date" name="exam_date" value="{{ $schedule->exam_date }}" required class="w-full rounded-lg border-gray-300 text-sm">
                                                            <div class="grid grid-cols-2 gap-2">
                                                                <input type="time" name="start_time" value="{{ substr($schedule->start_time, 0, 5) }}" required class="w-full rounded-lg border-gray-300 text-sm">
                                                                <input type="time" name="end_time" value="{{ $schedule->end_time ? substr($schedule->end_time, 0, 5) : '' }}" class="w-full rounded-lg border-gray-300 text-sm">
                                                            </div>
                                                            <select name="mode" class="w-full rounded-lg border-gray-300 text-sm">
                                                                <option value="paper" @selected($schedule->mode === 'paper')>Kertas</option>
                                                                <option value="cbt" @selected($schedule->mode === 'cbt')>CBT</option>
                                                            </select>
                                                            <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 text-sm">{{ $schedule->notes }}</textarea>
                                                            <button class="w-full px-3 py-2 rounded-lg bg-indigo-600 text-white font-semibold">Simpan Jadwal</button>
                                                        </form>
                                                    </div>
                                                </details>
                                                <form method="POST" action="{{ route('tu.ujian.schedule.destroy', $schedule->id) }}" class="inline" onsubmit="return confirm('Hapus jadwal ini?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="text-red-600 hover:underline">Hapus</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">Belum ada jadwal ujian.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="mb-5">
                        <h3 class="font-bold text-gray-800">Pengawas Ujian</h3>
                        <p class="text-sm text-gray-500 mt-1">Atur Pengawas 1 dan Pengawas 2 untuk setiap kombinasi jadwal dan ruang. Data ini otomatis digunakan pada bagian tanda tangan Daftar Hadir.</p>
                    </div>

                    @if($schedules->isEmpty() || $rooms->isEmpty())
                        <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-amber-800 text-sm">Tambahkan jadwal dan pastikan ruang ujian tersedia sebelum mengatur pengawas.</div>
                    @else
                        <form method="POST" action="{{ route('tu.ujian.invigilators.save', $program->id) }}" class="space-y-5">
                            @csrf
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Jadwal ujian</label>
                                    <select name="schedule_id" required class="w-full rounded-lg border-gray-300">
                                        <option value="">Pilih jadwal</option>
                                        @foreach($schedules as $schedule)
                                            <option value="{{ $schedule->id }}">{{ \Illuminate\Support\Carbon::parse($schedule->exam_date)->format('d-m-Y') }} — {{ $schedule->title }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Ruang ujian</label>
                                    <select name="room_id" required class="w-full rounded-lg border-gray-300">
                                        <option value="">Pilih ruang</option>
                                        @foreach($rooms as $room)
                                            <option value="{{ $room->id }}">{{ $room->room_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                                @foreach([1, 2] as $number)
                                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                                        <h4 class="font-semibold text-gray-800 mb-3">Pengawas {{ $number }}</h4>
                                        <div class="space-y-3">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Pilih dari GTK</label>
                                                <select name="supervisor_{{ $number }}_teacher_id" class="w-full rounded-lg border-gray-300">
                                                    <option value="">-- Isi manual / pengawas luar --</option>
                                                    @foreach($teacherOptions as $teacher)
                                                        <option value="{{ $teacher->id }}">
                                                            {{ $teacher->display_name }}{{ $teacher->display_nip ? ' — ' . $teacher->display_nip : '' }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Nama manual</label>
                                                <input type="text" name="supervisor_{{ $number }}_name" class="w-full rounded-lg border-gray-300" placeholder="Isi jika tidak memilih GTK">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">NIP / identitas</label>
                                                <input type="text" name="supervisor_{{ $number }}_nip" class="w-full rounded-lg border-gray-300" placeholder="NIP pengawas">
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="flex justify-end">
                                <button class="px-4 py-2 rounded-lg bg-cyan-700 text-white font-semibold hover:bg-cyan-800">Simpan Pengawas</button>
                            </div>
                        </form>

                        @if($invigilatorAssignments->isNotEmpty())
                            <div class="mt-6 border-t pt-5">
                                <h4 class="font-semibold text-gray-800 mb-3">Pengawas yang Sudah Ditentukan</h4>
                                <div class="overflow-x-auto border rounded-xl">
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-gray-50 text-gray-600">
                                            <tr>
                                                <th class="px-4 py-3 text-left">Tanggal / Ujian</th>
                                                <th class="px-4 py-3 text-left">Ruang</th>
                                                <th class="px-4 py-3 text-left">Pengawas 1</th>
                                                <th class="px-4 py-3 text-left">Pengawas 2</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y">
                                            @foreach($invigilatorAssignments->groupBy(fn ($item) => $item->exam_administration_schedule_id . ':' . $item->exam_administration_room_id) as $assignmentGroup)
                                                @php
                                                    $first = $assignmentGroup->first();
                                                    $p1 = $assignmentGroup->firstWhere('supervisor_no', 1);
                                                    $p2 = $assignmentGroup->firstWhere('supervisor_no', 2);
                                                @endphp
                                                <tr>
                                                    <td class="px-4 py-3">{{ \Illuminate\Support\Carbon::parse($first->exam_date)->format('d-m-Y') }} — {{ $first->schedule_title }}</td>
                                                    <td class="px-4 py-3">{{ $first->room_name }}</td>
                                                    <td class="px-4 py-3">
                                                        <div class="font-medium text-gray-900">{{ $p1->name ?? '-' }}</div>
                                                        <div class="text-xs text-gray-500">NIP. {{ $p1->nip ?? '-' }}</div>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <div class="font-medium text-gray-900">{{ $p2->name ?? '-' }}</div>
                                                        <div class="text-xs text-gray-500">NIP. {{ $p2->nip ?? '-' }}</div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    @endif
                </div>

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="mb-5">
                        <h3 class="font-bold text-gray-800">Penempatan Peserta Ujian</h3>
                        <p class="text-sm text-gray-500 mt-1">Pilih peserta secara manual, lalu tentukan ruang tujuan. Nomor peserta selalu menggunakan NISN.</p>
                    </div>

                    @if($rooms->isEmpty())
                        <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-amber-800 text-sm">Belum ada ruang ujian.</div>
                    @else
                        <form method="POST" action="{{ route('tu.ujian.participants.assign', $program->id) }}" id="participant-form">
                            @csrf
                            <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-4">
                                <div class="lg:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Cari peserta</label>
                                    <input type="text" id="student-filter" class="w-full rounded-lg border-gray-300" placeholder="Cari nama, NISN, atau kelas...">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Ruang tujuan</label>
                                    <select name="room_id" required class="w-full rounded-lg border-gray-300">
                                        <option value="">Pilih ruang</option>
                                        @foreach($rooms as $room)
                                            <option value="{{ $room->id }}" @disabled($room->remaining <= 0)>
                                                {{ $room->room_name }} — {{ $room->assigned_count }}/{{ $room->capacity_live }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="flex items-end">
                                    <button class="w-full px-4 py-2 rounded-lg bg-emerald-600 text-white font-semibold hover:bg-emerald-700">Tempatkan Peserta</button>
                                </div>
                            </div>

                            <p class="text-sm text-slate-600 mb-3">Daftar peserta mencakup seluruh murid yang terdaftar pada sekolah ini, termasuk yang belum masuk rombel semester program. Murid tanpa NISN tetap dapat ditempatkan menggunakan identitas internal sekolah.</p>
                            <div class="border rounded-xl overflow-hidden max-h-[430px] overflow-y-auto">
                                <table class="min-w-full text-sm" id="student-table">
                                    <thead class="bg-gray-50 sticky top-0 z-10 text-gray-600">
                                        <tr>
                                            <th class="px-3 py-3 text-center w-12"><input type="checkbox" id="check-all"></th>
                                            <th class="px-3 py-3 text-left">Nama Peserta</th>
                                            <th class="px-3 py-3 text-left">NISN / No. Peserta</th>
                                            <th class="px-3 py-3 text-left">Kelas Asal</th>
                                            <th class="px-3 py-3 text-left">Ruang Saat Ini</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y">
                                        @foreach($students as $student)
                                            @php $hasNisn = trim((string) $student->nisn) !== ''; @endphp
                                            <tr class="student-row" data-search="{{ strtolower(($student->nama ?? '') . ' ' . ($student->nisn ?? '') . ' ' . ($student->class_name ?? '')) }}">
                                                <td class="px-3 py-3 text-center">
                                                    <input type="checkbox" name="student_ids[]" value="{{ $student->id }}" class="student-check">
                                                </td>
                                                <td class="px-3 py-3 font-medium text-gray-900">{{ $student->nama }}</td>
                                                <td class="px-3 py-3">
                                                    @if($hasNisn)
                                                        <span class="font-mono">{{ $student->nisn }}</span>
                                                    @else
                                                        <span class="text-amber-700 font-semibold">NISN belum ada (ID lokal: {{ $student->id }})</span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-3">{{ $student->class_name ?: '-' }}</td>
                                                <td class="px-3 py-3">
                                                    @if($student->assigned_room_name)
                                                        <span class="inline-flex px-2 py-1 rounded-full bg-indigo-50 text-indigo-700 text-xs font-semibold">{{ $student->assigned_room_name }}</span>
                                                    @else
                                                        <span class="text-gray-400">Belum ditempatkan</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    @endif
                </div>

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="p-5 border-b flex items-center justify-between gap-3">
                        <div>
                            <h3 class="font-bold text-gray-800">Peserta yang Sudah Ditempatkan</h3>
                            <p class="text-sm text-gray-500">Nomor kursi awal dibentuk otomatis dan setelah itu dapat diatur manual. Memilih kursi yang sudah terisi akan menukar posisi peserta.</p>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="px-4 py-3 text-center">Kursi</th>
                                    <th class="px-4 py-3 text-left">Nama Peserta</th>
                                    <th class="px-4 py-3 text-left">NISN</th>
                                    <th class="px-4 py-3 text-left">Kelas</th>
                                    <th class="px-4 py-3 text-left">Ruang</th>
                                    <th class="px-4 py-3 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @forelse($participants as $participant)
                                    <tr>
                                        <td class="px-4 py-3 text-center">
                                            @php
                                                $participantRoom = $rooms->firstWhere('id', $participant->room_id);
                                                $participantCapacity = (int) ($participantRoom->capacity_live ?? 0);
                                            @endphp
                                            <form method="POST" action="{{ route('tu.ujian.participants.seat', [$program->id, $participant->participant_id]) }}" class="inline-flex items-center gap-2">
                                                @csrf
                                                @method('PATCH')
                                                <select name="seat_number" class="rounded-lg border-gray-300 text-sm py-1" onchange="this.form.submit()">
                                                    @for($seat = 1; $seat <= $participantCapacity; $seat++)
                                                        <option value="{{ $seat }}" @selected((int) $participant->seat_number === $seat)>{{ $seat }}</option>
                                                    @endfor
                                                </select>
                                            </form>
                                        </td>
                                        <td class="px-4 py-3 font-medium text-gray-900">{{ $participant->nama }}</td>
                                        <td class="px-4 py-3 font-mono">{{ $participant->nisn }}</td>
                                        <td class="px-4 py-3">{{ $participant->class_name ?: '-' }}</td>
                                        <td class="px-4 py-3">{{ $participant->room_name }}</td>
                                        <td class="px-4 py-3 text-right">
                                            <form method="POST" action="{{ route('tu.ujian.participants.remove', [$program->id, $participant->participant_id]) }}" onsubmit="return confirm('Keluarkan peserta ini dari ruang ujian?')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="text-red-600 hover:underline">Keluarkan</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">Belum ada peserta yang ditempatkan.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5">
                        <div>
                            <h3 class="font-bold text-gray-800">Denah Tempat Duduk</h3>
                            <p class="text-sm text-gray-500 mt-1">Papan tulis berada di bagian depan. Nomor kursi mengikuti posisi peserta dan dapat dipindahkan dari tabel peserta di atas.</p>
                        </div>
                        @if($rooms->isNotEmpty())
                            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                                <select id="seating-room-filter" name="room_id" form="form-print-selected-seating" class="rounded-lg border-gray-300 text-sm" required>
                                    @foreach($rooms as $room)
                                        <option value="{{ $room->id }}">{{ $room->room_name }}</option>
                                    @endforeach
                                </select>

                                <form id="form-print-selected-seating" method="GET"
                                      action="{{ route('tu.ujian.print.seating', $program->id) }}"
                                      target="_blank" class="inline-flex items-center gap-2">
                                    <input type="hidden" name="autoprint" value="1">
                                    <input type="hidden" name="orientation" value="landscape">
                                    <button id="btn-print-selected-seating" type="submit"
                                            class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg bg-green-600 text-white text-sm font-semibold hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 whitespace-nowrap">
                                        <span aria-hidden="true">🖨</span><span>Cetak Denah</span>
                                    </button>
                                </form>
                            </div>
                        @endif
                    </div>

                    @forelse($rooms as $room)
                        @php
                            $roomParticipants = $participants->where('room_id', $room->id)->keyBy(fn ($item) => (int) $item->seat_number);
                            $columns = max(4, min(10, (int) $room->seat_columns));
                        @endphp
                        <div class="seating-room-panel" id="room-{{ $room->id }}" @if(!$loop->first) style="display:none" @endif>
                            <div class="max-w-5xl mx-auto">
                                <div class="border-2 border-gray-700 rounded-lg px-4 py-2 text-center font-bold text-gray-800 bg-gray-100 mb-3">
                                    PAPAN TULIS / DEPAN RUANG
                                </div>
                                <div class="flex justify-center mb-5">
                                    <div class="border border-gray-400 rounded-lg px-5 py-2 text-xs font-bold text-gray-600 bg-white">MEJA PENGAWAS</div>
                                </div>
                                {{-- Pada ruang berkolom banyak, gulir horizontal menjaga foto/teks tidak terpotong. --}}
                                <div class="overflow-x-auto pb-2">
                                    <div class="grid gap-3" style="grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr)); min-width: {{ $columns * 116 }}px;">
                                        @for($seat = 1; $seat <= (int) $room->capacity_live; $seat++)
                                            @php $occupant = $roomParticipants->get($seat); @endphp
                                            <div class="min-h-40 rounded-xl border-2 p-2 text-center flex flex-col items-center justify-center
                                                {{ $occupant ? 'border-indigo-300 bg-indigo-50' : 'border-dashed border-gray-300 bg-gray-50' }}">
                                                <div class="text-xs font-bold text-gray-500 mb-1">KURSI {{ $seat }}</div>
                                                @if($occupant)
                                                    <div style="position:relative;width:44px;height:54px;flex-shrink:0;margin:0 auto 5px;border:1px solid #cbd5e1;border-radius:5px;overflow:hidden;background:#e2e8f0;">
                                                        <span aria-hidden="true" style="display:flex;align-items:center;justify-content:center;height:100%;font-size:9px;color:#64748b;">Tanpa foto</span>
                                                        @if(!empty($occupant->foto_url))
                                                            <img src="{{ $occupant->foto_url }}" alt="Foto {{ $occupant->nama }}" loading="lazy" decoding="async"
                                                                 style="position:absolute;inset:0;width:100%;height:100%;object-fit:contain;object-position:center;background:#fff;"
                                                                 onerror="this.style.display='none'">
                                                        @endif
                                                    </div>
                                                    <div class="text-xs font-bold text-gray-900 leading-tight" style="overflow-wrap:anywhere;">{{ $occupant->nama }}</div>
                                                    <div class="text-xs font-mono text-gray-600 mt-1">{{ $occupant->nisn }}</div>
                                                    <div class="text-xs text-gray-500">{{ $occupant->class_name ?: '-' }}</div>
                                                @else
                                                    <div class="text-xs text-gray-400">Kosong</div>
                                                @endif
                                            </div>
                                        @endfor
                                    </div>
                                </div>
                                <div class="mt-5 flex justify-between items-center text-sm text-gray-500">
                                    <span>Kapasitas {{ $room->capacity_live }} · Terisi {{ $room->assigned_count }} · {{ $columns }} kolom</span>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-sm text-gray-500">Belum ada ruang ujian.</div>
                    @endforelse
                </div>

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-1">Cetak Administrasi Ujian</h3>
                    <p class="text-sm text-gray-500 mb-5">Kop dan scan tanda tangan memakai pengaturan bersama Kartu Pelajar. Semua dokumen mengambil versi terbaru secara otomatis.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6 p-4 rounded-xl bg-slate-50 border">
                        <form action="{{ route('tu.ujian.stationery.upload') }}" method="POST" enctype="multipart/form-data" class="space-y-2">
                            @csrf
                            <input type="hidden" name="kind" value="kop">
                            <label class="block text-sm font-semibold">Unggah Kop Sekolah (JPG/PNG/WebP, maks. 2 MB)</label>
                            <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required class="block w-full text-sm">
                            @if(!empty($examPrintAssets['kopUrl']))<img src="{{ $examPrintAssets['kopUrl'] }}" alt="Pratinjau kop sekolah" class="max-h-20 w-full object-contain" loading="lazy">@endif
                            <button class="px-4 py-2 rounded-lg bg-sky-700 text-white font-bold">Simpan Kop Sekolah</button>
                        </form>
                        <form action="{{ route('tu.ujian.stationery.upload') }}" method="POST" enctype="multipart/form-data" class="space-y-2">
                            @csrf
                            <input type="hidden" name="kind" value="tanda_tangan">
                            <label class="block text-sm font-semibold">Unggah Scan Tanda Tangan Kepala Sekolah (maks. 2 MB)</label>
                            <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required class="block w-full text-sm">
                            @if(!empty($examPrintAssets['signatureUrl']))<img src="{{ $examPrintAssets['signatureUrl'] }}" alt="Pratinjau tanda tangan" class="max-h-20 w-full object-contain" loading="lazy">@endif
                            <button class="px-4 py-2 rounded-lg bg-sky-700 text-white font-bold">Simpan Tanda Tangan</button>
                        </form>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
                        <div class="border rounded-xl p-4">
                            <h4 class="font-semibold text-gray-800 mb-3">Denah Tempat Duduk</h4>
                            <form method="GET" action="{{ route('tu.ujian.print.seating', $program->id) }}" target="_blank" class="space-y-3">
                                <input type="hidden" name="autoprint" value="1">
                                <select name="room_id" required class="w-full rounded-lg border-gray-300">
                                    <option value="">Pilih ruang</option>
                                    @foreach($rooms as $room)
                                        <option value="{{ $room->id }}">{{ $room->room_name }}</option>
                                    @endforeach
                                </select>
                                <label class="block text-sm font-semibold">Orientasi A4</label>
                                <select name="orientation" class="w-full rounded-lg border-gray-300">
                                    <option value="landscape" selected>Landscape</option>
                                    <option value="portrait">Portrait</option>
                                </select>
                                <button class="w-full px-4 py-2 rounded-lg bg-green-600 text-white font-semibold hover:bg-green-700">Cetak Denah</button>
                            </form>
                        </div>

                        <div class="border rounded-xl p-4">
                            <h4 class="font-semibold text-gray-800 mb-3">Kartu Peserta</h4>
                            <form method="GET" action="{{ route('tu.ujian.print.cards', $program->id) }}" target="_blank" class="space-y-3">
                                <select name="room_id" class="w-full rounded-lg border-gray-300">
                                    <option value="">Semua ruang</option>
                                    @foreach($rooms as $room)
                                        <option value="{{ $room->id }}">{{ $room->room_name }}</option>
                                    @endforeach
                                </select>
                                <label class="block text-sm font-semibold">Orientasi A4 — 8 kartu/lembar</label>
                                <select name="orientation" class="w-full rounded-lg border-gray-300">
                                    <option value="portrait" selected>Portrait (2 kolom × 4 baris)</option>
                                    <option value="landscape">Landscape (4 kolom × 2 baris)</option>
                                </select>
                                <button class="w-full px-4 py-2 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700">Cetak 8 Kartu/Lembar</button>
                            </form>
                        </div>

                        <div class="border rounded-xl p-4">
                            <h4 class="font-semibold text-gray-800 mb-3">Daftar Hadir</h4>
                            <form method="GET" action="{{ route('tu.ujian.print.attendance', $program->id) }}" target="_blank" class="space-y-3">
                                <select name="schedule_id" required class="w-full rounded-lg border-gray-300">
                                    <option value="">Pilih jadwal</option>
                                    @foreach($schedules as $schedule)
                                        <option value="{{ $schedule->id }}">{{ $schedule->exam_date }} — {{ $schedule->title }}</option>
                                    @endforeach
                                </select>
                                <select name="room_id" required class="w-full rounded-lg border-gray-300">
                                    <option value="">Pilih ruang</option>
                                    @foreach($rooms as $room)
                                        <option value="{{ $room->id }}">{{ $room->room_name }}</option>
                                    @endforeach
                                </select>
                                <button class="w-full px-4 py-2 rounded-lg bg-emerald-600 text-white font-semibold hover:bg-emerald-700">Cetak Daftar Hadir</button>
                            </form>
                        </div>

                        <div class="border rounded-xl p-4">
                            <h4 class="font-semibold text-gray-800 mb-3">Berita Acara</h4>
                            <form method="GET" action="{{ route('tu.ujian.print.minutes', $program->id) }}" target="_blank" class="space-y-3">
                                <select name="schedule_id" required class="w-full rounded-lg border-gray-300">
                                    <option value="">Pilih jadwal</option>
                                    @foreach($schedules as $schedule)
                                        <option value="{{ $schedule->id }}">{{ $schedule->exam_date }} — {{ $schedule->title }}</option>
                                    @endforeach
                                </select>
                                <select name="room_id" required class="w-full rounded-lg border-gray-300">
                                    <option value="">Pilih ruang</option>
                                    @foreach($rooms as $room)
                                        <option value="{{ $room->id }}">{{ $room->room_name }}</option>
                                    @endforeach
                                </select>
                                <button class="w-full px-4 py-2 rounded-lg bg-amber-600 text-white font-semibold hover:bg-amber-700">Cetak Berita Acara</button>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const filter = document.getElementById('student-filter');
            const rows = Array.from(document.querySelectorAll('.student-row'));
            const checkAll = document.getElementById('check-all');
            const seatingRoomFilter = document.getElementById('seating-room-filter');
            const seatingPanels = Array.from(document.querySelectorAll('.seating-room-panel'));
            const printSelectedSeatingButton = document.getElementById('btn-print-selected-seating');

            function syncSelectedSeatingRoom() {
                if (!seatingRoomFilter) {
                    return;
                }

                seatingPanels.forEach(function (panel) {
                    panel.style.display = panel.id === 'room-' + seatingRoomFilter.value ? '' : 'none';
                });

                if (printSelectedSeatingButton) {
                    printSelectedSeatingButton.disabled = !seatingRoomFilter.value;
                }
            }

            if (seatingRoomFilter) {
                seatingRoomFilter.addEventListener('change', syncSelectedSeatingRoom);
                syncSelectedSeatingRoom();
            }

            if (filter) {
                filter.addEventListener('input', function () {
                    const keyword = String(this.value || '').toLowerCase().trim();
                    rows.forEach(function (row) {
                        row.style.display = row.dataset.search.includes(keyword) ? '' : 'none';
                    });
                });
            }

            if (checkAll) {
                checkAll.addEventListener('change', function () {
                    rows.forEach(function (row) {
                        if (row.style.display === 'none') return;
                        const checkbox = row.querySelector('.student-check:not(:disabled)');
                        if (checkbox) checkbox.checked = checkAll.checked;
                    });
                });
            }
        });
    </script>
</x-app-layout>
