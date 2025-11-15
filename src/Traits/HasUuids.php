<?php

namespace Urfysoft\TransactionalOutbox\Traits;

use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Support\Str;

trait HasUuids
{
    use HasUniqueStringIds;

    /**
     * Generate a new unique key for the model.
     */
    public function newUniqueId(): string
    {
        return Str::uuid7()->toString();
    }

    /**
     * Determine if given key is valid.
     *
     * @param  mixed  $value
     */
    protected function isValidUniqueId($value): bool
    {
        return Str::isUuid($value);
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
