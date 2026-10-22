<?php

declare(strict_types=1);

namespace App\Services\Sync;

use RuntimeException;

/**
 * V21R15 — STAGING ONLY, not registered in SyncApplier, HTTP, daemon, or Cloud Worker.
 * Contract for a FUTURE trusted PC pull consumer. It does not authenticate Cloud
 * transport: $verifiedSourceHp MUST come from a separately authenticated transport
 * session, NOT the event header/body. $localTeacherId and $localPc must be read
 * from the recipient's local authenticated context, NOT client-provided fields.
 * A paired proof key alone is not authorization to choose a different teacher.
 */
final class CloudLjkInboundAdapterV21
{
    public const SCHEMA = 'eduxid-ljk-inbound-v21r15';
    public const ENTITY = 'ljk_manual_sealed_result_v21r15';
    private const MAX_EVENT_BYTES = 32768;

    public function __construct(private CloudLjkManualApplyPipelineV21 $pipeline) {}

    /**
     * @param array<string,mixed> $event
     * @return array{already_applied:bool,published:bool,receipt_hash:string,scan_id:string,correct_count:int,score:float}
     */
    public function receiveFromAuthenticatedPull(
        int $localTeacherId,
        string $localPc,
        string $verifiedSourceHp,
        array $event
    ): array {
        if (!OpaqueRouteRegistryV21::enabled()) {
            throw new RuntimeException('BLOCKED V21R15: protokol anonim staging belum aktif.');
        }
        $parts = self::assertDeliveryShape($localTeacherId, $localPc, $verifiedSourceHp, $event);
        // Independently re-resolve teacher alias/PC ownership and active pair key from local DB.
        // Never trust school, teacher ID, NISN, roster, key or grade in delivery metadata.
        $school = CloudLjkPairVaultV21::assertOwner($localTeacherId, $verifiedSourceHp, $localPc);
        if (!hash_equals($school, $parts['sealed']['school_id'])) {
            throw new RuntimeException('BLOCKED V21R15: sekolah bukti dan sekolah PC berbeda.');
        }
        $secret = app(CloudLjkPairVaultV21::class)->active($localTeacherId, $verifiedSourceHp, $localPc);
        CloudLjkScanQualityV21::verify($parts['quality'], $parts['sealed'], $secret);
        CloudLjkScanQualityV21::assertPublishable($parts['quality']);

        // Durable transactional publication and genuine sealed identity verification
        // are delegated to V21R14; do not acknowledge Cloud delivery here.
        return $this->pipeline->apply(
            $localTeacherId, $verifiedSourceHp, $localPc,
            $parts['sealed'], $parts['answers'], $parts['quality']
        );
    }

    /**
     * Pure validation: no database writes, no ACK, no network access, no secret access.
     * $verifiedSourceHp is an authenticated external fact, not copied from $event.
     * @param array<string,mixed> $event
     * @return array{sealed:array<string,mixed>,answers:array<string,mixed>,quality:array<string,mixed>}
     */
    public static function assertDeliveryShape(
        int $localTeacherId,
        string $localPc,
        string $verifiedSourceHp,
        array $event
    ): array {
        if ($localTeacherId < 1 || !preg_match('/^[0-9]{8}-t_[a-z2-7]{26}-PC$/D', $localPc)
            || !preg_match('/^[0-9]{8}-t_[a-z2-7]{26}-HP$/D', $verifiedSourceHp)
            || substr($localPc, 0, -3) !== substr($verifiedSourceHp, 0, -3)) {
            throw new RuntimeException('BLOCKED V21R15: konteks autentikasi lokal PC/HP tidak sah.');
        }
        self::exactKeys($event, ['schema','entity_type','operation','source_device_id','target_device_id','payload']);
        if (($event['schema'] ?? null) !== self::SCHEMA
            || ($event['entity_type'] ?? null) !== self::ENTITY
            || ($event['operation'] ?? null) !== 'upsert'
            || !is_string($event['source_device_id'] ?? null)
            || !hash_equals($verifiedSourceHp, $event['source_device_id'])
            || !is_string($event['target_device_id'] ?? null)
            || !hash_equals($localPc, $event['target_device_id'])) {
            throw new RuntimeException('BLOCKED V21R15: rute atau entitas Cloud tidak sesuai konteks terautentikasi.');
        }
        $payload = $event['payload'] ?? null;
        if (!is_array($payload)) throw new RuntimeException('BLOCKED V21R15: payload tidak sah.');
        self::exactKeys($payload, ['sealed','answers','quality']);
        foreach (['sealed','answers','quality'] as $name) {
            if (!is_array($payload[$name]) || !$payload[$name]) {
                throw new RuntimeException('BLOCKED V21R15: komponen bukti tidak valid.');
            }
        }
        $sealed = $payload['sealed'];
        if (($sealed['publish_requested'] ?? null) !== true
            || !is_string($sealed['source_hp'] ?? null)
            || !hash_equals($verifiedSourceHp, $sealed['source_hp'])
            || !is_string($sealed['target_pc'] ?? null)
            || !hash_equals($localPc, $sealed['target_pc'])) {
            throw new RuntimeException('BLOCKED V21R15: bukti tidak ditujukan untuk publikasi PC ini.');
        }
        $encoded = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_EVENT_BYTES) {
            throw new RuntimeException('BLOCKED V21R15: ukuran event melebihi batas staging.');
        }
        // V21R13 envelope MUST exclude NISN, student_id, teacher login and pair key.
        CloudLjkSealedProofV21::assertEnvelope($sealed, $payload['answers']);
        return ['sealed' => $sealed, 'answers' => $payload['answers'], 'quality' => $payload['quality']];
    }

    /** @param array<string,mixed> $array @param list<string> $keys */
    private static function exactKeys(array $array, array $keys): void
    {
        $actual = array_keys($array);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw new RuntimeException('BLOCKED V21R15: field event tidak diizinkan atau tidak lengkap.');
        }
    }
}
