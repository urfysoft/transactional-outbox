<?php

namespace Urfysoft\TransactionalOutbox\Contracts;

use Urfysoft\TransactionalOutbox\Models\InboxMessage;

interface InboxEventHandler
{
    public function eventType(): string;

    public function handle(InboxMessage $message): void;
}
