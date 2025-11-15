<?php

namespace Urfysoft\TransactionalOutbox\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Urfysoft\TransactionalOutbox\Contracts\MessagePublisher;
use Urfysoft\TransactionalOutbox\Models\OutboxMessage;

/**
 * Relay responsible for pushing Outbox messages to external transports.
 *
 * --------------------------
 * Core duties
 * --------------------------
 * - Fetch pending (or failed) entries in batches.
 * - Lock each row before publishing to prevent duplicate deliveries.
 * - Update statistics and statuses (`processing`, `published`, `failed`).
 *
 * --------------------------
 * Layers involved
 * --------------------------
 * - Storage (`outbox_messages`) keeps the source of truth.
 * - Transport (`MessagePublisher`) encapsulates the underlying protocol.
 *
 * --------------------------
 * Invariants
 * --------------------------
 * - Every processed row is locked via `SELECT ... FOR UPDATE`.
 * - If the lock cannot be acquired, the method skips the record.
 * - Any transport exception triggers `markAsFailed` and increments `retry_count`.
 */
class OutboxMessageRelay
{
    private const int MAX_RETRIES = 5;

    private const int BATCH_SIZE = 100;

    public function __construct(
        private readonly MessagePublisher $publisher,
    ) {}

    /**
     * Processes delayed messages in bulk.
     *
     * Algorithm
     * - Look for `pending` entries where `retry_count < MAX_RETRIES`.
     * - Iterate through the collection and call `processMessage` for each.
     * - Track how many items succeeded, failed, or were skipped for cron metrics.
     *
     * @return array{processed:int,failed:int,skipped:int}
     */
    public function processMessages(int $limit = self::BATCH_SIZE): array
    {
        $messages = OutboxMessage::pending()
            ->where('retry_count', '<', self::MAX_RETRIES)
            ->limit($limit)
            ->get();

        $stats = [
            'processed' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($messages as $message) {
            try {
                $this->processMessage($message);
                $stats['processed']++;
            } catch (Throwable $e) {
                Log::error('Failed to process outbox message', [
                    'message_id' => $message->message_id,
                    'destination' => $message->destination_service,
                    'event_type' => $message->event_type,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Handles a single record, including locking and publishing.
     *
     * Steps
     * 1. Lock the row inside a short transaction (`FOR UPDATE`).
     * 2. If the lock cannot be acquired, return immediately (another worker owns it).
     * 3. Mark the record as `processing`.
     * 4. Publish it via `MessagePublisher`.
     * 5. On success call `markAsPublished`; on failure call `markAsFailed`.
     *
     * Invariants
     * - The method always works with the hydrated `OutboxMessage` model.
     * - It is intended for background workers/commands, not direct user code.
     */
    private function processMessage(OutboxMessage $message): void
    {
        $lockedMessage = null;

        DB::transaction(function () use ($message, &$lockedMessage) {
            // Lock the row for exclusive processing
            $lockedMessage = OutboxMessage::where('id', $message->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $lockedMessage) {
                return; // Another worker already picked this record
            }

            $lockedMessage->markAsProcessing();
        });

        if (! $lockedMessage) {
            Log::info('Skipping outbox message because lock was not acquired', [
                'message_id' => $message->message_id,
                'destination' => $message->destination_service,
                'event_type' => $message->event_type,
            ]);

            return;
        }

        try {
            // Send the message via the configured publisher
            $this->publisher->publish($lockedMessage);

            // Mark the message as delivered
            $lockedMessage->markAsPublished();

            Log::info('Message published successfully', [
                'message_id' => $lockedMessage->message_id,
                'destination' => $lockedMessage->destination_service,
                'event_type' => $lockedMessage->event_type,
            ]);
        } catch (Throwable $e) {
            $lockedMessage->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Same as `processMessages`, but limited to a specific `destination_service`.
     *
     * @return array{processed:int,failed:int}
     */
    public function processForService(string $service, int $limit = self::BATCH_SIZE): array
    {
        $messages = OutboxMessage::pending()
            ->forService($service)
            ->where('retry_count', '<', self::MAX_RETRIES)
            ->limit($limit)
            ->get();

        $stats = ['processed' => 0, 'failed' => 0];

        foreach ($messages as $message) {
            try {
                $this->processMessage($message);
                $stats['processed']++;
            } catch (Throwable $e) {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Moves `failed` entries back to `pending` and processes them again.
     *
     * @return array{retried:int,failed:int}
     */
    public function retryFailed(int $limit = self::BATCH_SIZE): array
    {
        $messages = OutboxMessage::where('status', 'failed')
            ->where('retry_count', '<', self::MAX_RETRIES)
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $stats = ['retried' => 0, 'failed' => 0];

        foreach ($messages as $message) {
            // Reset the status to pending before the new attempt
            $message->update(['status' => 'pending']);

            try {
                $this->processMessage($message);
                $stats['retried']++;
            } catch (Throwable $e) {
                $stats['failed']++;
            }
        }

        return $stats;
    }
}
