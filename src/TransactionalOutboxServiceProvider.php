<?php

namespace Urfysoft\TransactionalOutbox;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Urfysoft\TransactionalOutbox\Commands\CleanupMessages;
use Urfysoft\TransactionalOutbox\Commands\MakeToken;
use Urfysoft\TransactionalOutbox\Commands\ProcessInboxMessages;
use Urfysoft\TransactionalOutbox\Commands\ProcessOutboxMessages;
use Urfysoft\TransactionalOutbox\Contracts\InboxEventHandler;
use Urfysoft\TransactionalOutbox\Contracts\MessagePublisher;
use Urfysoft\TransactionalOutbox\Models\InboxMessage;
use Urfysoft\TransactionalOutbox\Publishers\HttpMessagePublisher;
use Urfysoft\TransactionalOutbox\Services\InboxMessageProcessor;
use Urfysoft\TransactionalOutbox\Services\InboxService;
use Urfysoft\TransactionalOutbox\Services\OutboxService;

class TransactionalOutboxServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('transactional-outbox')
            ->hasConfigFile('transactional-outbox')
            ->hasRoute('api')
            ->hasCommands([
                CleanupMessages::class,
                MakeToken::class,
                ProcessInboxMessages::class,
                ProcessOutboxMessages::class,
            ])
            ->hasMigrations([
                '2019_12_14_000001_create_personal_access_tokens_table',
                '2025_11_14_000002_create_outbox_messages_table',
                '2025_11_14_000001_create_inbox_messages_table',
            ]);

        // Bind the publisher implementation based on the configured driver
        $this->app->singleton(MessagePublisher::class, function ($app) {
            $broker = config('transactional-outbox.driver', 'http');

            return match ($broker) {
                'http' => new HttpMessagePublisher,
                default => throw new InvalidArgumentException("Unsupported broker: $broker"),
            };
        });

        // Register the inbox processor together with all handlers
        $this->app->singleton(InboxMessageProcessor::class, function ($app) {
            $processor = new InboxMessageProcessor;

            // Wire up event handlers
            $this->registerEventHandlers($processor, $app);

            return $processor;
        });

        // Aggregate inbox/outbox services behind a single facade-friendly class
        $this->app->singleton(TransactionalOutbox::class, function ($app) {
            return new TransactionalOutbox(
                $app->make(OutboxService::class),
                $app->make(InboxService::class),
                $app->make(InboxMessageProcessor::class),
            );
        });
    }

    /**
     * Registers handlers for incoming events.
     */
    private function registerEventHandlers(InboxMessageProcessor $processor, Container $app): void
    {
        $handlerClasses = config('transactional-outbox.inbox.handlers', []);

        foreach ($handlerClasses as $handlerClass) {
            $handler = $app->make($handlerClass);

            if (! $handler instanceof InboxEventHandler) {
                $type = is_object($handler) ? $handler::class : gettype($handler);
                throw new InvalidArgumentException(sprintf(
                    'Inbox handler [%s] must implement %s, %s given.',
                    $handlerClass,
                    InboxEventHandler::class,
                    $type,
                ));
            }

            $processor->registerHandler(
                $handler->eventType(),
                fn (InboxMessage $message): mixed => $handler->handle($message),
            );
        }
    }
}
