<?php

namespace Urfysoft\TransactionalOutbox\Services;

use Throwable;
use Urfysoft\TransactionalOutbox\Enums\InboxMessageStatusEnum;
use Urfysoft\TransactionalOutbox\Models\InboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service responsible for registering inbound messages (Inbox).
 *
 * Key idea
 * - Every external webhook is stored in the `inbox_messages` table first.
 * - A unique `message_id` guarantees idempotency (no duplicates).
 * - Actual processing is delegated to `InboxMessageProcessor`.
 */
class InboxService
{
    /**
     * Registers an inbound message and enforces idempotency.
     *
     * Steps
     * - Check `message_id` and drop duplicates if they already exist.
     * - Store payload/headers verbatim; handlers will shape them later.
     * - Set `status=pending` so workers can pick up the record.
     *
     * Returns `null` when the message has already been processed.
     */
    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $headers
     */
    public function receiveMessage(
        string $messageId,
        string $sourceService,
        string $eventType,
        array $payload,
        array $headers = [],
    ): ?InboxMessage {
        // Deduplicate by message id (idempotency guard)
        if (InboxMessage::isDuplicate($messageId)) {
            Log::info('Duplicate message ignored', [
                'message_id' => $messageId,
                'source_service' => $sourceService,
            ]);

            return null;
        }

        return InboxMessage::create([
            'message_id' => $messageId,
            'source_service' => $sourceService,
            'event_type' => $eventType,
            'payload' => $payload,
            'headers' => $headers,
            'received_at' => now(),
            'status' => 'pending',
        ]);
    }

    /**
     * Wraps the business handler in a short transaction and transitions statuses.
     *
     * - Only invokes the handler when the message is not yet `processed`.
     * - Marks the entry as `failed` and rethrows on any exception.
     *
     * @param callable(InboxMessage):void $handler
     * @throws Throwable
     */
    public function processMessage(InboxMessage $message, callable $handler): void
    {
        if ($message->status === InboxMessageStatusEnum::PROCESSED) {
            return; // Message was already processed
        }

        DB::transaction(function () use ($message, $handler) {
            $message->markAsProcessing();

            try {
                $handler($message);
                $message->markAsProcessed();
            } catch (\Throwable $e) {
                $message->markAsFailed($e->getMessage());
                throw $e;
            }
        });
    }
}
