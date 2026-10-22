(function (global) {
    'use strict';

    const TEMPLATE = {
        version: 'LJK-A4-V2',
        width: 1055,
        height: 1491,
        markers: {
            tl: [65.5, 598.5],
            tr: [988.5, 598.5],
            bl: [66.0, 1401.5],
            br: [988.5, 1401.5]
        },
        nisnX: [287.55, 347.55, 408.0, 469.0, 529.0, 589.0, 649.5, 709.0, 769.0, 830.0],
        nisnY: [310.0, 335.0, 360.0, 385.0, 410.0, 434.5, 459.5, 484.0, 509.0, 534.5],
        answerLeftX: [199.68, 255.98, 311.5, 367.48, 427.0],
        answerRightX: [639.5, 697.0, 753.62, 810.0, 866.0],
        answerY: [
            677.5, 706.0, 734.5, 762.65, 791.0,
            819.5, 847.65, 876.5, 905.0, 933.75,
            962.5, 991.45, 1020.0, 1049.5, 1078.5,
            1107.4, 1136.0, 1164.5, 1193.5, 1222.15,
            1251.0, 1280.2, 1308.5, 1337.5, 1366.45
        ]
    };

    const LETTERS = ['A', 'B', 'C', 'D', 'E'];

    function clamp(v, min, max) {
        return Math.max(min, Math.min(max, v));
    }

    function grayAt(data, width, height, x, y) {
        const ix = clamp(Math.round(x), 0, width - 1);
        const iy = clamp(Math.round(y), 0, height - 1);
        const i = (iy * width + ix) * 4;
        return (data[i] * 0.299) + (data[i + 1] * 0.587) + (data[i + 2] * 0.114);
    }

    function gaussianSolve(matrix, vector) {
        const n = vector.length;
        const a = matrix.map((row, i) => row.slice().concat([vector[i]]));

        for (let col = 0; col < n; col++) {
            let pivot = col;
            for (let row = col + 1; row < n; row++) {
                if (Math.abs(a[row][col]) > Math.abs(a[pivot][col])) pivot = row;
            }

            if (Math.abs(a[pivot][col]) < 1e-10) {
                throw new Error('Marker LJK tidak membentuk transformasi yang valid.');
            }

            if (pivot !== col) {
                const tmp = a[col]; a[col] = a[pivot]; a[pivot] = tmp;
            }

            const div = a[col][col];
            for (let j = col; j <= n; j++) a[col][j] /= div;

            for (let row = 0; row < n; row++) {
                if (row === col) continue;
                const factor = a[row][col];
                if (Math.abs(factor) < 1e-12) continue;
                for (let j = col; j <= n; j++) a[row][j] -= factor * a[col][j];
            }
        }

        return a.map(row => row[n]);
    }

    function homographyFromFour(templatePts, imagePts) {
        const m = [];
        const b = [];

        for (let i = 0; i < 4; i++) {
            const [x, y] = templatePts[i];
            const [u, v] = imagePts[i];
            m.push([x, y, 1, 0, 0, 0, -u * x, -u * y]);
            b.push(u);
            m.push([0, 0, 0, x, y, 1, -v * x, -v * y]);
            b.push(v);
        }

        const h = gaussianSolve(m, b);
        return [h[0], h[1], h[2], h[3], h[4], h[5], h[6], h[7], 1];
    }

    function project(H, x, y) {
        const d = (H[6] * x) + (H[7] * y) + H[8];
        return [
            ((H[0] * x) + (H[1] * y) + H[2]) / d,
            ((H[3] * x) + (H[4] * y) + H[5]) / d
        ];
    }

    function canvasFromImage(img, maxWidth) {
        const scale = Math.min(1, (maxWidth || 1800) / img.naturalWidth);
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        return canvas;
    }

    function loadImageFromFile(file) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
                try { resolve({ img, url }); } catch (e) { reject(e); }
            };
            img.onerror = () => {
                URL.revokeObjectURL(url);
                reject(new Error('Gambar LJK tidak dapat dibuka.'));
            };
            img.src = url;
        });
    }

    function downscaleForMarker(canvas) {
        const maxW = 700;
        const scale = Math.min(1, maxW / canvas.width);
        const c = document.createElement('canvas');
        c.width = Math.round(canvas.width * scale);
        c.height = Math.round(canvas.height * scale);
        c.getContext('2d', { willReadFrequently: true }).drawImage(canvas, 0, 0, c.width, c.height);
        return { canvas: c, scale };
    }

    function markerCandidates(sourceCanvas) {
        const { canvas, scale } = downscaleForMarker(sourceCanvas);
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        const image = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const w = canvas.width, h = canvas.height;
        const n = w * h;
        const dark = new Uint8Array(n);
        const visited = new Uint8Array(n);

        for (let p = 0, i = 0; p < n; p++, i += 4) {
            const g = image.data[i] * 0.299 + image.data[i + 1] * 0.587 + image.data[i + 2] * 0.114;
            if (g < 90) dark[p] = 1;
        }

        const stack = new Int32Array(Math.min(n, 200000));
        const out = [];
        const minArea = Math.max(20, Math.round(n * 0.00003));

        for (let start = 0; start < n; start++) {
            if (!dark[start] || visited[start]) continue;

            let top = 0;
            stack[top++] = start;
            visited[start] = 1;
            let area = 0;
            let minX = w, minY = h, maxX = 0, maxY = 0;

            while (top > 0) {
                const p = stack[--top];
                const y = Math.floor(p / w), x = p - y * w;
                area++;
                if (x < minX) minX = x; if (x > maxX) maxX = x;
                if (y < minY) minY = y; if (y > maxY) maxY = y;

                const neigh = [p - 1, p + 1, p - w, p + w];
                for (let k = 0; k < 4; k++) {
                    const q = neigh[k];
                    if (q < 0 || q >= n || visited[q] || !dark[q]) continue;
                    const qy = Math.floor(q / w), qx = q - qy * w;
                    if (Math.abs(qx - x) + Math.abs(qy - y) !== 1) continue;
                    if (top < stack.length) {
                        visited[q] = 1;
                        stack[top++] = q;
                    }
                }
            }

            if (area < minArea) continue;
            const bw = maxX - minX + 1, bh = maxY - minY + 1;
            const ratio = bw / Math.max(1, bh);
            const fill = area / Math.max(1, bw * bh);

            if (bw < 5 || bh < 5 || ratio < 0.55 || ratio > 1.8 || fill < 0.45) continue;
            if (bw > w * 0.10 || bh > h * 0.10) continue;

            out.push({
                x: ((minX + maxX) / 2) / scale,
                y: ((minY + maxY) / 2) / scale,
                width: bw / scale,
                height: bh / scale,
                area: area / (scale * scale),
                fill,
                quality: area * fill
            });
        }

        return out.sort((a, b) => b.quality - a.quality).slice(0, 30);
    }

    function pickMarkerPair(candidates, width, height) {
        const pairs = [];
        for (let i = 0; i < candidates.length; i++) {
            for (let j = i + 1; j < candidates.length; j++) {
                let a = candidates[i], b = candidates[j];
                if (a.x > b.x) [a, b] = [b, a];
                const sep = b.x - a.x;
                const yDiff = Math.abs(a.y - b.y);
                if (sep < width * 0.45 || yDiff > height * 0.12) continue;
                const y = (a.y + b.y) / 2;
                const score = (a.quality + b.quality) + sep - (yDiff * 4);
                pairs.push({ left: a, right: b, y, score });
            }
        }
        return pairs;
    }

    function detectMarkers(canvas) {
        const candidates = markerCandidates(canvas);
        const pairs = pickMarkerPair(candidates, canvas.width, canvas.height);

        if (pairs.length >= 2) {
            let best = null;
            for (let i = 0; i < pairs.length; i++) {
                for (let j = 0; j < pairs.length; j++) {
                    if (i === j) continue;
                    const top = pairs[i], bottom = pairs[j];
                    const gap = bottom.y - top.y;
                    if (gap < canvas.height * 0.30) continue;
                    const xAlign = Math.abs(top.left.x - bottom.left.x) + Math.abs(top.right.x - bottom.right.x);
                    const score = top.score + bottom.score + gap - xAlign;
                    if (!best || score > best.score) best = { top, bottom, score };
                }
            }

            if (best) {
                return {
                    fallback: false,
                    quality: 'detected',
                    points: [
                        [best.top.left.x, best.top.left.y],
                        [best.top.right.x, best.top.right.y],
                        [best.bottom.left.x, best.bottom.left.y],
                        [best.bottom.right.x, best.bottom.right.y]
                    ],
                    candidates
                };
            }
        }

        // Fallback hanya untuk scan yang sudah terpotong rapat seperti A4.
        return {
            fallback: true,
            quality: 'fallback',
            points: [
                [TEMPLATE.markers.tl[0] / TEMPLATE.width * canvas.width, TEMPLATE.markers.tl[1] / TEMPLATE.height * canvas.height],
                [TEMPLATE.markers.tr[0] / TEMPLATE.width * canvas.width, TEMPLATE.markers.tr[1] / TEMPLATE.height * canvas.height],
                [TEMPLATE.markers.bl[0] / TEMPLATE.width * canvas.width, TEMPLATE.markers.bl[1] / TEMPLATE.height * canvas.height],
                [TEMPLATE.markers.br[0] / TEMPLATE.width * canvas.width, TEMPLATE.markers.br[1] / TEMPLATE.height * canvas.height]
            ],
            candidates
        };
    }

    function inkScore(image, H, tx, ty, innerRadius, outerRadius) {
        const data = image.data, w = image.width, h = image.height;
        let total = 0, count = 0;
        const step = 1.2;

        for (let oy = -outerRadius; oy <= outerRadius; oy += step) {
            for (let ox = -outerRadius; ox <= outerRadius; ox += step) {
                const d2 = ox * ox + oy * oy;
                if (d2 < innerRadius * innerRadius || d2 > outerRadius * outerRadius) continue;
                const [sx, sy] = project(H, tx + ox, ty + oy);
                const g = grayAt(data, w, h, sx, sy);
                total += (255 - g) / 255;
                count++;
            }
        }

        return count ? total / count : 0;
    }

    function bestOf(scores) {
        const ranked = scores.map((v, i) => ({ v, i })).sort((a, b) => b.v - a.v);
        return {
            index: ranked[0].i,
            best: ranked[0].v,
            second: ranked[1] ? ranked[1].v : 0,
            margin: ranked[0].v - (ranked[1] ? ranked[1].v : 0)
        };
    }

    function analyzeNisn(image, H) {
        const digits = [];
        const details = [];
        let ambiguous = 0;

        for (let col = 0; col < 10; col++) {
            const scores = TEMPLATE.nisnY.map(y => inkScore(image, H, TEMPLATE.nisnX[col], y, 1.5, 6.4));
            const pick = bestOf(scores);
            const ok = pick.best >= 0.12 && pick.margin >= 0.035;
            digits.push(ok ? String(pick.index) : '?');
            if (!ok) ambiguous++;
            details.push({ column: col + 1, digit: ok ? pick.index : null, scores, ...pick, ok });
        }

        return { nisn: digits.join(''), digits, details, ambiguous };
    }

    function answerXY(number, optionIndex) {
        if (number <= 25) {
            return [TEMPLATE.answerLeftX[optionIndex], TEMPLATE.answerY[number - 1]];
        }
        return [TEMPLATE.answerRightX[optionIndex], TEMPLATE.answerY[number - 26]];
    }

    function normalizeBlueprint(questionConfig) {
        if (Number.isInteger(Number(questionConfig))) {
            const count = Number(questionConfig);
            if (count < 1 || count > 50) throw new Error('Jumlah soal harus 1–50.');
            return Array.from({ length: count }, (_, i) => ({
                number: i + 1,
                type: 'pilihan_ganda',
                choices: LETTERS.slice()
            }));
        }

        if (!Array.isArray(questionConfig) || questionConfig.length < 1 || questionConfig.length > 50) {
            throw new Error('Konfigurasi soal LJK tidak valid.');
        }

        return questionConfig.map((q, i) => {
            const type = String(q?.type || 'pilihan_ganda').toLowerCase();
            if (!['pilihan_ganda', 'majemuk', 'benar_salah'].includes(type)) {
                throw new Error(`Soal nomor ${i + 1} tidak kompatibel dengan LJK-A4-V2.`);
            }
            return {
                number: i + 1,
                type,
                choices: type === 'benar_salah' ? ['A', 'B'] : LETTERS.slice()
            };
        });
    }

    function analyzeAnswers(image, H, blueprint) {
        const answers = {};
        const details = [];
        let ambiguous = 0;

        for (const config of blueprint) {
            const q = config.number;
            const scores = LETTERS.map((_, idx) => {
                const [x, y] = answerXY(q, idx);
                return inkScore(image, H, x, y, 4.2, 8.3);
            });

            const pick = bestOf(scores);
            const baseline = scores.reduce((a, b) => a + b, 0) / scores.length;
            const excess = pick.best - baseline;

            const selectedIndices = [];
            const weakIndices = [];
            scores.forEach((score, idx) => {
                // Annulus sengaja menghindari huruf A-E yang tercetak di pusat bubble.
                // Bubble kosong template berada jauh di bawah ambang ini; fixed threshold
                // juga tetap benar jika soal majemuk mengarsir 3-5 pilihan sekaligus.
                if (score >= 0.16) {
                    selectedIndices.push(idx);
                } else if (score >= 0.12) {
                    weakIndices.push(idx);
                }
            });

            const selections = selectedIndices.map(i => LETTERS[i]);
            let ok = weakIndices.length === 0;

            if (config.type === 'pilihan_ganda') {
                ok = ok && selections.length <= 1;
            } else if (config.type === 'benar_salah') {
                ok = ok
                    && selections.length <= 1
                    && selections.every(v => v === 'A' || v === 'B')
                    && selectedIndices.every(i => i <= 1);
            }

            // Template kosong terkalibrasi maksimum sekitar 0.106 pada annulus jawaban.
            // Karena itu 0.12–0.16 diperlakukan sebagai arsiran tipis/ambigu; di bawah
            // 0.12 adalah bubble kosong yang valid, bukan otomatis hasil ambigu.
            const answer = selections.join(',');
            answers[q] = answer;
            if (!ok) ambiguous++;

            details.push({
                number: q,
                type: config.type,
                answer,
                selections,
                scores,
                baseline,
                excess,
                weakIndices,
                ...pick,
                ok
            });
        }

        return { answers, details, ambiguous };
    }

    function buildConfidence(nisnResult, answerResult, markerResult) {
        const answer = {};
        answerResult.details.forEach(d => {
            answer[d.number] = {
                selected: d.answer,
                type: d.type,
                best: Number(d.best.toFixed(4)),
                second: Number(d.second.toFixed(4)),
                margin: Number(d.margin.toFixed(4)),
                ok: d.ok
            };
        });

        return {
            template: TEMPLATE.version,
            marker_mode: markerResult.fallback ? 'fallback' : 'detected',
            nisn: nisnResult.details.map(d => ({
                column: d.column,
                digit: d.digit,
                best: Number(d.best.toFixed(4)),
                second: Number(d.second.toFixed(4)),
                margin: Number(d.margin.toFixed(4)),
                ok: d.ok
            })),
            answers: answer
        };
    }

    function makeOverlay(sourceCanvas, H, markerResult, nisnResult, answerResult) {
        const out = document.createElement('canvas');
        out.width = sourceCanvas.width;
        out.height = sourceCanvas.height;
        const ctx = out.getContext('2d');
        ctx.drawImage(sourceCanvas, 0, 0);

        ctx.save();
        ctx.lineWidth = Math.max(2, out.width / 500);
        ctx.font = Math.max(12, Math.round(out.width / 70)) + 'px Arial';

        markerResult.points.forEach((p, i) => {
            ctx.strokeStyle = markerResult.fallback ? '#f59e0b' : '#16a34a';
            ctx.strokeRect(p[0] - 15, p[1] - 15, 30, 30);
            ctx.fillStyle = ctx.strokeStyle;
            ctx.fillText(['TL','TR','BL','BR'][i], p[0] + 18, p[1]);
        });

        nisnResult.details.forEach((d, col) => {
            if (d.digit === null) return;
            const [x, y] = project(H, TEMPLATE.nisnX[col], TEMPLATE.nisnY[d.digit]);
            ctx.strokeStyle = d.ok ? '#16a34a' : '#dc2626';
            ctx.beginPath(); ctx.arc(x, y, 10, 0, Math.PI * 2); ctx.stroke();
        });

        answerResult.details.forEach(d => {
            (d.selections || []).forEach(letter => {
                const idx = LETTERS.indexOf(letter);
                if (idx < 0) return;
                const [tx, ty] = answerXY(d.number, idx);
                const [x, y] = project(H, tx, ty);
                ctx.strokeStyle = d.ok ? '#2563eb' : '#dc2626';
                ctx.beginPath(); ctx.arc(x, y, 11, 0, Math.PI * 2); ctx.stroke();
            });
        });

        ctx.restore();
        return out;
    }

    function analyzeCanvas(sourceCanvas, questionConfig) {
        const blueprint = normalizeBlueprint(questionConfig);
        const markerResult = detectMarkers(sourceCanvas);
        const templatePts = [
            TEMPLATE.markers.tl,
            TEMPLATE.markers.tr,
            TEMPLATE.markers.bl,
            TEMPLATE.markers.br
        ];
        const H = homographyFromFour(templatePts, markerResult.points);
        const image = sourceCanvas.getContext('2d', { willReadFrequently: true })
            .getImageData(0, 0, sourceCanvas.width, sourceCanvas.height);

        const nisn = analyzeNisn(image, H);
        const answer = analyzeAnswers(image, H, blueprint);
        let ambiguousCount = nisn.ambiguous + answer.ambiguous;
        if (markerResult.fallback) ambiguousCount++;

        return {
            templateVersion: TEMPLATE.version,
            nisn: nisn.nisn,
            nisnDigits: nisn.digits,
            answers: answer.answers,
            confidence: buildConfidence(nisn, answer, markerResult),
            ambiguousCount,
            markerFallback: markerResult.fallback,
            markerPoints: markerResult.points,
            overlayCanvas: makeOverlay(sourceCanvas, H, markerResult, nisn, answer)
        };
    }

    async function analyze(file, questionConfig) {
        const loaded = await loadImageFromFile(file);
        const sourceCanvas = canvasFromImage(loaded.img, 1800);
        URL.revokeObjectURL(loaded.url);
        return analyzeCanvas(sourceCanvas, questionConfig);
    }

    function markerCheck(canvas) {
        const normalized = canvas.width > 1000
            ? (() => {
                const c = document.createElement('canvas');
                const scale = 1000 / canvas.width;
                c.width = 1000;
                c.height = Math.max(1, Math.round(canvas.height * scale));
                c.getContext('2d', { willReadFrequently: true }).drawImage(canvas, 0, 0, c.width, c.height);
                return c;
            })()
            : canvas;
        const result = detectMarkers(normalized);
        return {
            detected: !result.fallback,
            points: result.points,
            candidateCount: Array.isArray(result.candidates) ? result.candidates.length : 0
        };
    }

    global.EduxidLjkOmr = {
        TEMPLATE,
        analyze,
        analyzeCanvas,
        markerCheck
    };
})(window);
