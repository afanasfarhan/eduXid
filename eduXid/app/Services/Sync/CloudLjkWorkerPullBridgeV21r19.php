<?php

declare(strict_types=1);

namespace App\Services\Sync;

use RuntimeException;

/**
 * eduXid V21R19 — STAGING ONLY. No HTTP requests, no ACK side effect.
 * Call exclusively with the unmodified HTTPS response of /api/sync/pull fetched
 * with the LOCAL PC's Worker device-token. The token / URL / TLS / device identity
 * must be checked by a separately audited authenticated transport client.
 * Never use a payload-supplied origin, login identity or teacher ID as trusted facts.
 * Legacy LmsSyncEngine remains quarantined for this entity.
 */
final class CloudLjkWorkerPullBridgeV21r19
{
    private const ENTITY = CloudLjkInboundAdapterV21::ENTITY;
    private const ALIAS_PC = '/^[0-9]{8}-t_[a-z2-7]{26}-PC$/D';
    private const ALIAS_HP = '/^[0-9]{8}-t_[a-z2-7]{26}-HP$/D';
    private const EVENT_ID = '/^[A-Za-z0-9._:-]{1,128}$/D';

    public function __construct(private CloudLjkCommittedReceiptV21 $receipt) {}

    /**
     * @param array<string,mixed> $response JSON HTTPS response received by the authenticated PC.
     * @return array{ack_ready:bool,ack_body:array{event_ids:list<string>},event_id:string,receipt_hash:string,already_applied:bool}
     */
    public function receiveVerifiedWorkerPull(
        int $trustedTeacherId,
        string $trustedPc,
        array $response,
        int $eventIndex
    ): array {
        if ($trustedTeacherId < 1 || !preg_match(self::ALIAS_PC, $trustedPc)) {
            throw new RuntimeException('V21R19: otorisasi PC lokal tidak sah.');
        }
        if (($response['success'] ?? null) !== true || !is_array($response['data'] ?? null)) {
            throw new RuntimeException('V21R19: respons Worker gagal.');
        }
        $data = $response['data'];
        if (($data['device_id'] ?? null) !== $trustedPc
            || ($data['school_id'] ?? null) !== substr($trustedPc, 0, 8)
            || !is_array($data['events'] ?? null)
            || !is_int($data['count'] ?? null)
            || $data['count'] !== count($data['events'])
            || $data['count'] < 1 || $data['count'] > 100
            || !isset($data['events'][$eventIndex]) || !is_array($data['events'][$eventIndex])) {
            throw new RuntimeException('V21R19: envelope pull bukan milik PC lokal.');
        }
        $row = $data['events'][$eventIndex];
        $expected = ['event_id','school_id','source_device_id','target_device_id','entity_type',
            'entity_id','action','version','payload','status','created_at',
            'delivered_at','acknowledged_at'];
        $keys = array_keys($row);
        sort($keys, SORT_STRING); sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new RuntimeException('V21R19: field event Worker tidak sah.');
        }
        $id = $row['event_id'];
        $source = $row['source_device_id'];
        if (!is_string($id) || !preg_match(self::EVENT_ID, $id)
            || !is_string($source) || !preg_match(self::ALIAS_HP, $source)
            || substr($source, 0, -2) !== substr($trustedPc, 0, -2)
            || ($row['target_device_id'] ?? null) !== $trustedPc
            || ($row['school_id'] ?? null) !== substr($trustedPc, 0, 8)
            || ($row['entity_type'] ?? null) !== self::ENTITY
            || ($row['action'] ?? null) !== 'upsert'
            || ($row['version'] ?? null) !== 1
            || ($row['status'] ?? null) !== 'DELIVERED'
            || $row['acknowledged_at'] !== null
            || !is_string($row['entity_id'] ?? null)
            || !is_array($row['payload'] ?? null)) {
            throw new RuntimeException('V21R19: sumber, tujuan atau status event Worker tidak sah.');
        }
        $payload = $row['payload'];
        $pk = array_keys($payload);
        sort($pk, SORT_STRING);
        if ($pk !== ['answers','quality','sealed'] || !is_array($payload['sealed'])
            || !is_array($payload['answers']) || !is_array($payload['quality'])
            || ($payload['sealed']['scan_id'] ?? null) !== $row['entity_id']) {
            throw new RuntimeException('V21R19: isi event LJK tidak cocok dengan identitas scan.');
        }
        // Reconstruct V21R15 contract from Worker-owned delivery metadata, not
        // from user-provided event claims. Origin is trusted ONLY if the caller
        // actually verified the Worker TLS+Bearer session before calling here.
        $adapterEvent = [
            'schema' => CloudLjkInboundAdapterV21::SCHEMA,
            'entity_type' => self::ENTITY,
            'operation' => 'upsert',
            'source_device_id' => $source,
            'target_device_id' => $trustedPc,
            'payload' => $payload,
        ];
        $result = $this->receipt->receiveAndVerifyCommit(
            $trustedTeacherId, $trustedPc, $source, $id, $adapterEvent
        );
        if (($result['ack_permitted'] ?? null) !== true
            || ($result['ack_event_id'] ?? null) !== $id
            || !is_string($result['receipt_hash'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $result['receipt_hash'])
            || !is_bool($result['already_applied'] ?? null)) {
            throw new RuntimeException('V21R19: belum ada bukti commit layak ACK.');
        }
        // PLAN ONLY. No HTTP call; in particular, no ACK on parser/DB failure.
        return [
            'ack_ready' => true,
            'ack_body' => ['event_ids' => [$id]],
            'event_id' => $id,
            'receipt_hash' => $result['receipt_hash'],
            'already_applied' => $result['already_applied'],
        ];
    }
}
