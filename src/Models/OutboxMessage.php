<?php

namespace Urfysoft\TransactionalOutbox\Models;

use Urfysoft\TransactionalOutbox\Enums\OutboxMessageStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\UuidV7;

class OutboxMessage extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'processes_at' => 'datetime',
        'published_at' => 'datetime',
        'status' => OutboxMessageStatusEnum::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (OutboxMessage $message) {
            if (empty($message->message_id)) {
                $message->message_id = UuidV7::v7()->toRfc4122();
            }
        });
    }

    public function markAsPublished(): void
    {
        $this->update([
            'status' => OutboxMessageStatusEnum::PUBLISHED,
            'published_at' => now(),
        ]);
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => OutboxMessageStatusEnum::PROCESSING,
            'processes_at' => now(),
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->increment('retry_count');

        $this->update([
            'status' => OutboxMessageStatusEnum::FAILED,
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
            ->where('status', OutboxMessageStatusEnum::PENDING)
            ->orderBy('created_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForService(Builder $query, string $service): Builder
    {
        return $query->where('destination_service', $service);
    }
}
