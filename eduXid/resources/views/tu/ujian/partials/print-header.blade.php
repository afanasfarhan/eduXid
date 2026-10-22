<div class="print-kop">
    @if(!empty($kopUrl))
        <img src="{{ $kopUrl }}" alt="Kop Sekolah" class="print-kop-image">
    @else
        <div class="print-kop-text">
            <div class="school-name">{{ $school->nama ?? $school->nama_sekolah ?? 'SEKOLAH' }}</div>
            @if(!empty($school->npsn))
                <div>NPSN: {{ $school->npsn }}</div>
            @endif
            <div>
                {{ $school->alamat ?? $school->alamat_jalan ?? '' }}
                @if(!empty($school->desa_kelurahan)) · {{ $school->desa_kelurahan }} @endif
                @if(!empty($school->kecamatan)) · {{ $school->kecamatan }} @endif
            </div>
        </div>
    @endif
</div>
