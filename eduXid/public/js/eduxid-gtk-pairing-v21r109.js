/* eduXid V21R109 — pengumuman pairing konsisten saat klik menu / AJAX. Kewenangan tetap di middleware Laravel. */
(function () {
  'use strict';
  const cfg = document.getElementById('eduxid-pairing-v21r109-config');
  if (!cfg || cfg.dataset.unpaired !== '1') return;
  const m = document.getElementById('eduxid-pairing-v21r109-modal');
  if (!m) return;
  const root = (document.querySelector('base')?.href || window.location.origin);
  const allowedNames = ['dashboard','about','guru/pengaturan','mengajar','rombel','pengaturan/','donasi/','pilih-semester','cloud/pair','profile','logout'];
  function permitted(path) {
    const s = path.replace(/^\/+|\/+$/g,'');
    if (s === '' || s === 'login' || s === 'dashboard' || s === 'about' || s === 'guru/pengaturan' || s === 'mengajar'
        || s === 'rombel' || /^rombel\/\d+$/.test(s) || /^mengajar\/\d+\/nilai(?:-akhir)?(?:\/.*)?$/.test(s)
        || s === 'pilih-semester' || s === 'cloud/pair' || s === 'profile' || s === 'logout'
        || s.startsWith('pengaturan/') || s.startsWith('donasi/') || s === 'internal/student-profile-overlay') return true;
    return false;
  }
  function show() { m.hidden = false; m.style.display='flex'; m.querySelector('[role="dialog"]')?.focus(); }
  function hide() { m.hidden = true; m.style.display='none'; }
  m.querySelector('[data-close]')?.addEventListener('click', hide);
  m.addEventListener('click',e=>{if(e.target===m)hide();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape' && !m.hidden)hide();});
  document.addEventListener('click', function(e) {
    const a=e.target.closest?.('a[href]'); if(!a || a.target==='_blank' || a.hasAttribute('download'))return;
    const href=a.getAttribute('href');if(!href||href.startsWith('#')||href.startsWith('javascript:'))return;
    let u;try{u=new URL(href,document.baseURI);}catch{return;}
    if (u.origin !== window.location.origin) return;
    if (!permitted(u.pathname)) {e.preventDefault();e.stopImmediatePropagation();show();}
  },true);
  // Tindakan form diblokir juga di server; tampilan langsung memberi pesan.
  document.addEventListener('submit',function(e){
    const f=e.target; const action=f.getAttribute('action')||location.pathname;
    let u;try{u=new URL(action,document.baseURI);}catch{return;}
    if(u.origin===location.origin && !permitted(u.pathname)){e.preventDefault();e.stopImmediatePropagation();show();}
  },true);
  const orig=window.fetch;
  if(typeof orig==='function')window.fetch=function(){
    return orig.apply(this,arguments).then(async response=>{
      if (response.status===403 && response.headers.get('content-type')?.includes('application/json')){
        try {const body=await response.clone().json();if(body?.code==='PAIRING_REQUIRED') show();}catch{}
      }
      return response;
    });
  };
})();
