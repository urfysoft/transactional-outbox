<?php

namespace Urfysoft\TransactionalOutbox\Enums;

use Urfysoft\TransactionalOutbox\Traits\ExtensionForEnum;

enum OutboxMessageStatusEnum: string
{
    use ExtensionForEnum;

    case PENDING = 'pending';

    case PROCESSING = 'processing';

    case PUBLISHED = 'published';

    case FAILED = 'failed';
}
