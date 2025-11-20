<?php

namespace Urfysoft\TransactionalOutbox\Http\Controllers;

use Illuminate\Http\Request;
use Urfysoft\TransactionalOutbox\Services\InboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HTTP entry point for external services publishing events to our Inbox.
 *
 * What it does
 * - Reads identification headers (`X-Message-Id`, `X-Source-Service`, `X-Event-Type`).
 * - Collects custom `X-*` headers and stores them with the payload.
 * - Delegates idempotency checks and persistence to `InboxService`.
 *
 * Responses
 * - `202 Accepted` — new message accepted and queued.
 * - `200 already_processed` — duplicate message, no work required.
 * - `400` — required headers are missing.
 * - `500` — internal error, details logged.
 */
class MessageWebhookController extends Controller
{
    public function __construct(
        private readonly InboxService $inboxService,
    ) {}

    /**
     * Validates and stores a webhook message.
     *
     * Steps
     * - Extract required headers; respond with 400 when they are missing.
     * - Gather every `X-*` header to keep technical metadata.
     * - Delegate persistence to `InboxService` and log the outcome.
     */
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $headersConfig = config('transactional-outbox.headers', []);
            $messageIdHeader = $headersConfig['message_id'] ?? 'X-Message-Id';
            $sourceHeader = $headersConfig['source_service'] ?? 'X-Source-Service';
            $eventHeader = $headersConfig['event_type'] ?? 'X-Event-Type';

            $messageId = $request->header($messageIdHeader) ?? $request->input('message_id');
            $sourceService = $request->header($sourceHeader) ?? $request->input('source_service');
            $eventType = $request->header($eventHeader) ?? $request->input('event_type');
            if (! is_string($messageId) || $messageId === '' ||
                ! is_string($sourceService) || $sourceService === '' ||
                ! is_string($eventType) || $eventType === '') {
                return response()->json([
                    'error' => 'Missing required headers or fields',
                ], 400);
            }

            // Extract user-defined headers
            /** @var array<string, list<string|null>> $allHeaders */
            $allHeaders = $request->headers->all();

            $prefix = strtolower($headersConfig['custom_prefix'] ?? 'x-');
            $headers = [];
            foreach ($allHeaders as $key => $values) {
                if ($prefix !== '' && ! str_starts_with(strtolower($key), $prefix)) {
                    continue;
                }

                $headers[$key] = $values[0] ?? null;
            }

            /** @var array<string, mixed> $payload */
            $payload = $request->all();

            $inbox = $this->inboxService->receiveMessage(
                messageId: (string) $messageId,
                sourceService: (string) $sourceService,
                eventType: (string) $eventType,
                payload: $payload,
                headers: $headers,
            );

            if ($inbox === null) {
                return response()->json([
                    'status' => 'already_processed',
                    'message' => 'Message already received',
                ], 200);
            }

            Log::info('Message received from microservice', [
                'message_id' => $messageId,
                'source_service' => $sourceService,
                'event_type' => $eventType,
            ]);

            return response()->json([
                'status' => 'received',
                'message_id' => $messageId,
                'inbox_id' => $inbox->id,
            ], 202);
        } catch (Throwable $e) {
            Log::error('Failed to receive message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to process message',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
