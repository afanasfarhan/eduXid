(() => {
'use strict';

const VERSION = '14R10';
const TEMPLATE = {
  name: 'LJK-A4-V3',
  width: 1448,
  height: 2048,
  markers: {
    tl: [90, 400],
    tr: [1358.5, 400],
    bl: [90, 1889.5],
    br: [1358.5, 1889.5]
  },
  nisn: {
    x0: 302.73636364,
    dx: 82.73636364,
    y0: 495.96363636,
    dy: 33.09696970,
    radius: 7
  },
  answers: {
    leftX: [254, 331, 407, 482, 558],
    rightX: [889, 964, 1041, 1117, 1192],
    y0: 989.10923077,
    dy: 36.40923077,
    radius: 7
  }
};

function clamp(v, min, max, fallback = min) {
  v = Number(v);
  return Number.isFinite(v) ? Math.max(min, Math.min(max, v)) : fallback;
}

async function sourceToCanvas(source) {
  const blob = source instanceof Blob
    ? source
    : (source?.blob instanceof Blob ? source.blob : null);

  if (!blob) throw new Error('Sumber gambar LJK bukan Blob/File yang valid.');

  let bitmap = null;
  if (typeof createImageBitmap === 'function') {
    try { bitmap = await createImageBitmap(blob); } catch (_) {}
  }

  if (!bitmap) {
    const url = URL.createObjectURL(blob);
    try {
      bitmap = await new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('Gambar LJK tidak dapat dibaca.'));
        img.src = url;
      });
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  let sw = bitmap.width, sh = bitmap.height;
  if (!sw || !sh) throw new Error('Ukuran gambar LJK tidak valid.');

  const landscape = sw > sh;
  const targetW = Math.min(1600, landscape ? sh : sw);
  const targetH = Math.round(targetW * ((landscape ? sw : sh) / (landscape ? sh : sw)));

  const canvas = document.createElement('canvas');
  canvas.width = targetW;
  canvas.height = targetH;
  const ctx = canvas.getContext('2d', { alpha: false, willReadFrequently: true });
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, targetW, targetH);

  if (landscape) {
    ctx.save();
    ctx.translate(targetW, 0);
    ctx.rotate(Math.PI / 2);
    ctx.drawImage(bitmap, 0, 0, sw, sh, 0, 0, targetH, targetW);
    ctx.restore();
  } else {
    ctx.drawImage(bitmap, 0, 0, sw, sh, 0, 0, targetW, targetH);
  }

  try { bitmap.close?.(); } catch (_) {}

  return { canvas, ctx, width: targetW, height: targetH };
}

function grayscale(imageData) {
  const data = imageData.data;
  const out = new Uint8Array(imageData.width * imageData.height);
  for (let i = 0, p = 0; i < data.length; i += 4, p++) {
    out[p] = Math.round(data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114);
  }
  return out;
}

function otsuThreshold(gray) {
  const hist = new Uint32Array(256);
  for (let i = 0; i < gray.length; i++) hist[gray[i]]++;

  let total = gray.length;
  let sum = 0;
  for (let i = 0; i < 256; i++) sum += i * hist[i];

  let sumB = 0, wB = 0, best = 0, maxVar = -1;
  for (let t = 0; t < 256; t++) {
    wB += hist[t];
    if (!wB) continue;
    const wF = total - wB;
    if (!wF) break;
    sumB += t * hist[t];
    const mB = sumB / wB;
    const mF = (sum - sumB) / wF;
    const between = wB * wF * (mB - mF) * (mB - mF);
    if (between > maxVar) {
      maxVar = between;
      best = t;
    }
  }
  return clamp(best, 65, 145, 100);
}

function connectedDarkCandidates(gray, w, h, threshold) {
  const n = gray.length;
  const visited = new Uint8Array(n);
  const stack = new Int32Array(n);
  const minDim = Math.min(w, h);
  const minSide = Math.max(7, minDim * 0.0075);
  const maxSide = minDim * 0.10;
  const candidates = [];

  for (let idx = 0; idx < n; idx++) {
    if (visited[idx] || gray[idx] >= threshold) continue;

    let sp = 0;
    stack[sp++] = idx;
    visited[idx] = 1;

    let count = 0;
    let minX = w, maxX = 0, minY = h, maxY = 0;
    let sumX = 0, sumY = 0;

    while (sp > 0) {
      const p = stack[--sp];
      const y = Math.floor(p / w);
      const x = p - y * w;

      count++;
      sumX += x;
      sumY += y;
      if (x < minX) minX = x;
      if (x > maxX) maxX = x;
      if (y < minY) minY = y;
      if (y > maxY) maxY = y;

      if (x > 0) {
        const q = p - 1;
        if (!visited[q] && gray[q] < threshold) {
          visited[q] = 1;
          stack[sp++] = q;
        }
      }
      if (x + 1 < w) {
        const q = p + 1;
        if (!visited[q] && gray[q] < threshold) {
          visited[q] = 1;
          stack[sp++] = q;
        }
      }
      if (y > 0) {
        const q = p - w;
        if (!visited[q] && gray[q] < threshold) {
          visited[q] = 1;
          stack[sp++] = q;
        }
      }
      if (y + 1 < h) {
        const q = p + w;
        if (!visited[q] && gray[q] < threshold) {
          visited[q] = 1;
          stack[sp++] = q;
        }
      }
    }

    const bw = maxX - minX + 1;
    const bh = maxY - minY + 1;
    const side = Math.min(bw, bh);
    const maxBoxSide = Math.max(bw, bh);
    if (side < minSide || maxBoxSide > maxSide) continue;

    const ratio = bw / Math.max(1, bh);
    if (ratio < 0.50 || ratio > 2.0) continue;

    const fill = count / Math.max(1, bw * bh);
    if (fill < 0.50) continue;

    candidates.push({
      x: sumX / count,
      y: sumY / count,
      w: bw,
      h: bh,
      area: count,
      density: fill,
    });
  }

  candidates.sort((a, b) => b.area - a.area);
  return candidates;
}

function chooseCornerMarkers(candidates, w, h) {
  if (!candidates.length) return null;

  const maxArea = Math.max(...candidates.map(c => c.area), 1);
  const corners = [
    ['tl', 0, 0],
    ['tr', 1, 0],
    ['bl', 0, 1],
    ['br', 1, 1],
  ];

  const chosen = {};
  const used = new Set();

  for (const [name, tx, ty] of corners) {
    let best = null;
    for (let i = 0; i < candidates.length; i++) {
      if (used.has(i)) continue;
      const c = candidates[i];
      const xn = c.x / w;
      const yn = c.y / h;
      const dist = Math.abs(xn - tx) + Math.abs(yn - ty);
      const sizeBonus = Math.min(1, c.area / maxArea);
      const score = -dist + sizeBonus * 0.15;

      if (!best || score > best.score) {
        best = { score, i, c };
      }
    }
    if (!best) return null;
    used.add(best.i);
    chosen[name] = best.c;
  }

  return chosen;
}

function markerQuality(found, w, h) {
  const xs = Object.values(found).map(m => m.x);
  const ys = Object.values(found).map(m => m.y);
  const widthSpan = (Math.max(...xs) - Math.min(...xs)) / w;
  const heightSpan = (Math.max(...ys) - Math.min(...ys)) / h;

  const topWidth = Math.hypot(found.tr.x - found.tl.x, found.tr.y - found.tl.y);
  const bottomWidth = Math.hypot(found.br.x - found.bl.x, found.br.y - found.bl.y);
  const leftHeight = Math.hypot(found.bl.x - found.tl.x, found.bl.y - found.tl.y);
  const rightHeight = Math.hypot(found.br.x - found.tr.x, found.br.y - found.tr.y);

  const widthRatio = Math.min(topWidth, bottomWidth) / Math.max(1, Math.max(topWidth, bottomWidth));
  const heightRatio = Math.min(leftHeight, rightHeight) / Math.max(1, Math.max(leftHeight, rightHeight));

  // Marker palsu (teks/jawaban/garis) dapat terlihat sebagai komponen gelap.
  // Tolak jika salah satu dari empat komponen tidak padat seperti kotak penanda.
  const densities = Object.values(found).map(m => Number(m.density || 0));
  const minDensity = Math.min(...densities);
  const validMarkerCount = densities.filter(d => d >= 0.78).length;
  const predictedBR = {
    x: found.tr.x + found.bl.x - found.tl.x,
    y: found.tr.y + found.bl.y - found.tl.y,
  };
  // Toleransi perspektif ringan/kuat masih diperbolehkan; posisi jauh meleset ditolak.
  const brResidual = Math.hypot(
    (found.br.x - predictedBR.x) / Math.max(1, w),
    (found.br.y - predictedBR.y) / Math.max(1, h)
  );
  return {
    width_span: widthSpan,
    height_span: heightSpan,
    width_ratio: widthRatio,
    height_ratio: heightRatio,
    min_density: minDensity,
    valid_marker_count: validMarkerCount,
    br_residual: brResidual,
    good: widthSpan >= 0.48 && heightSpan >= 0.53 &&
      widthRatio >= 0.55 && heightRatio >= 0.55 &&
      validMarkerCount === 4 && brResidual <= 0.10,
  };
}

function detectMarkers(gray, w, h) {
  const threshold = otsuThreshold(gray);
  const candidates = connectedDarkCandidates(gray, w, h, threshold);
  const found = chooseCornerMarkers(candidates, w, h);

  if (!found) {
    return {
      found: {},
      ok: false,
      threshold,
      candidate_count: candidates.length,
      valid_count: 0,
      quality: { good: false, width_span: 0, height_span: 0, width_ratio: 0, height_ratio: 0 },
    };
  }

  const quality = markerQuality(found, w, h);

  return {
    found,
    ok: Object.keys(found).length === 4 && quality.good,
    threshold,
    candidate_count: candidates.length,
    valid_count: quality.valid_marker_count,
    quality,
  };
}

function solveLinear(A, b) {
  const n = b.length;
  const M = A.map((row, i) => [...row, b[i]]);

  for (let col = 0; col < n; col++) {
    let pivot = col;
    for (let row = col + 1; row < n; row++) {
      if (Math.abs(M[row][col]) > Math.abs(M[pivot][col])) pivot = row;
    }

    if (Math.abs(M[pivot][col]) < 1e-12) throw new Error('Transformasi marker tidak stabil.');

    [M[col], M[pivot]] = [M[pivot], M[col]];
    const div = M[col][col];
    for (let j = col; j <= n; j++) M[col][j] /= div;

    for (let row = 0; row < n; row++) {
      if (row === col) continue;
      const factor = M[row][col];
      if (!factor) continue;
      for (let j = col; j <= n; j++) M[row][j] -= factor * M[col][j];
    }
  }
  return M.map(row => row[n]);
}

function homography(canonical, observed) {
  const A = [], b = [];
  for (let i = 0; i < 4; i++) {
    const [X, Y] = canonical[i];
    const [x, y] = observed[i];
    A.push([X, Y, 1, 0, 0, 0, -x * X, -x * Y]);
    b.push(x);
    A.push([0, 0, 0, X, Y, 1, -y * X, -y * Y]);
    b.push(y);
  }

  const p = solveLinear(A, b);
  return [p[0], p[1], p[2], p[3], p[4], p[5], p[6], p[7], 1];
}

function mapPoint(H, X, Y) {
  const den = H[6] * X + H[7] * Y + H[8];
  if (Math.abs(den) < 1e-12) throw new Error('Transformasi koordinat tidak valid.');
  return {
    x: (H[0] * X + H[1] * Y + H[2]) / den,
    y: (H[3] * X + H[4] * Y + H[5]) / den
  };
}

function localScale(H, X, Y) {
  const p = mapPoint(H, X, Y);
  const px = mapPoint(H, X + 10, Y);
  const py = mapPoint(H, X, Y + 10);
  const sx = Math.hypot(px.x - p.x, px.y - p.y) / 10;
  const sy = Math.hypot(py.x - p.x, py.y - p.y) / 10;
  return Math.max(0.2, (sx + sy) / 2);
}

function diskDarkness(gray, w, h, H, X, Y, radiusCanonical) {
  const p = mapPoint(H, X, Y);
  const radius = Math.max(2, radiusCanonical * localScale(H, X, Y));
  const r2 = radius * radius;

  const x0 = Math.max(0, Math.floor(p.x - radius));
  const x1 = Math.min(w - 1, Math.ceil(p.x + radius));
  const y0 = Math.max(0, Math.floor(p.y - radius));
  const y1 = Math.min(h - 1, Math.ceil(p.y + radius));

  let sum = 0, count = 0;
  for (let y = y0; y <= y1; y++) {
    for (let x = x0; x <= x1; x++) {
      const dx = x - p.x, dy = y - p.y;
      if (dx * dx + dy * dy > r2) continue;
      sum += 1 - gray[y * w + x] / 255;
      count++;
    }
  }
  return count ? sum / count : 0;
}

function ringDarkness(gray, w, h, H, X, Y, innerCanonical = 10, outerCanonical = 15) {
  const p = mapPoint(H, X, Y);
  const scale = localScale(H, X, Y);
  const ri = innerCanonical * scale, ro = outerCanonical * scale;
  const ri2 = ri * ri, ro2 = ro * ro;

  const x0 = Math.max(0, Math.floor(p.x - ro));
  const x1 = Math.min(w - 1, Math.ceil(p.x + ro));
  const y0 = Math.max(0, Math.floor(p.y - ro));
  const y1 = Math.min(h - 1, Math.ceil(p.y + ro));

  let sum = 0, count = 0;
  for (let y = y0; y <= y1; y++) {
    for (let x = x0; x <= x1; x++) {
      const dx = x - p.x, dy = y - p.y;
      const d2 = dx * dx + dy * dy;
      if (d2 < ri2 || d2 > ro2) continue;
      sum += 1 - gray[y * w + x] / 255;
      count++;
    }
  }
  return count ? sum / count : 0;
}

function canonicalMarkers(order = 'normal') {
  const m = TEMPLATE.markers;
  if (order === 'rot180') return [m.br, m.bl, m.tr, m.tl];
  return [m.tl, m.tr, m.bl, m.br];
}

function observedMarkers(markerResult) {
  const f = markerResult.found;
  return [
    [f.tl.x, f.tl.y],
    [f.tr.x, f.tr.y],
    [f.bl.x, f.bl.y],
    [f.br.x, f.br.y]
  ];
}

function orientationScore(gray, w, h, H) {
  const probes = [];
  for (const q of [1, 5, 10, 15, 20, 25, 26, 30, 35, 40, 45, 50]) {
    const xs = q <= 25 ? TEMPLATE.answers.leftX : TEMPLATE.answers.rightX;
    const y = TEMPLATE.answers.y0 + TEMPLATE.answers.dy * ((q - 1) % 25);
    for (const x of xs) probes.push([x, y]);
  }
  let sum = 0;
  for (const [x, y] of probes) sum += ringDarkness(gray, w, h, H, x, y);
  return sum / Math.max(1, probes.length);
}

function chooseTransform(gray, w, h, markerResult) {
  const observed = observedMarkers(markerResult);
  const normal = homography(canonicalMarkers('normal'), observed);
  const rotated = homography(canonicalMarkers('rot180'), observed);

  const normalScore = orientationScore(gray, w, h, normal);
  const rotatedScore = orientationScore(gray, w, h, rotated);

  return rotatedScore > normalScore * 1.18
    ? { H: rotated, orientation: 180, score: rotatedScore }
    : { H: normal, orientation: 0, score: normalScore };
}

function isMultipleType(type) {
  type = String(type || '').toLowerCase();
  return /majemuk|kompleks|multiple|multi/.test(type);
}

function readNisn(gray, w, h, H, threshold) {
  let nisn = '';
  let ambiguity = 0;
  let confidenceSum = 0;

  for (let col = 0; col < 10; col++) {
    const X = TEMPLATE.nisn.x0 + TEMPLATE.nisn.dx * col;
    const scores = [];

    for (let digit = 0; digit < 10; digit++) {
      const Y = TEMPLATE.nisn.y0 + TEMPLATE.nisn.dy * digit;
      scores.push(diskDarkness(gray, w, h, H, X, Y, TEMPLATE.nisn.radius));
    }

    const selected = scores
      .map((score, digit) => ({ score, digit }))
      .filter(item => item.score >= threshold)
      .sort((a, b) => b.score - a.score);

    if (selected.length !== 1) {
      ambiguity++;
      nisn += '?';
      continue;
    }

    nisn += String(selected[0].digit);
    const second = [...scores].sort((a, b) => b - a)[1] || 0;
    confidenceSum += clamp((selected[0].score - second) / Math.max(0.15, 1 - threshold), 0, 1, 0);
  }

  return {
    nisn: ambiguity === 0 ? nisn : '',
    raw_nisn: nisn,
    ambiguous_count: ambiguity,
    confidence: ambiguity === 0 ? confidenceSum / 10 : 0
  };
}

function readAnswers(gray, w, h, H, options) {
  const questionCount = Math.max(1, Math.min(50, Number(options.question_count || 50)));
  const threshold = clamp(options.bubble_threshold, 0.18, 0.75, 0.42);
  const questionTypes = options.question_types || {};

  const answers = {};
  const answerConfidence = {};
  let ambiguous = 0, blank = 0, multi = 0, single = 0;

  for (let q = 1; q <= questionCount; q++) {
    const xs = q <= 25 ? TEMPLATE.answers.leftX : TEMPLATE.answers.rightX;
    const Y = TEMPLATE.answers.y0 + TEMPLATE.answers.dy * ((q - 1) % 25);
    const scores = xs.map(X => diskDarkness(
      gray, w, h, H, X, Y, TEMPLATE.answers.radius
    ));

    const selected = scores
      .map((score, idx) => ({ score, letter: String.fromCharCode(65 + idx) }))
      .filter(item => item.score >= threshold);

    const type = questionTypes[String(q)] ?? questionTypes[q] ?? '';
    const multipleAllowed = isMultipleType(type);

    if (selected.length === 0) {
      answers[q] = '';
      answerConfidence[q] = 1;
      blank++;
      continue;
    }

    const minSelected = Math.min(...selected.map(x => x.score));
    const maxUnselected = Math.max(
      0,
      ...scores.filter(score => score < threshold)
    );
    const confidence = clamp(
      Math.min(
        (minSelected - threshold) / Math.max(0.08, 1 - threshold),
        (threshold - maxUnselected) / Math.max(0.08, threshold)
      ) + 0.5,
      0,
      1,
      0
    );

    if (selected.length === 1) {
      answers[q] = multipleAllowed
        ? [selected[0].letter]
        : selected[0].letter;
      single++;
    } else {
      answers[q] = selected.map(x => x.letter);
      multi++;
      if (!multipleAllowed) ambiguous++;
    }

    answerConfidence[q] = Number(confidence.toFixed(3));
  }

  return {
    answers,
    answer_confidence: answerConfidence,
    ambiguous_count: ambiguous,
    blank_count: blank,
    multi_count: multi,
    single_count: single,
    detected_question_count: questionCount
  };
}

async function scanFile(source, options = {}) {
  const { ctx, width: w, height: h } = await sourceToCanvas(source);
  const image = ctx.getImageData(0, 0, w, h);
  const gray = grayscale(image);

  const markerResult = detectMarkers(gray, w, h);
  if (!markerResult.ok) {
    const density = Object.entries(markerResult.found)
      .map(([k, v]) => `${k.toUpperCase()}=${v.density.toFixed(2)}`)
      .join(', ');
    throw new Error(
      'Marker LJK-A4-V3 tidak terbaca lengkap. Pastikan keempat kotak hitam terlihat. ' + density
    );
  }

  const transform = chooseTransform(gray, w, h, markerResult);
  const threshold = clamp(options.bubble_threshold, 0.18, 0.75, 0.42);

  const nisn = readNisn(gray, w, h, transform.H, threshold);
  const answerRead = readAnswers(gray, w, h, transform.H, options);

  // Blank answers are legitimate. Only structure failure is fatal.
  if (answerRead.detected_question_count < 1) {
    throw new Error('Area jawaban LJK-A4-V3 tidak dapat dipetakan.');
  }

  const answerConfValues = Object.values(answerRead.answer_confidence || {});
  const avgAnswerConfidence = answerConfValues.length
    ? answerConfValues.reduce((sum, value) => sum + Number(value || 0), 0) / answerConfValues.length
    : 0;

  const confidence = Number((
    avgAnswerConfidence * 0.75 +
    (nisn.nisn ? nisn.confidence * 0.20 : 0.08) +
    0.05
  ).toFixed(3));

  return {
    success: true,
    version: VERSION,
    template_version: TEMPLATE.name,
    nisn: nisn.nisn,
    raw_nisn: nisn.raw_nisn,
    answers: answerRead.answers,
    answer_confidence: answerRead.answer_confidence,
    confidence,
    ambiguous_count: answerRead.ambiguous_count + nisn.ambiguous_count,
    marker_ok: true,
    marker_count: 4,
    scan_ok: true,
    detected_question_count: answerRead.detected_question_count,
    blank_count: answerRead.blank_count,
    multi_count: answerRead.multi_count,
    single_count: answerRead.single_count,
    orientation: transform.orientation,
    debug: {
      marker_density: Object.fromEntries(
        Object.entries(markerResult.found).map(([k, v]) => [k, Number(v.density.toFixed(3))])
      ),
      orientation_score: Number(transform.score.toFixed(3)),
      marker_quality: markerResult.quality,
      threshold
    }
  };
}

window.EDUXID_LJK_SCANNER_V14R10 = {
  version: VERSION,
  template: TEMPLATE.name,
  scanFile,
  processFile: scanFile,
  analyze: scanFile
};
window.EDUXID_LJK_SCANNER = window.EDUXID_LJK_SCANNER_V14R10;
})();
