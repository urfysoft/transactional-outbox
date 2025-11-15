<?php

namespace Urfysoft\TransactionalOutbox\Contracts;

use Urfysoft\TransactionalOutbox\Models\OutboxMessage;

interface MessagePublisher
{
    /**
     * Transport-level contract responsible for delivering Outbox messages.
     *
     * Each implementation encapsulates a specific protocol (HTTP, Kafka, AMQP, etc.)
     * and must throw an exception whenever delivery fails so the relay can mark the entry as `failed`.
     */
    public function publish(OutboxMessage $message): void;

    /**
     * Transport health check hook (useful for readiness and liveness probes).
     */
    public function isHealthy(): bool;
}
