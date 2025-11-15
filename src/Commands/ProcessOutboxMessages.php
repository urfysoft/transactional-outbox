<?php

namespace Urfysoft\TransactionalOutbox\Commands;

use Urfysoft\TransactionalOutbox\Services\OutboxMessageRelay;
use Illuminate\Console\Command;

class ProcessOutboxMessages extends Command
{
    protected $signature = 'outbox:process
                            {--service= : Process messages only for the specified service}
                            {--limit=100 : Maximum number of messages per run}
                            {--retry : Reprocess messages marked as failed}';

    protected $description = 'Processes outbox messages and publishes them to the broker';

    public function handle(OutboxMessageRelay $relay): int
    {
        $limit = $this->resolveLimit($this->option('limit'));
        $serviceOption = $this->option('service');
        $service = is_string($serviceOption) && $serviceOption !== '' ? $serviceOption : null;
        $retry = (bool) $this->option('retry');

        $this->info('Processing outbox queue...');

        if ($retry) {
            /** @var array{retried:int,failed:int} $stats */
            $stats = $relay->retryFailed($limit);
            $this->info(sprintf(
                'Retried: %d, Failed: %d',
                $stats['retried'],
                $stats['failed'],
            ));
        } elseif ($service !== null) {
            /** @var array{processed:int,failed:int} $stats */
            $stats = $relay->processForService($service, $limit);
            $this->info(sprintf(
                'Published: %d, Failed: %d',
                $stats['processed'],
                $stats['failed'],
            ));
        } else {
            /** @var array{processed:int,failed:int,skipped:int} $stats */
            $stats = $relay->processMessages($limit);
            $this->info(sprintf(
                'Published: %d, Failed: %d, Skipped: %d',
                $stats['processed'],
                $stats['failed'],
                $stats['skipped'],
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Ensures the limit is a positive integer and falls back to config defaults when needed.
     *
     * @param  mixed  $limitOption
     */
    private function resolveLimit(mixed $limitOption): int
    {
        $configuredDefault = (int) config('transactional-outbox.processing.batch_size', 100);
        $limit = is_numeric($limitOption) ? (int) $limitOption : $configuredDefault;

        if ($limit <= 0) {
            $this->warn(sprintf(
                'Invalid limit provided. Falling back to configured batch size (%d).',
                $configuredDefault,
            ));

            return max($configuredDefault, 1);
        }

        return $limit;
    }
}
