/* eduXid LJK Scanner Core v13R9
 * Shared by PWA Guru and Laptop Guru.
 * Template: LJK-A4-V3.
 * Photo processing is entirely local in the browser.
 */
(() => {
  'use strict';

  const VERSION = '13R9-20261006';
  const CAL = window.EDUXID_LJK_A4_V3;
  if (!CAL || CAL.template_id !== 'LJK-A4-V3') {
    console.error('[eduXid LJK Core v13R9] LJK-A4-V3 calibration is missing.');
    return;
  }

  const PAGE_W = Number(CAL.marker.warp_size_px.width || 1840);
  const PAGE_H = Number(CAL.marker.warp_size_px.height || 2160);
  const DEFAULT_MIN_THRESHOLD = 38;
  const REVIEW_BAND = 9;
  const INNER_RADIUS = 6.5;
  const CENTER_SEARCH = [-1, 0, 1];
  const BG_RADIUS = 28;
  const BG_PATCH = [-2, 0, 2];

  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const percentile = (values, p) => {
    if (!values.length) return 0;
    const a = [...values].sort((x, y) => x - y);
    const pos = clamp((a.length - 1) * p, 0, a.length - 1);
    const lo = Math.floor(pos), hi = Math.ceil(pos);
    return lo === hi ? a[lo] : a[lo] + (a[hi] - a[lo]) * (pos - lo);
  };
  const median = values => percentile(values, 0.5);

  function grayData(canvas) {
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const d = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
    const g = new Uint8Array(canvas.width * canvas.height);
    for (let i = 0, j = 0; i < d.length; i += 4, j++) {
      g[j] = (d[i] * 77 + d[i + 1] * 150 + d[i + 2] * 29) >> 8;
    }
    return g;
  }

  async function loadBitmap(file) {
    if (window.createImageBitmap) {
      try { return await createImageBitmap(file, { imageOrientation: 'from-image' }); }
      catch (_) { try { return await createImageBitmap(file); } catch (_) {} }
    }
    return await new Promise((resolve, reject) => {
      const img = new Image();
      const url = URL.createObjectURL(file);
      img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
      img.onerror = e => { URL.revokeObjectURL(url); reject(e); };
      img.src = url;
    });
  }

  function sourceCanvas(bitmap, maxSide = 2400) {
    const w = Number(bitmap.width || bitmap.naturalWidth || 0);
    const h = Number(bitmap.height || bitmap.naturalHeight || 0);
    if (!w || !h) throw new Error('Ukuran foto tidak dapat dibaca.');
    const scale = Math.min(1, maxSide / Math.max(w, h));
    const c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(w * scale));
    c.height = Math.max(1, Math.round(h * scale));
    c.getContext('2d', { willReadFrequently: true }).drawImage(bitmap, 0, 0, c.width, c.height);
    return c;
  }

  function integralGray(gray, w, h) {
    const iw = w + 1;
    const a = new Uint32Array((w + 1) * (h + 1));
    for (let y = 1; y <= h; y++) {
      let row = 0;
      const off = (y - 1) * w;
      for (let x = 1; x <= w; x++) {
        row += gray[off + x - 1];
        a[y * iw + x] = a[(y - 1) * iw + x] + row;
      }
    }
    return a;
  }

  function rectStats(ii, w, h, x, y, bw, bh) {
    const iw = w + 1;
    const x1 = clamp(Math.floor(x), 0, w), y1 = clamp(Math.floor(y), 0, h);
    const x2 = clamp(Math.ceil(x + bw), 0, w), y2 = clamp(Math.ceil(y + bh), 0, h);
    const area = Math.max(1, (x2 - x1) * (y2 - y1));
    const sum = ii[y2 * iw + x2] - ii[y1 * iw + x2] - ii[y2 * iw + x1] + ii[y1 * iw + x1];
    return { sum, area, mean: sum / area };
  }

  function markerCandidate(ii, w, h, x, y, s) {
    const inner = rectStats(ii, w, h, x, y, s, s);
    const pad = Math.max(5, Math.round(s * 0.6));
    const outer = rectStats(ii, w, h, x - pad, y - pad, s + pad * 2, s + pad * 2);
    const ringArea = Math.max(1, outer.area - inner.area);
    const ringMean = (outer.sum - inner.sum) / ringArea;
    const contrast = ringMean - inner.mean;
    const darkness = 255 - inner.mean;
    if (inner.mean > 165 || contrast < 35 || darkness < 80) return null;
    const score = (contrast / 255) * 0.72 + (darkness / 255) * 0.28;
    return { x: x + s / 2, y: y + s / 2, size: s, score, innerMean: inner.mean, ringMean };
  }

  function quadrantBounds(w, h, quad) {
    const qx = Math.round(w * 0.48), qy = Math.round(h * 0.43);
    if (quad === 'tl') return [0, 0, qx, qy];
    if (quad === 'tr') return [w - qx, 0, w, qy];
    if (quad === 'bl') return [0, h - qy, qx, h];
    return [w - qx, h - qy, w, h];
  }

  function findMarker(gray, w, h, quad) {
    const ii = integralGray(gray, w, h);
    const minSide = Math.min(w, h);
    const sizes = [0.018, 0.023, 0.028, 0.034, 0.041, 0.048]
      .map(v => Math.max(12, Math.round(minSide * v)));
    const [x0, y0, x1, y1] = quadrantBounds(w, h, quad);
    let best = null;

    for (const s of sizes) {
      const stride = Math.max(4, Math.round(s / 5));
      for (let y = y0; y <= y1 - s; y += stride) {
        for (let x = x0; x <= x1 - s; x += stride) {
          const c = markerCandidate(ii, w, h, x, y, s);
          if (c && (!best || c.score > best.score)) best = c;
        }
      }
    }
    if (!best) return null;

    let refined = best;
    const baseS = best.size;
    const sizeVariants = [0.85, 0.93, 1, 1.07, 1.15].map(v => Math.max(10, Math.round(baseS * v)));
    const cx0 = best.x, cy0 = best.y;
    for (const s of sizeVariants) {
      const radius = Math.max(4, Math.round(baseS * 0.5));
      for (let cy = cy0 - radius; cy <= cy0 + radius; cy += 2) {
        for (let cx = cx0 - radius; cx <= cx0 + radius; cx += 2) {
          const c = markerCandidate(ii, w, h, cx - s / 2, cy - s / 2, s);
          if (c && c.score > refined.score) refined = c;
        }
      }
    }
    return refined;
  }

  const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
  function polygonCross(a, b, c) { return (b.x - a.x) * (c.y - b.y) - (b.y - a.y) * (c.x - b.x); }

  function detectMarkers(canvas) {
    const gray = grayData(canvas), w = canvas.width, h = canvas.height;
    const m = {
      tl: findMarker(gray, w, h, 'tl'), tr: findMarker(gray, w, h, 'tr'),
      bl: findMarker(gray, w, h, 'bl'), br: findMarker(gray, w, h, 'br')
    };
    if (Object.values(m).some(x => !x || x.score < 0.42)) return null;

    const top = dist(m.tl, m.tr), bottom = dist(m.bl, m.br);
    const left = dist(m.tl, m.bl), right = dist(m.tr, m.br);
    const sizeVals = Object.values(m).map(x => x.size);
    const sizeRatio = Math.max(...sizeVals) / Math.max(1, Math.min(...sizeVals));
    const convex = [
      polygonCross(m.tl, m.tr, m.br), polygonCross(m.tr, m.br, m.bl),
      polygonCross(m.br, m.bl, m.tl), polygonCross(m.bl, m.tl, m.tr)
    ].every(v => v > 0);
    if (!convex || top < w * 0.35 || bottom < w * 0.35 || left < h * 0.35 || right < h * 0.35) return null;
    if (top / bottom < 0.42 || top / bottom > 2.4 || left / right < 0.42 || left / right > 2.4 || sizeRatio > 2.4) return null;

    const raw = Object.values(m).reduce((s, x) => s + x.score, 0) / 4;
    const symmetry = 1 - Math.min(1, Math.abs(top - bottom) / Math.max(top, bottom) * 0.45 + Math.abs(left - right) / Math.max(left, right) * 0.45);
    const confidence = clamp(raw * 0.78 + symmetry * 0.22, 0, 1);
    return { ...m, confidence, sourceGray: gray, sourceWidth: w, sourceHeight: h };
  }

  function solveLinear(A, b) {
    const n = b.length;
    for (let i = 0; i < n; i++) {
      let p = i;
      for (let r = i + 1; r < n; r++) if (Math.abs(A[r][i]) > Math.abs(A[p][i])) p = r;
      if (Math.abs(A[p][i]) < 1e-10) throw new Error('Transformasi perspektif tidak stabil.');
      [A[i], A[p]] = [A[p], A[i]]; [b[i], b[p]] = [b[p], b[i]];
      const d = A[i][i];
      for (let c = i; c < n; c++) A[i][c] /= d; b[i] /= d;
      for (let r = 0; r < n; r++) {
        if (r === i) continue;
        const f = A[r][i];
        for (let c = i; c < n; c++) A[r][c] -= f * A[i][c];
        b[r] -= f * b[i];
      }
    }
    return b;
  }

  function homography(dst, src) {
    const A = [], b = [];
    for (let i = 0; i < 4; i++) {
      const x = dst[i].x, y = dst[i].y, u = src[i].x, v = src[i].y;
      A.push([x, y, 1, 0, 0, 0, -u * x, -u * y]); b.push(u);
      A.push([0, 0, 0, x, y, 1, -v * x, -v * y]); b.push(v);
    }
    return [...solveLinear(A, b), 1];
  }

  function markerHomography(markers) {
    const d = CAL.marker.destination_marker_centers_px;
    const dst = [
      { x: d.top_left[0], y: d.top_left[1] }, { x: d.top_right[0], y: d.top_right[1] },
      { x: d.bottom_left[0], y: d.bottom_left[1] }, { x: d.bottom_right[0], y: d.bottom_right[1] }
    ];
    return homography(dst, [markers.tl, markers.tr, markers.bl, markers.br]);
  }

  function project(H, x, y) {
    const den = H[6] * x + H[7] * y + H[8];
    if (!Number.isFinite(den) || Math.abs(den) < 1e-9) return { x: -1, y: -1 };
    return {
      x: (H[0] * x + H[1] * y + H[2]) / den,
      y: (H[3] * x + H[4] * y + H[5]) / den
    };
  }

  function sampleGray(gray, w, h, x, y) {
    if (x < 0 || y < 0 || x >= w - 1 || y >= h - 1) return 255;
    const x0 = Math.floor(x), y0 = Math.floor(y), x1 = x0 + 1, y1 = y0 + 1;
    const wx = x - x0, wy = y - y0;
    const a = gray[y0 * w + x0], b = gray[y0 * w + x1], c = gray[y1 * w + x0], d = gray[y1 * w + x1];
    return a * (1 - wx) * (1 - wy) + b * wx * (1 - wy) + c * (1 - wx) * wy + d * wx * wy;
  }

  function sampleCanonical(gray, w, h, H, x, y) {
    const p = project(H, x, y);
    return sampleGray(gray, w, h, p.x, p.y);
  }

  function metricAt(gray, w, h, H, point, ox, oy, threshold) {
    const cx = Number(point[0]) + ox, cy = Number(point[1]) + oy;
    const inner = [];
    const core = [];
    for (let y = -INNER_RADIUS; y <= INNER_RADIUS; y += 1.5) {
      for (let x = -INNER_RADIUS; x <= INNER_RADIUS; x += 1.5) {
        const d2 = x * x + y * y;
        if (d2 <= INNER_RADIUS * INNER_RADIUS) {
          const v = sampleCanonical(gray, w, h, H, cx + x, cy + y);
          inner.push(v);
          if (d2 <= 10) core.push(v);
        }
      }
    }

    // Ambil latar tepat di luar bubble, terutama diagonal agar tidak mengenai
    // huruf A-E atau nomor soal. Ini membuat bayangan/pencahayaan lokal ikut
    // terkompensasi tanpa membaca garis lingkaran bubble sebagai isian.
    const bg = [];
    const angles = [Math.PI/4, 3*Math.PI/4, 5*Math.PI/4, 7*Math.PI/4];
    for (const ang of angles) {
      const px = cx + BG_RADIUS * Math.cos(ang), py = cy + BG_RADIUS * Math.sin(ang);
      for (const yy of BG_PATCH) for (const xx of BG_PATCH) {
        bg.push(sampleCanonical(gray, w, h, H, px + xx, py + yy));
      }
    }

    const bgLum = percentile(bg, 0.70);
    const innerMean = inner.reduce((a,v)=>a+v,0) / Math.max(1, inner.length);
    const innerP25 = percentile(inner, 0.25);
    const coreMean = core.reduce((a,v)=>a+v,0) / Math.max(1, core.length);
    const contrastMean = Math.max(0, bgLum - innerMean);
    const contrastP25 = Math.max(0, bgLum - innerP25);
    const coreContrast = Math.max(0, bgLum - coreMean);
    const darkFraction = inner.filter(v => v < bgLum - 42).length / Math.max(1, inner.length);
    const veryDarkFraction = inner.filter(v => v < bgLum - 70).length / Math.max(1, inner.length);

    // score tetap disediakan untuk debug/UI, tetapi keputusan filled tidak lagi
    // hanya "siapa yang paling gelap". Bubble harus memenuhi beberapa syarat.
    const score = 0.42 * contrastMean + 0.24 * contrastP25 + 0.18 * coreContrast
      + 32 * darkFraction + 18 * veryDarkFraction;

    const t = Number(threshold || DEFAULT_MIN_THRESHOLD);
    const paperOk = bgLum >= 105;
    const filled = paperOk && (
      (contrastMean >= t && darkFraction >= 0.28 && coreContrast >= t * 0.72) ||
      (contrastMean >= t + 14 && darkFraction >= 0.18 && coreContrast >= t * 0.62) ||
      (darkFraction >= 0.52 && coreContrast >= t * 0.55)
    );
    const review = !filled && paperOk && (
      (contrastMean >= t - REVIEW_BAND && darkFraction >= 0.16) ||
      (darkFraction >= 0.30 && coreContrast >= t * 0.42)
    );

    return { score, bgLum, innerMean, innerP25, coreMean, contrastMean, contrastP25,
      coreContrast, darkFraction, veryDarkFraction, filled, review, offsetX: ox, offsetY: oy };
  }

  function bubbleMetric(gray, w, h, H, point, threshold) {
    let best = null;
    // Pencarian hanya +/-1 px. v13R8 memakai pencarian lebih luas dan memilih
    // titik tergelap; itu bisa menyentuh outline bubble dan menyebabkan false positive.
    for (const oy of CENTER_SEARCH) for (const ox of CENTER_SEARCH) {
      const m = metricAt(gray, w, h, H, point, ox, oy, threshold);
      if (!best || m.score > best.score) best = m;
    }
    return best || { score:0,bgLum:255,innerMean:255,innerP25:255,coreMean:255,
      contrastMean:0,contrastP25:0,coreContrast:0,darkFraction:0,veryDarkFraction:0,
      filled:false,review:false,offsetX:0,offsetY:0 };
  }

  function collectMetrics(gray, w, h, H, questionCount, threshold) {
    const nisnPts = CAL.nisn.centers_warp_px || [];
    const left = CAL.answers.left_centers_warp_px || [], right = CAL.answers.right_centers_warp_px || [];
    const nisnMetrics = nisnPts.map(p => bubbleMetric(gray, w, h, H, p, threshold));
    const answers = [];
    const max = Math.min(50, Number(questionCount || 50));
    for (let q = 1; q <= max; q++) {
      const row = q <= 25 ? q - 1 : q - 26;
      const src = q <= 25 ? left : right;
      answers.push(src.slice(row * 5, row * 5 + 5).map(p => bubbleMetric(gray, w, h, H, p, threshold)));
    }
    return { nisnMetrics, answerMetrics: answers };
  }

  function robustThreshold(metrics, minThreshold) {
    // Threshold v13R9 adalah minimum contrast, bukan ranking global antarbubble.
    // Sedikit adaptasi hanya menaikkan ambang jika noise keseluruhan tinggi.
    const t0 = clamp(Number(minThreshold || DEFAULT_MIN_THRESHOLD), 28, 70);
    const blankLike = metrics.filter(m => !m.filled).map(m => Number(m.contrastMean || 0));
    if (!blankLike.length) return t0;
    const noise95 = percentile(blankLike, 0.95);
    return clamp(Math.max(t0, noise95 + 13), 28, 70);
  }

  function reclassifyMetric(m, threshold) {
    const t = Number(threshold || DEFAULT_MIN_THRESHOLD);
    const paperOk = Number(m.bgLum || 0) >= 105;
    const filled = paperOk && (
      (m.contrastMean >= t && m.darkFraction >= 0.28 && m.coreContrast >= t * 0.72) ||
      (m.contrastMean >= t + 14 && m.darkFraction >= 0.18 && m.coreContrast >= t * 0.62) ||
      (m.darkFraction >= 0.52 && m.coreContrast >= t * 0.55)
    );
    const review = !filled && paperOk && (
      (m.contrastMean >= t - REVIEW_BAND && m.darkFraction >= 0.16) ||
      (m.darkFraction >= 0.30 && m.coreContrast >= t * 0.42)
    );
    return { ...m, filled, review };
  }

  function readNisn(nisnMetrics, threshold) {
    const cols = Number(CAL.nisn.columns || 10), rows = Number(CAL.nisn.rows || 10);
    let value = ''; const details = []; let ambiguousCount = 0;
    for (let c = 0; c < cols; c++) {
      const list = [];
      for (let digit = 0; digit < rows; digit++) {
        const raw = nisnMetrics[digit * cols + c] || { score:0 };
        const m = reclassifyMetric(raw, threshold);
        list.push({ digit, metric:m, filled:!!m.filled, review:!!m.review, score:Number(m.score||0) });
      }
      const selected = list.filter(x => x.filled);
      const near = list.filter(x => !x.filled && x.review);
      const clear = selected.length === 1 && near.length === 0;
      if (!clear) ambiguousCount++;
      value += clear ? String(selected[0].digit) : '?';
      details.push({ column:c+1, digit:clear?selected[0].digit:null,
        selected:selected.map(x=>x.digit), near:near.map(x=>x.digit),
        scores:list.map(x=>x.score), ambiguous:!clear });
    }
    return { nisn:value, details, ambiguousCount,
      confidence:clamp(1-ambiguousCount/Math.max(1,cols),0,1) };
  }

  function readAnswers(answerMetrics, threshold) {
    const options = CAL.answers.options || ['A','B','C','D','E'];
    return answerMetrics.map((metrics, i) => {
      const classified = metrics.map(m => reclassifyMetric(m, threshold));
      const selected = [], near = [];
      classified.forEach((m, idx) => {
        const label = String(options[idx] || String.fromCharCode(65 + idx));
        if (m.filled) selected.push(label);
        else if (m.review) near.push(label);
      });
      return {
        no:i+1,
        selected,
        value:selected.join(','),
        near,
        scores:classified.map(m=>Number(m.score||0)),
        metrics:classified,
        blank:selected.length===0 && near.length===0,
        ambiguous:near.length>0,
        multiple:selected.length>1,
        threshold
      };
    });
  }

  function createWarpPreview(gray, w, h, H, scale = 0.5) {
    const pw = Math.round(PAGE_W * scale), ph = Math.round(PAGE_H * scale);
    const c = document.createElement('canvas'); c.width = pw; c.height = ph;
    const od = new Uint8ClampedArray(pw * ph * 4);
    let k = 0;
    for (let y = 0; y < ph; y++) {
      const cy = y / scale;
      for (let x = 0; x < pw; x++) {
        const cx = x / scale;
        const p = project(H, cx, cy);
        const v = Math.round(sampleGray(gray, w, h, p.x, p.y));
        od[k++] = v; od[k++] = v; od[k++] = v; od[k++] = 255;
      }
    }
    c.getContext('2d').putImageData(new ImageData(od, pw, ph), 0, 0);
    return c;
  }

  function drawOverlay(canvas, scan) {
    const scale = canvas.width / PAGE_W;
    const ctx = canvas.getContext('2d'); ctx.save();
    ctx.lineWidth = Math.max(1, 2 * scale);
    const drawBubble = (p, status) => {
      ctx.beginPath(); ctx.arc(Number(p[0]) * scale, Number(p[1]) * scale, 15 * scale, 0, Math.PI * 2);
      ctx.strokeStyle = status === 'selected' ? 'rgba(22,163,74,.95)' : status === 'near' ? 'rgba(245,158,11,.95)' : 'rgba(100,116,139,.24)';
      ctx.stroke();
    };
    const left = CAL.answers.left_centers_warp_px || [], right = CAL.answers.right_centers_warp_px || [];
    scan.answers.forEach((a, qi) => {
      const row = qi < 25 ? qi : qi - 25, src = qi < 25 ? left : right;
      for (let j = 0; j < 5; j++) {
        const p = src[row * 5 + j]; if (!p) continue;
        const opt = String((CAL.answers.options || ['A','B','C','D','E'])[j]);
        drawBubble(p, a.selected.includes(opt) ? 'selected' : a.near.includes(opt) ? 'near' : 'blank');
      }
    });
    const npts = CAL.nisn.centers_warp_px || [];
    scan.nisn.details.forEach((d, col) => {
      for (let digit = 0; digit < 10; digit++) {
        const p = npts[digit * 10 + col]; if (!p) continue;
        drawBubble(p, d.selected.includes(digit) ? 'selected' : d.near.includes(digit) ? 'near' : 'blank');
      }
    });
    ctx.restore(); return canvas;
  }

  function normalizeSet(value) {
    if (value == null) return [];
    let src = value;
    if (typeof src === 'string') {
      const raw = src.trim();
      try { const parsed = JSON.parse(raw); if (Array.isArray(parsed)) src = parsed; else src = raw.split(/[\s,;|]+/u); }
      catch (_) { src = raw.split(/[\s,;|]+/u); }
    }
    if (!Array.isArray(src)) src = [src];
    return [...new Set(src.map(v => String(v ?? '').trim().toUpperCase()).filter(v => ['A','B','C','D','E'].includes(v)))].sort();
  }

  function isMultipleType(type, key) {
    return /majemuk|multiple|multi/i.test(String(type || '')) || normalizeSet(key).length > 1;
  }

  function compareAnswer(answer, key, type) {
    const a = normalizeSet(answer), k = normalizeSet(key), multiple = isMultipleType(type, k);
    if (!multiple && a.length > 1) return { ok: false, invalid: true, a, k, multiple };
    const ok = a.length === k.length && a.every((v, i) => v === k[i]);
    return { ok, invalid: false, a, k, multiple };
  }

  async function scanFile(file, options = {}) {
    const onStage = typeof options.onStage === 'function' ? options.onStage : () => {};
    onStage('photo', 'Membaca foto...');
    const bitmap = await loadBitmap(file);
    const src = sourceCanvas(bitmap, Number(options.maxSide || 2400));
    if (bitmap.close) try { bitmap.close(); } catch (_) {}

    onStage('markers', 'Mendeteksi empat marker dan geometri kertas...');
    const markers = detectMarkers(src);
    if (!markers) throw new Error('Empat marker LJK-A4-V3 tidak terdeteksi dengan cukup yakin. Pastikan keempat marker terlihat, kertas tidak terpotong, dan pencahayaan merata.');
    const H = markerHomography(markers);

    onStage('measure', 'Mengukur isian bubble dengan koreksi pencahayaan lokal...');
    const requestedThreshold = Number(options.minThreshold || DEFAULT_MIN_THRESHOLD);
    const metrics = collectMetrics(markers.sourceGray, markers.sourceWidth, markers.sourceHeight, H, Number(options.questionCount || 50), requestedThreshold);
    const all = [...metrics.nisnMetrics, ...metrics.answerMetrics.flat()];
    const threshold = robustThreshold(all, requestedThreshold);

    onStage('read', 'Membaca NISN dan jawaban...');
    const nisn = readNisn(metrics.nisnMetrics, threshold);
    const answers = readAnswers(metrics.answerMetrics, threshold);
    const reviewCount = answers.filter(a => a.ambiguous).length + nisn.ambiguousCount;
    const blankCount = answers.filter(a => a.blank).length;

    let previewCanvas = null;
    if (options.preview !== false) {
      onStage('preview', 'Membuat pratinjau hasil...');
      previewCanvas = createWarpPreview(markers.sourceGray, markers.sourceWidth, markers.sourceHeight, H, Number(options.previewScale || 0.5));
      drawOverlay(previewCanvas, { nisn, answers });
    }

    return {
      version: VERSION,
      template_id: CAL.template_id,
      markers: { tl: markers.tl, tr: markers.tr, bl: markers.bl, br: markers.br },
      markerConfidence: markers.confidence,
      threshold,
      minimumThreshold: Number(options.minThreshold || DEFAULT_MIN_THRESHOLD),
      nisn,
      answers,
      reviewCount,
      blankCount,
      previewCanvas,
      quality: {
        marker: markers.confidence,
        nisn: nisn.confidence,
        reviewCount,
        clear: markers.confidence >= 0.55 && nisn.ambiguousCount === 0
      }
    };
  }

  window.EDUXID_LJK_SCANNER_V13R9 = {
    version: VERSION,
    template: CAL.template_id,
    calibration: CAL,
    defaults: { minThreshold: DEFAULT_MIN_THRESHOLD, reviewBand: REVIEW_BAND },
    scanFile,
    normalizeSet,
    isMultipleType,
    compareAnswer,
    robustThreshold
  };
  console.info('[eduXid LJK Core v13R9] siap', { template: CAL.template_id, defaultThreshold: DEFAULT_MIN_THRESHOLD });
})();
