<?php

namespace Urfysoft\TransactionalOutbox\Models;

use Urfysoft\TransactionalOutbox\Enums\InboxMessageStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InboxMessage extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'received_at' => 'datetime',
        'processes_at' => 'datetime',
        'status' => InboxMessageStatusEnum::class,
    ];

    public function markAsProcessed(): void
    {
        $this->update([
            'status' => InboxMessageStatusEnum::PROCESSED,
            'processes_at' => now(),
        ]);
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => InboxMessageStatusEnum::PROCESSING]);
    }

    public function markAsFailed(string $error): void
    {
        $this->increment('retry_count');

        $this->update([
            'status' => InboxMessageStatusEnum::FAILED,
            'last_error' => $error,
        ]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query
            ->where('status', InboxMessageStatusEnum::PENDING)
            ->orderBy('received_at');
    }

    public static function isDuplicate(string $messageId): bool
    {
        return static::where('message_id', $messageId)->exists();
    }
}
