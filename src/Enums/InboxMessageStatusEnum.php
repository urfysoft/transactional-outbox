<?php

namespace Urfysoft\TransactionalOutbox\Enums;

use Urfysoft\TransactionalOutbox\Traits\ExtensionForEnum;

enum InboxMessageStatusEnum: string
{
    use ExtensionForEnum;

    case PENDING = 'pending';

    case PROCESSING = 'processing';

    case PROCESSED = 'processed';

    case FAILED = 'failed';
}
