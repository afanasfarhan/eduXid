<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Word DOCX OOXML reader: deterministic-first, offline, no external calls.
 * Parses visible paragraphs, native Word numbering, tables and embedded media.
 * Any incomplete result remains a REVIEW draft; no answer keys are inferred.
 */
final class WordQuestionImportService
{
    private const WORD = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const DRAW = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const VML = 'urn:schemas-microsoft-com:vml';

    public function parse(string $path): array
    {
        if (!class_exists(ZipArchive::class) || !class_exists(DOMDocument::class)) {
            throw new RuntimeException('PHP memerlukan ekstensi ZIP dan DOM/XML untuk membaca Word.');
        }
        if (!is_file($path) || (filesize($path) ?: 0) > 10 * 1024 * 1024) {
            throw new RuntimeException('Dokumen Word tidak valid atau lebih besar dari 10 MB.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('File bukan dokumen DOCX yang dapat dibaca.');
        try {
            if ($zip->numFiles > 1000) throw new RuntimeException('Isi DOCX terlalu banyak.');
            $expanded = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $expanded += (int)($stat['size'] ?? 0);
                if ($expanded > 30 * 1024 * 1024) throw new RuntimeException('Dokumen Word terlalu besar setelah diekstrak.');
            }
            $xml = $zip->getFromName('word/document.xml');
            if (!is_string($xml) || $xml === '' || strlen($xml) > 12 * 1024 * 1024) {
                throw new RuntimeException('Struktur utama dokumen Word tidak tersedia.');
            }
            $dom = $this->xml($xml);
            $xp = $this->xpath($dom);
            $rels = $this->relationships($zip);
            $numInfo = $this->numbering($zip);
            $blocks = [];
            $body = $xp->query('/w:document/w:body')->item(0);
            if (!$body) throw new RuntimeException('Isi DOCX tidak ditemukan.');
            foreach ($body->childNodes as $node) {
                if (!$node instanceof DOMElement) continue;
                if ($node->namespaceURI !== self::WORD) continue;
                if ($node->localName === 'p') {
                    $t = $this->paragraphText($xp, $node);
                    $media = $this->images($xp, $node, $rels, $zip);
                    $listInfo = $this->listInfo($xp, $node, $numInfo);
                    if ($t !== '' || $media) $blocks[] = ['type'=>'p','text'=>$t,'level'=>$listInfo['level'],'list_id'=>$listInfo['id'],'images'=>$media];
                } elseif ($node->localName === 'tbl') {
                    $html = $this->table($xp, $node);
                    if ($html !== '') $blocks[] = ['type'=>'table','html'=>$html];
                }
            }
            return $this->assemble($blocks);
        } finally {
            $zip->close();
        }
    }

    private function xml(string $content): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $old = libxml_use_internal_errors(true);
        try {
            if (!$doc->loadXML($content, LIBXML_NONET | LIBXML_COMPACT)) throw new RuntimeException('XML pada DOCX tidak valid.');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        return $doc;
    }

    private function xpath(DOMDocument $doc): DOMXPath
    {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('w', self::WORD);
        $xp->registerNamespace('a', self::DRAW);
        $xp->registerNamespace('r', self::REL);
        $xp->registerNamespace('v', self::VML);
        return $xp;
    }

    private function relationships(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('word/_rels/document.xml.rels');
        if (!is_string($xml)) return [];
        $xp = new DOMXPath($this->xml($xml));
        $out = [];
        foreach ($xp->query('//*[local-name()="Relationship"]') as $n) {
            if (!$n instanceof DOMElement || strtolower($n->getAttribute('TargetMode')) === 'external') continue;
            $target = str_replace('\\','/', $n->getAttribute('Target'));
            // Only embedded image files in word/media; never traverse other archive paths.
            if (str_starts_with($target, '/word/media/')) $target = substr($target, 1);
            elseif (str_starts_with($target, 'media/')) $target = 'word/' . $target;
            if (preg_match('~^word/media/[A-Za-z0-9_.-]+\.(png|jpe?g|webp)$~i', $target)) {
                $out[$n->getAttribute('Id')] = $target;
            }
        }
        return $out;
    }

    private function numbering(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('word/numbering.xml');
        if (!is_string($raw)) return [];
        $xp = $this->xpath($this->xml($raw));
        $abstract = [];
        foreach ($xp->query('//w:abstractNum') as $n) {
            $id = $n->getAttributeNS(self::WORD, 'abstractNumId');
            foreach ($xp->query('./w:lvl', $n) as $level) {
                $i = (int)$level->getAttributeNS(self::WORD, 'ilvl');
                $abstract[$id][$i] = true;
            }
        }
        $mapping = [];
        foreach ($xp->query('//w:num') as $n) {
            $numId = $n->getAttributeNS(self::WORD, 'numId');
            $ref = $xp->query('./w:abstractNumId', $n)->item(0);
            if ($ref instanceof DOMElement) $mapping[$numId] = $abstract[$ref->getAttributeNS(self::WORD, 'val')] ?? [];
        }
        return $mapping;
    }

    private function listInfo(DOMXPath $xp, DOMElement $p, array $nums): array
    {
        $level = $xp->query('./w:pPr/w:numPr/w:ilvl', $p)->item(0);
        $numId = $xp->query('./w:pPr/w:numPr/w:numId', $p)->item(0);
        if ($level instanceof DOMElement && $numId instanceof DOMElement) {
            $l = (int)$level->getAttributeNS(self::WORD, 'val');
            $id = $numId->getAttributeNS(self::WORD, 'val');
            return ['level'=>$l, 'id'=>$id];
        }
        return ['level'=>null,'id'=>null];
    }

    private function paragraphText(DOMXPath $xp, DOMElement $p): string
    {
        $pieces = [];
        foreach ($xp->query('.//w:t | .//w:tab | .//w:br', $p) as $node) {
            $pieces[] = $node->localName === 't' ? $node->textContent : ' ';
        }
        return trim((string)preg_replace('/\s+/u', ' ', implode('', $pieces)));
    }

    private function table(DOMXPath $xp, DOMElement $table): string
    {
        $rows = [];
        foreach ($xp->query('./w:tr', $table) as $row) {
            $cells = [];
            foreach ($xp->query('./w:tc', $row) as $cell) {
                $parts = [];
                foreach ($xp->query('./w:p', $cell) as $p) {
                    $t = $this->paragraphText($xp, $p);
                    if ($t !== '') $parts[] = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                $spanEl = $xp->query('./w:tcPr/w:gridSpan', $cell)->item(0);
                $colspan = $spanEl instanceof DOMElement ? (int)$spanEl->getAttributeNS(self::WORD, 'val') : 1;
                $colspan = max(1, min(12, $colspan));
                $cells[] = '<td'.($colspan > 1 ? ' colspan="'.$colspan.'"' : '').'>'.implode('<br>', $parts).'</td>';
            }
            if ($cells) $rows[] = '<tr>'.implode('', $cells).'</tr>';
        }
        return $rows ? '<table><tbody>'.implode('', $rows).'</tbody></table>' : '';
    }

    private function images(DOMXPath $xp, DOMElement $p, array $rels, ZipArchive $zip): array
    {
        $out = [];
        foreach ($xp->query('.//a:blip | .//v:imagedata', $p) as $node) {
            $id = $node->getAttributeNS(self::REL, $node->localName === 'blip' ? 'embed' : 'id');
            $file = $rels[$id] ?? null;
            if (!$file) continue;
            $bytes = $zip->getFromName($file);
            if (!is_string($bytes) || strlen($bytes) > 180 * 1024 || strlen($bytes) === 0) continue;
            $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                'png'=>'image/png','jpg','jpeg'=>'image/jpeg','webp'=>'image/webp',default=>null
            };
            // Reject forged extensions and active content. Basic magic-byte checks.
            $signature = $mime === 'image/png' ? str_starts_with($bytes, "\x89PNG\r\n\x1a\n")
                : ($mime === 'image/jpeg' ? str_starts_with($bytes, "\xff\xd8\xff") : ($mime === 'image/webp' ? substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' : false));
            if ($signature) $out[] = 'data:'.$mime.';base64,'.base64_encode($bytes);
        }
        return array_values(array_unique($out));
    }

    private function assemble(array $blocks): array
    {
        $questions = [];
        $current = null;
        $counter = 0;
        $questionListId = null;
        $lastOption = null;
        $pending = [];
        $shared = [];
        $warnings = [];
        $metadata = [];
        $flush = static function () use (&$current, &$questions, &$lastOption): void {
            if ($current === null) return;
            $current['text'] = trim($current['text']);
            if ($current['text'] !== '') $questions[] = $current;
            $current = null;
            $lastOption = null;
        };
        foreach ($blocks as $block) {
            if ($block['type'] === 'table') {
                if ($current !== null && count($current['options']) < 5) {
                    $current['text'] .= "\n".$block['html'];
                } elseif ($current !== null && count($current['options']) === 5) {
                    $pending[] = ['text'=>'','table'=>$block['html']];
                } else {
                    $pending[] = ['text'=>'','table'=>$block['html']];
                }
                continue;
            }
            $text = $block['text'];
            $level = $block['level'];
            $listId = $block['list_id'] ?? null;
            $media = $block['images'];
            if (preg_match('/^Mata Pelajaran\s*:\s*(.+)$/iu', $text, $m)) $metadata['mapel'] = trim($m[1]);
            if (preg_match('/^Kelas\s*:\s*(.+)$/iu', $text, $m)) $metadata['kelas'] = trim($m[1]);
            // Word may use level ZERO for A-E options with a different numId.
            // The first level-0 list identifies question numbering; other list IDs
            // are never promoted to questions based solely on indentation.
            if ($questionListId === null && $level === 0 && $listId !== null) $questionListId = $listId;
            $isQuestion = $level === 0 && $listId === $questionListId;
            $explicitNo = null;
            if (!$isQuestion && preg_match('/^(\d{1,3})\s*[.\)]\s+(.+)$/us', $text, $m)) {
                $n = (int)$m[1];
                if ($n > 0 && ($current !== null || $n <= 3 || $n === $counter+1)) {
                    $explicitNo = $n;
                    $isQuestion = true;
                    $text = $m[2];
                }
            }
            // Some Word documents (including a table-led question) omit the
            // question-number paragraph but keep a new numbered option list.
            // An option list following a completed five-choice question and
            // orphan stimulus becomes the next question, not six extra choices.
            if (!$isQuestion && $current !== null && count($current['options']) === 5
                && $level !== null && $listId !== $questionListId && $pending !== []) {
                $flush();
                $counter++;
                $current = ['number'=>$counter,'text'=>'','options'=>[],'image'=>null,'warnings'=>['Nomor soal asli tidak eksplisit; periksa urutan soal.'],'raw'=>''];
                foreach ($pending as $entry) {
                    if (($entry['table'] ?? '') !== '') $current['text'] .= $entry['table']."\n";
                    elseif (($entry['text'] ?? '') !== '') $current['text'] .= htmlspecialchars($entry['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."\n";
                    if (!empty($entry['image'])) $current['image'] ??= $entry['image'];
                }
                $pending = [];
            }
            if ($isQuestion) {
                $flush();
                $counter = $explicitNo ?? ($counter + 1);
                $current = ['number'=>$counter,'text'=>'','options'=>[],'image'=>null,'warnings'=>[],'raw'=>''];
                foreach ($pending as $entry) {
                    if (($entry['table'] ?? '') !== '') $current['text'] .= $entry['table']."\n";
                    elseif (($entry['text'] ?? '') !== '') $current['text'] .= htmlspecialchars($entry['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."\n";
                    if (!empty($entry['image'])) $current['image'] ??= $entry['image'];
                }
                $pending = [];
                $current['text'] .= htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $current['raw'] .= $text;
                if ($media) $current['image'] = $media[0];
                continue;
            }
            $letter = null;
            if (preg_match('/^([A-Ea-e])\s*[.\)\:\-]\s*(.+)$/us', $text, $m)) {
                $letter = strtoupper($m[1]);
                $text = $m[2];
            } elseif ($level !== null && ($level >= 1 || ($listId !== null && $listId !== $questionListId)) && $current !== null) {
                $letter = chr(65 + min(4, count($current['options'])));
            }
            if ($letter !== null && $current !== null && !isset($current['options'][$letter])) {
                $current['options'][$letter] = $text;
                $current['raw'] .= '\n'.$letter.'. '.$text;
                $lastOption = $letter;
                continue;
            }
            if ($current === null || (count($current['options']) >= 5 && $level === null)) {
                // Skip masthead logos before the first question. After a
                // complete question retain all prose/tables awaiting next.
                if (($current !== null && (count($current['options']) >= 5))
                    || ($text !== '' && preg_match('/(untuk\s+soal\s+nomor|soal\s+nomor\s+\d+|grafik\s+(batang|berikut)|perhatikan\s+gambar)/iu', $text))) {
                    $pending[] = ['text'=>$text,'image'=>$media[0] ?? null];
                    if ($media && $shared) {
                        $last = count($shared) - 1;
                        if ($shared[$last]['image'] === null) $shared[$last]['image'] = $media[0];
                    }
                    if (preg_match('/(?:nomor)\s+(\d+)\s*(?:s\.?d\.?|sampai|hingga|[-–])\s*(\d+)/iu', $text, $m)) {
                        $shared[] = ['from'=>(int)$m[1], 'to'=>(int)$m[2], 'text'=>$text, 'image'=>$media[0] ?? null];
                    }
                }
                continue;
            }
            if ($media && $current !== null) $current['image'] ??= $media[0];
            if ($text === '') continue;
            if ($lastOption !== null && $level !== null && $level > 0) {
                $current['options'][$lastOption] .= ' '.$text;
            } else {
                $current['text'] .= "\n".htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $current['raw'] .= "\n".$text;
        }
        $flush();
        // Shared prompt/graphic is copied to each affected question for independent CBT/print rendering.
        foreach ($questions as &$q) {
            foreach ($shared as $s) {
                if ($q['number'] < $s['from'] || $q['number'] > $s['to']) continue;
                if ($q['number'] !== $s['from']) {
                    $q['text'] = htmlspecialchars($s['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."\n".$q['text'];
                }
                if ($s['image']) $q['image'] ??= $s['image'];
            }
            $q['text'] = nl2br($q['text'], false);
            if (count($q['options']) !== 5 || array_keys($q['options']) !== ['A','B','C','D','E']) {
                $q['warnings'][] = 'Opsi belum lengkap atau urutannya tidak dikenali. Periksa pada pratinjau.';
            }
            if (preg_match('/<table\b/', $q['text'])) $q['warnings'][] = 'Tabel dari Word dipertahankan. Periksa tata letaknya sebelum simpan.';
            if ($q['image']) $q['warnings'][] = 'Gambar/grafik dari DOCX terlampir. Periksa kecocokan stimulus.';
            unset($q['raw']);
        }
        unset($q);
        if (!$questions) throw new RuntimeException('Nomor soal tidak dapat dikenali. Gunakan daftar bernomor Word atau tulis nomor 1., 2., dst.');
        if (count($questions) > 80) throw new RuntimeException('Maksimum 80 soal dalam satu impor Word agar formulir tetap aman. Pisahkan dokumen menjadi beberapa berkas.');
        return ['metadata'=>$metadata, 'questions'=>$questions, 'warnings'=>$warnings, 'ai_status'=>'off'];
    }
}
