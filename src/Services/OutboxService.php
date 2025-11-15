<?php

namespace Urfysoft\TransactionalOutbox\Services;

use Urfysoft\TransactionalOutbox\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;

/**
 * Transactional Outbox orchestration service.
 *
 * --------------------------
 * Responsibilities
 * --------------------------
 * - Persists events in the `outbox_messages` table together with aggregate attributes.
 * - Guarantees that business logic and queuing messages happen atomically.
 * - Supports single-message and multi-message scenarios without duplicating code.
 *
 * --------------------------
 * Invariants
 * --------------------------
 * - Records are created in the `pending` status and ordered by creation time.
 * - Custom transactions wrap both the business logic and the Outbox insert.
 * - Delivery is delegated to `OutboxMessageRelay`; this service only prepares data.
 *
 * --------------------------
 * When to use
 * --------------------------
 * - Any inter-service event exchange that needs exactly-once semantics.
 * - Bulk publications when several downstream systems must be notified.
 */
class OutboxService
{
    /**
     * Persists an event in the outbox table without executing business logic.
     *
     * Behavior
     * - Does not open a transaction: the caller decides when to commit.
     * - Stores payload/headers as JSON together with aggregate metadata (type/id).
     * - Always writes the `pending` status so the relay can pick it up.
     *
     * When to call
     * - After the domain operation has already happened and you only need to capture the event.
     * - When preparing test data or manually replaying events.
     */
    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $headers
     */
    public function sendToService(
        string $destinationService,
        string $eventType,
        array $payload,
        string $aggregateType,
        string $aggregateId,
        ?string $destinationTopic = null,
        array $headers = [],
    ): OutboxMessage {
        return OutboxMessage::create([
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'destination_service' => $destinationService,
            'destination_topic' => $destinationTopic,
            'payload' => $payload,
            'headers' => $headers,
            'status' => 'pending',
            'created_at' => now(),
        ]);
    }

    /**
     * Wraps the domain command and Outbox insert inside a single transaction.
     *
     * Steps
     * 1. Opens a transaction.
     * 2. Executes the provided closure (business logic).
     * 3. Persists the event via `sendToService`.
     * 4. Commits and returns the business logic result.
     *
     * Invariants
     * - Neither operation is committed if an exception occurs.
     * - The caller receives exactly what `$businessLogic` returned.
     */
    /**
     * @param  callable():mixed  $businessLogic
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $headers
     */
    public function executeAndSend(
        callable $businessLogic,
        string $destinationService,
        string $eventType,
        array $payload,
        string $aggregateType,
        string $aggregateId,
        ?string $destinationTopic = null,
        array $headers = [],
    ): mixed {
        return DB::transaction(function () use (
            $businessLogic,
            $destinationService,
            $eventType,
            $payload,
            $aggregateType,
            $aggregateId,
            $destinationTopic,
            $headers
        ) {
            // Execute business logic first
            $result = $businessLogic();

            // Then queue the message in the outbox
            $this->sendToService(
                $destinationService,
                $eventType,
                $payload,
                $aggregateType,
                $aggregateId,
                $destinationTopic,
                $headers,
            );

            return $result;
        });
    }

    /**
     * Bulk publication helper: one transaction for business logic and N messages.
     *
     * Highlights
     * - Each entry is described by a config array (destination, payload, headers, etc.).
     * - A failure on message #2 rolls back the first message as well.
     * - Uniqueness checks belong to the delivery layer, not this method.
     *
     * @param  callable():mixed  $businessLogic
     * @param  array<int,array{
     *     destination_service:string,
     *     event_type:string,
     *     payload:array<string,mixed>,
     *     aggregate_type:string,
     *     aggregate_id:string,
     *     destination_topic?:string,
     *     headers?:array<string,mixed>
     * }>  $messages
     */
    public function executeAndSendMultiple(
        callable $businessLogic,
        array $messages,
    ): mixed {
        return DB::transaction(function () use ($businessLogic, $messages) {
            $result = $businessLogic();

            foreach ($messages as $message) {
                $this->sendToService(
                    destinationService: $message['destination_service'],
                    eventType: $message['event_type'],
                    payload: $message['payload'],
                    aggregateType: $message['aggregate_type'],
                    aggregateId: $message['aggregate_id'],
                    destinationTopic: $message['destination_topic'] ?? null,
                    headers: $message['headers'] ?? [],
                );
            }

            return $result;
        });
    }
}
