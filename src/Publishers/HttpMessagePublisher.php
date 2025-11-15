<?php

namespace Urfysoft\TransactionalOutbox\Publishers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Urfysoft\TransactionalOutbox\Contracts\MessagePublisher;
use Urfysoft\TransactionalOutbox\Models\OutboxMessage;

class HttpMessagePublisher implements MessagePublisher
{
    /** @var array<string,string> */
    private array $serviceUrls;

    public function __construct()
    {
        $this->serviceUrls = config('transactional-outbox.services', []);
    }

    /**
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    public function publish(OutboxMessage $message): void
    {
        $url = $this->getServiceUrl($message->destination_service, $message->destination_topic);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Message-Id' => $message->message_id,
                'X-Source-Service' => config('transactional-outbox.service_name'),
                'X-Event-Type' => $message->event_type,
                ...$message->headers ?? [],
            ])
                ->timeout(30)
                ->post($url, $message->payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException(
                "Failed to publish message: {$e->getMessage()}",
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "Failed to publish message: HTTP {$response->status()}",
            );
        }
    }

    public function isHealthy(): bool
    {
        return true;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function getServiceUrl(string $service, ?string $endpoint): string
    {
        $baseUrl = $this->serviceUrls[$service] ?? throw new InvalidArgumentException(
            "Service URL not configured: $service",
        );

        $path = $endpoint ?? 'events';

        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }
}
