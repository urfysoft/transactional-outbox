<?php

namespace Urfysoft\TransactionalOutbox\Traits;

trait ExtensionForEnum
{
    /**
     * @return list<string|int>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }
}
