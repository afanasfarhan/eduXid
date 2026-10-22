<?php

namespace App\Logging;

use App\Support\SensitiveDataProtector;
use Illuminate\Log\Logger as LaravelLogger;
use Monolog\Logger as MonologLogger;

/**
 * Laravel LogManager passes Illuminate\Log\Logger to tap callbacks.
 * Preserve sensitive-data redaction while supporting both wrappers and
 * raw Monolog instances; otherwise logging itself throws a TypeError.
 */
final class RedactSensitiveLogData
{
    public function __invoke(LaravelLogger|MonologLogger $logger): void
    {
        $monolog = $logger instanceof LaravelLogger ? $logger->getLogger() : $logger;
        $monolog->pushProcessor(static function ($record) {
            if ($record instanceof \Monolog\LogRecord) {
                return $record->with(
                    message: SensitiveDataProtector::redactText($record->message),
                    context: SensitiveDataProtector::redactContext($record->context),
                    extra: SensitiveDataProtector::redactContext($record->extra)
                );
            }

            // Defensive fallback for older Monolog processor signatures.
            if (is_array($record)) {
                $record['message'] = SensitiveDataProtector::redactText((string) ($record['message'] ?? ''));
                $record['context'] = SensitiveDataProtector::redactContext((array) ($record['context'] ?? []));
                $record['extra'] = SensitiveDataProtector::redactContext((array) ($record['extra'] ?? []));
            }
            return $record;
        });
    }
}
