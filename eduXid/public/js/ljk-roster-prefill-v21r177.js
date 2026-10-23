/* eduXid V21R178 — koreksi perataan angka NISN pada kotak isian LJK-A4-V3.
 * Dokumen dibuat lokal, NISN tidak dikirim ke Cloud/layanan pihak ketiga.
 * Bila V21 roster hanya memiliki alias, menolak cetak: dilarang menebak NISN.
 */
(() => {
'use strict';
if (window.eduXidLjkRosterPrintV177) return;
const IMG=window.EDUXID_LJK_BG_URL || '/assets/ljk/LJK-A4-V3-print-bg.png';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const plain=v=>String(v??'').trim().replace(/[\u0000-\u001f\u007f]/g,' ');
const pick=(v,...names)=>{for(const key of names){const x=v?.[key];if(x!==undefined&&x!==null&&String(x).trim()!=='')return plain(x);}return '';};
function prepareAssignment(a){
 const id=pick(a,'ta_id','teaching_assignment_id','id');
 const kelas=pick(a,'homeroom_name','kelas','class_name','nama_rombel');
 const mapel=pick(a,'subject_name','mapel','nama_mata_pelajaran');
 const roster=Array.isArray(a.roster)?a.roster:[];
 const good=[],bad=[],counts=new Map();
 for(const raw of roster){
   const n=pick(raw,'nisn','student_nisn','nisn_siswa');
   if(/^\d{10}$/.test(n))counts.set(n,(counts.get(n)||0)+1);
 }
 for(const raw of roster){
   const nama=pick(raw,'nama','name','student_name','nama_siswa');
   // Alias s_... cannot be decoded; NEVER use it as a NISN.
   const nisn=pick(raw,'nisn','student_nisn','nisn_siswa');
   const label=nama||'(nama belum tersedia)';
   if(!/^\d{10}$/.test(nisn)||!nama){bad.push({label,reason:!nama?'Nama kosong':'NISN bukan 10 digit / belum tersedia di HP'});continue;}
   if((counts.get(nisn)||0)>1){bad.push({label,reason:'NISN duplikat pada rombel'});continue;}
   good.push({nama,nisn,kelas,mapel});
 }
 return {id,kelas,mapel,good,bad,total:roster.length, rosterAvailable:Array.isArray(a.roster)};
}
const mm=(num)=>Number(num).toFixed(3);
function svgFor(s){
 const nums=Array.from(s.nisn,(d,i)=>{
    const x=44+12*i,y=72+4.8*Number(d);
    return `<circle cx="${mm(x)}" cy="${mm(y)}" r="1.55" fill="#000"/><text x="${mm(x)}" y="66.15" text-anchor="middle" font-size="2.65" font-family="Arial,sans-serif" font-weight="700" fill="#111">${d}</text>`;
 }).join('');
 const safeName=esc(s.nama.slice(0,100)),safeClass=esc(s.kelas.slice(0,85)),safeMapel=esc(s.mapel.slice(0,100));
 const nameSize=s.nama.length>34?'2.55':'3.05';
 const mapelSize=s.mapel.length>32?'2.45':'3.05';
 return `<svg class="ed-ljk-overlay" viewBox="0 0 210 297" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
 <defs><clipPath id="ed-ljk-name-clip"><rect x="49.7" y="29.0" width="55.5" height="5.4"/></clipPath><clipPath id="ed-ljk-class-clip"><rect x="140.7" y="29.0" width="65" height="5.4"/></clipPath><clipPath id="ed-ljk-subject-clip"><rect x="140.7" y="38.7" width="65" height="5.4"/></clipPath></defs>
 <text clip-path="url(#ed-ljk-name-clip)" x="49.8" y="33.15" font-family="Arial,sans-serif" font-size="${nameSize}" font-weight="600" fill="#111">${safeName}</text>
 <text clip-path="url(#ed-ljk-class-clip)" x="140.8" y="33.15" font-family="Arial,sans-serif" font-size="3.05" font-weight="600" fill="#111">${safeClass}</text>
  <text clip-path="url(#ed-ljk-subject-clip)" x="140.8" y="43" font-family="Arial,sans-serif" font-size="${mapelSize}" font-weight="600" fill="#111">${safeMapel}</text>
 ${nums}</svg>`;
}
function printDocument(students,summary){
 if(!students.length)throw Error('Belum ada siswa dengan nama dan NISN 10 digit yang valid.');
 if(students.length>80)throw Error('Maksimum 80 lembar per proses cetak. Pilih sebagian siswa terlebih dahulu.');
 const pop=window.open('','_blank');
 if(!pop)throw Error('Izinkan pop-up untuk membuka pratinjau LJK.');
 const pages=students.map(s=>`<section class="ed-ljk-sheet"><img class="ed-ljk-bg" src="${IMG}" alt="Template resmi LJK-A4-V3">${svgFor(s)}</section>`).join('');
 const h=`<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>LJK Per Rombel — ${esc(summary)}</title>
 <style>
 @page{size:A4 portrait;margin:0}
 *{box-sizing:border-box}body{margin:0;background:#e2e8f0;color:#1e293b;font-family:Arial,sans-serif}
 .ed-ljk-toolbar{position:sticky;top:0;z-index:10;padding:12px 18px;background:white;border-bottom:1px solid #ccc;display:flex;gap:12px;align-items:center;flex-wrap:wrap;font-size:13px}
 .ed-ljk-toolbar button{padding:9px 13px;background:#2563eb;color:#fff;border:0;border-radius:7px;font-weight:bold;cursor:pointer}
 .ed-ljk-note{color:#475569}
 .ed-ljk-sheet{position:relative;display:block;width:210mm;height:297mm;margin:14px auto;background:white;overflow:hidden;break-after:page;page-break-after:always;box-shadow:0 0 12px #94a3b8}
 .ed-ljk-sheet:last-child{break-after:auto;page-break-after:auto}
 .ed-ljk-bg{position:absolute;inset:0;width:210mm;height:297mm;object-fit:fill}
 .ed-ljk-overlay{position:absolute;inset:0;width:210mm;height:297mm}
 @media print{body{background:white}.ed-ljk-toolbar{display:none!important}.ed-ljk-sheet{margin:0;box-shadow:none;width:210mm;height:297mm;break-inside:avoid;page-break-inside:avoid}*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
 </style></head><body><div class="ed-ljk-toolbar"><strong>${students.length} lembar LJK siap dicetak</strong><span class="ed-ljk-note">Ukuran A4 · skala 100% · tanggal ditulis manual · cetak satu sisi</span><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>${pages}</body></html>`;
 pop.document.open();pop.document.write(h);pop.document.close();pop.focus();
 return students.length;
}
function dialog(assignments){
 const list=assignments.filter(a=>a.id&&a.kelas&&a.mapel);
 if(!list.length)throw Error('Penugasan/rombel guru belum tersedia. Sinkronkan Dapodik ke perangkat ini.');
 const overlay=document.createElement('div');overlay.className='ed-ljk-roster-overlay';
 overlay.innerHTML='<section class="ed-ljk-roster-panel" role="dialog" aria-modal="true" aria-label="Cetak LJK per rombel"><div class="ed-ljk-roster-head"><div><h2>LJK Per Rombel</h2><p>Nama, kelas, mapel, dan NISN terisi dari data siswa. <strong>Hari/Tanggal tetap kosong untuk ditulis manual.</strong></p></div><button type="button" data-close aria-label="Tutup">✕</button></div><label class="ed-ljk-roster-label">Pilih rombel dan mata pelajaran<select id="ed-ljk-roster-ta"></select></label><div id="ed-ljk-roster-stats" role="status"></div><div id="ed-ljk-roster-students"></div><p class="ed-ljk-roster-note">LJK-A4-V3 resmi, 50 nomor A–E, 4 marker dan NISN 10 kolom; hanya identitas yang ditambahkan. Cetak kertas A4 pada ukuran aktual (100%).</p><button type="button" id="ed-ljk-roster-print" class="ed-ljk-roster-primary">Cetak LJK Siswa Terpilih / Simpan PDF</button></section>';
 document.body.appendChild(overlay);
 const panel=overlay.firstElementChild,sel=panel.querySelector('#ed-ljk-roster-ta'),stats=panel.querySelector('#ed-ljk-roster-stats'),studentsEl=panel.querySelector('#ed-ljk-roster-students'),btn=panel.querySelector('#ed-ljk-roster-print');
 sel.innerHTML=list.map((a,i)=>`<option value="${i}">${esc(a.kelas)} · ${esc(a.mapel)} (${a.total} siswa)</option>`).join('');
 let selected=list[0];
 const paint=()=>{
 selected=list[Number(sel.value)]||list[0];
 const ok=selected.good.length;
 stats.innerHTML=`<b>${ok} siap cetak</b> dari ${selected.total} siswa; <b>${selected.bad.length} perlu koreksi biodata.</b>`;
 const explanation=selected.bad.length ? '<p class="ed-ljk-roster-warning">'+(selected.good.length===0?'Cetak ditahan: ':'')+'Periksa NISN pada sumber data sekolah. Dalam mode identitas V21, roster Cloud menggunakan alias sehingga NISN asli tidak tersedia di HP. Cetak melalui eduXid Laptop sampai roster privat berizin tersedia di perangkat ini.</p>' : '';
 studentsEl.innerHTML=explanation+selected.good.map((s,i)=>`<label class="ed-ljk-roster-student"><input type="checkbox" data-student="${i}" checked><span>${esc(s.nama)}<small>${esc(s.nisn)}</small></span></label>`).join('')+(selected.bad.length?'<details><summary>'+selected.bad.length+' data belum bisa dicetak</summary>'+selected.bad.map(s=>'<div class="ed-ljk-roster-invalid">'+esc(s.label)+' — '+esc(s.reason)+'</div>').join('')+'</details>':'');
 btn.disabled=!ok;
 };
 sel.onchange=paint;
 panel.querySelector('[data-close]').onclick=()=>overlay.remove();
 btn.onclick=()=>{
   const chosen=Array.from(studentsEl.querySelectorAll('input[data-student]:checked')).map(i=>selected.good[Number(i.dataset.student)]).filter(Boolean);
   if(!chosen.length){stats.textContent='Pilih minimal satu siswa.';return;}
   try{printDocument(chosen,selected.kelas+' '+selected.mapel);}catch(e){stats.textContent=e.message;}
 };
 paint();
}
function openPwa(){
 const sessionPromise=window.eduXidCloud?.session?.();
 const assignments=window.EDUXID_GURU_APP_STATE?.getTeachingAssignments?.();
 if(!sessionPromise || !Array.isArray(assignments))throw Error('Data guru belum tersedia. Login dan sinkronkan penugasan terlebih dahulu.');
 // Cached session can be read asynchronously. The UI itself does not expose raw NISN to Cloud.
 return Promise.resolve(sessionPromise).then(s=>{
  if(s?.role!=='guru'||!s.school_id||!s.login_id)throw Error('Akses hanya untuk guru yang telah login.');
  // The canonical roster is the SAME data loaded by attendance (offline).
  dialog(assignments.map(prepareAssignment));
 });
}
function openLaptop(assignments){
 dialog((Array.isArray(assignments)?assignments:[]).map(prepareAssignment));
}
window.eduXidLjkRosterPrintV177={openPwa,openLaptop,prepareAssignment,printDocument,svgFor};
})();
