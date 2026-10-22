(() => {
'use strict';
window.EDUXID_LJK_LAPTOP_V14R11=true;
const root=document.getElementById('ljk-auto-v14-root'); if(!root) return;
const $=id=>document.getElementById(id); const csrf=root.dataset.csrf;const initialExam=String(root.dataset.initialExam||new URLSearchParams(location.search).get('exam')||'').trim();
const urls={package:root.dataset.packageUrl,results:root.dataset.resultsUrl,store:root.dataset.storeUrl,result:root.dataset.resultBase,publish:root.dataset.publishBase,syncPwa:root.dataset.syncPwaBase,zip:root.dataset.zipUrl,pdf:root.dataset.pdfUrl};
let examId='',pkg=null,rows=[],reviewOnly=false,processing=false,editRow=null,reviewAnswers={};
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const uid=()=>crypto.randomUUID?crypto.randomUUID():Date.now()+'-'+Math.random().toString(16).slice(2);
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
 const options={template:'LJK-A4-V3',auto:true,review:false,question_count:Number(pkg?.exam?.question_count||50),question_types:questionTypes,calibration,marker_threshold:calibration.marker_threshold,bubble_threshold:calibration.bubble_threshold,ambiguity_gap:calibration.ambiguity_gap,min_confidence:calibration.min_confidence,perspective_auto:true};

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
async function save(s,file,meta={}){const f=new FormData();f.set('scan_id',uid());f.set('exam_id',examId);f.set('exam_sync_id',pkg?.exam?.sync_id||'');f.set('nisn',s.nisn||'');f.set('student_name',s.student_name||'');f.set('answers',JSON.stringify(s.answers||{}));if(s.answer_confidence)f.set('answer_confidence',JSON.stringify(s.answer_confidence));f.set('confidence',String(Number.isFinite(s.confidence)?s.confidence:1));f.set('ambiguous_count',String(Number.isFinite(s.ambiguous_count)?s.ambiguous_count:0));f.set('marker_ok',s.marker_ok?'1':'0');f.set('scan_ok',s.scan_ok?'1':'0');if(s.detected_question_count!=null)f.set('detected_question_count',String(s.detected_question_count));f.set('template_version','LJK-A4-V3');f.set('source_type',meta.source_type||'image');f.set('source_name',meta.source_name||file?.name||'');if(meta.source_index!=null)f.set('source_index',String(meta.source_index));if(file)f.set('image',file,file.name||'ljk.jpg');const r=await fetch(urls.store,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf},body:f});const b=await r.json().catch(()=>null);if(!r.ok||!b?.success)throw Error(b?.message||'HTTP '+r.status);return b.data;}
function badge(r){const m={success:['Berhasil','bg-emerald-100 text-emerald-700'],review:['Perlu Dicek','bg-amber-100 text-amber-800'],duplicate:['Duplikat','bg-red-100 text-red-700'],failed:['Gagal','bg-red-100 text-red-700']};const [t,c]=m[r.status]||[r.status,'bg-gray-100 text-gray-700'];return `<span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold ${c}">${esc(t)}</span>`;}
function statusLabel(r){
 const m={success:'Berhasil',review:'Perlu Dicek',duplicate:'Duplikat',failed:'Gagal'};
 let text=m[r.status]||String(r.status||'');
 if(r.published)text+=(text?' · ':'')+'Sudah masuk Buku Nilai';
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
   const value=reviewAnswers[key]??'';
   const selected=normalizeChoiceValue(value);
   const unexpected=!multi&&selected.length>1;
   const buttons=['A','B','C','D','E'].map(letter=>{
     const active=answerSelected(value,letter);
     return `<button type="button" data-review-q="${esc(key)}" data-review-choice="${letter}" class="h-9 min-w-9 rounded-lg border px-2 text-xs font-black transition ${active?'border-indigo-600 bg-indigo-600 text-white shadow-sm':'border-slate-200 bg-white text-slate-600 hover:border-indigo-300 hover:bg-indigo-50'}">${letter}</button>`;
   }).join('');
   const badge=multi
     ? '<span class="rounded-full bg-violet-100 px-2 py-1 text-[10px] font-bold text-violet-700">Majemuk</span>'
     : '<span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-500">Tunggal</span>';
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
function downloadResults(){
 if(!examId){alert('Pilih Paket Ujian terlebih dahulu.');return;}
 const data=visibleRows();
 if(!data.length){alert('Belum ada hasil koreksi yang dapat diunduh.');return;}

 const examTitle=pkg?.exam?.title||pkg?.exam?.judul_ujian||pkg?.exam?.name||$('ljk-exam')?.selectedOptions?.[0]?.textContent||('Paket-'+examId);
 const lines=[];
 lines.push(['No.','Nama Murid','NISN','Skor Betul','Nilai','Status'].map(csvCell).join(';'));
 data.forEach((r,i)=>{
   const scoreCorrect=(r.correct_count??0)+'/'+(r.question_count??0);
   const nilai=Number(r.score??0);
   lines.push([
     i+1,
     r.student_name||'Belum dikenali',
     r.nisn||'',
     scoreCorrect,
     Number.isFinite(nilai)?nilai.toFixed(0):'',
     statusLabel(r)
   ].map(csvCell).join(';'));
 });

 const csv='\uFEFF'+lines.join('\r\n');
 const blob=new Blob([csv],{type:'text/csv;charset=utf-8;'});
 const url=URL.createObjectURL(blob);
 const a=document.createElement('a');
 const today=new Date().toISOString().slice(0,10);
 a.href=url;
 a.download='hasil-koreksi-ljk-'+safeFilename(examTitle)+'-'+today+'.csv';
 document.body.appendChild(a);
 a.click();
 a.remove();
 setTimeout(()=>URL.revokeObjectURL(url),1500);
}
function render(){
 const visible=visibleRows();
 $('ljk-results-body').innerHTML=visible.map((r,i)=>`<tr class="group transition hover:bg-slate-50/80"><td class="px-4 py-4 text-slate-400">${i+1}</td><td class="px-4 py-4"><div class="font-bold text-slate-900">${esc(r.student_name||'Belum dikenali')}</div>${r.source_name?`<div class="mt-1 max-w-[240px] truncate text-[11px] text-slate-400">${esc(r.source_name)}</div>`:''}</td><td class="px-4 py-4 font-mono text-xs text-slate-600">${esc(r.nisn||'-')}</td><td class="px-4 py-4"><span class="font-extrabold text-slate-800">${r.correct_count}/${r.question_count}</span><div class="mt-1 text-[11px] text-slate-400">jawaban benar</div></td><td class="px-4 py-4"><span class="inline-flex min-w-12 justify-center rounded-xl bg-slate-900 px-3 py-1.5 text-base font-black text-white">${Number(r.score||0).toFixed(0)}</span></td><td class="px-4 py-4">${badge(r)}${r.published?'<div class="mt-1.5 text-[11px] font-semibold text-emerald-600">✓ Sudah masuk Buku Nilai</div>':''}${r.issue_message?`<div class="mt-1.5 max-w-sm text-[11px] leading-4 text-slate-500">${esc(r.issue_message)}</div>`:''}</td><td class="px-4 py-4 text-right whitespace-nowrap">${String(r.scan_id).startsWith('failed-')?'':`<button data-edit="${esc(r.scan_id)}" class="rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-bold text-indigo-700 transition hover:bg-indigo-100">Edit</button><button data-delete="${esc(r.scan_id)}" class="ml-1 rounded-xl border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-bold text-rose-700 transition hover:bg-rose-100">Hapus</button>`}</td></tr>`).join('')||'<tr><td colspan="7" class="px-6 py-14 text-center"><div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-2xl">🧾</div><div class="mt-3 font-bold text-slate-700">Belum ada hasil koreksi</div><div class="mt-1 text-xs text-slate-400">Pilih Paket Ujian lalu pindai LJK dari kamera, gambar, PDF, atau ZIP.</div></td></tr>';
 const s=rows.filter(r=>r.status==='success').length,v=rows.filter(r=>r.status==='review').length,p=rows.filter(r=>['duplicate','failed'].includes(r.status)).length;
 $('sum-total').textContent=rows.length;$('sum-success').textContent=s;$('sum-review').textContent=v;$('sum-problem').textContent=p;
 const unpublished=rows.filter(r=>!r.published&&!['duplicate','failed'].includes(r.status));
 $('ljk-publish').disabled=!examId||unpublished.length===0;$('ljk-sync-pwa').disabled=!examId;
 const dl=$('ljk-download-results');if(dl)dl.disabled=!examId||visible.length===0;
}
function live(r){$('ljk-live').classList.remove('hidden');$('ljk-live-body').innerHTML=`<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><div class="text-lg font-extrabold text-slate-900">${esc(r.student_name||'Belum dikenali')}</div><div class="mt-1 font-mono text-xs text-slate-500">NISN ${esc(r.nisn||'-')}</div></div><div class="flex flex-wrap items-center gap-3"><div class="rounded-xl bg-white/80 px-3 py-2 text-sm text-slate-600 shadow-sm"><b class="text-slate-900">${r.correct_count}/${r.question_count}</b> benar</div><div class="rounded-xl bg-emerald-600 px-4 py-2 text-xl font-black text-white shadow-sm">${Number(r.score||0).toFixed(0)}</div><div>${badge(r)}</div></div></div>`;}
async function refresh(){if(!examId){rows=[];render();return;}rows=await jf(urls.results+'?exam_id='+encodeURIComponent(examId));render();}
async function process(items,type){
 if(!examId)throw Error('Pilih Paket Ujian terlebih dahulu.');
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

    $('ljk-progress').textContent=`[2/3] Mencocokkan ${answerCount} jawaban dengan kunci Paket Ujian...`;
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
async function loadPdfJs(){if(window.pdfjsLib)return window.pdfjsLib;for(const src of ['/pdfjs/pdf.min.js','/js/pdf.min.js','/vendor/pdfjs/pdf.min.js','/pdfjs-dist/build/pdf.min.js']){try{await loadScript(src);if(window.pdfjsLib)return window.pdfjsLib;}catch(_){}}return null;}
async function pdfItems(file){const lib=await loadPdfJs();if(!lib)return null;const pdf=await lib.getDocument({data:await file.arrayBuffer()}).promise;const out=[];for(let p=1;p<=pdf.numPages;p++){const page=await pdf.getPage(p),vp=page.getViewport({scale:2.25}),c=document.createElement('canvas');c.width=Math.ceil(vp.width);c.height=Math.ceil(vp.height);await page.render({canvasContext:c.getContext('2d',{alpha:false}),viewport:vp}).promise;const blob=await new Promise((res,rej)=>c.toBlob(b=>b?res(b):rej(Error('Konversi halaman PDF gagal.')),'image/png',0.95));out.push({file:new File([blob],`page-${String(p).padStart(4,'0')}.png`,{type:'image/png'}),name:`Halaman ${p}`,index:p});}return out;}
$('ljk-exam').addEventListener('change',async e=>{examId=e.target.value;const u=new URL(location.href);if(examId)u.searchParams.set('exam',examId);else u.searchParams.delete('exam');history.replaceState(null,'',u.pathname+u.search+u.hash);pkg=null;rows=[];render();if(!examId)return;try{pkg=await jf(urls.package+'/'+encodeURIComponent(examId));await refresh();$('ljk-progress').textContent=`${pkg.exam.question_count} soal siap. Ambil foto atau impor hasil Pemindai.`;const meta=$('ljk-package-meta');if(meta)meta.textContent=`Paket aktif · ${pkg.exam.question_count} soal · peserta dan kunci siap untuk koreksi.`;}catch(x){alert(x.message);}});
for(const id of ['ljk-camera','ljk-images'])$(id).addEventListener('change',async e=>{const fs=Array.from(e.target.files||[]);e.target.value='';if(!fs.length)return;try{await process(fs.map((file,i)=>({file,name:file.name,index:i+1})),id==='ljk-camera'?'camera':'image');}catch(x){alert(x.message);}});
$('ljk-zip').addEventListener('change',async e=>{const file=e.target.files?.[0];e.target.value='';if(!file)return;try{if(!examId)throw Error('Pilih Paket Ujian terlebih dahulu.');const fd=new FormData();fd.set('file',file);const r=await fetch(urls.zip,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf},body:fd});const b=await r.json().catch(()=>null);if(!r.ok||!b?.success)throw Error(b?.message||'Import ZIP gagal.');await process(b.data.items||[],'zip');}catch(x){alert(x.message);}});
$('ljk-pdf').addEventListener('change',async e=>{const file=e.target.files?.[0];e.target.value='';if(!file)return;try{if(!examId)throw Error('Pilih Paket Ujian terlebih dahulu.');let items=await pdfItems(file);if(!items){const fd=new FormData();fd.set('file',file);const r=await fetch(urls.pdf,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf},body:fd});const b=await r.json().catch(()=>null);if(!r.ok||!b?.success)throw Error(b?.message||'Import PDF gagal.');items=b.data.items||[];}await process(items,'pdf');}catch(x){alert(x.message);}});
const dlResults=$('ljk-download-results');if(dlResults)dlResults.onclick=downloadResults;
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
     $('ljk-review-image').src=urls.result+'/'+encodeURIComponent(editRow.scan_id)+'/image?ts='+Date.now();
   }else{
     $('ljk-review-image-wrap').classList.add('hidden');
     if(empty)empty.classList.remove('hidden');
   }
   $('ljk-review-modal').classList.remove('hidden');
 }
 if(del){
   if(!confirm('Hapus hasil koreksi LJK ini?'))return;
   try{await jf(urls.result+'/'+encodeURIComponent(del.dataset.delete),{method:'DELETE'});await refresh();}catch(x){alert(x.message);}
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
   const saved=await jf(urls.result+'/'+encodeURIComponent(editRow.scan_id),{
     method:'PUT',
     headers:{'Content-Type':'application/json'},
     body:JSON.stringify({nisn:$('ljk-review-nisn').value,answers})
   });
   close();
   await refresh();
   alert(`Perubahan tersimpan. ${saved.correct_count}/${saved.question_count} benar · nilai ${Number(saved.score||0).toFixed(0)}.`);
 }catch(x){alert(x.message);}
};
$('ljk-publish').onclick=async()=>{
 if(!examId){alert('Pilih Paket Ujian terlebih dahulu.');return;}
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
   const d=await jf(urls.publish+'/'+encodeURIComponent(examId),{
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
   btn.textContent='✓ Kirim ke Buku Nilai';
   render();
 }
};
$('ljk-sync-pwa').onclick=async()=>{if(!examId)return;try{$('ljk-sync-pwa').disabled=true;const d=await jf(urls.syncPwa+'/'+encodeURIComponent(examId),{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});alert('Paket LJK privat dikirim ke PWA Guru: '+d.target_device_id);}catch(x){alert(x.message);}finally{render();}};
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
render();if(initialExam){const sel=$('ljk-exam');if(sel&&Array.from(sel.options).some(o=>String(o.value)===initialExam)){sel.value=initialExam;sel.dispatchEvent(new Event('change'));}}
})();
