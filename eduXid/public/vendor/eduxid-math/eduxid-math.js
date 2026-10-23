/* eduXid V21R181: equation-editor bridge. No CDN or external calls.
 * MathLive is an optional MIT vendor installed locally to /vendor/mathlive/.
 * Both the MathLive math-field and manual LaTeX insert the SAME `$...$` source;
 * KaTeX remains the renderer for preview/print/CBT. Never overwrite saved data.
 */
(() => {
  'use strict';
  if (window.eduXidEquationEditor) return;
  const PREFIX = '/vendor/mathlive/';
  const targetSelector = 'textarea[name="pertanyaan"],textarea[name^="pertanyaan["],textarea[name="tka_stimulus"],textarea[name="tka_pernyataan"],textarea[name="pembahasan"],textarea[name^="opsi"],input[type="text"][name^="opsi["],input[type="text"][name="kunci_singkat"]';
  const P = [
    ['Pecahan',String.raw`\frac{a}{b}`], ['Akar',String.raw`\sqrt{x}`],
    ['Pangkat',String.raw`x^{2}`], ['Indeks',String.raw`x_{1}`],
    ['Integral',String.raw`\int_a^b f(x)\,dx`], ['Sigma',String.raw`\sum_{i=1}^{n}i`],
    ['Vektor',String.raw`\vec{F}`], ['Theta',String.raw`\theta`],
    ['Delta',String.raw`\Delta`], ['Pi',String.raw`\pi`],
    ['Matriks',String.raw`\begin{pmatrix}a&b\\c&d\end{pmatrix}`],
    ['Kali',String.raw`\times`], ['Derajat',String.raw`90^{\circ}`]
  ];
  let target=null, cursor=0, selectionEnd=0, modal=null, initialFocus=null;
  const owned = node => (node instanceof HTMLTextAreaElement || (node instanceof HTMLInputElement && node.type==='text')) && (node.matches(targetSelector) || (node instanceof HTMLTextAreaElement && node.closest('#ed-ws-qform')));
  function remember(node){if(!owned(node))return;target=node;cursor=node.selectionStart??node.value.length;selectionEnd=node.selectionEnd??cursor;}
  document.addEventListener('focusin', e=>{if(owned(e.target)){buttons();remember(e.target);}}, true);
  document.addEventListener('keyup', e=>{if(owned(e.target))remember(e.target);},true);
  document.addEventListener('mouseup',e=>{if(owned(e.target))remember(e.target);},true);
  function buttons(root=document){
    root.querySelectorAll('textarea,input[type="text"]').forEach(ta=>{
      if(!owned(ta)||ta.dataset.edmathReady==='1')return;
      ta.dataset.edmathReady='1';
      const row=document.createElement('div'); row.className='edmath-inline';
      const btn=document.createElement('button');btn.type='button';btn.textContent='∑ Sisipkan Rumus';btn.setAttribute('aria-label','Sisipkan rumus matematika ke '+(ta.name||'teks'));
      btn.addEventListener('click',()=>{remember(ta);open(ta);});
      const label=document.createElement('span');label.className='edmath-hint';label.textContent='Rumus visual atau LaTeX · bisa offline';
      row.append(btn,label);ta.insertAdjacentElement('afterend',row);
    });
  }
  function katexRender(tex,out){
    out.replaceChildren();
    if (!tex.trim()) {out.textContent='Pratinjau rumus akan tampil di sini.';return;}
    if(typeof window.katex?.render==='function'){
      try {window.katex.render(tex,out,{throwOnError:true,trust:false,strict:'warn',output:'html'});return;}
      catch(e){out.textContent='Periksa penulisan LaTeX: '+String(e.message||e);out.classList.add('edmath-warn');return;}
    }
    out.textContent='KaTeX belum dimuat; LaTeX: '+tex;
  }
  function clearWarn(el){el.classList.remove('edmath-warn');}
  let vendorLoading=null;
  function loadMathLive(){
    if (customElements.get('math-field'))return Promise.resolve(true);
    if(vendorLoading)return vendorLoading;
    vendorLoading = new Promise(resolve=>{
      const script=document.createElement('script');
      script.src=PREFIX+'mathlive.min.js';script.async=true;
      script.onload=()=>resolve(!!customElements.get('math-field'));
      script.onerror=()=>resolve(false);
      document.head.appendChild(script);
    });
    return vendorLoading;
  }
  async function open(field){
    if(modal)modal.remove();
    if(field)remember(field);
    if(!target){const first=document.querySelector(targetSelector);if(!first)return;remember(first);}
    initialFocus=document.activeElement;
    const back=document.createElement('div');back.className='edmath-backdrop';back.setAttribute('role','presentation');
    const dialog=document.createElement('section');dialog.className='edmath-dialog';dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');dialog.setAttribute('aria-label','Equation Editor eduXid');
    dialog.innerHTML='<div class="edmath-head"><h3>∑ Equation Editor · eduXid</h3><button type="button" class="edmath-cancel" data-cancel aria-label="Tutup">✕</button></div><div class="edmath-status" data-mode>Menyiapkan editor lokal…</div><label class="edmath-label">Rumus (MathLive jika tersedia)</label><div data-visual></div><label class="edmath-label" for="edmath-tex">Kode LaTeX (tetap bisa disunting manual)</label><textarea id="edmath-tex" rows="3" spellcheck="false" placeholder="\\frac{a}{b}"></textarea><div class="edmath-toolbar" data-pallete></div><label class="edmath-label">Pratinjau KaTeX</label><div class="edmath-preview" data-preview aria-live="polite"></div><div class="edmath-actions"><button type="button" class="edmath-cancel" data-cancel>Batal</button><button type="button" class="edmath-action" data-display>Rumus baris tersendiri</button><button type="button" class="edmath-save" data-insert>Sisipkan Rumus</button></div>';
    back.appendChild(dialog);document.body.appendChild(back);modal=back;
    const editor=dialog.querySelector('#edmath-tex'),view=dialog.querySelector('[data-preview]'),visual=dialog.querySelector('[data-visual]');
    editor.value='';const update=()=>{clearWarn(view);katexRender(editor.value,view);};
    editor.addEventListener('input',()=>{update();if(visual.firstElementChild&&visual.firstElementChild.value!==editor.value)visual.firstElementChild.value=editor.value;});
    for(const [label,template] of P){const btn=document.createElement('button');btn.type='button';btn.textContent=label;btn.title=template;
      btn.onclick=()=>{const from=editor.selectionStart??editor.value.length,to=editor.selectionEnd??from;
        editor.setRangeText(template,from,to,'end');editor.dispatchEvent(new Event('input',{bubbles:true}));editor.focus();};dialog.querySelector('[data-pallete]').append(btn);}
    const close=()=>{back.remove();if(modal===back)modal=null;initialFocus?.focus?.();};
    dialog.querySelectorAll('[data-cancel]').forEach(b=>b.onclick=close);
    back.onclick=e=>{if(e.target===back)close();};
    const esc=e=>{if(e.key==='Escape'){e.preventDefault();close();}};
    back.addEventListener('keydown',esc);
    const insert=display=>{
      const tex=editor.value.trim();if(!tex)return editor.focus();
      const formatted=display?'$$'+tex+'$$':'$'+tex+'$';
      const from=Math.max(0,Math.min(cursor,target.value.length));
      const to=Math.max(from,Math.min(selectionEnd,target.value.length));
      target.setRangeText(formatted,from,to,'end');
      target.dispatchEvent(new Event('input',{bubbles:true}));target.dispatchEvent(new Event('change',{bubbles:true}));
      const destination=target;close();destination.focus();
    };
    dialog.querySelector('[data-insert]').onclick=()=>insert(false);
    dialog.querySelector('[data-display]').onclick=()=>insert(true);
    update();editor.focus();
    const ok=await loadMathLive();
    if(!back.isConnected)return;
    const status=dialog.querySelector('[data-mode]');
    if(!ok){status.textContent='Mode LaTeX + KaTeX offline aktif. MathLive visual belum dipaketkan pada perangkat ini.';return;}
    try{
      const MathClass=window.MathfieldElement||customElements.get('math-field');
      if(MathClass){MathClass.fontsDirectory=PREFIX+'fonts/';MathClass.soundsDirectory=null;}
      const mf=document.createElement('math-field');mf.setAttribute('virtual-keyboard-mode','manual');mf.setAttribute('smart-mode','');mf.value=editor.value;
      mf.style.display='block';visual.appendChild(mf);
      mf.addEventListener('input',()=>{editor.value=mf.value;update();});
      status.textContent='MathLive visual + KaTeX aktif secara lokal. LaTeX manual tetap tersedia.';
    }catch(e){status.textContent='MathLive tidak dapat dimulai ('+String(e.message||e)+'). Editor LaTeX tetap tersedia.';}
  }
  function boot(){buttons();const observer=new MutationObserver(records=>{
    let any=false;for(const r of records){if(r.type==='attributes'){any=true;break;}for(const n of r.addedNodes){if(n.nodeType===1&&(n.matches?.('textarea,input')||n.querySelector?.('textarea,input'))){any=true;break;}}if(any)break;}
    if(any)buttons();
  });observer.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['name']});}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
  window.eduXidEquationEditor={open,attach:buttons,templates:P,version:'V21R181'};
})();
