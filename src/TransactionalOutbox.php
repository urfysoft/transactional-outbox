<?php

namespace Urfysoft\TransactionalOutbox;

use InvalidArgumentException;
use Urfysoft\TransactionalOutbox\Contracts\InboxEventHandler;
use Urfysoft\TransactionalOutbox\Models\InboxMessage;
use Urfysoft\TransactionalOutbox\Models\OutboxMessage;
use Urfysoft\TransactionalOutbox\Services\InboxMessageProcessor;
use Urfysoft\TransactionalOutbox\Services\InboxService;
use Urfysoft\TransactionalOutbox\Services\OutboxService;

/**
 * Facade-friendly entry point that exposes both Outbox and Inbox services.
 *
 * Convenient wrapper so application code can call `TransactionalOutbox::sendToService()`
 * or `TransactionalOutbox::receiveMessage()` via the Laravel facade without importing
 * separate services.
 */
readonly class TransactionalOutbox
{
    public function __construct(
        private OutboxService $outbox,
        private InboxService $inbox,
        private InboxMessageProcessor $processor,
    ) {}

    /**
     * Proxy to {@see OutboxService::sendToService()}.
     *
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
        return $this->outbox->sendToService(
            destinationService: $destinationService,
            eventType: $eventType,
            payload: $payload,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            destinationTopic: $destinationTopic,
            headers: $headers,
        );
    }

    /**
     * Proxy to {@see OutboxService::executeAndSend()}.
     *
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
        return $this->outbox->executeAndSend(
            businessLogic: $businessLogic,
            destinationService: $destinationService,
            eventType: $eventType,
            payload: $payload,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            destinationTopic: $destinationTopic,
            headers: $headers,
        );
    }

    /**
     * Proxy to {@see OutboxService::executeAndSendMultiple()}.
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
        return $this->outbox->executeAndSendMultiple($businessLogic, $messages);
    }

    /**
     * Proxy to {@see InboxService::receiveMessage()}.
     *
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
        return $this->inbox->receiveMessage(
            messageId: $messageId,
            sourceService: $sourceService,
            eventType: $eventType,
            payload: $payload,
            headers: $headers,
        );
    }

    /**
     * Proxy helper so callers can reuse inbox processing logic when needed.
     *
     * @param  callable(InboxMessage):void  $handler
     */
    public function processInboxMessage(InboxMessage $message, callable $handler): void
    {
        $this->inbox->processMessage($message, $handler);
    }

    /**
     * Registers a handler object or callable for inbound events.
     *
     * @param  callable(InboxMessage):void|null  $handler
     */
    public function registerInboxHandler(InboxEventHandler|callable $handler, ?string $eventType = null): void
    {
        if ($handler instanceof InboxEventHandler) {
            $this->processor->registerHandler(
                $handler->eventType(),
                fn (InboxMessage $message): mixed => $handler->handle($message),
            );

            return;
        }

        if (! is_string($eventType) || $eventType === '') {
            throw new InvalidArgumentException('Event type is required when registering a callable handler.');
        }

        $this->processor->registerHandler($eventType, $handler);
    }
}
