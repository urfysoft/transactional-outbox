# Transactional Outbox Pattern for Laravel Microservices

Complete Transactional Outbox implementation for reliable communication between microservices.

## Installation

```bash
composer require urfysoft/transactional-outbox
```

### Publish assets

```bash
# Publish config and migrations
php artisan vendor:publish --provider="Urfysoft\TransactionalOutbox\TransactionalOutboxServiceProvider"
```

This command copies:

- `config/transactional-outbox.php`
- `database/migrations/*create_outbox_messages_table.php`
- `database/migrations/*create_inbox_messages_table.php`

Run the migrations after publishing:

```bash
php artisan migrate
```

## Configuration

Key settings live in `config/transactional-outbox.php`.

- **Service identity & Sanctum ability**
  - `service_name`: name announced in outbound headers.
  - `sanctum.required_ability`: ability that incoming Sanctum tokens must possess.
- **Headers**
  - Override header names or the prefix (default `X-`) via the `headers` array.
- **Destinations**
  - Map logical service names to endpoints inside the `services` array.
- **Driver**
  - Choose the message broker driver (`http`, `kafka`, `rabbitmq` when implemented).
- **Processing**
  - Control batch size, retry limits, and throttling.
- **Inbox handlers**
  - Register classes implementing `Urfysoft\TransactionalOutbox\Contracts\InboxEventHandler` under `inbox.handlers`.

Example handler:

```php
namespace App\Messaging;

use Urfysoft\TransactionalOutbox\Contracts\InboxEventHandler;
use Urfysoft\TransactionalOutbox\Models\InboxMessage;

class PaymentCompletedHandler implements InboxEventHandler
{
    public function eventType(): string
    {
        return 'PaymentCompleted';
    }

    public function handle(InboxMessage $message): void
    {
        // process payload...
    }
}
```

Register the class in `config/transactional-outbox.php` or at runtime:

```php
use TransactionalOutbox;
use App\Messaging\PaymentCompletedHandler;

TransactionalOutbox::registerInboxHandler(new PaymentCompletedHandler());
```

### Sanctum setup

The package expects [Laravel Sanctum](https://laravel.com/docs/sanctum) to be installed and configured.

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

Issue tokens for upstream services with the configured ability (default `transactional-outbox`):

```php
$serviceUser->createToken('microservice:inventory', ['transactional-outbox']);
```

## Architecture Overview

### Outbox Pattern (Sending Messages)
1. Business logic and an outbox message are persisted in **the same database transaction**
2. A background worker reads the outbox table and publishes to the message broker
3. Messages are marked as published once delivery succeeds
4. Failed messages are automatically retried

### Inbox Pattern (Receiving Messages)
1. Messages arrive via HTTP webhooks or a broker consumer
2. Each message is stored in the inbox table for **idempotency** (duplicate detection)
3. A background worker processes inbox messages
4. Business logic runs in a transaction that also updates the message status

## Usage Examples

### Sending Messages to Other Services

#### Single destination
```php
use App\Services\OutboxService;

class OrderController extends Controller
{
    public function __construct(private OutboxService $outbox) {}
    
    public function createOrder(Request $request)
    {
        $order = $this->outbox->executeAndSend(
            businessLogic: fn() => Order::create($request->all()),
            destinationService: 'payment-service',
            eventType: 'OrderCreated',
            payload: ['order_id' => $orderId, ...],
            aggregateType: 'Order',
            aggregateId: $orderId
        );
        
        return response()->json($order, 201);
    }
}
```

#### Multiple destinations
```php
$order = $this->outbox->executeAndSendMultiple(
    businessLogic: fn() => $order->complete(),
    messages: [
        [
            'destination_service' => 'inventory-service',
            'event_type' => 'OrderCompleted',
            'payload' => [...],
            'aggregate_type' => 'Order',
            'aggregate_id' => $orderId,
        ],
        [
            'destination_service' => 'notification-service',
            'event_type' => 'OrderCompleted',
            'payload' => [...],
            'aggregate_type' => 'Order',
            'aggregate_id' => $orderId,
        ],
    ]
);
```

### Receiving Messages from Other Services

#### Event handler registration
Inside `MessageBrokerServiceProvider`:
```php
$processor->registerHandler('PaymentCompleted', function ($message) {
    $order = Order::find($message->payload['order_id']);
    $order->update(['payment_status' => 'paid']);
});
```

#### Webhook endpoint
Other services POST to:
```
POST https://your-service/api/webhooks/messages
Headers:
  X-Message-Id: unique-id
  X-Source-Service: payment-service
  X-Event-Type: PaymentCompleted
  X-API-Key: your-key
Body: {...payload...}
```

## Message Broker Options

### HTTP (Default)
- Simple REST API calls
- No additional infrastructure required
- Great for small/medium deployments

### Kafka
```bash
composer require nmred/kafka-php
```
Set `MESSAGE_BROKER_DRIVER=kafka`

### RabbitMQ
```bash
composer require php-amqplib/php-amqplib
```
Set `MESSAGE_BROKER_DRIVER=rabbitmq`

## Running the System

### Start the scheduler (required)
```bash
php artisan schedule:work
```

### Manual processing
```bash
# Process outbox messages
php artisan outbox:process

# Process inbox messages
php artisan inbox:process

# Process messages for a specific service
php artisan outbox:process --service=payment-service

# Retry failed messages
php artisan outbox:process --retry
php artisan inbox:process --retry

# Cleanup old messages
php artisan messages:cleanup --days=7
```

## Configuration Tips

- **Header names:** customize `transactional-outbox.headers` to redefine which headers carry the message id, source service, event type, or to change the prefix used when collecting custom metadata.
- **Inbox handlers:** list handler classes inside `transactional-outbox.inbox.handlers`. Each class must implement `Urfysoft\TransactionalOutbox\Contracts\InboxEventHandler` (define `eventType()` and `handle()`).
- **Runtime registration:** handlers can also be registered anywhere via the facade:

```php
use TransactionalOutbox;
use App\Messaging\PaymentCompletedHandler;

TransactionalOutbox::registerInboxHandler(new PaymentCompletedHandler());
```

### Monitoring
```sql
-- Pending outbox messages
SELECT * FROM outbox_messages WHERE status = 'pending';

-- Failed outbox messages
SELECT * FROM outbox_messages WHERE status = 'failed';

-- Pending inbox messages
SELECT * FROM inbox_messages WHERE status = 'pending';
```

## Key Capabilities

✅ **Atomicity**: Business logic and messages live in the same transaction\
✅ **Reliability**: No data loss even when the broker is down\
✅ **Idempotency**: Duplicate messages are automatically detected\
✅ **Retry logic**: Failed deliveries are retried automatically\
✅ **Multi-broker**: HTTP, Kafka, RabbitMQ drivers\
✅ **Monitoring**: Track message statuses and errors\
✅ **Scalability**: Batch processing support

## Best Practices

1. **Always propagate a correlation ID** for request tracing
2. **Keep payloads small**—send references instead of full objects
3. **Monitor failed messages** and set up alerts
4. **Clean up regularly** to remove processed records
5. **Test idempotency** to ensure handlers tolerate duplicates
6. **Use a dead-letter queue** after exhausting retries
7. **Version your events**—include a version in `event_type`

## Troubleshooting

**Messages are not processed:**
- Ensure the scheduler is running: `php artisan schedule:work`
- Inspect message statuses in the database
- Check logs: `tail -f storage/logs/laravel.log`

**Duplicate messages:**
- The Inbox pattern handles duplicates automatically
- Verify `message_id` uniqueness

**Failed messages:**
- Inspect the `last_error` column
- Use the retry command: `php artisan outbox:process --retry`
- Confirm the destination service is reachable

## Advanced Topic: Saga Pattern

Combine Transactional Outbox with the Saga pattern for distributed transactions:

```php
// Orchestration-based saga
class OrderSaga
{
    public function execute(Order $order)
    {
        DB::transaction(function () use ($order) {
            // Step 1: Reserve inventory
            $this->outbox->sendToService(...);
            
            // Step 2: Charge payment
            $this->outbox->sendToService(...);
            
            // Step 3: Confirm the order
            $this->outbox->sendToService(...);
        });
    }
    
    // Compensation handlers for failures
    public function compensate() { ... }
}
```
