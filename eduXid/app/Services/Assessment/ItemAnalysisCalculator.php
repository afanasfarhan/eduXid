<?php

namespace App\Services\Assessment;

class ItemAnalysisCalculator
{
    public function analyze(array $questions, array $participants, array $config = []): array
    {
        $cfg = $this->config($config);
        $participants = array_values($participants);
        $questions = array_values($questions);

        foreach ($participants as &$participant) {
            $participant['total_correct'] = $this->totalCorrect($participant['correctness'] ?? []);
        }
        unset($participant);

        $questionIds = array_values(array_map(
            static fn (array $q) => (int) ($q['id'] ?? 0),
            $questions
        ));

        $reliability = $this->cronbachAlpha($participants, $questionIds);
        $items = [];

        foreach ($questions as $question) {
            $qid = (int) ($question['id'] ?? 0);
            $valid = array_values(array_filter(
                $participants,
                static fn (array $p) =>
                    array_key_exists($qid, $p['correctness'] ?? [])
                    && ($p['correctness'][$qid] ?? null) !== null
            ));

            $n = count($valid);
            $correct = 0;
            $blank = 0;

            foreach ($valid as $p) {
                if (($p['correctness'][$qid] ?? false) === true) {
                    $correct++;
                }
                if ($this->isBlank($p['answers'][$qid] ?? null)) {
                    $blank++;
                }
            }

            $pIndex = $n > 0 ? $correct / $n : null;
            $pointBiserial = $this->correctedItemTotalCorrelation($valid, $qid);
            $discrimination = $this->upperLowerDiscrimination(
                $valid,
                $qid,
                (float) $cfg['upper_lower_fraction']
            );

            $options = $this->optionStats(
                $question,
                $valid,
                $qid,
                (float) $cfg['distractor']['minimum_share'],
                (float) $cfg['upper_lower_fraction']
            );

            $remaining = array_values(array_filter(
                $questionIds,
                static fn (int $id) => $id !== $qid
            ));
            $alphaIfDeleted = $this->cronbachAlpha($participants, $remaining);

            [$status, $recommendation] = $this->decision(
                $question,
                $n,
                $pIndex,
                $discrimination,
                $pointBiserial,
                $options,
                $cfg
            );

            $recommendationImprovement = $this->improvementRecommendation(
                $question,
                $n,
                $blank,
                $pIndex,
                $discrimination,
                $pointBiserial,
                $options,
                $status,
                $cfg
            );

            $items[] = [
                'question_id' => $qid,
                'order' => (int) ($question['order'] ?? 0),
                'type' => (string) ($question['type'] ?? ''),
                'stem' => (string) ($question['stem'] ?? ''),
                'material' => (string) ($question['material'] ?? ''),
                'indicator' => (string) ($question['indicator'] ?? ''),
                'cognitive_level' => (string) ($question['cognitive_level'] ?? ''),
                'answer_key' => $this->answerKeyLabel($question['key'] ?? null),
                'participant_count' => $n,
                'correct_count' => $correct,
                'blank_count' => $blank,
                'difficulty_index' => $this->roundNullable($pIndex),
                'difficulty_label' => $this->difficultyLabel($pIndex, $cfg),
                'discrimination_index' => $this->roundNullable($discrimination),
                'discrimination_label' => $this->discriminationLabel($discrimination, $cfg),
                'point_biserial' => $this->roundNullable($pointBiserial),
                'point_biserial_label' => $this->pointBiserialLabel($pointBiserial, $cfg),
                'alpha_if_deleted' => $this->roundNullable($alphaIfDeleted),
                'options' => $options,
                'status' => $status,
                'recommendation' => $recommendation,
                'recommendation_improvement' => $recommendationImprovement,
            ];
        }

        $scores = array_values(array_filter(array_map(
            static fn (array $p) => is_numeric($p['score'] ?? null) ? (float) $p['score'] : null,
            $participants
        ), static fn ($v) => $v !== null));

        $sourceCounts = [];
        foreach ($participants as $p) {
            $source = strtolower((string) ($p['source'] ?? 'unknown'));
            $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;
        }

        $statusCounts = [];
        foreach ($items as $item) {
            $statusCounts[$item['status']] = ($statusCounts[$item['status']] ?? 0) + 1;
        }

        return [
            'summary' => [
                'participant_count' => count($participants),
                'question_count' => count($questions),
                'mean_score' => $this->roundNullable($this->mean($scores), 2),
                'median_score' => $this->roundNullable($this->median($scores), 2),
                'min_score' => $scores !== [] ? round(min($scores), 2) : null,
                'max_score' => $scores !== [] ? round(max($scores), 2) : null,
                'stddev_score' => $this->roundNullable($this->stddev($scores), 2),
                'reliability_alpha' => $this->roundNullable($reliability),
                'reliability_label' => $this->reliabilityLabel($reliability),
                'sample_note' => $this->sampleNote(count($participants), $cfg),
                'source_counts' => $sourceCounts,
                'status_counts' => $statusCounts,
            ],
            'items' => $items,
            'cognitive' => $this->groupStats($items, 'cognitive_level', 'Belum diisi'),
            'indicators' => $this->groupStats($items, 'indicator', 'Tanpa indikator'),
            'participants' => $participants,
        ];
    }

    private function config(array $config): array
    {
        $defaults = [
            'upper_lower_fraction' => 0.27,
            'difficulty' => [
                'difficult_max' => 0.29,
                'moderate_min' => 0.30,
                'moderate_max' => 0.70,
                'easy_min' => 0.71,
            ],
            'discrimination' => [
                'very_good_min' => 0.40,
                'good_min' => 0.30,
                'review_min' => 0.20,
            ],
            'point_biserial' => [
                'good_min' => 0.30,
                'review_min' => 0.20,
            ],
            'distractor' => [
                'minimum_share' => 0.05,
            ],
            'sample' => [
                'very_small_max' => 19,
                'caution_max' => 29,
                'classroom_max' => 49,
            ],
        ];

        return array_replace_recursive($defaults, $config);
    }

    private function totalCorrect(array $correctness, ?int $exclude = null): int
    {
        $sum = 0;
        foreach ($correctness as $qid => $value) {
            if ($exclude !== null && (int) $qid === $exclude) {
                continue;
            }
            if ($value === true) {
                $sum++;
            }
        }
        return $sum;
    }

    private function correctedItemTotalCorrelation(array $participants, int $qid): ?float
    {
        if (count($participants) < 3) {
            return null;
        }

        $x = [];
        $y = [];

        foreach ($participants as $p) {
            $value = $p['correctness'][$qid] ?? null;
            if ($value === null) {
                continue;
            }
            $x[] = $value === true ? 1.0 : 0.0;
            $y[] = (float) $this->totalCorrect($p['correctness'] ?? [], $qid);
        }

        return $this->pearson($x, $y);
    }

    private function upperLowerDiscrimination(array $participants, int $qid, float $fraction): ?float
    {
        $n = count($participants);
        if ($n < 4) {
            return null;
        }

        usort($participants, static function (array $a, array $b): int {
            return ((int) ($b['total_correct'] ?? 0)) <=> ((int) ($a['total_correct'] ?? 0));
        });

        $size = max(1, (int) round($n * $fraction));
        $size = min($size, intdiv($n, 2));

        if ($size < 1) {
            return null;
        }

        $upper = array_slice($participants, 0, $size);
        $lower = array_slice($participants, -$size);

        $pUpper = $this->correctRate($upper, $qid);
        $pLower = $this->correctRate($lower, $qid);

        if ($pUpper === null || $pLower === null) {
            return null;
        }

        return $pUpper - $pLower;
    }

    private function correctRate(array $participants, int $qid): ?float
    {
        $n = 0;
        $correct = 0;

        foreach ($participants as $p) {
            $v = $p['correctness'][$qid] ?? null;
            if ($v === null) {
                continue;
            }
            $n++;
            if ($v === true) {
                $correct++;
            }
        }

        return $n > 0 ? $correct / $n : null;
    }

    private function optionStats(
        array $question,
        array $participants,
        int $qid,
        float $minShare,
        float $fraction
    ): array {
        $type = strtolower((string) ($question['type'] ?? ''));
        $optionMap = is_array($question['options'] ?? null) ? $question['options'] : [];
        $keySet = $this->normalizeSelection($question['key'] ?? null, $type, array_keys($optionMap));

        if ($optionMap === [] && str_contains($type, 'benar_salah')) {
            if ($keySet !== [] && in_array($keySet[0], ['BENAR', 'SALAH'], true)) {
                $optionMap = ['BENAR' => 'Benar', 'SALAH' => 'Salah'];
            } else {
                $optionMap = ['A' => 'Benar', 'B' => 'Salah'];
            }
        }

        if ($optionMap === []) {
            return [];
        }

        $n = count($participants);
        $sorted = $participants;
        usort($sorted, static fn (array $a, array $b) =>
            ((int) ($b['total_correct'] ?? 0)) <=> ((int) ($a['total_correct'] ?? 0))
        );

        $groupSize = $n >= 4
            ? max(1, min((int) round($n * $fraction), intdiv($n, 2)))
            : 0;

        $upperKeys = [];
        $lowerKeys = [];
        if ($groupSize > 0) {
            foreach (array_slice($sorted, 0, $groupSize) as $p) {
                $upperKeys[(string) ($p['participant_key'] ?? '')] = true;
            }
            foreach (array_slice($sorted, -$groupSize) as $p) {
                $lowerKeys[(string) ($p['participant_key'] ?? '')] = true;
            }
        }

        $stats = [];
        foreach ($optionMap as $key => $text) {
            $optionKey = strtoupper(trim((string) $key));
            $count = 0;
            $upper = 0;
            $lower = 0;
            $selectionVector = [];
            $totalExclVector = [];

            foreach ($participants as $p) {
                $selected = $this->normalizeSelection(
                    $p['answers'][$qid] ?? null,
                    $type,
                    array_keys($optionMap)
                );
                $hit = in_array($optionKey, $selected, true);
                if ($hit) {
                    $count++;
                }

                $pk = (string) ($p['participant_key'] ?? '');
                if ($hit && isset($upperKeys[$pk])) {
                    $upper++;
                }
                if ($hit && isset($lowerKeys[$pk])) {
                    $lower++;
                }

                $selectionVector[] = $hit ? 1.0 : 0.0;
                $totalExclVector[] = (float) $this->totalCorrect($p['correctness'] ?? [], $qid);
            }

            $share = $n > 0 ? $count / $n : 0.0;
            $isKey = in_array($optionKey, $keySet, true);
            $corr = $this->pearson($selectionVector, $totalExclVector);

            if ($isKey) {
                $status = 'Kunci';
            } elseif ($count === 0) {
                $status = 'Tidak berfungsi';
            } elseif ($share < $minShare) {
                $status = 'Lemah';
            } elseif ($groupSize > 0 && $upper > $lower) {
                $status = 'Mencurigakan';
            } else {
                $status = 'Efektif';
            }

            $stats[] = [
                'key' => $optionKey,
                'text' => trim(strip_tags((string) $text)),
                'is_key' => $isKey,
                'count' => $count,
                'share' => round($share, 4),
                'upper_count' => $upper,
                'lower_count' => $lower,
                'correlation' => $this->roundNullable($corr),
                'status' => $status,
            ];
        }

        return $stats;
    }

    private function normalizeSelection(mixed $raw, string $type = '', array $optionKeys = []): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $text = trim((string) $raw);
            if ($text === '') {
                return [];
            }

            $decoded = json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $parts = $decoded;
            } else {
                $parts = preg_split('/[\s,;|]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
            }
        }

        $keys = array_map(static fn ($k) => strtoupper(trim((string) $k)), $optionKeys);
        $trueFalseWords = in_array('BENAR', $keys, true) || in_array('SALAH', $keys, true);

        $result = [];
        foreach ($parts as $part) {
            $value = strtoupper(trim(strip_tags((string) $part)));
            if ($value === '') {
                continue;
            }

            if (str_contains($type, 'benar_salah') && $trueFalseWords) {
                if (in_array($value, ['TRUE', 'BENAR', '1', 'YA'], true)) {
                    $value = 'BENAR';
                } elseif (in_array($value, ['FALSE', 'SALAH', '0', 'TIDAK'], true)) {
                    $value = 'SALAH';
                }
            }

            $result[] = $value;
        }

        $result = array_values(array_unique($result));
        sort($result);
        return $result;
    }

    private function decision(
        array $question,
        int $n,
        ?float $difficulty,
        ?float $discrimination,
        ?float $pointBiserial,
        array $options,
        array $cfg
    ): array {
        if ($n === 0 || $difficulty === null) {
            return ['Tidak Dianalisis', 'Belum ada respons valid untuk butir ini.'];
        }

        if (($discrimination !== null && $discrimination < 0)
            || ($pointBiserial !== null && $pointBiserial < 0)) {
            return [
                'Bermasalah',
                'Periksa kunci jawaban, redaksi soal, dan kemungkinan ambiguitas; daya pembeda/korelasi item-total bernilai negatif.',
            ];
        }

        $badDistractors = array_values(array_filter(
            $options,
            static fn (array $o) =>
                !($o['is_key'] ?? false)
                && in_array($o['status'] ?? '', ['Tidak berfungsi', 'Lemah', 'Mencurigakan'], true)
        ));

        $goodDisc = ($discrimination === null || $discrimination >= (float) $cfg['discrimination']['good_min']);
        $goodPb = ($pointBiserial === null || $pointBiserial >= (float) $cfg['point_biserial']['good_min']);
        $moderate = $difficulty >= (float) $cfg['difficulty']['moderate_min']
            && $difficulty <= (float) $cfg['difficulty']['moderate_max'];

        if ($badDistractors !== []) {
            return [
                'Perbaiki',
                'Perbaiki distraktor yang tidak berfungsi/lemah atau justru lebih menarik kelompok atas.',
            ];
        }

        if ($moderate && $goodDisc && $goodPb) {
            return ['Pertahankan', 'Statistik butir mendukung untuk dipertahankan.'];
        }

        if ($difficulty <= 0.05 || $difficulty >= 0.95) {
            return [
                'Tinjau',
                'Butir sangat ekstrem (sangat sukar/sangat mudah); tinjau kesesuaian dengan tujuan penilaian.',
            ];
        }

        if (($discrimination !== null && $discrimination < (float) $cfg['discrimination']['review_min'])
            || ($pointBiserial !== null && $pointBiserial < (float) $cfg['point_biserial']['review_min'])) {
            return [
                'Tinjau',
                'Daya pembeda/korelasi item-total masih lemah; periksa redaksi, kunci, dan kesesuaian indikator.',
            ];
        }

        return ['Tinjau', 'Pertimbangkan konteks indikator, tingkat kognitif, dan tujuan tes sebelum memutuskan.'];
    }

    private function improvementRecommendation(
        array $question,
        int $n,
        int $blank,
        ?float $difficulty,
        ?float $discrimination,
        ?float $pointBiserial,
        array $options,
        string $status,
        array $cfg
    ): string {
        if ($n === 0 || $difficulty === null) {
            return 'Kumpulkan respons peserta yang valid terlebih dahulu sebelum melakukan revisi berdasarkan statistik.';
        }

        $actions = [];
        $type = strtolower((string) ($question['type'] ?? ''));

        if (($discrimination !== null && $discrimination < 0)
            || ($pointBiserial !== null && $pointBiserial < 0)) {
            $actions[] = 'Periksa ulang kunci jawaban dan aturan penskoran; bandingkan pilihan kelompok atas dengan kunci untuk mendeteksi kemungkinan kunci salah.';
            $actions[] = 'Tinjau redaksi pokok soal terhadap kemungkinan ambigu, lebih dari satu jawaban benar, petunjuk yang menyesatkan, atau ketidaksesuaian dengan indikator.';
        }

        if ($difficulty <= 0.05) {
            $actions[] = 'Butir sangat sukar: verifikasi kunci dan materi yang memang telah diajarkan; sederhanakan redaksi atau kompleksitas yang tidak relevan bila kesukaran tidak disengaja.';
        } elseif ($difficulty < (float) $cfg['difficulty']['moderate_min']) {
            $actions[] = 'Butir sukar: pastikan tuntutan kognitif sesuai tujuan; bila bukan untuk menyeleksi kemampuan tinggi, kurangi kerumitan yang tidak perlu dan perjelas informasi penting.';
        } elseif ($difficulty >= 0.95) {
            $actions[] = 'Butir sangat mudah: bila bukan butir prasyarat/penguasaan dasar, tingkatkan tuntutan penalaran, kurangi petunjuk jawaban, atau gunakan stimulus yang lebih bermakna.';
        } elseif ($difficulty > (float) $cfg['difficulty']['moderate_max']) {
            $actions[] = 'Butir mudah: pertahankan bila ditujukan mengukur kompetensi dasar; bila diperlukan daya ukur lebih tinggi, naikkan kompleksitas konteks atau level kognitif.';
        }

        if (($discrimination !== null && $discrimination >= 0 && $discrimination < (float) $cfg['discrimination']['review_min'])
            || ($pointBiserial !== null && $pointBiserial >= 0 && $pointBiserial < (float) $cfg['point_biserial']['review_min'])) {
            $actions[] = 'Perkuat daya pembeda dengan memperjelas fokus pertanyaan, memastikan hanya peserta yang menguasai indikator yang memperoleh jawaban benar, dan menghapus petunjuk jawaban yang terlalu jelas.';
        }

        $badOptions = [];
        $suspiciousOptions = [];
        foreach ($options as $option) {
            if (($option['is_key'] ?? false) === true) {
                continue;
            }
            $key = (string) ($option['key'] ?? '?');
            $optionStatus = (string) ($option['status'] ?? '');
            if (in_array($optionStatus, ['Tidak berfungsi', 'Lemah'], true)) {
                $badOptions[] = $key;
            } elseif ($optionStatus === 'Mencurigakan') {
                $suspiciousOptions[] = $key;
            }
        }

        if ($badOptions !== []) {
            $actions[] = 'Ganti/perbaiki distraktor ' . implode(', ', $badOptions)
                . ' dengan pengecoh yang masuk akal dan berasal dari miskonsepsi umum peserta, tanpa membuatnya terlalu mudah dieliminasi.';
        }

        if ($suspiciousOptions !== []) {
            $actions[] = 'Tinjau distraktor ' . implode(', ', $suspiciousOptions)
                . ' karena lebih menarik kelompok berkemampuan tinggi; periksa kemungkinan opsi tersebut juga benar atau redaksi/kunci bermasalah.';
        }

        $blankShare = $n > 0 ? $blank / $n : 0.0;
        if ($blankShare >= 0.10) {
            $actions[] = 'Jawaban kosong relatif tinggi (' . number_format($blankShare * 100, 1, ',', '.')
                . '%): periksa kejelasan instruksi, waktu pengerjaan, keterbacaan stimulus, dan kemungkinan kendala teknis/pemindaian.';
        }

        if (str_contains($type, 'majemuk')) {
            $actions[] = 'Untuk pilihan majemuk, pastikan seluruh kombinasi kunci benar-benar diperlukan dan aturan skor sesuai desain; hindari opsi yang maknanya tumpang tindih.';
        }

        if ($actions === [] && $status === 'Pertahankan') {
            $actions[] = 'Tidak diperlukan revisi statistik utama. Pertahankan butir, simpan sebagai butir terkalibrasi, dan pantau kembali statistiknya pada penggunaan berikutnya.';
        } elseif ($actions === []) {
            $actions[] = 'Lakukan telaah isi oleh guru/mapel: cek kesesuaian indikator, level kognitif, bahasa, kunci, dan konteks sebelum memutuskan revisi atau penggunaan ulang.';
        }

        if ($n < 30) {
            $actions[] = 'Karena jumlah peserta kurang dari 30, gunakan rekomendasi ini sebagai indikasi dan konfirmasikan kembali pada penggunaan berikutnya dengan sampel lebih besar.';
        }

        return implode(' ', array_values(array_unique($actions)));
    }

    private function difficultyLabel(?float $value, array $cfg): string
    {
        if ($value === null) return '-';
        if ($value <= (float) $cfg['difficulty']['difficult_max']) return 'Sukar';
        if ($value >= (float) $cfg['difficulty']['easy_min']) return 'Mudah';
        return 'Sedang';
    }

    private function discriminationLabel(?float $value, array $cfg): string
    {
        if ($value === null) return '-';
        if ($value < 0) return 'Negatif';
        if ($value >= (float) $cfg['discrimination']['very_good_min']) return 'Sangat baik';
        if ($value >= (float) $cfg['discrimination']['good_min']) return 'Baik';
        if ($value >= (float) $cfg['discrimination']['review_min']) return 'Perlu ditinjau';
        return 'Lemah';
    }

    private function pointBiserialLabel(?float $value, array $cfg): string
    {
        if ($value === null) return '-';
        if ($value < 0) return 'Negatif';
        if ($value >= 0.40) return 'Sangat baik';
        if ($value >= (float) $cfg['point_biserial']['good_min']) return 'Baik';
        if ($value >= (float) $cfg['point_biserial']['review_min']) return 'Perlu ditinjau';
        return 'Lemah';
    }

    private function reliabilityLabel(?float $value): string
    {
        if ($value === null) return 'Belum dapat dihitung';
        if ($value >= 0.90) return 'Sangat tinggi';
        if ($value >= 0.80) return 'Baik';
        if ($value >= 0.70) return 'Cukup';
        if ($value >= 0.60) return 'Perlu perhatian';
        return 'Rendah';
    }

    private function sampleNote(int $n, array $cfg): string
    {
        if ($n === 0) return 'Belum ada peserta valid.';
        if ($n <= (int) $cfg['sample']['very_small_max']) {
            return "N={$n} - sampel sangat kecil; hasil analisis bersifat indikatif.";
        }
        if ($n <= (int) $cfg['sample']['caution_max']) {
            return "N={$n} - interpretasikan dengan hati-hati.";
        }
        if ($n <= (int) $cfg['sample']['classroom_max']) {
            return "N={$n} - cukup untuk analisis kelas, namun tetap gunakan pertimbangan isi.";
        }
        return "N={$n} - estimasi statistik relatif lebih stabil.";
    }

    private function groupStats(array $items, string $field, string $fallback): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = trim((string) ($item[$field] ?? '')) ?: $fallback;
            $groups[$key] ??= ['label' => $key, 'items' => 0, 'sum_p' => 0.0, 'n_p' => 0];
            $groups[$key]['items']++;
            if ($item['difficulty_index'] !== null) {
                $groups[$key]['sum_p'] += (float) $item['difficulty_index'];
                $groups[$key]['n_p']++;
            }
        }

        foreach ($groups as &$g) {
            $g['mean_correct_pct'] = $g['n_p'] > 0
                ? round(($g['sum_p'] / $g['n_p']) * 100, 1)
                : null;
            unset($g['sum_p'], $g['n_p']);
        }
        unset($g);

        uasort($groups, static fn ($a, $b) => strcmp($a['label'], $b['label']));
        return array_values($groups);
    }

    private function cronbachAlpha(array $participants, array $qids): ?float
    {
        $qids = array_values(array_filter(array_map('intval', $qids), static fn ($id) => $id > 0));
        if (count($qids) < 2 || count($participants) < 2) {
            return null;
        }

        $rows = [];
        foreach ($participants as $p) {
            $row = [];
            $complete = true;
            foreach ($qids as $qid) {
                $v = $p['correctness'][$qid] ?? null;
                if ($v === null) {
                    $complete = false;
                    break;
                }
                $row[] = $v === true ? 1.0 : 0.0;
            }
            if ($complete) {
                $rows[] = $row;
            }
        }

        if (count($rows) < 2) {
            return null;
        }

        $k = count($qids);
        $itemVarSum = 0.0;

        for ($j = 0; $j < $k; $j++) {
            $column = array_map(static fn ($row) => $row[$j], $rows);
            $itemVarSum += $this->variance($column) ?? 0.0;
        }

        $totals = array_map(static fn ($row) => array_sum($row), $rows);
        $totalVar = $this->variance($totals);

        if ($totalVar === null || $totalVar <= 0) {
            return null;
        }

        return ($k / ($k - 1)) * (1 - ($itemVarSum / $totalVar));
    }

    private function pearson(array $x, array $y): ?float
    {
        $n = min(count($x), count($y));
        if ($n < 3) {
            return null;
        }

        $x = array_slice($x, 0, $n);
        $y = array_slice($y, 0, $n);
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $num = 0.0;
        $dx = 0.0;
        $dy = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $a = $x[$i] - $mx;
            $b = $y[$i] - $my;
            $num += $a * $b;
            $dx += $a * $a;
            $dy += $b * $b;
        }

        if ($dx <= 0 || $dy <= 0) {
            return null;
        }

        return $num / sqrt($dx * $dy);
    }

    private function variance(array $values): ?float
    {
        $n = count($values);
        if ($n < 2) return null;
        $mean = array_sum($values) / $n;
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }
        return $sum / ($n - 1);
    }

    private function stddev(array $values): ?float
    {
        $variance = $this->variance($values);
        return $variance === null ? null : sqrt($variance);
    }

    private function mean(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    private function median(array $values): ?float
    {
        if ($values === []) return null;
        sort($values, SORT_NUMERIC);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? (float) $values[$mid] : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
    }

    private function isBlank(mixed $value): bool
    {
        if (is_array($value)) {
            return count(array_filter($value, static fn ($v) => trim((string) $v) !== '')) === 0;
        }
        return trim((string) $value) === '';
    }

    private function answerKeyLabel(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        $text = trim((string) $value);
        if ($text === '') return '-';

        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return implode(', ', array_map('strval', $decoded));
        }

        return $text;
    }

    private function roundNullable(?float $value, int $precision = 3): ?float
    {
        return $value === null ? null : round($value, $precision);
    }
}
