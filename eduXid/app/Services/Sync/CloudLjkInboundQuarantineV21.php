<?php

declare(strict_types=1);

namespace App\Services\Sync;

/**
 * V21R16 fail-closed fence for *all* standard inbound paths, even when the
 * future sealed-result envelope is delivered without a current event_id.
 * It does NOT verify an event nor activate a new transport/ACK route.
 */
final class CloudLjkInboundQuarantineV21
{
    public static function isProtected(array $change): bool
    {
        foreach (['entity_type', 'entity'] as $field) {
            $value = $change[$field] ?? null;
            if (is_string($value) && preg_match('/^ljk_manual_sealed_result_v21(?:r[0-9]+)?$/iD', trim($value))) {
                return true;
            }
        }
        $payload = $change['payload'] ?? null;
        if (is_array($payload)) {
            $schema = $payload['schema'] ?? null;
            $entity = $payload['entity_type'] ?? $payload['entity'] ?? null;
            if ((is_string($schema) && preg_match('/^eduxid-ljk-inbound-v21(?:r[0-9]+)?$/iD', trim($schema)))
                || (is_string($entity) && preg_match('/^ljk_manual_sealed_result_v21(?:r[0-9]+)?$/iD', trim($entity)))) {
                return true;
            }
        }
        return false;
    }
}
