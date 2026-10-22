<?php

namespace App\Support;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Baca gambar XLSX langsung dari OOXML; tidak bergantung pada event
 * drawing Laravel-Excel yang bisa tidak dipanggil dalam mode read-only.
 * Nomor baris Excel (1-based) => data URL gambar.
 */
final class ExcelQuestionImageReader
{
    private const MAX_IMAGE_BYTES = 204800;
    private const MAX_IMAGES = 500;
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const SHEET_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const DRAW_NS = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';
    private const A_NS = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    public static function fromXlsx(string $filename): array
    {
        if (!class_exists(ZipArchive::class) || !class_exists(DOMDocument::class)) {
            throw new RuntimeException('Impor gambar Excel membutuhkan ekstensi PHP zip dan dom/xml.');
        }
        $zip = new ZipArchive();
        if ($zip->open($filename, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('File XLSX tidak dapat dibuka sebagai arsip Excel.');
        }
        try {
            $workbook = self::document($zip, 'xl/workbook.xml');
            $xp = new DOMXPath($workbook);
            $xp->registerNamespace('m', self::SHEET_NS);
            $xp->registerNamespace('r', self::REL_NS);
            $first = $xp->query('//m:sheets/m:sheet')->item(0);
            if (!$first) {
                throw new RuntimeException('File Excel tidak memiliki lembar Bank Soal.');
            }
            $sheetRel = $first->getAttributeNS(self::REL_NS, 'id');
            $relations = self::relationships($zip, 'xl/workbook.xml');
            $sheetRef = $relations[$sheetRel] ?? null;
            if (!$sheetRef || !str_ends_with($sheetRef['type'], '/worksheet')) {
                throw new RuntimeException('Relasi lembar Bank Soal tidak valid.');
            }
            $sheetPath = self::partPath('xl/workbook.xml', $sheetRef);
            $sheet = self::document($zip, $sheetPath);
            $sx = new DOMXPath($sheet);
            $sx->registerNamespace('m', self::SHEET_NS);
            $drawingRels = self::relationships($zip, $sheetPath);
            $result = [];
            $foundDrawing = false;
            foreach ($sx->query('//m:drawing') as $drawingLink) {
                $ref = $drawingRels[$drawingLink->getAttributeNS(self::REL_NS, 'id')] ?? null;
                if (!$ref || !str_ends_with($ref['type'], '/drawing')) {
                    throw new RuntimeException('Referensi gambar pada lembar Excel tidak valid.');
                }
                $foundDrawing = true;
                $drawingPath = self::partPath($sheetPath, $ref);
                $drawingDoc = self::document($zip, $drawingPath);
                $dx = new DOMXPath($drawingDoc);
                $dx->registerNamespace('xdr', self::DRAW_NS);
                $dx->registerNamespace('a', self::A_NS);
                $dx->registerNamespace('r', self::REL_NS);
                $imageRels = self::relationships($zip, $drawingPath);
                foreach ($dx->query('//xdr:oneCellAnchor | //xdr:twoCellAnchor') as $anchor) {
                    $from = $dx->query('./xdr:from', $anchor)->item(0);
                    $blip = $dx->query('./xdr:pic/xdr:blipFill/a:blip', $anchor)->item(0);
                    if (!$from || !$blip) {
                        continue;
                    }
                    $column = (int) $dx->evaluate('string(xdr:col)', $from);
                    $row = (int) $dx->evaluate('string(xdr:row)', $from) + 1;
                    if ($row < 2) {
                        continue;
                    }
                    if ($column !== 15) {
                        throw new RuntimeException("Baris {$row}: gambar harus ditambatkan di kolom P (Gambar Soal).");
                    }
                    if (isset($result[$row])) {
                        throw new RuntimeException("Baris {$row}: gambar ganda pada satu soal tidak didukung.");
                    }
                    if (count($result) >= self::MAX_IMAGES) {
                        throw new RuntimeException('Maksimal 500 gambar per berkas impor Excel.');
                    }
                    $embed = $blip->getAttributeNS(self::REL_NS, 'embed');
                    $rel = $imageRels[$embed] ?? null;
                    if (!$rel || !str_ends_with($rel['type'], '/image')) {
                        throw new RuntimeException("Baris {$row}: berkas gambar tertanam tidak ditemukan.");
                    }
                    $mediaPath = self::partPath($drawingPath, $rel);
                    $stat = $zip->statName($mediaPath);
                    if (!$stat || ($stat['size'] ?? 0) > self::MAX_IMAGE_BYTES) {
                        throw new RuntimeException("Baris {$row}: gambar harus berukuran maksimal 200 KB.");
                    }
                    $data = $zip->getFromName($mediaPath);
                    if (!is_string($data) || $data === '' || strlen($data) > self::MAX_IMAGE_BYTES) {
                        throw new RuntimeException("Baris {$row}: gambar tertanam gagal dibaca atau terlalu besar.");
                    }
                    $metadata = @getimagesizefromstring($data);
                    $mime = is_array($metadata) ? (string) ($metadata['mime'] ?? '') : '';
                    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                        throw new RuntimeException("Baris {$row}: gambar hanya boleh PNG, JPEG, atau WebP.");
                    }
                    $result[$row] = 'data:' . $mime . ';base64,' . base64_encode($data);
                }
            }
            // Gambar model 'Place in Cell' (richData) tidak boleh diabaikan diam-diam.
            if (!$foundDrawing) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    if (str_starts_with($name, 'xl/media/') || str_starts_with($name, 'xl/richData/')) {
                        throw new RuntimeException('Gambar Excel tidak dikenali. Gunakan Insert > Pictures > Place over Cells, lalu posisikan gambar di kolom P.');
                    }
                }
            }
            ksort($result, SORT_NUMERIC);
            return $result;
        } finally {
            $zip->close();
        }
    }

    private static function document(ZipArchive $zip, string $part): DOMDocument
    {
        $raw = $zip->getFromName($part);
        if (!is_string($raw) || $raw === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $raw)) {
            throw new RuntimeException("Struktur Excel tidak valid: {$part}.");
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadXML($raw, LIBXML_NONET)) {
                throw new RuntimeException("XML Excel tidak dapat dibaca: {$part}.");
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $dom;
    }

    private static function relationships(ZipArchive $zip, string $source): array
    {
        $relPart = dirname($source) . '/_rels/' . basename($source) . '.rels';
        if ($zip->locateName($relPart) === false) {
            return [];
        }
        $doc = self::document($zip, $relPart);
        $map = [];
        foreach ($doc->getElementsByTagNameNS('http://schemas.openxmlformats.org/package/2006/relationships', 'Relationship') as $rel) {
            $map[$rel->getAttribute('Id')] = [
                'target' => $rel->getAttribute('Target'),
                'type' => $rel->getAttribute('Type'),
                'mode' => $rel->getAttribute('TargetMode'),
            ];
        }
        return $map;
    }

    private static function partPath(string $source, array $relation): string
    {
        $target = (string) ($relation['target'] ?? '');
        if (($relation['mode'] ?? '') === 'External' || $target === '' || preg_match('~^[a-z][a-z0-9+.-]*:~i', $target)) {
            throw new RuntimeException('Referensi gambar eksternal tidak diperbolehkan dalam Excel.');
        }
        $combined = str_starts_with($target, '/') ? ltrim($target, '/') : dirname($source) . '/' . $target;
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $combined)) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                if (!$parts) throw new RuntimeException('Path di dalam Excel tidak aman.');
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        $normalized = implode('/', $parts);
        if (!str_starts_with($normalized, 'xl/')) {
            throw new RuntimeException('Referensi gambar berada di luar konten Excel.');
        }
        return $normalized;
    }
}
