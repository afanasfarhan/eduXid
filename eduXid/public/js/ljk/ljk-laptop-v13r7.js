(() => {
  'use strict';

  const root = document.getElementById('ljk-laptop-v13r7');
  if (!root) return;

  const CAL = window.EDUXID_LJK_A4_V3;
  if (!CAL || CAL.template_id !== 'LJK-A4-V3') {
    console.error('[eduXid LJK Laptop v13R7] Kalibrasi LJK-A4-V3 tidak tersedia.');
    return;
  }

  const PAGE_W = Number(CAL.marker.warp_size_px.width);
  const PAGE_H = Number(CAL.marker.warp_size_px.height);
  const SAMPLE_RADIUS = Math.max(4, Math.round(Number(CAL.bubble.recommended_sample_radius_px || 13.5)));
  const $ = s => root.querySelector(s);
  const clamp = (v,a,b) => Math.max(a, Math.min(b,v));
  const examSelect = $('#ljk-exam');
  const fileInput = $('#ljk-file');
  const cameraBtn = $('#ljk-camera');
  const galleryBtn = $('#ljk-gallery');
  const processBtn = $('#ljk-process');
  const syncBtn = $('#ljk-sync-private');
  const saveBtn = $('#ljk-save');
  const preview = $('#ljk-preview');
  const canvas = $('#ljk-canvas');
  const statusBox = $('#ljk-status');
  const packageInfo = $('#ljk-package-info');
  const threshold = $('#ljk-threshold');
  const thresholdWrap = $('#ljk-threshold-wrap');
  const thresholdValue = $('#ljk-threshold-value');
  const resultBox = $('#ljk-result');
  const summary = $('#ljk-summary');
  const body = $('#ljk-result-body');
  const warning = $('#ljk-review-warning');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  let pkg = null;
  let file = null;
  let objectUrl = null;
  let lastResult = null;

  function urlFrom(template, key, value) {
    return String(template || '').replace(key, encodeURIComponent(String(value)));
  }
  function packageUrl(id) { return urlFrom(root.dataset.packageUrlTemplate, '__EXAM__', id); }
  function commitUrl(id) { return urlFrom(root.dataset.commitUrlTemplate, '__EXAM__', id); }
  function syncUrl(id) { return urlFrom(root.dataset.syncUrlTemplate, '__EXAM__', id); }
  function setStatus(text, type='') {
    statusBox.textContent = text;
    statusBox.className = 'rounded-lg border p-3 text-sm ' + (type === 'ok' ? 'bg-green-50 border-green-200 text-green-800' : type === 'err' ? 'bg-red-50 border-red-200 text-red-800' : type === 'warn' ? 'bg-amber-50 border-amber-200 text-amber-800' : 'bg-gray-50 border-gray-200 text-gray-700');
  }
  function normalizeSet(value) {
    const src = Array.isArray(value) ? value : String(value || '').toUpperCase().split(/[\s,;|]+/);
    return [...new Set(src.map(v => String(v).trim().toUpperCase()).filter(v => ['A','B','C','D','E'].includes(v)))].sort();
  }
  function isMultipleType(type, key) {
    return /majemuk|multiple|multi/i.test(String(type || '')) || normalizeSet(key).length > 1;
  }
  function compareAnswer(answer, key, type) {
    const a = normalizeSet(answer), k = normalizeSet(key);
    const multiple = isMultipleType(type, k);
    if (!multiple && a.length > 1) return { ok:false, invalid:true, a, k, multiple };
    return { ok:a.length > 0 && a.length === k.length && a.every((v,i)=>v===k[i]), invalid:false, a, k, multiple };
  }

  async function loadPackage() {
    const id = examSelect.value;
    pkg = null; lastResult = null; resultBox.classList.add('hidden');
    cameraBtn.disabled = galleryBtn.disabled = processBtn.disabled = syncBtn.disabled = !id;
    if (!id) { packageInfo.classList.add('hidden'); setStatus('Pilih Paket Ujian terlebih dahulu.'); return; }
    setStatus('Memuat kunci privat dan roster siswa dari laptop...');
    try {
      const r = await fetch(packageUrl(id), { headers:{Accept:'application/json'} });
      const j = await r.json();
      if (!r.ok || !j.success) throw new Error(j.message || 'Gagal memuat paket privat.');
      pkg = j.data;
      packageInfo.innerHTML = `<b>${escapeHtml(pkg.title)}</b> · ${pkg.question_count} soal · ${pkg.students?.length || 0} siswa pada roster · kunci privat tersedia.`;
      packageInfo.classList.remove('hidden');
      cameraBtn.disabled = galleryBtn.disabled = syncBtn.disabled = false;
      processBtn.disabled = !file;
      setStatus('Paket siap. Ambil foto atau pilih foto LJK-A4-V3.', 'ok');
    } catch (e) {
      setStatus('Gagal memuat paket: ' + e.message, 'err');
    }
  }

  function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }

  examSelect.addEventListener('change', loadPackage);
  cameraBtn.addEventListener('click', () => { fileInput.setAttribute('capture','environment'); fileInput.click(); });
  galleryBtn.addEventListener('click', () => { fileInput.removeAttribute('capture'); fileInput.click(); });
  fileInput.addEventListener('change', () => {
    file = fileInput.files?.[0] || null;
    if (!file) return;
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = URL.createObjectURL(file);
    preview.src = objectUrl; preview.classList.remove('hidden');
    processBtn.disabled = !pkg;
    setStatus('Foto siap. Tekan Proses Foto.', 'ok');
  });
  threshold.addEventListener('input', () => thresholdValue.textContent = threshold.value);
  threshold.addEventListener('change', () => { if (file && pkg) process(); });

  syncBtn.addEventListener('click', async () => {
    if (!pkg) return;
    syncBtn.disabled = true; setStatus('Mengirim paket privat ke PWA Guru...');
    try {
      const r = await fetch(syncUrl(pkg.exam_id), { method:'POST', headers:{'X-CSRF-TOKEN':csrf,Accept:'application/json'} });
      const j = await r.json();
      if (!r.ok || !j.success) throw new Error(j.message || 'Sinkronisasi paket privat gagal.');
      setStatus(j.message || 'Paket privat dikirim ke PWA Guru.', 'ok');
    } catch (e) { setStatus(e.message, 'err'); }
    finally { syncBtn.disabled = false; }
  });

  function grayData(c){const ctx=c.getContext('2d',{willReadFrequently:true}),im=ctx.getImageData(0,0,c.width,c.height),d=im.data,g=new Uint8Array(c.width*c.height);for(let i=0,j=0;i<d.length;i+=4,j++)g[j]=(d[i]*77+d[i+1]*150+d[i+2]*29)>>8;return g;}
  function integralDark(gray,w,h,cut=100){const iw=w+1,a=new Uint32Array((w+1)*(h+1));for(let y=1;y<=h;y++){let row=0;for(let x=1;x<=w;x++){row+=gray[(y-1)*w+(x-1)]<cut?1:0;a[y*iw+x]=a[(y-1)*iw+x]+row;}}return a;}
  function boxSum(ii,w,x,y,bw,bh){const iw=w+1,h=Math.floor(ii.length/iw)-1,x1=clamp(x,0,w),y1=clamp(y,0,h),x2=clamp(x+bw,0,w),y2=clamp(y+bh,0,h);return ii[y2*iw+x2]-ii[y1*iw+x2]-ii[y2*iw+x1]+ii[y1*iw+x1];}
  function findMarker(gray,w,h,quad){const ii=integralDark(gray,w,h,105),min=Math.min(w,h),sizes=[.018,.025,.033,.042].map(v=>Math.max(10,Math.round(min*v)));let x0=0,y0=0,x1=w,y1=h;const mx=Math.round(w*.42),my=Math.round(h*.36);if(quad==='tl'){x1=mx;y1=my}else if(quad==='tr'){x0=w-mx;y1=my}else if(quad==='bl'){x1=mx;y0=h-my}else{x0=w-mx;y0=h-my}let best=null;for(const s of sizes){const stride=Math.max(3,Math.round(s/4));for(let y=y0;y<=y1-s;y+=stride)for(let x=x0;x<=x1-s;x+=stride){const den=boxSum(ii,w,x,y,s,s)/(s*s);if(den<.48)continue;const score=den-((x<3||y<3||x+s>w-3||y+s>h-3)?.12:0);if(!best||score>best.score)best={x:x+s/2,y:y+s/2,size:s,score};}}return best;}
  function detectMarkers(c){const g=grayData(c),w=c.width,h=c.height,m=['tl','tr','bl','br'].map(q=>findMarker(g,w,h,q));if(m.some(x=>!x||x.score<.5))return null;return {tl:m[0],tr:m[1],bl:m[2],br:m[3],confidence:m.reduce((s,x)=>s+x.score,0)/4};}
  function solveLinear(A,b){const n=b.length;for(let i=0;i<n;i++){let p=i;for(let r=i+1;r<n;r++)if(Math.abs(A[r][i])>Math.abs(A[p][i]))p=r;if(Math.abs(A[p][i])<1e-10)throw new Error('Transformasi perspektif tidak stabil.');[A[i],A[p]]=[A[p],A[i]];[b[i],b[p]]=[b[p],b[i]];const d=A[i][i];for(let c=i;c<n;c++)A[i][c]/=d;b[i]/=d;for(let r=0;r<n;r++){if(r===i)continue;const f=A[r][i];for(let c=i;c<n;c++)A[r][c]-=f*A[i][c];b[r]-=f*b[i];}}return b;}
  function homography(dst,src){const A=[],b=[];for(let i=0;i<4;i++){const x=dst[i].x,y=dst[i].y,u=src[i].x,v=src[i].y;A.push([x,y,1,0,0,0,-u*x,-u*y]);b.push(u);A.push([0,0,0,x,y,1,-v*x,-v*y]);b.push(v);}const h=solveLinear(A,b);return [...h,1];}
  function warpPerspective(src,m){const out=document.createElement('canvas');out.width=PAGE_W;out.height=PAGE_H;const sim=src.getContext('2d',{willReadFrequently:true}).getImageData(0,0,src.width,src.height),od=new Uint8ClampedArray(PAGE_W*PAGE_H*4),d=CAL.marker.destination_marker_centers_px,dst=[{x:d.top_left[0],y:d.top_left[1]},{x:d.top_right[0],y:d.top_right[1]},{x:d.bottom_left[0],y:d.bottom_left[1]},{x:d.bottom_right[0],y:d.bottom_right[1]}],H=homography(dst,[m.tl,m.tr,m.bl,m.br]),sd=sim.data,sw=src.width,sh=src.height;for(let y=0;y<PAGE_H;y++)for(let x=0;x<PAGE_W;x++){const den=H[6]*x+H[7]*y+H[8],sx=(H[0]*x+H[1]*y+H[2])/den,sy=(H[3]*x+H[4]*y+H[5])/den,ix=Math.round(sx),iy=Math.round(sy),di=(y*PAGE_W+x)*4;if(ix>=0&&iy>=0&&ix<sw&&iy<sh){const si=(iy*sw+ix)*4;od[di]=sd[si];od[di+1]=sd[si+1];od[di+2]=sd[si+2];od[di+3]=255}else{od[di]=od[di+1]=od[di+2]=255;od[di+3]=255;}}out.getContext('2d').putImageData(new ImageData(od,PAGE_W,PAGE_H),0,0);return out;}
  function darknessAt(gray,w,h,cx,cy,r){let sum=0,n=0,rr=Math.max(2,Math.round(r));for(let dy=-rr;dy<=rr;dy++)for(let dx=-rr;dx<=rr;dx++){if(dx*dx+dy*dy>rr*rr)continue;const x=Math.round(cx+dx),y=Math.round(cy+dy);if(x<0||y<0||x>=w||y>=h)continue;sum+=255-gray[y*w+x];n++;}return n?sum/n:0;}
  function bubbleScore(gray,w,h,p){return darknessAt(gray,w,h,Number(p[0]),Number(p[1]),SAMPLE_RADIUS);}
  function readNisn(gray,w,h,t){const cols=10,rows=10,pts=CAL.nisn.centers_warp_px||[];let out='';const details=[];for(let c=0;c<cols;c++){const scores=[];for(let d=0;d<rows;d++)scores.push(bubbleScore(gray,w,h,pts[d*cols+c]));const rank=scores.map((score,digit)=>({score,digit})).sort((a,b)=>b.score-a.score),best=rank[0],second=rank[1],delta=best.score-second.score,ok=best.score>=t&&delta>=6;out+=ok?String(best.digit):'?';details.push({column:c+1,digit:ok?best.digit:null,scores,best:best.score,second:second.score,delta});}return {nisn:out,details};}
  function readAnswerPoints(gray,w,h,points,t){const scores=points.map(p=>bubbleScore(gray,w,h,p)),sorted=[...scores].sort((a,b)=>a-b),baseline=sorted[Math.floor(sorted.length/2)]||0,adaptive=Math.max(Number(t),baseline+6),selected=[];scores.forEach((score,i)=>{if(score>=adaptive)selected.push(CAL.answers.options[i]||String.fromCharCode(65+i));});return {selected, value:selected.join(','),scores,baseline,adaptive,multiple:selected.length>1};}
  function readAnswers(gray,w,h,t,count){const out=[],left=CAL.answers.left_centers_warp_px||[],right=CAL.answers.right_centers_warp_px||[],max=Math.min(50,count||50);for(let q=1;q<=max;q++){const row=q<=25?q-1:q-26,src=q<=25?left:right;out.push(readAnswerPoints(gray,w,h,src.slice(row*5,row*5+5),t));}return out;}
  async function bitmap(f){if(window.createImageBitmap)try{return await createImageBitmap(f,{imageOrientation:'from-image'});}catch(_){try{return await createImageBitmap(f)}catch(__){}}return await new Promise((resolve,reject)=>{const i=new Image(),u=URL.createObjectURL(f);i.onload=()=>{URL.revokeObjectURL(u);resolve(i)};i.onerror=reject;i.src=u;});}
  function sourceCanvas(b,max=1500){const s=Math.min(1,max/Math.max(b.width,b.height)),c=document.createElement('canvas');c.width=Math.round(b.width*s);c.height=Math.round(b.height*s);c.getContext('2d',{willReadFrequently:true}).drawImage(b,0,0,c.width,c.height);return c;}

  function resolveStudent(nisn) {
    if (!/^\d{10}$/.test(nisn)) return null;
    return (pkg?.students || []).find(s => String(s.nisn || '') === nisn) || null;
  }
  function gradeRows(answers) {
    const qs = pkg?.questions || [];
    let correct=0, graded=0, invalid=0;
    const rows=answers.map((a,i)=>{
      const q=qs[i] || {no:i+1,type:'',key:[]};
      const cmp=compareAnswer(a.selected,q.key,q.type);
      if (cmp.invalid) invalid++;
      if (cmp.k.length) { graded++; if(cmp.ok) correct++; }
      return {...cmp,no:i+1,type:q.type||'',answer:a.value||'',key:cmp.k.join(',')};
    });
    return {rows,correct,graded,invalid,score:graded?Math.round(correct/graded*10000)/100:0};
  }

  async function process() {
    if (!file || !pkg) return;
    processBtn.disabled=true; saveBtn.disabled=true; resultBox.classList.add('hidden');
    try {
      setStatus('Mendeteksi empat marker dan menormalkan perspektif...');
      const b=await bitmap(file),src=sourceCanvas(b),markers=detectMarkers(src);if(b.close)try{b.close()}catch(_){}
      if(!markers)throw new Error('Empat marker sudut tidak terdeteksi. Foto ulang dengan seluruh area marker terlihat dan cahaya merata.');
      const warped=warpPerspective(src,markers),gray=grayData(warped),t=Number(threshold.value||45);
      setStatus('Membaca NISN dan semua bubble jawaban...');
      const nisn=readNisn(gray,PAGE_W,PAGE_H,t),student=resolveStudent(nisn.nisn),answers=readAnswers(gray,PAGE_W,PAGE_H,t,pkg.question_count),grade=gradeRows(answers);
      canvas.width=PAGE_W;canvas.height=PAGE_H;canvas.getContext('2d').drawImage(warped,0,0);canvas.classList.remove('hidden');thresholdWrap.classList.remove('hidden');
      lastResult={nisn:nisn.nisn,student,answers,grade,markerConfidence:markers.confidence};
      renderResult();
      setStatus(student ? `Pembacaan selesai: ${student.name} (${nisn.nisn}), nilai sementara ${grade.score}. Periksa hasil lalu Simpan Nilai.` : `NISN ${nisn.nisn} terbaca tetapi nama siswa tidak ditemukan pada roster paket. Jangan simpan sebelum data siswa benar.`, student?'ok':'warn');
    } catch(e) { setStatus('Proses koreksi gagal: '+e.message,'err'); }
    finally { processBtn.disabled=false; }
  }

  function renderResult() {
    if (!lastResult) return;
    const g=lastResult.grade,s=lastResult.student;
    summary.innerHTML=[
      ['NISN',lastResult.nisn],['Nama Siswa',s?.name || 'Tidak ditemukan'],['Nilai',g.score],['Benar',`${g.correct}/${g.graded}`],['Marker',`${Math.round(lastResult.markerConfidence*100)}%`]
    ].map(([a,b])=>`<div class="bg-white border rounded-lg p-3"><small class="text-gray-500">${escapeHtml(a)}</small><b class="block text-base text-gray-800">${escapeHtml(b)}</b></div>`).join('');
    body.innerHTML=g.rows.map((r,i)=>`<tr data-row="${i}"><td class="p-2 border text-center">${i+1}</td><td class="p-2 border text-center">${escapeHtml(r.type)}</td><td class="p-2 border"><input class="ljk-answer-edit w-28 rounded border-gray-300 text-sm" data-i="${i}" value="${escapeHtml(r.answer)}" placeholder="A,B"></td><td class="p-2 border text-center">${escapeHtml(r.key)}</td><td class="p-2 border text-center">${r.invalid?'<span class="text-amber-700 font-semibold">Ganda tidak valid</span>':r.ok?'<span class="text-green-700 font-semibold">Benar</span>':'<span class="text-red-700 font-semibold">Salah</span>'}</td></tr>`).join('');
    body.querySelectorAll('.ljk-answer-edit').forEach(input=>input.addEventListener('change',()=>{
      const i=Number(input.dataset.i),set=normalizeSet(input.value);lastResult.answers[i].selected=set;lastResult.answers[i].value=set.join(',');lastResult.grade=gradeRows(lastResult.answers);renderResult();
    }));
    const problems=[];
    if(!s)problems.push('Nama siswa belum ditemukan.');
    if(lastResult.nisn.includes('?'))problems.push('NISN belum terbaca lengkap.');
    if(g.invalid)problems.push(`${g.invalid} soal pilihan ganda biasa memiliki isian ganda.`);
    warning.textContent=problems.join(' ');
    saveBtn.disabled=problems.length>0;
    resultBox.classList.remove('hidden');
  }

  processBtn.addEventListener('click', process);
  saveBtn.addEventListener('click', async()=>{
    if(!lastResult||!pkg||saveBtn.disabled)return;
    saveBtn.disabled=true;setStatus('Menyimpan nilai melalui kontrak koreksi CBT resmi...');
    try{
      const answers=lastResult.answers.map(a=>normalizeSet(a.selected));
      const r=await fetch(commitUrl(pkg.exam_id),{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({nisn:lastResult.nisn,answers})});
      const j=await r.json();if(!r.ok||!j.success)throw new Error(j.message||'Gagal menyimpan nilai.');
      setStatus(`Nilai tersimpan: ${j.data.name} (${j.data.nisn}) · nilai ${j.data.score} · benar ${j.data.correct_count}/${j.data.total_questions}.`,'ok');
      saveBtn.disabled=true;
    }catch(e){setStatus(e.message,'err');saveBtn.disabled=false;}
  });

  console.info('[eduXid LJK Laptop v13R7] aktif', {template:CAL.template_id, warp:[PAGE_W,PAGE_H]});
})();
