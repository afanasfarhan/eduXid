<?php

namespace App\Services\Assessment;

/**
 * Renderer matematika khusus PDF.
 *
 * Dompdf tidak menjalankan JavaScript KaTeX. Karena itu ekspresi KaTeX/LaTeX
 * yang tersimpan di Bank Soal diubah menjadi HTML cetak yang aman:
 * pecahan, akar, superscript/subscript, huruf Yunani, vektor, dan operator.
 *
 * Ini menjaga sintaks $...$, $$...$$, \( ... \), \[ ... \] terbaca
 * sebagai matematika pada PDF tanpa memerlukan Node/Chromium saat runtime.
 */
class PdfMathRenderer
{
    private array $symbols = [
        '\\alpha' => 'α', '\\beta' => 'β', '\\gamma' => 'γ', '\\delta' => 'δ',
        '\\epsilon' => 'ε', '\\varepsilon' => 'ε', '\\zeta' => 'ζ', '\\eta' => 'η',
        '\\theta' => 'θ', '\\vartheta' => 'ϑ', '\\iota' => 'ι', '\\kappa' => 'κ',
        '\\lambda' => 'λ', '\\mu' => 'μ', '\\nu' => 'ν', '\\xi' => 'ξ',
        '\\pi' => 'π', '\\rho' => 'ρ', '\\sigma' => 'σ', '\\tau' => 'τ',
        '\\upsilon' => 'υ', '\\phi' => 'φ', '\\varphi' => 'ϕ', '\\chi' => 'χ',
        '\\psi' => 'ψ', '\\omega' => 'ω',
        '\\Gamma' => 'Γ', '\\Delta' => 'Δ', '\\Theta' => 'Θ', '\\Lambda' => 'Λ',
        '\\Xi' => 'Ξ', '\\Pi' => 'Π', '\\Sigma' => 'Σ', '\\Phi' => 'Φ',
        '\\Psi' => 'Ψ', '\\Omega' => 'Ω',
        '\\times' => '×', '\\cdot' => '·', '\\pm' => '±', '\\mp' => '∓',
        '\\leq' => '≤', '\\le' => '≤', '\\geq' => '≥', '\\ge' => '≥',
        '\\neq' => '≠', '\\ne' => '≠', '\\approx' => '≈', '\\sim' => '∼',
        '\\propto' => '∝', '\\infty' => '∞', '\\partial' => '∂',
        '\\nabla' => '∇', '\\sum' => '∑', '\\prod' => '∏', '\\int' => '∫',
        '\\oint' => '∮', '\\rightarrow' => '→', '\\to' => '→',
        '\\leftarrow' => '←', '\\leftrightarrow' => '↔',
        '\\degree' => '°', '\\circ' => '°',
    ];

    public function toHtml(?string $text): string
    {
        $text = $this->normalizeText($text);
        if ($text === '') {
            return '';
        }

        $pattern = '/\$\$(.+?)\$\$|\\\\\[(.+?)\\\\\]|\\\\\((.+?)\\\\\)|(?<!\\\\)\$(.+?)(?<!\\\\)\$/s';
        $offset = 0;
        $html = '';

        if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return $this->escapePlain($text);
        }

        foreach ($matches[0] as $i => $whole) {
            [$raw, $pos] = $whole;
            if ($pos > $offset) {
                $html .= $this->escapePlain(substr($text, $offset, $pos - $offset));
            }

            $math = '';
            for ($g = 1; $g <= 4; $g++) {
                if (($matches[$g][$i][1] ?? -1) >= 0) {
                    $math = (string) $matches[$g][$i][0];
                    break;
                }
            }

            $display = str_starts_with($raw, '$$') || str_starts_with($raw, '\\[');
            $tag = $display ? 'div' : 'span';
            $class = $display ? 'math-display' : 'math-inline';

            $html .= '<' . $tag . ' class="' . $class . '">'
                . $this->mathHtml($math)
                . '</' . $tag . '>';

            $offset = $pos + strlen($raw);
        }

        if ($offset < strlen($text)) {
            $html .= $this->escapePlain(substr($text, $offset));
        }

        return $html;
    }

    public function toPlainText(?string $text): string
    {
        $html = $this->toHtml($text);
        $html = preg_replace(
            ['~<span class="math-frac-num">(.*?)</span>~s', '~<span class="math-frac-den">(.*?)</span>~s'],
            ['$1/', '$1'],
            $html
        ) ?? $html;

        $html = str_replace(
            ['<sup>', '</sup>', '<sub>', '</sub>', '<br>', '<br/>', '<br />'],
            ['^', '', '_', '', ' ', ' ', ' '],
            $html
        );

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function normalizeText(?string $text): string
    {
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    private function escapePlain(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return nl2br($text, false);
    }

    private function mathHtml(string $math): string
    {
        $math = trim($math);
        $placeholders = [];

        $store = static function (string $html) use (&$placeholders): string {
            $key = '@@EDUXIDMATH' . count($placeholders) . '@@';
            $placeholders[$key] = $html;
            return $key;
        };

        $math = str_replace(
            ['\\left', '\\right', '\\!', '\\,', '\\;', '\\:', '\\quad', '\\qquad'],
            ['', '', '', ' ', ' ', ' ', '  ', '    '],
            $math
        );

        // Structured commands, processed repeatedly for nested content.
        $math = $this->replaceTwoArgCommand($math, '\\frac', function ($a, $b) use ($store) {
            return $store(
                '<span class="math-frac">'
                . '<span class="math-frac-num">' . $this->mathHtml($a) . '</span>'
                . '<span class="math-frac-den">' . $this->mathHtml($b) . '</span>'
                . '</span>'
            );
        });

        foreach ([
            '\\sqrt' => fn ($a) => '<span class="math-root">√<span class="math-radicand">' . $this->mathHtml($a) . '</span></span>',
            '\\vec' => fn ($a) => '<span class="math-vector">' . $this->mathHtml($a) . '&#8407;</span>',
            '\\hat' => fn ($a) => '<span class="math-accent">' . $this->mathHtml($a) . '&#770;</span>',
            '\\bar' => fn ($a) => '<span class="math-overline">' . $this->mathHtml($a) . '</span>',
            '\\overline' => fn ($a) => '<span class="math-overline">' . $this->mathHtml($a) . '</span>',
            '\\mathbf' => fn ($a) => '<strong>' . $this->mathHtml($a) . '</strong>',
            '\\mathrm' => fn ($a) => '<span class="math-roman">' . $this->mathHtml($a) . '</span>',
            '\\text' => fn ($a) => '<span class="math-text">' . htmlspecialchars($a, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>',
            '\\operatorname' => fn ($a) => '<span class="math-roman">' . htmlspecialchars($a, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>',
        ] as $command => $callback) {
            $math = $this->replaceOneArgCommand($math, $command, function ($arg) use ($store, $callback) {
                return $store($callback($arg));
            });
        }

        // Superscript/subscript groups or single tokens.
        $math = $this->replaceScripts($math, $store);

        $escaped = htmlspecialchars($math, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Named symbols/operators.
        uksort($this->symbols, static fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($this->symbols as $latex => $symbol) {
            $escaped = str_replace(
                htmlspecialchars($latex, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $symbol,
                $escaped
            );
        }

        foreach (['sin','cos','tan','cot','sec','csc','log','ln','exp','lim','max','min'] as $fn) {
            $escaped = str_replace('\\' . $fn, $fn, $escaped);
        }

        $escaped = preg_replace('/\\\\([A-Za-z]+)/', '$1', $escaped) ?? $escaped;
        $escaped = str_replace(['\\{', '\\}', '\\_', '\\%'], ['{', '}', '_', '%'], $escaped);
        $escaped = str_replace(['{', '}'], ['', ''], $escaped);

        foreach ($placeholders as $key => $html) {
            $escaped = str_replace($key, $html, $escaped);
        }

        return '<span class="math-expression">' . $escaped . '</span>';
    }

    private function replaceScripts(string $text, callable $store): string
    {
        $out = '';
        $len = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];

            if (($ch === '^' || $ch === '_') && $i + 1 < $len) {
                [$arg, $end] = $this->readArgumentAt($text, $i + 1);
                if ($arg !== null) {
                    $tag = $ch === '^' ? 'sup' : 'sub';
                    $out .= $store('<' . $tag . '>' . $this->mathHtml($arg) . '</' . $tag . '>');
                    $i = $end - 1;
                    continue;
                }
            }

            $out .= $ch;
        }

        return $out;
    }

    private function replaceOneArgCommand(string $text, string $command, callable $callback): string
    {
        $offset = 0;
        while (($pos = strpos($text, $command, $offset)) !== false) {
            $argStart = $pos + strlen($command);
            [$arg, $end] = $this->readArgumentAt($text, $argStart);
            if ($arg === null) {
                $offset = $argStart;
                continue;
            }

            $replacement = $callback($arg);
            $text = substr($text, 0, $pos) . $replacement . substr($text, $end);
            $offset = $pos + strlen($replacement);
        }
        return $text;
    }

    private function replaceTwoArgCommand(string $text, string $command, callable $callback): string
    {
        $offset = 0;
        while (($pos = strpos($text, $command, $offset)) !== false) {
            $aStart = $pos + strlen($command);
            [$a, $aEnd] = $this->readArgumentAt($text, $aStart);
            if ($a === null) {
                $offset = $aStart;
                continue;
            }

            [$b, $bEnd] = $this->readArgumentAt($text, $aEnd);
            if ($b === null) {
                $offset = $aEnd;
                continue;
            }

            $replacement = $callback($a, $b);
            $text = substr($text, 0, $pos) . $replacement . substr($text, $bEnd);
            $offset = $pos + strlen($replacement);
        }
        return $text;
    }

    /**
     * @return array{0:?string,1:int}
     */
    private function readArgumentAt(string $text, int $start): array
    {
        $len = strlen($text);
        while ($start < $len && ctype_space($text[$start])) {
            $start++;
        }

        if ($start >= $len) {
            return [null, $start];
        }

        if ($text[$start] !== '{') {
            if ($text[$start] === '\\') {
                if (preg_match('/\\\\[A-Za-z]+/', substr($text, $start), $m)) {
                    return [$m[0], $start + strlen($m[0])];
                }
            }
            return [$text[$start], $start + 1];
        }

        $depth = 0;
        for ($i = $start; $i < $len; $i++) {
            if ($text[$i] === '{') {
                $depth++;
            } elseif ($text[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return [substr($text, $start + 1, $i - $start - 1), $i + 1];
                }
            }
        }

        return [null, $start];
    }
}
