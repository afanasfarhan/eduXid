/* eduXid V21R87 — satu modal asli Lihat Profil Murid untuk halaman HTML sekolah.
   Tidak menebak identitas berdasarkan nama; profil selalu dimuat dari Laravel lokal. */
(function () {
    'use strict';
    if (window.__eduxidStudentProfile87) return;
    window.__eduxidStudentProfile87 = true;
    const reNisn = /(?:^|\D)(\d{10})(?!\d)/g;
    const modalId = 'modal-siswa';
    let lastFocus = null;
    let seq = 0;
    const skipTag = 'button,input,select,option,textarea,label,a[href],[contenteditable],.drag-handle';

    function modal() { return document.getElementById(modalId); }
    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value === undefined || value === null || value === '' ? '-' : String(value);
    }
    function cleanNisn(text) {
        reNisn.lastIndex = 0;
        const match = reNisn.exec(String(text || ''));
        return match ? match[1] : '';
    }
    function candidatesFromRow(row) {
        const explicit = String(row.dataset.eduxidStudentId || row.dataset.studentId || '').trim();
        const hidden = row.querySelector('input[type="hidden"][name$="[student_id]"], input[type="hidden"][name="student_id"]');
        const hiddenValue = hidden ? hidden.value : '';
        const explicitlyId = /^\d+$/.test(explicit) ? explicit :
            (/^\d+$/.test(hiddenValue) ? hiddenValue : '');
        const viaData = cleanNisn(row.dataset.eduxidStudentNisn || row.dataset.nisn || '');
        const contained = viaData || cleanNisn(row.textContent || '');
        return { student_id: explicitlyId, nisn: contained };
    }
    function eligible(el) {
        if (!el || !el.isConnected || el.closest('#modal-siswa,#eduxid-profil-modal,#canvas-denah')) return false;
        if (el.closest(skipTag)) return false;
        return true;
    }
    function activate(el, who, assign) {
        if (!eligible(el) || el.dataset.eduxidProfileReady === '1') return;
        if (!who.student_id && !who.nisn) return;
        el.dataset.eduxidProfileReady = '1';
        if (who.student_id) el.dataset.eduxidProfileStudentId = who.student_id;
        if (who.nisn) el.dataset.eduxidProfileNisn = who.nisn;
        if (assign) el.dataset.eduxidProfileAssignmentId = assign;
        el.setAttribute('role', 'button');
        if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex','0');
        el.setAttribute('title','Lihat Profil Murid');
        el.style.cursor = 'pointer';
        el.classList.add('eduxid-global-profile-trigger');
    }
    function findAssignment(row) {
        const own = row.dataset.assignmentId || row.dataset.eduxidAssignmentId;
        if (own && /^\d+$/.test(own)) return own;
        const form = row.closest('form');
        const url = form ? form.getAttribute('action') || '' : '';
        const match = url.match(/\/mengajar\/(\d+)\/nilai/);
        const locationMatch = window.location.pathname.match(/\/mengajar\/(\d+)\/nilai/);
        return match ? match[1] : (locationMatch ? locationMatch[1] : '');
    }
    function markRow(row) {
        if (row.dataset.eduxidProfileScanned === '1') return;
        if (row.closest('#modal-siswa,#canvas-denah') || row.closest('[data-eduxid-profile-ignore]')) return;
        const who = candidatesFromRow(row);
        if (!who.student_id && !who.nisn) return;
        // Jangan mengikat baris berisi NISN lebih dari satu murid.
        if (!who.student_id) {
            const all = Array.from(String(row.textContent).matchAll(/(?:^|\D)(\d{10})(?!\d)/g), m => m[1]);
            if (new Set(all).size > 1) return;
        }
        row.dataset.eduxidProfileScanned = '1';
        const assign = findAssignment(row);
        if (row.matches('tr')) {
            const cells = Array.from(row.cells || []);
            const tbl = row.closest('table');
            const head = tbl ? Array.from(tbl.querySelectorAll('thead tr:last-child th'))
                .map(x => x.textContent.toLowerCase().trim()) : [];
            let nameIndex = head.findIndex(h => /(?:^|\s)(nama|murid|siswa|peserta)(?:\s|$)/.test(h) && !/ayah|ibu|sekolah|kelas|guru/.test(h));
            let nisnIndex = head.findIndex(h => /nisn/.test(h));
            if (nameIndex < 0 && who.student_id && row.dataset.eduxidStudentName) {
                nameIndex = cells.findIndex(c => c.textContent.trim().includes(row.dataset.eduxidStudentName));
            }
            if (nisnIndex < 0 && who.nisn) nisnIndex = cells.findIndex(c => c.textContent.includes(who.nisn));
            // Header dapat punya colspan atau column tidak lengkap; cari pada cell yang tepat.
            const named = nameIndex >= 0 ? cells[nameIndex] : null;
            const numbered = nisnIndex >= 0 ? cells[nisnIndex] : null;
            [named, numbered].filter(Boolean).forEach(cell => {
                const inner = Array.from(cell.children).find(el =>
                    /^(SPAN|DIV|P|STRONG|B)$/i.test(el.tagName) && !el.querySelector(skipTag)
                );
                activate(inner || cell, who, assign);
            });
            // V21R86 telah memiliki tombol; server tetap menentukan isi modal asli.
            row.querySelectorAll('[data-eduxid-open-profile],.eduxid-profile-trigger,[data-eduxid-profile-trigger]').forEach(el => {
                if (!el.matches(skipTag)) activate(el, who, assign);
                else {
                    el.dataset.eduxidProfileReady = '1';
                    el.dataset.eduxidProfileStudentId = who.student_id;
                    el.dataset.eduxidProfileNisn = who.nisn;
                    if (assign) el.dataset.eduxidProfileAssignmentId = assign;
                    el.classList.add('eduxid-global-profile-trigger');
                }
            });
        } else {
            // Kartu dengan identitas yang sudah diketahui secara eksplisit.
            const children = Array.from(row.querySelectorAll('span,div,p,strong,b'))
                .filter(el => el.children.length === 0 && !el.closest(skipTag));
            for (const child of children) {
                const s = child.textContent.trim();
                if (s === who.nisn || s === 'NISN: ' + who.nisn ||
                    (row.dataset.eduxidStudentName && s === row.dataset.eduxidStudentName)) {
                    activate(child,who,assign);
                }
            }
        }
    }
    function scan() {
        document.querySelectorAll('table tbody tr, tr[data-eduxid-student-id],li[data-eduxid-student-id],.student-row,.student-card,[data-eduxid-student-id]')
            .forEach(markRow);
    }
    function close() {
        const m=modal(); if (!m) return;
        m.classList.remove('flex'); m.classList.add('hidden'); m.setAttribute('aria-hidden','true');
        if (lastFocus && lastFocus.isConnected && lastFocus.focus) lastFocus.focus();
    }
    function photo(url) {
        const box=document.getElementById('modal-foto-container');
        if (!box) return;
        box.replaceChildren();
        const fallback=()=>{ box.replaceChildren();const el=document.createElement('div');
            el.className='rounded-md shadow-sm bg-gray-100 flex items-center justify-center text-xs text-gray-500';
            el.style.cssText='width:96px;height:110px';el.textContent='Tanpa foto';box.appendChild(el);};
        if (!url) {fallback(); return;}
        const img=document.createElement('img');img.src=url;img.alt='Foto murid';
        img.style.cssText='width:96px;height:110px';img.className='rounded-md shadow-md object-cover';
        img.addEventListener('error',fallback,{once:true});box.appendChild(img);
    }
    function fill(d) {
        setText('modal-nama',d.nama);setText('modal-nisn','('+(d.nisn||'-')+')');
        setText('modal-jk',d.jenis_kelamin === 'L' ? 'Laki-laki' : (d.jenis_kelamin === 'P' ? 'Perempuan':'-'));
        // V21R91: gunakan label mapel yang diberikan server dan fallback netral.
        const namaMapel = String(d.mapel || '').trim();
        const judulMapel = !namaMapel || /^rapor\s+semester$/i.test(namaMapel)
            ? 'NILAI MAPEL'
            : /^nilai\s+/i.test(namaMapel)
                ? namaMapel.toLocaleUpperCase('id-ID')
                : 'NILAI ' + namaMapel.toLocaleUpperCase('id-ID');
        setText('eduxid-global-profil-mapel', judulMapel);
        setText('modal-sakit',d.absensi?.sakit ?? 0);
        setText('modal-izin',d.absensi?.izin ?? 0);
        setText('modal-alpa',d.absensi?.alpa ?? 0);
        setText('modal-diagnostik',d.nilai?.diagnostik);
        setText('modal-formatif',d.nilai?.formatif);
        setText('modal-sumatif',d.nilai?.sumatif);
        for(let i=1;i<=8;i++){
            const box=document.getElementById('modal-d'+i);if(!box) continue;
            box.replaceChildren();
            const v=Math.max(1,Math.min(5,parseInt(d.dimensi?.['d'+i],10)||3));
            for(let j=1;j<=5;j++){const span=document.createElement('span');
                span.className='text-base '+(j<=v?'text-yellow-400':'text-gray-300');span.textContent='★';box.appendChild(span);}
        }
        photo(d.foto_url);
    }
    async function open(trigger) {
        const id=trigger.dataset.eduxidProfileStudentId;
        const nisn=trigger.dataset.eduxidProfileNisn;
        if (!id && !nisn) return;
        const m=modal();if (!m) return;
        lastFocus=trigger;const n=++seq;
        m.classList.remove('hidden');m.classList.add('flex');m.setAttribute('aria-hidden','false');
        setText('modal-nama','Memuat profil…');setText('modal-nisn','');
        setText('eduxid-global-profil-mapel','Memuat…');
        ['modal-jk','modal-sakit','modal-izin','modal-alpa','modal-diagnostik',
         'modal-formatif','modal-sumatif'].forEach(id=>setText(id,'-'));
        for(let i=1;i<=8;i++)document.getElementById('modal-d'+i)?.replaceChildren();
        document.getElementById('modal-foto-container')?.replaceChildren();
        const token=document.querySelector('meta[name="csrf-token"]')?.content||'';
        const form=new URLSearchParams();
        if(id) form.set('student_id',id);else form.set('nisn',nisn);
        const a=trigger.dataset.eduxidProfileAssignmentId;
        if(a && /^\d+$/.test(a)) form.set('assignment_id',a);
        try{
            const endpoint=m.dataset.endpoint || '/internal/student-profile-overlay';
            const result=await fetch(endpoint,{
                method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-CSRF-TOKEN':token,'Accept':'application/json'},
                credentials:'same-origin',cache:'no-store',body:form.toString()
            });
            if(!result.ok)throw new Error(result.status===403?'Akses profil tidak diizinkan.':
                (result.status===404?'Profil tidak ditemukan.':'Tidak dapat memuat profil.'));
            const body=await result.json();if(n!==seq||!m.classList.contains('flex'))return;
            if(!body.data)throw new Error('Data profil tidak tersedia.');
            fill(body.data);
        }catch(err){if(n!==seq)return;setText('modal-nama',err.message||'Profil tidak tersedia');
            setText('modal-nisn','');setText('eduxid-global-profil-mapel','-');}
        m.querySelector('[role="document"]')?.focus();
    }
    document.addEventListener('click',ev=>{
        const button=ev.target.closest?.('.eduxid-global-profile-trigger');
        if(button && button.dataset.eduxidProfileReady === '1') {
            const nestedControl=ev.target.closest?.(skipTag);
            if(nestedControl && nestedControl !== button && !nestedControl.classList.contains('eduxid-global-profile-trigger')) return;
            ev.preventDefault();ev.stopPropagation();ev.stopImmediatePropagation();open(button);return;
        }
        if(ev.target.id==='close-modal' || (modal() && ev.target===modal()))close();
    },true);
    document.addEventListener('keydown',ev=>{
        if(ev.key==='Escape' && modal()?.classList.contains('flex')) {ev.preventDefault();close();return;}
        const active=ev.target.closest?.('.eduxid-global-profile-trigger');
        if(active && (ev.key==='Enter'||ev.key===' ')) {
            const nested=ev.target.closest?.(skipTag);
            if(nested && nested!==active && !nested.classList.contains('eduxid-global-profile-trigger')) return;
            ev.preventDefault();ev.stopPropagation();ev.stopImmediatePropagation();open(active);
        }
    },true);
    function init() {
        const m=modal();
        m?.querySelector('#close-modal')?.addEventListener('click',ev=>{ev.preventDefault();ev.stopPropagation();close();});
        m?.addEventListener('click',ev=>{if(ev.target===m)close();});
        scan();let scheduled=false;
        const observer=new MutationObserver(()=>{
            if(scheduled)return;scheduled=true;
            setTimeout(()=>{scheduled=false;scan();},180);
        });
        const main=document.querySelector('main')||document.body;
        observer.observe(main,{childList:true,subtree:true});
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
