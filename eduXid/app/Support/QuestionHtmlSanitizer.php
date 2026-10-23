<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;
use RuntimeException;

/** One explicit HTML policy for typed questions, XLSX/CSV import and old records. */
final class QuestionHtmlSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    public static function purifier(): HTMLPurifier
    {
        if (self::$purifier !== null) return self::$purifier;
        $cache = storage_path('app/purifier_cache');
        if (!is_dir($cache) && !mkdir($cache, 0775, true) && !is_dir($cache)) {
            throw new RuntimeException('Direktori cache purifier tidak dapat dibuat.');
        }
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', $cache);
        $config->set('HTML.Allowed', 'p,b,i,u,ul,ol,li,strong,em,span[style],img[src|alt|width|height],br,sub,sup,table,thead,tbody,tfoot,tr,th[colspan|rowspan],td[colspan|rowspan],caption');
        $config->set('URI.Base', asset(''));
        $config->set('URI.MakeAbsolute', true);
        return self::$purifier = new HTMLPurifier($config);
    }

    public static function clean(?string $html): string
    {
        return self::purifier()->purify((string) $html);
    }
}
