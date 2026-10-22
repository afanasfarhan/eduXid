(() => {
'use strict';
window.EDUXID_LJK_LAPTOP_V16_MANUAL=true;
const root=document.getElementById('ljk-auto-v14-root'); if(!root) return;
const $=id=>document.getElementById(id); const csrf=root.dataset.csrf;const initialExam=String(root.dataset.initialExam||new URLSearchParams(location.search).get('exam')||'').trim();
const urls={package:root.dataset.packageUrl,results:root.dataset.resultsUrl,store:root.dataset.storeUrl,result:root.dataset.resultBase,clear:root.dataset.clearBase,publish:root.dataset.publishBase,syncPwa:root.dataset.syncPwaBase,zip:root.dataset.zipUrl,pdf:root.dataset.pdfUrl,manual:root.dataset.manualKeyBase,standalone:root.dataset.standaloneBase};
const isStandalone=()=>String(examId).startsWith('manual::');
const standalonePath=()=>urls.standalone+'/'+encodeURIComponent(examId);
let examId='',pkg=null,rows=[],reviewOnly=false,processing=false,editRow=null,reviewAnswers={},manualDirty=false,standaloneMode=false,standaloneLoaded=false;
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
// UUID valid pada HTTP LAN Windows (crypto.randomUUID hanya tersedia di secure context).
const uid=()=>{
 if(typeof crypto!=='undefined'&&typeof crypto.randomUUID==='function')return crypto.randomUUID();
 const bytes=new Uint8Array(16);
 if(typeof crypto!=='undefined'&&typeof crypto.getRandomValues==='function')crypto.getRandomValues(bytes);
 else for(let i=0;i<16;i++)bytes[i]=Math.floor(Math.random()*256);
 bytes[6]=(bytes[6]&15)|64;bytes[8]=(bytes[8]&63)|128;
 const s=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
 return `${s.slice(0,8)}-${s.slice(8,12)}-${s.slice(12,16)}-${s.slice(16,20)}-${s.slice(20)}`;
};
const CAL_KEY='eduxid_ljk_calibration_v3';
const CAL_DEFAULT={marker_threshold:0.55,bubble_threshold:0.42,ambiguity_gap:0.08,min_confidence:0.72,perspective_auto:true};
function clamp(v,min,max,fallback){v=Number(v);return Number.isFinite(v)?Math.min(max,Math.max(min,v)):fallback;}
function normalizeCalibration(raw){
 raw=raw&&typeof raw==='object'?raw:{};
 return{
   marker_threshold:clamp(raw.marker_threshold,0.10,0.95,CAL_DEFAULT.marker_threshold),
   bubble_threshold:clamp(raw.bubble_threshold,0.10,0.95,CAL_DEFAULT.bubble_threshold),
   ambiguity_gap:clamp(raw.ambiguity_gap,0.01,0.50,CAL_DEFAULT.ambiguity_gap),
   min_confidence:clamp(raw.min_confidence,0.10,1.00,CAL_DEFAULT.min_confidence),
   perspective_auto:raw.perspective_auto!==false
 };
}
function loadCalibration(){
 try{return normalizeCalibration(JSON.parse(localStorage.getItem(CAL_KEY)||'{}'));}catch(_){return {...CAL_DEFAULT};}
}
function publishCalibration(cal){
 cal=normalizeCalibration(cal);
 window.EDUXID_LJK_CALIBRATION=cal;
 window.LJK_A4_V3_CALIBRATION=cal;
 window.dispatchEvent(new CustomEvent('eduxid:ljk-calibration',{detail:cal}));
 return cal;
}
function saveCalibration(cal){
 cal=publishCalibration(cal);
 localStorage.setItem(CAL_KEY,JSON.stringify(cal));
 return cal;
}
let calibration=publishCalibration(loadCalibration());
async function jf(url,opt={}){const r=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf,...(opt.headers||{})},...opt});const b=await r.json().catch(()=>null);if(!r.ok||!b?.success)throw Error(b?.message||'HTTP '+r.status);return b.data;}
async function loadScript(src){return await new Promise((res,rej)=>{const s=document.createElement('script');s.src=src;s.onload=()=>res(true);s.onerror=rej;document.head.appendChild(s);});}
function scannerModule(){return window.EDUXID_LJK_SCANNER_V14R10||window.EDUXID_LJK_SCANNER_V14R9||window.EDUXID_LJK_SCANNER_V13R11||window.EDUXID_LJK_SCANNER_V13R10||window.EDUXID_LJK_SCANNER_V13R9||window.EDUXID_LJK_SCANNER||window.EDUXID_LJK?.scanner||null;}
function scannerFns(){const out=[];const m=scannerModule();if(m)for(const n of ['scanFile','processFile','processImage','scanImage','processPhoto','scanPhoto','readFile','analyze'])if(typeof m[n]==='function')out.push([m,m[n]]);for(const n of ['processLjkPhoto','processLjkImage','scanLjkPhoto','scanLjkImage'])if(typeof window[n]==='function')out.push([window,window[n]]);return out;}
async function ensureScanner(){if(scannerFns().length)return true;for(const src of ['/js/ljk-scanner-v14r10.js','/js/ljk-scanner-v14r9.js','/js/ljk-scanner-v13r11.js','/js/ljk-scanner-v13r10.js','/js/ljk-scanner-v13r9.js','/js/ljk-scanner.js','/js/ljk-omr.js','/ljk/js/scanner.js']){try{await loadScript(src);if(scannerFns().length){$('ljk-engine-state').textContent='Pemindai LJK aktif: '+src;return true;}}catch(_){}}$('ljk-engine-state').textContent='Pemindai belum ditemukan. Pastikan Pemindai LJK-A4-V3 v14R10 terpasang.';return false;}
async function durl(file){return await new Promise((res,rej)=>{const r=new FileReader();r.onload=()=>res(r.result);r.onerror=()=>rej(r.error);r.readAsDataURL(file);});}
function norm(raw){raw=raw?.data||raw?.result||raw||{};const id=raw.identity||raw.identitas||{};const ans=raw.answers||raw.jawaban||raw.responses||raw.detected_answers||{};return{nisn:String(raw.nisn??raw.student_nisn??raw.omr_nisn??id.nisn??'').replace(/\D/g,''),student_name:raw.student_name??raw.nama??id.name??'',answers:ans,answer_confidence:raw.answer_confidence||raw.confidences||null,confidence:Number(raw.confidence??raw.avg_confidence??raw.score_confidence??1),ambiguous_count:Number(raw.ambiguous_count??raw.ambiguities?.length??raw.ambiguous?.length??0),blank_count:Number(raw.blank_count??0),multi_count:Number(raw.multi_count??0),single_count:Number(raw.single_count??0),marker_ok:raw.marker_ok??raw.markers_ok??raw.markerDetected??raw.valid_template??true,scan_ok:raw.scan_ok??raw.success??true,detected_question_count:Number(raw.detected_question_count??raw.question_count??Object.keys(ans||{}).length)||null};}
async function scan(file){
 if(!await ensureScanner())throw Error('Pemindai LJK tidak tersedia.');
 if(!(file instanceof Blob))throw Error('Berkas gambar LJK tidak valid.');
 const state=$('ljk-engine-state');if(state)state.textContent='Pemindai: membaca marker, NISN, dan jawaban...';
 let last=null;
 calibration=publishCalibration(loadCalibration());
 const questionTypes=Object.fromEntries((pkg?.questions||[]).map(q=>[String(q.number??q.no??''),String(q.type??q.jenis_soal??'')]));
 const options={template:'LJK-A4-V3',auto:true,review:false,question_count:Number(pkg?.exam?.question_count||50),question_types:questionTypes,calibration,marker_threshold:calibration.marker_threshold,bubble_threshold:calibration.bubble_threshold,ambiguity_gap:calibration.ambiguity_gap,min_confidence:calibration.min_confidence,perspective_auto:!!calibration.perspective_auto};

 const native=window.EDUXID_LJK_SCANNER_V14R10;
 if(native&&typeof native.scanFile==='function'){
   try{
     const x=await native.scanFile(file,options);
     const n=norm(x);
     if(Object.keys(n.answers||{}).length>0){
       if(state)state.textContent=`Pemindai v14R10: ${Object.values(n.answers||{}).filter(v=>Array.isArray(v)?v.length>0:String(v||'').trim()!=='').length} terisi · ${n.blank_count||0} kosong · ${n.multi_count||0} pilihan majemuk/multi.`;
       return n;
     }
   }catch(e){last=e;console.warn('[LJK SCANNER V14R10]',e);}
 }

 const data=await durl(file).catch(()=>null);
 for(const [ctx,fn] of scannerFns()){
   if(native&&(ctx===native||fn===native.scanFile||fn===native.processFile||fn===native.analyze))continue;
   for(const arg of [file,data].filter(Boolean)){
     try{
       const x=await fn.call(ctx,arg,options);
       if(!x)continue;
       const n=norm(x);
       if(Object.keys(n.answers||{}).length>0)return n;
     }catch(e){last=e;}
   }
 }
 throw Error(last?.message||'Pemindai tidak menghasilkan jawaban. Pastikan JPG berasal dari Template LJK-A4-V3 dan seluruh halaman terlihat.');
}
async function save(s,file,meta={}){guardManualReady();const f=new FormData();f.set('scan_id',uid());f.set('exam_id',examId);f.set('exam_sync_id',pkg?.exam?.sync_id||'');f.set('nisn',s.nisn||'');f.set('student_name',s.student_name||'');f.set('answers',JSON.stringify(s.answers||{}));if(s.answer_confidence)f.set('answer_confidence',JSON.stringify(s.answer_confidence));f.set('confidence',String(Number.isFinite(s.confidence)?s.confidence:1));f.set('ambiguous_count',String(Number.isFinite(s.ambiguous_count)?s.ambiguous_count:0));f.set('marker_ok',s.marker_ok?'1':'0');f.set('scan_ok',s.scan_ok?'1':'0');if(s.detected_question_count!=null)f.set('detected_question_count',String(s.detected_question_count));f.set('template_version','LJK-A4-V3');f.set('source_type',meta.source_type||'image');f.set('source_name',meta.source_name||file?.name||'');if(meta.source_index!=null)f.set('source_index',String(meta.source_index));if(file)f.set('image',file,file.name||'ljk.jpg');if(isStandalone())f.set('manual_key_fingerprint',pkg?.manual_key_fingerprint||'');const r=await fetch(isStandalone()?standalonePath()+'/results':urls.store,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf},body:f});const b=await r.json().catch(()=>null);if(!r.ok||!b?.success)throw Error(b?.message||'HTTP '+r.status);return b.data;}
function badge(r){const m={success:['Berhasil','bg-emerald-100 text-emerald-700'],review:['Perlu Dicek','bg-amber-100 text-amber-800'],duplicate:['Duplikat','bg-red-100 text-red-700'],failed:['Gagal','bg-red-100 text-red-700']};const [t,c]=m[r.status]||[r.status,'bg-gray-100 text-gray-700'];return `<span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold ${c}">${esc(t)}</span>`;}
function statusLabel(r){
 const m={success:'Berhasil',review:'Perlu Dicek',duplicate:'Duplikat',failed:'Gagal'};
 let text=m[r.status]||String(r.status||'');
 if(r.published)text+=(text?' · ':'')+'Sudah masuk Buku Nilai';
 text+=(text?' · ':'')+((r.key_source==='manual')?'Kunci Manual':'Kunci Paket');
 if(r.issue_message)text+=(text?' · ':'')+String(r.issue_message);
 return text||'-';
}
function csvCell(v){
 const s=String(v??'').replace(/\r?\n/g,' ').trim();
 return '"'+s.replace(/"/g,'""')+'"';
}
function safeFilename(v){
 return String(v||'paket-ujian')
  .normalize('NFKD')
  .replace(/[^\w\s.-]+/g,'')
  .replace(/\s+/g,'-')
  .replace(/-+/g,'-')
  .replace(/^[.-]+|[.-]+$/g,'')
  .slice(0,80)||'paket-ujian';
}
function visibleRows(){return reviewOnly?rows.filter(r=>r.status==='review'):rows;}
function isMultipleQuestion(q){
 const t=String(q?.type??q?.jenis_soal??'').toLowerCase();
 return /majemuk|kompleks|multiple|multi/.test(t);
}
function questionKey(q){
 return String(q?.question_bank_id??q?.id??q?.number??q?.no??'');
}
function normalizeChoiceValue(value){
 if(Array.isArray(value)){
   return [...new Set(value.map(v=>String(v||'').trim().toUpperCase()).filter(v=>/^[A-E]$/.test(v)))];
 }
 const raw=String(value??'').trim().toUpperCase();
 if(!raw)return [];
 if(raw.startsWith('[')){
   try{return normalizeChoiceValue(JSON.parse(raw));}catch(_){}
 }
 return [...new Set(raw.split(/[\s,;|/+]+/).filter(v=>/^[A-E]$/.test(v)))];
}
function initReviewAnswers(row){
 reviewAnswers={};
 for(const q of (pkg?.questions||[])){
   const key=questionKey(q);
   const number=String(q?.number??q?.no??'');
   let value='';
   if(row?.answers&&Object.prototype.hasOwnProperty.call(row.answers,key))value=row.answers[key];
   else if(number&&row?.answers&&Object.prototype.hasOwnProperty.call(row.answers,number))value=row.answers[number];

   const selected=normalizeChoiceValue(value);
   reviewAnswers[key]=isMultipleQuestion(q)
     ? selected
     : (selected.length>1?selected:(selected[0]||''));
 }
}
function answerSelected(value,letter){
 return Array.isArray(value)?value.includes(letter):String(value||'')===letter;
}
function reviewStats(){
 let blank=0,single=0,multi=0;
 for(const q of (pkg?.questions||[])){
   const value=reviewAnswers[questionKey(q)];
   const arr=normalizeChoiceValue(value);
   if(arr.length===0)blank++;
   else if(arr.length===1)single++;
   else multi++;
 }
 return{blank,single,multi,total:(pkg?.questions||[]).length};
}
function renderReviewAnswers(){
 const grid=$('ljk-review-answer-grid');if(!grid)return;
 const questions=pkg?.questions||[];
 grid.innerHTML=questions.map(q=>{
   const key=questionKey(q),number=q?.number??q?.no??'-',multi=isMultipleQuestion(q);
   const type=String(q?.type??q?.jenis_soal??'').toLowerCase();
   const trueFalse=type==='benar_salah';
   const value=reviewAnswers[key]??'';
   const selected=normalizeChoiceValue(value);
   const unexpected=!multi&&selected.length>1;
   const letters=trueFalse?['A','B']:['A','B','C','D','E'];
   const buttons=letters.map(letter=>{
     const active=answerSelected(value,letter);
     const label=trueFalse?(letter==='A'?'A · Benar':'B · Salah'):letter;
     return `<button type="button" data-review-q="${esc(key)}" data-review-choice="${letter}" class="h-9 min-w-9 rounded-lg border px-2 text-xs font-black transition ${active?'border-indigo-600 bg-indigo-600 text-white shadow-sm':'border-slate-200 bg-white text-slate-600 hover:border-indigo-300 hover:bg-indigo-50'}">${label}</button>`;
   }).join('');
   const badge=multi
     ? '<span class="rounded-full bg-violet-100 px-2 py-1 text-[10px] font-bold text-violet-700">Majemuk</span>'
     : trueFalse
       ? '<span class="rounded-full bg-emerald-100 px-2 py-1 text-[10px] font-bold text-emerald-700">Benar/Salah</span>'
       : '<span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-500">Pilihan Ganda</span>';
   const warning=unexpected?'<span class="text-[10px] font-semibold text-rose-600">Pilih satu atau Kosong</span>':'';
   return `<div class="grid grid-cols-[52px_minmax(0,1fr)] items-center gap-2 px-3 py-2.5 ${unexpected?'bg-rose-50/60':''}">
     <div class="text-sm font-black text-slate-700">${esc(number)}</div>
     <div class="flex flex-wrap items-center gap-1.5">${badge}${buttons}<button type="button" data-review-q="${esc(key)}" data-review-clear="1" class="h-9 rounded-lg border border-slate-200 bg-slate-100 px-2.5 text-[11px] font-bold text-slate-600 hover:bg-slate-200">Kosong</button>${warning}</div>
   </div>`;
 }).join('')||'<div class="p-5 text-sm text-slate-500">Daftar soal Paket Ujian belum tersedia.</div>';

 const s=reviewStats(),summary=$('ljk-review-answer-summary');
 if(summary)summary.textContent=`${s.single} tunggal · ${s.multi} multi · ${s.blank} kosong`;
}
function serializedReviewAnswers(){
 const out={};
 for(const q of (pkg?.questions||[])){
   const key=questionKey(q),value=reviewAnswers[key];
   const arr=normalizeChoiceValue(value);
   if(isMultipleQuestion(q))out[key]=arr;
   else out[key]=arr.length===0?'':(arr.length===1?arr[0]:arr);
 }
 return out;
}
/* V21R76 — Ekspor selalu dari hasil TERSIMPAN pada sesi aktif, bukan filter layar. */
async function downloadResults(){
 if(!examId){alert('Pilih Paket Ujian atau sesi kunci manual terlebih dahulu.');return;}
 if(processing){alert('Pemindaian sedang berlangsung. Selesaikan terlebih dahulu sebelum mengunduh hasil.');return;}
 const selectedExam=String(examId), selectedStandalone=isStandalone();
 const button=$('ljk-download-results');if(button){button.disabled=true;button.textContent='⏳ Menyiapkan Excel...';}
 try {
   if(!window.eduXidLjkExcel||typeof window.eduXidLjkExcel.exportResults!=='function')
     throw Error('Komponen ekspor Excel belum termuat. Muat ulang halaman.');
   const endpoint=selectedStandalone ? standalonePath()+'/results' : urls.results+'?exam_id='+encodeURIComponent(selectedExam);
   const saved=await jf(endpoint);
   if(String(examId)!==selectedExam||isStandalone()!==selectedStandalone)
     throw Error('Sesi berubah saat ekspor. Silakan unduh ulang dari sesi yang aktif.');
   if(!Array.isArray(saved))throw Error('Respons hasil koreksi dari server tidak valid.');
   if(saved.length===0)throw Error('Belum ada hasil koreksi tersimpan pada sesi ini. Excel tidak dibuat.');
   const valid=saved.filter(r=>r&&typeof r==='object'&&r.scan_id!==undefined&&
     r.correct_count!==undefined&&r.question_count!==undefined&&r.score!==undefined);
   if(valid.length!==saved.length)throw Error('Sebagian data koreksi belum lengkap. Excel tidak dibuat agar tidak menyesatkan.');
   const title=pkg?.exam?.title||pkg?.exam?.judul_ujian||$('ljk-exam')?.selectedOptions?.[0]?.textContent||('Sesi-'+selectedExam);
   const file=window.eduXidLjkExcel.exportResults(valid.map(r=>({...r,status_label:statusLabel(r)})),title);
   if(!file||typeof file.size!=='number'||file.size<1000)
     throw Error('Berkas Excel yang dibentuk tidak valid.');
   rows=saved;render();
   $('ljk-progress').textContent='Download Hasil Excel: '+saved.length+' hasil tersimpan diekspor (tanpa filter Perlu Dicek).';
 }catch(error){alert('Download Excel gagal: '+(error?.message||String(error)));}
 finally {if(button)button.textContent='⬇ Download Hasil Excel';render();}
}

function render(){
 const visible=visibleRows();
 $('ljk-results-body').innerHTML=visible.map((r,i)=>{const actions=String(r.scan_id).startsWith('failed-')?'':
  `${(isStandalone()&&r.published)?'':`<button data-edit="${esc(r.scan_id)}" class="rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-bold text-indigo-700 transition hover:bg-indigo-100">Edit</button>`}${r.published?'':`<button data-delete="${esc(r.scan_id)}" class="ml-1 rounded-xl border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-bold text-rose-700 transition hover:bg-rose-100">Hapus</button>`}`;return `<tr class="group transition hover:bg-slate-50/80"><td class="px-4 py-4 text-slate-400">${i+1}</td><td class="px-4 py-4"><div class="font-bold text-slate-900">${esc(r.student_name||'Belum dikenali')}</div>${r.source_name?`<div class="mt-1 max-w-[240px] truncate text-[11px] text-slate-400">${esc(r.source_name)}</div>`:''}</td><td class="px-4 py-4 whitespace-nowrap text-slate-700">${esc(r.class_name||r.kelas||'-')}</td><td class="px-4 py-4 font-mono text-xs text-slate-600">${esc(r.nisn||'-')}</td><td class="px-4 py-4"><span class="font-extrabold text-slate-800">${r.correct_count}/${r.question_count}</span><div class="mt-1 text-[11px] text-slate-400">jawaban benar</div></td><td class="px-4 py-4"><span class="inline-flex min-w-12 justify-center rounded-xl bg-slate-900 px-3 py-1.5 text-base font-black text-white">${Number(r.score||0).toFixed(0)}</span></td><td class="px-4 py-4">${badge(r)}<div class="mt-1 text-[11px] text-slate-500">${r.key_source==='manual'?'Kunci Manual Guru':'Kunci Paket'}</div>${r.published?'<div class="mt-1.5 text-[11px] font-semibold text-emerald-600">✓ Sudah masuk Buku Nilai</div>':''}${r.issue_message?`<div class="mt-1.5 max-w-sm text-[11px] leading-4 text-slate-500">${esc(r.issue_message)}</div>`:''}</td><td class="px-4 py-4 text-right whitespace-nowrap">${actions}</td></tr>`;}).join('')||'<tr><td colspan="8" class="px-6 py-14 text-center"><div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-2xl">🧾</div><div class="mt-3 font-bold text-slate-700">Belum ada hasil koreksi</div><div class="mt-1 text-xs text-slate-400">Pilih Paket Ujian atau sesi kunci manual, lalu pindai LJK dari kamera, gambar, PDF, atau ZIP.</div></td></tr>';
 const s=rows.filter(r=>r.status==='success').length,v=rows.filter(r=>r.status==='review').length,p=rows.filter(r=>['duplicate','failed'].includes(r.status)).length;
 $('sum-total').textContent=rows.length;$('sum-success').textContent=s;$('sum-review').textContent=v;$('sum-problem').textContent=p;
 const unpublished=rows.filter(r=>!r.published&&!['duplicate','failed'].includes(r.status));
 $('ljk-publish').disabled=processing||!examId||unpublished.length===0;$('ljk-sync-pwa').disabled=processing||!examId;
 const clearAll=$('ljk-clear-all');if(clearAll)clearAll.disabled=processing||!examId||rows.length===0;
 const dl=$('ljk-download-results');if(dl)dl.disabled=processing||!examId||rows.length===0;const analysis=$('ljk-item-analysis');if(analysis){analysis.disabled=processing||!examId||!rows.some(r=>r.status==='success'&&r.scan_id&&!String(r.scan_id).startsWith('failed-'));analysis.title=analysis.disabled?'Analisis tersedia setelah minimal satu hasil LJK berstatus Berhasil.':'Analisis butir jawaban yang berhasil dikoreksi';}
}
function live(r){$('ljk-live').classList.remove('hidden');$('ljk-live-body').innerHTML=`<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><div class="text-lg font-extrabold text-slate-900">${esc(r.student_name||'Belum dikenali')}</div><div class="mt-1 font-mono text-xs text-slate-500">NISN ${esc(r.nisn||'-')}</div></div><div class="flex flex-wrap items-center gap-3"><div class="rounded-xl bg-white/80 px-3 py-2 text-sm text-slate-600 shadow-sm"><b class="text-slate-900">${r.correct_count}/${r.question_count}</b> benar</div><div class="rounded-xl bg-emerald-600 px-4 py-2 text-xl font-black text-white shadow-sm">${Number(r.score||0).toFixed(0)}</div><div>${badge(r)}</div></div></div>`;}
async function refresh(){if(!examId){rows=[];render();return;}rows=await jf(isStandalone()?standalonePath()+'/results':urls.results+'?exam_id='+encodeURIComponent(examId));render();}
async function process(items,type){guardManualReady();
 if(!examId)throw Error('Pilih Paket Ujian atau sesi kunci manual terlebih dahulu.');
 if(processing)throw Error('Batch lain masih diproses.');
 processing=true;let done=0,success=0,failed=0;
 try{
  for(let i=0;i<items.length;i++){
   const it=items[i];
   const label=it.name||('LJK '+(i+1));
   $('ljk-progress').textContent=`[1/3] Membaca LJK ${i+1}/${items.length}: ${label}`;
   try{
    let file=it.file;
    if(!file&&it.url){
      const r=await fetch(it.url,{credentials:'same-origin'});
      if(!r.ok)throw Error('Gagal mengambil halaman/gambar hasil impor.');
      const blob=await r.blob();
      file=new File([blob],it.name||`ljk-${i+1}.png`,{type:blob.type||'image/png'});
    }
    if(!(file instanceof Blob))throw Error('Berkas gambar tidak valid.');
    const s=await scan(file);
    const answerCount=Object.values(s.answers||{}).filter(v=>String(v||'').trim()!=='').length;
    if(answerCount===0)throw Error('Pemindai tidak menemukan isian jawaban pada lembar ini.');

    $('ljk-progress').textContent=`[2/3] Mencocokkan ${answerCount} jawaban dengan kunci aktif...`;
    const saved=await save(s,file,{source_type:type,source_name:it.name||file.name,source_index:it.index??i+1});

    $('ljk-progress').textContent=`[3/3] Menyimpan hasil: ${saved.student_name||saved.nisn||label} · ${saved.correct_count}/${saved.question_count} benar · nilai ${Number(saved.score||0).toFixed(0)}`;
    rows.unshift(saved);live(saved);render();success++;
   }catch(e){
    console.error('[LJK AUTO]',e);failed++;
    rows.unshift({scan_id:'failed-'+uid(),student_name:label,nisn:'',correct_count:0,question_count:pkg?.exam?.question_count||0,score:0,status:'failed',issue_message:e.message});render();
    $('ljk-progress').textContent=`Gagal membaca ${label}: ${e.message}`;
   }
   done++;await new Promise(r=>setTimeout(r,40));
  }
 }finally{
   processing=false;
   $('ljk-progress').textContent=`Batch selesai: ${success} berhasil, ${failed} gagal, total ${done} lembar.`;
   await refresh();
 }
}
const PDFJS_VERSION='3.11.174';
const PDFJS_LOCAL='/pdfjs/pdf.min.js?v='+PDFJS_VERSION;
const PDFJS_WORKER_LOCAL='/pdfjs/pdf.worker.min.js?v='+PDFJS_VERSION;
const PDFJS_CDN='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/'+PDFJS_VERSION+'/pdf.min.js';
const PDFJS_WORKER_CDN='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/'+PDFJS_VERSION+'/pdf.worker.min.js';
async function loadPdfJs(){
 if(window.pdfjsLib){
   try{if(window.pdfjsLib.GlobalWorkerOptions&&!window.pdfjsLib.GlobalWorkerOptions.workerSrc)window.pdfjsLib.GlobalWorkerOptions.workerSrc=PDFJS_WORKER_LOCAL;}catch(_){}
   return window.pdfjsLib;
 }
 const candidates=[
   {src:PDFJS_LOCAL,worker:PDFJS_WORKER_LOCAL},
   {src:'/js/pdf.min.js',worker:'/js/pdf.worker.min.js'},
   {src:'/vendor/pdfjs/pdf.min.js',worker:'/vendor/pdfjs/pdf.worker.min.js'},
   {src:'/pdfjs-dist/build/pdf.min.js',worker:'/pdfjs-dist/build/pdf.worker.min.js'},
   {src:PDFJS_CDN,worker:PDFJS_WORKER_CDN},
 ];
 for(const candidate of candidates){
   try{
     await loadScript(candidate.src);
     if(window.pdfjsLib){
       try{
         if(window.pdfjsLib.GlobalWorkerOptions)window.pdfjsLib.GlobalWorkerOptions.workerSrc=candidate.worker;
       }catch(_){}
       return window.pdfjsLib;
     }
   }catch(e){
     console.warn('[LJK PDF.js] gagal memuat',candidate.src,e);
   }
 }
 return null;
}
async function pdfItems(file){
 const lib=await loadPdfJs();
 if(!lib)return null;
 $('ljk-progress').textContent='Membuka PDF dan menyiapkan halaman...';
 let task;
 try{
   task=lib.getDocument({
     data:await file.arrayBuffer(),
     cMapPacked:true,
     useSystemFonts:true,
     isEvalSupported:true
   });
 }catch(e){
   throw Error('PDF.js gagal membuka dokumen: '+(e?.message||e));
 }
 const pdf=await task.promise;
 const out=[];
 for(let p=1;p<=pdf.numPages;p++){
   $('ljk-progress').textContent=`Rasterisasi PDF ${p}/${pdf.numPages} di browser...`;
   const page=await pdf.getPage(p);
   const vp=page.getViewport({scale:2.35});
   const c=document.createElement('canvas');
   c.width=Math.ceil(vp.width);
   c.height=Math.ceil(vp.height);
   const ctx=c.getContext('2d',{alpha:false,willReadFrequently:true});
   ctx.fillStyle='#fff';
   ctx.fillRect(0,0,c.width,c.height);
   await page.render({canvasContext:ctx,viewport:vp,background:'rgb(255,255,255)'}).promise;
   const blob=await new Promise((res,rej)=>c.toBlob(
     b=>b?res(b):rej(Error('Konversi halaman PDF ke PNG gagal.')),
     'image/png'
   ));
   out.push({
     file:new File([blob],`page-${String(p).padStart(4,'0')}.png`,{type:'image/png'}),
     name:`Halaman ${p}`,
     index:p
   });
   try{page.cleanup?.();}catch(_){}
 }
 try{pdf.cleanup?.();pdf.destroy?.();}catch(_){}
 return out;
}
// Manual key configuration is persisted on the teacher server and is authoritative.
function manualQuestionKey(q){return String(q.question_bank_id??q.id??q.number??'');}
function manualChoice(q){const t=String(q.type??q.jenis_soal??'').toLowerCase();return t==='benar_salah'?['A','B']:['A','B','C','D','E'];}
function manualIsMultiple(q){return /majemuk|kompleks|multi/.test(String(q.type??q.jenis_soal??'').toLowerCase());}
function manualColumns(entries, renderer){
 const groups=[];
 for(let i=0;i<entries.length;i+=10){
  const part=entries.slice(i,i+10);
  groups.push(`<section class="ljk-key-column"><h5 class="ljk-key-col-head">Nomor ${i+1}–${i+part.length}</h5><div class="ljk-key-col-rows">${part.map((row,j)=>renderer(row,i+j)).join('')}</div></section>`);
 }
 return groups.join('');
}
function displayManualEditor(){
 const cfg=pkg?.manual_key||{source:'package',keys:{}};
 const available=!!pkg?.exam?.id&&!isStandalone();
 const selector=$('ljk-key-source');selector.disabled=!available;selector.value=cfg.source==='manual'?'manual':'package';
 $('ljk-manual-key-editor').classList.toggle('hidden',!available||selector.value!=='manual');
 const grid=$('ljk-manual-key-grid');
 grid.innerHTML=manualColumns(pkg?.questions||[],(q,i)=>{
   const k=manualQuestionKey(q),value=cfg.keys?.[k]??'',multi=manualIsMultiple(q),chosen=Array.isArray(value)?value:[value];
   const choices=manualChoice(q),questionType=String(q.type??q.jenis_soal??'').toLowerCase();
   const typeLabel=questionType==='benar_salah'?'B/S':multi?'Majemuk':'PG';
   const choicesHtml=choices.map(a=>{
     const selected=chosen.includes(a);
     const input=multi
       ?`<input class="ljk-manual-multi" type="checkbox" data-q="${esc(k)}" value="${a}" ${selected?'checked':''}>`
       :`<input class="ljk-manual-single-choice" type="checkbox" data-q="${esc(k)}" value="${a}" ${selected?'checked':''}>`;
     return `<label>${input}${a}</label>`;
   }).join('');
   return `<div class="ljk-key-row"><div class="ljk-key-row-inline">
     <span class="ljk-key-num">${i+1}.</span>
     <span class="ljk-key-type-static" title="Jenis soal: ${esc(typeLabel)}">${typeLabel}</span>
     <div class="ljk-key-choices" role="group" aria-label="Kunci soal nomor ${i+1}">${choicesHtml}</div>
   </div></div>`;
 });
 const status=$('ljk-manual-key-status');if(status)status.textContent=cfg.source==='manual'?'Kunci manual aktif.':'Lengkapi dan simpan sebelum memindai.';
 const deleteKey=$('ljk-manual-key-delete');if(deleteKey){deleteKey.classList.toggle('hidden',!available||!cfg.has_manual_key);deleteKey.disabled=!available||!cfg.has_manual_key||processing;}
 updateManualStatus();manualDirty=false;
}
function readManualKeys(){
 const values={};for(const el of document.querySelectorAll('.ljk-manual-single-choice:checked'))values[el.dataset.q]=el.value;
 for(const el of document.querySelectorAll('.ljk-manual-multi:checked'))(values[el.dataset.q]??=[]).push(el.value);
 return values;
}
function updateManualStatus(){
 const cfg=pkg?.manual_key||{};const active=cfg.source==='manual';
 $('ljk-key-source-status').textContent=isStandalone()?'Sesi kunci manual aktif. Kunci terkunci per sesi dan siap dikirim ke PWA Guru.':!pkg?'Pilih Paket Ujian terlebih dahulu.':
  cfg.stale?'Susunan soal berubah; kunci manual harus disimpan ulang.':
  (active?'Mengoreksi menggunakan kunci manual guru. Scan dan nilai resmi menggunakan sumber ini.':'Mengoreksi menggunakan kunci asli Paket Ujian.');
}
function guardManualReady(){
 if(!pkg||!examId)throw Error('Pilih Paket Ujian atau sesi kunci manual terlebih dahulu.');
 if(!isStandalone()&&manualDirty)throw Error('Perubahan sumber/kunci jawaban belum disimpan. Simpan pilihan kunci sebelum memindai atau mengirim ke PWA.');
 if(!isStandalone()&&pkg.manual_key?.stale)throw Error('Kunci manual usang. Perbarui dan simpan dahulu.');
}
$('ljk-key-source').addEventListener('change',async e=>{
 if(!pkg)return;
 if(e.target.value==='manual'){
   $('ljk-manual-key-editor').classList.remove('hidden');manualDirty=true;
   $('ljk-manual-key-status').textContent='Isi kunci lalu klik Simpan & Gunakan Kunci Manual.';
   return;
 }
 if(!confirm('Gunakan kembali kunci asli Paket Ujian untuk pemindaian berikutnya? Hasil yang sudah dipindai tetap memakai kunci sebelumnya.')){
   e.target.value=pkg.manual_key?.source||'package';return;
 }
 try{const cfg=await jf(urls.manual+'/'+encodeURIComponent(examId),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({source:'package'})});
   pkg.manual_key=cfg;displayManualEditor();alert('Kunci Paket Ujian aktif. Hasil scan sebelumnya tidak diubah.');
 }catch(err){e.target.value=pkg.manual_key?.source||'package';alert(err.message);}
});
$('ljk-manual-key-grid').addEventListener('change',event=>{
 // PG dan Benar/Salah hanya satu jawaban; pilihan masih bisa dikosongkan kembali.
 if(event.target.matches('.ljk-manual-single-choice')&&event.target.checked){
   const row=event.target.closest('.ljk-key-row');
   row?.querySelectorAll('.ljk-manual-single-choice').forEach(box=>{if(box!==event.target)box.checked=false;});
 }
 manualDirty=true;
 $('ljk-manual-key-status').textContent='Kunci berubah; simpan sebelum memindai.';
});
$('ljk-manual-key-delete').addEventListener('click',async()=>{
 if(!examId||isStandalone()||!pkg||!pkg.manual_key?.has_manual_key)return;
 if(processing)return alert('Selesaikan koreksi yang berjalan dahulu.');
 if(!confirm('Hapus kunci manual untuk Paket Ujian ini?\n\nKunci asli Paket Ujian akan dipakai kembali. Hasil koreksi dan nilai yang sudah disimpan tetap ada.'))return;
 const btn=$('ljk-manual-key-delete');btn.disabled=true;
 try{
  const cfg=await jf(urls.manual+'/'+encodeURIComponent(examId),{method:'DELETE'});
  pkg.manual_key=cfg;manualDirty=false;displayManualEditor();render();
  const warning=cfg.pwa_sync_warning?'\nPerhatian: kirim ulang Paket Ujian ke PWA Guru: '+cfg.pwa_sync_warning:'';
  alert('Kunci manual dihapus. Koreksi berikutnya menggunakan kunci asli Paket Ujian.'+warning);
 }catch(error){alert('Hapus kunci manual gagal: '+error.message);}
 finally{displayManualEditor();}
});
$('ljk-manual-key-save').addEventListener('click',async()=>{
 if(!examId||!pkg)return alert('Pilih Paket Ujian terlebih dahulu.');
 try{
   const cfg=await jf(urls.manual+'/'+encodeURIComponent(examId),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({source:'manual',keys:readManualKeys()})});
   pkg.manual_key=cfg;displayManualEditor();
   alert('Kunci manual disimpan di laptop guru. Gunakan Kirim ke PWA Guru untuk menyinkronkan paket privat dengan kunci ini. Hasil scan lama tidak berubah.');
 }catch(err){alert('Kunci manual tidak dapat disimpan: '+err.message);}
});
// V13 — sesi koreksi mandiri. Paket Ujian tidak perlu dibuat.
function standaloneGrid(){
 const n=Math.max(1,Math.min(50,Number($('ljk-standalone-count').value)||50));
 $('ljk-standalone-count').value=n;
 const old=Array.from($('ljk-standalone-grid').querySelectorAll('[data-manual-create-num]')).map(el=>({
  n:Number(el.dataset.manualCreateNum),type:el.querySelector('select[data-type]')?.value||'pilihan_ganda',
  values:Array.from(el.querySelectorAll('[data-answer]:checked')).map(x=>x.value)
 }));
 $('ljk-standalone-grid').innerHTML=manualColumns(Array.from({length:n},(_,idx)=>idx+1),i=>{
  const previous=old.find(x=>x.n===i);const typ=previous?.type||'pilihan_ganda';
  const allowed=typ==='benar_salah'?['A','B']:['A','B','C','D','E'];
  const checked=(previous?.values||[]).filter(v=>allowed.includes(v));
  return `<div data-manual-create-num="${i}" class="ljk-key-row">
    <div class="ljk-key-row-inline"><span class="ljk-key-num">${i}.</span>
    <select data-type aria-label="Jenis soal nomor ${i}" class="ljk-key-type">
       <option value="pilihan_ganda" ${typ==='pilihan_ganda'?'selected':''}>PG</option>
       <option value="majemuk" ${typ==='majemuk'?'selected':''}>Majemuk</option>
       <option value="benar_salah" ${typ==='benar_salah'?'selected':''}>B/S</option>
    </select>
    <div class="ljk-key-choices" role="group" aria-label="Kunci jawaban nomor ${i}">${['A','B','C','D','E'].map(a=>`<label class="${allowed.includes(a)?'':'ljk-key-disabled'}"><input data-answer type="checkbox" aria-label="Nomor ${i} pilihan ${a}" value="${a}" ${checked.includes(a)?'checked':''} ${allowed.includes(a)?'':'disabled'}>${a}</label>`).join('')}</div>
    </div>
   </div>`;
 });
}
$('ljk-standalone-grid').addEventListener('change',event=>{
 const card=event.target.closest('[data-manual-create-num]');if(!card)return;
 const type=card.querySelector('[data-type]')?.value||'pilihan_ganda';
 const choices=Array.from(card.querySelectorAll('[data-answer]'));
 choices.forEach(box=>{
  box.disabled=type==='benar_salah'&&!['A','B'].includes(box.value);
  if(box.disabled)box.checked=false;
  box.closest('label')?.classList.toggle('ljk-key-disabled',box.disabled);
 });
 if(type!=='majemuk'){
  const chosen=choices.filter(box=>box.checked);
  const keep=event.target.matches('[data-answer]')&&event.target.checked?event.target:chosen[0];
  choices.forEach(box=>{if(box!==keep)box.checked=false;});
 }
});
function createManualKeys(){
 return Array.from($('ljk-standalone-grid').querySelectorAll('[data-manual-create-num]')).map((card)=>{
  const type=card.querySelector('[data-type]').value;
  const choices=Array.from(card.querySelectorAll('[data-answer]:checked')).map(c=>c.value);
  const number=card.dataset.manualCreateNum;
  if(!choices.length)throw Error(`Nomor ${number} belum memiliki kunci.`);
  if(type!=='majemuk'&&choices.length!==1)throw Error(`Nomor ${number} hanya boleh satu kunci.`);
  if(type==='benar_salah'&&choices.some(c=>!['A','B'].includes(c)))throw Error(`Benar/Salah nomor ${number} hanya A atau B.`);
  return {type,answer:type==='majemuk'?choices:choices[0]};
 });
}
function updateStandaloneModes(){
 $('ljk-mode-package').className='eduxid-btn rounded-lg px-4 py-2 text-sm font-bold '+(!standaloneMode?'bg-blue-600 text-white':'border border-blue-200 bg-white text-blue-700');
 $('ljk-mode-standalone').className='eduxid-btn rounded-lg px-4 py-2 text-sm font-bold '+(standaloneMode?'bg-blue-600 text-white':'border border-blue-200 bg-white text-blue-700');
 $('ljk-standalone-panel').classList.toggle('hidden',!standaloneMode);
 $('ljk-package-mode').classList.toggle('hidden',standaloneMode);
 $('ljk-manual-key-section').classList.toggle('hidden',standaloneMode);
}
async function standaloneList(){
 const data=await jf(urls.standalone);
 $('ljk-standalone-assignment').innerHTML='<option value="">Pilih Mapel dan Rombel...</option>'+
  (data.assignments||[]).map(a=>`<option value="${Number(a.id)}">${esc(a.subject_name||'Mapel')} · ${esc(a.class_name||'Kelas')}</option>`).join('');
 const sel=$('ljk-standalone-sessions');const last=sel.value;
 sel.innerHTML='<option value="">Pilih sesi tersimpan...</option>'+(data.sessions||[]).map(s=>
  `<option value="${esc(s.session_id)}">${esc(s.title)} · ${esc(s.question_count)} soal · ${esc(s.tanggal)}</option>`).join('');
 if((data.sessions||[]).some(s=>s.session_id===last))sel.value=last;
 $('ljk-standalone-delete').disabled=processing||!sel.value;
 standaloneLoaded=true;
}
async function openStandalone(id){
 if(!String(id||'').startsWith('manual::'))return;
 const detail=await jf(urls.standalone+'/'+encodeURIComponent(id));
 examId=id;pkg={
  exam:{id:null,sync_id:id,title:detail.title,question_count:detail.question_count,
    homeroom_id:detail.homeroom_id},
  manual_session_id:id,manual_key_fingerprint:detail.key_fingerprint,
  questions:(detail.keys||[]).map((k,i)=>({question_bank_id:i+1,number:i+1,
    type:k.type,jenis_soal:k.type,weight:1,kunci_jawaban:k.answer})),
  students:detail.students||[],
  manual_key:{source:'manual',stale:false,keys:Object.fromEntries((detail.keys||[]).map((k,i)=>[String(i+1),k.answer]))}
 };
 standaloneMode=true;manualDirty=false;rows=[];reviewOnly=false;
 updateStandaloneModes();displayManualEditor();
 $('ljk-standalone-sessions').value=id;
 $('ljk-standalone-delete').disabled=false;
 const meta=$('ljk-package-meta');if(meta)meta.textContent=`Kunci Manual ${detail.question_count} soal · ${detail.title}`;
 $('ljk-progress').textContent=`Sesi manual ${detail.question_count} soal aktif. Pemindaian dan koreksi siap.`;
 await refresh();
}
$('ljk-mode-standalone').onclick=async()=>{
 standaloneMode=true;examId='';pkg=null;rows=[];
 updateStandaloneModes();displayManualEditor();render();
 if(!standaloneLoaded)try{await standaloneList();}catch(e){$('ljk-standalone-status').textContent=e.message;}
};
$('ljk-mode-package').onclick=()=>{
 standaloneMode=false;examId='';pkg=null;rows=[];
 $('ljk-exam').value='';updateStandaloneModes();displayManualEditor();render();
};
$('ljk-standalone-count').addEventListener('change',standaloneGrid);
$('ljk-standalone-grid').addEventListener('change',e=>{
 if(e.target.matches('select[data-type]')){
   const card=e.target.closest('[data-manual-create-num]');
   if(e.target.value==='benar_salah')card.querySelectorAll('[data-answer]').forEach(x=>{if(x.value!=='A'&&x.value!=='B')x.checked=false;});
 }
 if(e.target.matches('[data-answer]')&&e.target.checked){
   const card=e.target.closest('[data-manual-create-num]');
   if(card.querySelector('[data-type]').value!=='majemuk'){
     card.querySelectorAll('[data-answer]').forEach(x=>{if(x!==e.target)x.checked=false;});
   }
 }
});
$('ljk-standalone-create').onclick=async()=>{
 const b=$('ljk-standalone-create');
 try{
  const keys=createManualKeys();
  const payload={teaching_assignment_id:Number($('ljk-standalone-assignment').value),
    title:$('ljk-standalone-title').value,tanggal:$('ljk-standalone-date').value,
    jenis_penilaian:$('ljk-standalone-type').value,keys};
  if(!payload.teaching_assignment_id)throw Error('Pilih penugasan mengajar dahulu.');
  b.disabled=true;b.textContent='Menyimpan sesi...';
  const created=await jf(urls.standalone,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  await standaloneList();await openStandalone(created.session_id);
  $('ljk-standalone-status').textContent=created.pwa_sync_warning
    ? 'Sesi tersimpan; pengiriman otomatis PWA belum berhasil: '+created.pwa_sync_warning+' Gunakan Kirim ke PWA Guru untuk mencoba lagi.'
    : 'Sesi tersimpan. Kunci otomatis dimasukkan ke antrean PWA Guru. Gunakan tombol Kirim ke PWA Guru untuk mengulangi jika diperlukan.';
 }catch(e){alert('Tidak dapat membuat sesi: '+e.message);}
 finally{b.disabled=false;b.textContent='Simpan Sesi dan Aktifkan';}
};
$('ljk-standalone-delete').addEventListener('click',async()=>{
 const id=$('ljk-standalone-sessions').value;
 if(!id||!id.startsWith('manual::'))return;
 if(processing)return alert('Selesaikan proses koreksi yang berjalan dahulu.');
 const label=$('ljk-standalone-sessions').selectedOptions[0]?.textContent||'sesi ini';
 if(!confirm('Hapus sesi dan seluruh kunci manual berikut?\n'+label+'\n\nHasil scan akan diarsipkan, kunci dicabut dari PWA Guru, tetapi nilai yang sudah masuk Buku Nilai tidak dihapus.'))return;
 const button=$('ljk-standalone-delete');button.disabled=true;
 try{
  const result=await jf(urls.standalone+'/'+encodeURIComponent(id),{method:'DELETE'});
  if(examId===id){examId='';pkg=null;rows=[];reviewOnly=false;render();}
  $('ljk-standalone-sessions').value='';await standaloneList();
  $('ljk-standalone-status').textContent='Sesi dan kunci manual berhasil dihapus dari daftar aktif.';
  const pending=result.pwa_sync?.status==='pending'?' Penghapusan PWA dalam antrean dan akan diselesaikan saat sinkron.':'';
  alert('Sesi dihapus. '+result.archived_results+' hasil diarsipkan, '+result.preserved_published+' nilai terpublikasi dipertahankan.'+pending);
 }catch(error){alert('Hapus sesi gagal: '+error.message);}
 finally{button.disabled=!$('ljk-standalone-sessions').value;}
});
$('ljk-standalone-sessions').addEventListener('change',async e=>{
 $('ljk-standalone-delete').disabled=!e.target.value;
 if(!e.target.value)return;
 try{await openStandalone(e.target.value);}catch(err){alert(err.message);}
});
standaloneGrid();
$('ljk-exam').addEventListener('change',async e=>{standaloneMode=false;updateStandaloneModes();examId=e.target.value;const u=new URL(location.href);if(examId)u.searchParams.set('exam',examId);else u.searchParams.delete('exam');history.replaceState(null,'',u.pathname+u.search+u.hash);pkg=null;rows=[];manualDirty=false;displayManualEditor();render();if(!examId)return;try{pkg=await jf(urls.package+'/'+encodeURIComponent(examId));displayManualEditor();await refresh();$('ljk-progress').textContent=`${pkg.exam.question_count} soal siap. Ambil foto atau impor hasil Pemindai.`;const meta=$('ljk-package-meta');if(meta)meta.textContent=`Paket aktif · ${pkg.exam.question_count} soal · peserta dan kunci siap untuk koreksi.`;}catch(x){alert(x.message);}});
for(const id of ['ljk-camera','ljk-images'])$(id).addEventListener('change',async e=>{const fs=Array.from(e.target.files||[]);e.target.value='';if(!fs.length)return;try{await process(fs.map((file,i)=>({file,name:file.name,index:i+1})),id==='ljk-camera'?'camera':'image');}catch(x){alert(x.message);}});
$('ljk-zip').addEventListener('change',async e=>{const file=e.target.files?.[0];e.target.value='';if(!file)return;try{if(!examId)throw Error('Pilih Paket Ujian atau sesi kunci manual terlebih dahulu.');const fd=new FormData();fd.set('file',file);const r=await fetch(urls.zip,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf},body:fd});const b=await r.json().catch(()=>null);if(!r.ok||!b?.success)throw Error(b?.message||'Import ZIP gagal.');await process(b.data.items||[],'zip');}catch(x){alert(x.message);}});
$('ljk-pdf').addEventListener('change',async e=>{
 const file=e.target.files?.[0];e.target.value='';if(!file)return;
 try{
   if(!examId)throw Error('Pilih Paket Ujian atau sesi kunci manual terlebih dahulu.');
   let items=await pdfItems(file);
   if(!items){
     $('ljk-progress').textContent='PDF.js belum tersedia; mencoba rasterizer lokal server...';
     const fd=new FormData();fd.set('file',file);
     const r=await fetch(urls.pdf,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf},body:fd});
     const b=await r.json().catch(()=>null);
     if(!r.ok||!b?.success){
       throw Error(
         (b?.message||'Import PDF gagal.')+
         ' Patch v14R12 akan memakai PDF.js lokal bila file library berhasil dipasang.'
       );
     }
     items=b.data.items||[];
   }
   await process(items,'pdf');
 }catch(x){
   $('ljk-progress').textContent='Import PDF gagal: '+x.message;
   alert(x.message);
 }
});
const dlResults=$('ljk-download-results');if(dlResults)dlResults.onclick=downloadResults;
$('ljk-item-analysis').onclick=()=>{
 const button=$('ljk-item-analysis');
 if(button.disabled||!examId)return;
 const base=isStandalone()?button.dataset.analysisManual:button.dataset.analysisBase;
 const target=isStandalone()?base.replace('__SESSION__',encodeURIComponent(examId)):base.replace('__EXAM__',encodeURIComponent(examId))+'?source=ljk';
 location.href=target;
};
$('ljk-only-review').onclick=()=>{reviewOnly=!reviewOnly;$('ljk-only-review').textContent=reviewOnly?'Lihat Semua Hasil':'Lihat yang Perlu Dicek';render();};
$('ljk-results-body').addEventListener('click',async e=>{
 const ed=e.target.closest('[data-edit]'),del=e.target.closest('[data-delete]');
 if(ed){
   editRow=rows.find(r=>r.scan_id===ed.dataset.edit);if(!editRow)return;
   $('ljk-review-nisn').value=editRow.nisn||'';
   initReviewAnswers(editRow);
   renderReviewAnswers();
   const empty=$('ljk-review-image-empty');
   if(editRow.has_image){
     $('ljk-review-image-wrap').classList.remove('hidden');
     if(empty)empty.classList.add('hidden');
     $('ljk-review-image').src=(isStandalone()?standalonePath()+'/results/':urls.result+'/')+encodeURIComponent(editRow.scan_id)+'/image?ts='+Date.now();
   }else{
     $('ljk-review-image-wrap').classList.add('hidden');
     if(empty)empty.classList.remove('hidden');
   }
   $('ljk-review-modal').classList.remove('hidden');
 }
 if(del){
   if(!confirm('Hapus hasil koreksi LJK ini?'))return;
   try{await jf((isStandalone()?standalonePath()+'/results/':urls.result+'/')+encodeURIComponent(del.dataset.delete),{method:'DELETE'});await refresh();}catch(x){alert(x.message);}
 }
});
$('ljk-review-answer-grid').addEventListener('click',e=>{
 const btn=e.target.closest('[data-review-q]');if(!btn)return;
 const key=String(btn.dataset.reviewQ||'');
 const q=(pkg?.questions||[]).find(item=>questionKey(item)===key);
 if(!q)return;

 if(btn.dataset.reviewClear){
   reviewAnswers[key]=isMultipleQuestion(q)?[]:'';
   renderReviewAnswers();
   return;
 }

 const letter=String(btn.dataset.reviewChoice||'').toUpperCase();
 if(!/^[A-E]$/.test(letter))return;

 if(isMultipleQuestion(q)){
   const current=normalizeChoiceValue(reviewAnswers[key]);
   reviewAnswers[key]=current.includes(letter)
     ? current.filter(v=>v!==letter)
     : [...current,letter].sort();
 }else{
   const current=normalizeChoiceValue(reviewAnswers[key]);
   reviewAnswers[key]=(current.length===1&&current[0]===letter)?'':letter;
 }
 renderReviewAnswers();
});
function close(){editRow=null;reviewAnswers={};$('ljk-review-modal').classList.add('hidden');}
$('ljk-review-close').onclick=close;
$('ljk-review-cancel').onclick=close;
$('ljk-review-save').onclick=async()=>{
 if(!editRow)return;
 try{
   const answers=serializedReviewAnswers();
   const saved=await jf((isStandalone()?standalonePath()+'/results/':urls.result+'/')+encodeURIComponent(editRow.scan_id),{
     method:'PUT',
     headers:{'Content-Type':'application/json'},
     body:JSON.stringify({nisn:$('ljk-review-nisn').value,answers})
   });
   close();
   await refresh();
   const rep=saved.republish||null;
   let msg=`Perubahan tersimpan. ${saved.correct_count}/${saved.question_count} benar · nilai ${Number(saved.score||0).toFixed(0)}.`;
   if(rep){
     if(Number(rep.published||0)>0)msg+=' Nilai resmi di Buku Nilai juga sudah diperbarui.';
     if((rep.errors||[]).length)msg+=' Perubahan scan tersimpan, tetapi pembaruan Buku Nilai perlu diulang melalui tombol Kirim ke Buku Nilai: '+rep.errors.slice(0,2).join('; ');
     if((rep.warnings||[]).length)msg+=' Peringatan sinkronisasi: '+rep.warnings.slice(0,2).join('; ');
   }
   alert(msg);
 }catch(x){alert(x.message);}
};
$('ljk-clear-all').onclick=async()=>{
 if(!examId||processing)return;
 const count=rows.length;
 if(!count){alert('Belum ada hasil koreksi untuk Paket Ujian ini.');return;}
 if(!confirm(`Hapus semua hasil koreksi (${count} baris) pada Paket Ujian ini?\n\nHasil akan disembunyikan dari Hasil & Review. Nilai yang sudah masuk Buku Nilai TIDAK dihapus.`))return;
 const btn=$('ljk-clear-all');
 try{
   processing=true;render();btn.textContent='Menghapus hasil...';
   const result=await jf(isStandalone()?standalonePath()+'/results':urls.clear+'/'+encodeURIComponent(examId),{method:'DELETE'});
   $('ljk-live').classList.add('hidden');
   reviewOnly=false;$('ljk-only-review').textContent='Lihat yang Perlu Dicek';
   await refresh();
   alert(`Selesai. ${result.archived||0} hasil koreksi diarsipkan. ${result.published_preserved||0} hasil sudah memiliki nilai resmi yang tetap dipertahankan di Buku Nilai.`);
 }catch(x){alert('Hapus semua hasil koreksi gagal: '+x.message);}
 finally{processing=false;btn.textContent='🗑 Hapus Semua Hasil Koreksi';render();}
};
$('ljk-publish').onclick=async()=>{
 if(!examId){alert('Pilih Paket Ujian atau sesi kunci manual terlebih dahulu.');return;}
 const ready=rows.filter(r=>r.status==='success'&&!r.published);
 const review=rows.filter(r=>r.status==='review'&&!r.published);

 if(!ready.length){
   alert(review.length
     ? `Belum ada hasil berstatus Berhasil untuk dikirim. Masih ada ${review.length} hasil Perlu Dicek. Buka Edit, perbaiki NISN/jawaban, lalu Simpan Perubahan terlebih dahulu.`
     : 'Tidak ada hasil baru yang siap dikirim ke Buku Nilai.');
   return;
 }

 if(!confirm(`Kirim ${ready.length} hasil LJK berstatus Berhasil ke Buku Nilai? Nilai akan diproses melalui koreksi resmi eduXid.`))return;

 const btn=$('ljk-publish');
 try{
   btn.disabled=true;
   btn.textContent='Mengirim...';
   const d=await jf(isStandalone()?standalonePath()+'/publish':urls.publish+'/'+encodeURIComponent(examId),{
     method:'POST',
     headers:{'Content-Type':'application/json'},
     body:JSON.stringify({scan_ids:ready.map(r=>r.scan_id)})
   });
   const errors=d.errors||[],warnings=d.warnings||[];
   let msg=`Kirim ke Buku Nilai selesai.\nBerhasil: ${d.published||0}\nDilewati: ${d.skipped||0}\nError: ${errors.length}`;
   if(errors.length)msg+='\n\n'+errors.slice(0,6).join('\n');
   if(warnings.length)msg+='\n\nPeringatan:\n'+warnings.slice(0,4).join('\n');
   alert(msg);
   await refresh();
 }catch(x){
   alert('Kirim ke Buku Nilai gagal: '+x.message);
 }finally{
   btn.textContent='✓ Kirim Hasil ke Buku Nilai';
   render();
 }
};
$('ljk-sync-pwa').onclick=async()=>{if(!examId)return;try{guardManualReady();$('ljk-sync-pwa').disabled=true;const d=await jf(isStandalone()?standalonePath()+'/sync-pwa':urls.syncPwa+'/'+encodeURIComponent(examId),{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});alert('Kunci LJK privat masuk antrean sinkronisasi untuk PWA Guru: '+d.target_device_id);}catch(x){alert(x.message);}finally{render();}};
const calOpen=$('ljk-calibration-open'),calModal=$('ljk-calibration-modal');
function calFill(c){c=normalizeCalibration(c);$('ljk-cal-marker').value=c.marker_threshold;$('ljk-cal-bubble').value=c.bubble_threshold;$('ljk-cal-ambiguity').value=c.ambiguity_gap;$('ljk-cal-confidence').value=c.min_confidence;$('ljk-cal-perspective').checked=!!c.perspective_auto;}
function calRead(){return normalizeCalibration({marker_threshold:$('ljk-cal-marker').value,bubble_threshold:$('ljk-cal-bubble').value,ambiguity_gap:$('ljk-cal-ambiguity').value,min_confidence:$('ljk-cal-confidence').value,perspective_auto:$('ljk-cal-perspective').checked});}
function calClose(){calModal?.classList.add('hidden');}
if(calOpen)calOpen.onclick=()=>{calFill(loadCalibration());calModal?.classList.remove('hidden');};
if($('ljk-calibration-close'))$('ljk-calibration-close').onclick=calClose;
if($('ljk-calibration-cancel'))$('ljk-calibration-cancel').onclick=calClose;
if($('ljk-calibration-reset'))$('ljk-calibration-reset').onclick=()=>calFill(CAL_DEFAULT);
if($('ljk-calibration-save'))$('ljk-calibration-save').onclick=()=>{calibration=saveCalibration(calRead());calClose();const state=$('ljk-engine-state');if(state)state.textContent='Kalibrasi Pemindai tersimpan di laptop ini.';};

const sb=$('ljk-scroll-results');if(sb)sb.onclick=()=>$('ljk-results-section')?.scrollIntoView({behavior:'smooth',block:'start'});
render();if(initialExam){const sel=$('ljk-exam');const opt=sel?Array.from(sel.options).find(o=>String(o.value)===initialExam):null;if(sel&&opt&&!opt.disabled){sel.value=initialExam;sel.dispatchEvent(new Event('change'));}}
})();
