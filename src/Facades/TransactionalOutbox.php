<?php

namespace Urfysoft\TransactionalOutbox\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Urfysoft\TransactionalOutbox\TransactionalOutbox
 */
class TransactionalOutbox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Urfysoft\TransactionalOutbox\TransactionalOutbox::class;
    }
}
