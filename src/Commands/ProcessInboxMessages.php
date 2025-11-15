<?php

namespace Urfysoft\TransactionalOutbox\Commands;

use Urfysoft\TransactionalOutbox\Services\InboxMessageProcessor;
use Illuminate\Console\Command;

class ProcessInboxMessages extends Command
{
    protected $signature = 'inbox:process
                            {--limit=100 : Maximum number of messages per run}
                            {--retry : Reprocess messages marked as failed}';

    protected $description = 'Processes inbound messages from other services';

    public function handle(InboxMessageProcessor $processor): int
    {
        $limit = $this->resolveLimit($this->option('limit'));
        $retry = (bool) $this->option('retry');

        $this->info('Processing inbox queue...');

        if ($retry) {
            /** @var array{retried:int,failed:int} $stats */
            $stats = $processor->retryFailed($limit);
            $this->info(sprintf(
                'Retried: %d, Failed: %d',
                $stats['retried'],
                $stats['failed'],
            ));
        } else {
            /** @var array{processed:int,failed:int,no_handler:int} $stats */
            $stats = $processor->processMessages($limit);
            $this->info(sprintf(
                'Processed: %d, Failed: %d, No handler: %d',
                $stats['processed'],
                $stats['failed'],
                $stats['no_handler'],
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Validates the limit option and falls back to config defaults when necessary.
     *
     * @param mixed $limitOption
     * @return int
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
