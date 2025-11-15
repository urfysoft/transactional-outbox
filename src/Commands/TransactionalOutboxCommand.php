<?php

namespace Urfysoft\TransactionalOutbox\Commands;

use Illuminate\Console\Command;

class TransactionalOutboxCommand extends Command
{
    public $signature = 'transactional-outbox';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
