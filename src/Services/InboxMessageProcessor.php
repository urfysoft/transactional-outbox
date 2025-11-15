<?php

namespace Urfysoft\TransactionalOutbox\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Urfysoft\TransactionalOutbox\Enums\InboxMessageStatusEnum;
use Urfysoft\TransactionalOutbox\Models\InboxMessage;

/**
 * Background processor for inbound Inbox messages.
 *
 * Purpose
 * - Executes registered handlers for events produced by other services.
 * - Enforces retry limits (`retry_count < MAX_RETRIES`).
 * - Emits metrics for processed, failed, and unhandled messages.
 *
 * Invariants
 * - Handlers must be registered before the worker starts.
 * - Each message flows through: `pending → processing → processed|failed`.
 * - Retries happen only through `retryFailed`.
 */
class InboxMessageProcessor
{
    private const int MAX_RETRIES = 5;

    private const int BATCH_SIZE = 100;

    /** @var array<string, callable(InboxMessage):void> */
    private array $handlers = [];

    /**
     * Registers a handler for a specific event type.
     *
     * The handler receives a fully hydrated `InboxMessage` instance, so it can log,
     * call domain services, and mutate the message status directly.
     */
    /**
     * @param  callable(InboxMessage):void  $handler
     */
    public function registerHandler(string $eventType, callable $handler): void
    {
        $this->handlers[$eventType] = $handler;
    }

    /**
     * Processes inbound messages currently in the `pending` status.
     *
     * How it works
     * - Pulls entities in FIFO order (order by `received_at`).
     * - Marks them as `processing` to prevent double handling.
     * - Counts messages without handlers separately.
     * - Marks failures but keeps the exception contained.
     */
    /**
     * @return array{processed:int,failed:int,no_handler:int}
     */
    public function processMessages(int $limit = self::BATCH_SIZE): array
    {
        $messages = InboxMessage::pending()
            ->where('retry_count', '<', self::MAX_RETRIES)
            ->limit($limit)
            ->get();

        $stats = [
            'processed' => 0,
            'failed' => 0,
            'no_handler' => 0,
        ];

        foreach ($messages as $message) {
            $result = $this->processSingleMessage($message);

            match ($result) {
                'processed' => $stats['processed']++,
                'failed' => $stats['failed']++,
                'no_handler' => $stats['no_handler']++,
                default => null,
            };
        }

        return $stats;
    }

    /**
     * Retries processing for messages that are currently `failed`.
     *
     * Typical use cases: the upstream service recovered or a bug in the handler was fixed.
     * The method resets the status back to `pending`, invokes the handler again,
     * and tracks how many messages succeeded or failed during the retry.
     */
    /**
     * @return array{retried:int,failed:int}
     */
    public function retryFailed(int $limit = self::BATCH_SIZE): array
    {
        $messages = InboxMessage::where('status', InboxMessageStatusEnum::FAILED)
            ->where('retry_count', '<', self::MAX_RETRIES)
            ->orderBy('received_at')
            ->limit($limit)
            ->get();

        $stats = ['retried' => 0, 'failed' => 0];

        foreach ($messages as $message) {
            $result = $this->processSingleMessage($message, expectFailedStatus: true);

            if ($result === 'processed') {
                $stats['retried']++;
            } else {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * @return 'processed'|'failed'|'no_handler'|'skipped'
     */
    private function processSingleMessage(InboxMessage $message, bool $expectFailedStatus = false): string
    {
        $eventType = (string) $message->event_type;

        if (! array_key_exists($eventType, $this->handlers)) {
            Log::warning('No handler registered for event type', [
                'event_type' => $message->event_type,
                'message_id' => $message->message_id,
            ]);

            return 'no_handler';
        }

        $lockedMessage = $expectFailedStatus
            ? $this->acquireFailedMessage($message)
            : $this->acquirePendingMessage($message);

        if (! $lockedMessage) {
            Log::info('Skipping inbox message because lock was not acquired', [
                'message_id' => $message->message_id,
                'event_type' => $message->event_type,
            ]);

            return 'skipped';
        }

        try {
            $handler = $this->handlers[$eventType];
            $handler($lockedMessage);

            $lockedMessage->markAsProcessed();

            Log::info('Inbox message processed', [
                'message_id' => $lockedMessage->message_id,
                'event_type' => $lockedMessage->event_type,
                'source' => $lockedMessage->source_service,
            ]);

            return 'processed';
        } catch (Throwable $e) {
            $lockedMessage->markAsFailed($e->getMessage());

            Log::error('Failed to process inbox message', [
                'message_id' => $lockedMessage->message_id,
                'event_type' => $lockedMessage->event_type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 'failed';
        }
    }

    private function acquirePendingMessage(InboxMessage $message): ?InboxMessage
    {
        $lockedMessage = null;

        DB::transaction(function () use ($message, &$lockedMessage) {
            $lockedMessage = InboxMessage::where('id', $message->id)
                ->where('status', InboxMessageStatusEnum::PENDING)
                ->lockForUpdate()
                ->first();

            if ($lockedMessage) {
                $lockedMessage->markAsProcessing();
            }
        });

        return $lockedMessage;
    }

    private function acquireFailedMessage(InboxMessage $message): ?InboxMessage
    {
        $lockedMessage = null;

        DB::transaction(function () use ($message, &$lockedMessage) {
            $lockedMessage = InboxMessage::where('id', $message->id)
                ->where('status', InboxMessageStatusEnum::FAILED)
                ->lockForUpdate()
                ->first();

            if ($lockedMessage) {
                $lockedMessage->update(['status' => InboxMessageStatusEnum::PENDING]);
                $lockedMessage->markAsProcessing();
            }
        });

        return $lockedMessage;
    }
}
