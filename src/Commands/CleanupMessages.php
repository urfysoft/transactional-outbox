<?php

namespace Urfysoft\TransactionalOutbox\Commands;

use Illuminate\Console\Command;
use Urfysoft\TransactionalOutbox\Enums\InboxMessageStatusEnum;
use Urfysoft\TransactionalOutbox\Enums\OutboxMessageStatusEnum;
use Urfysoft\TransactionalOutbox\Models\InboxMessage;
use Urfysoft\TransactionalOutbox\Models\OutboxMessage;

class CleanupMessages extends Command
{
    private const string TYPE_OUTBOX = 'outbox';

    private const string TYPE_INBOX = 'inbox';

    private const string TYPE_BOTH = 'both';

    protected $signature = 'urfysoft:messages-cleanup
                            {--days=7 : Number of days to keep processed messages}
                            {--type=both : Which boxes to purge (outbox, inbox, both)}';

    protected $description = 'Deletes processed messages older than the retention window';

    public function handle(): int
    {
        $days = $this->resolveDays($this->option('days'));
        $type = $this->resolveType($this->option('type'));
        if ($type === null) {
            return self::INVALID;
        }
        $cutoff = now()->subDays($days);

        $deleted = 0;

        if (in_array($type, [self::TYPE_OUTBOX, self::TYPE_BOTH], true)) {
            /** @var int $outboxDeleted */
            $outboxDeleted = OutboxMessage::where('status', OutboxMessageStatusEnum::PUBLISHED)
                ->whereNotNull('published_at')
                ->where('published_at', '<', $cutoff)
                ->delete();
            $this->info(sprintf('Deleted %d outbox messages', $outboxDeleted));
            $deleted += $outboxDeleted;
        }

        if (in_array($type, [self::TYPE_INBOX, self::TYPE_BOTH], true)) {
            /** @var int $inboxDeleted */
            $inboxDeleted = InboxMessage::where('status', InboxMessageStatusEnum::PROCESSED)
                ->whereNotNull('processes_at')
                ->where('processes_at', '<', $cutoff)
                ->delete();
            $this->info(sprintf('Deleted %d inbox messages', $inboxDeleted));
            $deleted += $inboxDeleted;
        }

        $this->info(sprintf('Total deleted messages: %d', $deleted));

        return self::SUCCESS;
    }

    /**
     * Normalizes the --type option and validates allowed values.
     */
    private function resolveType(mixed $type): ?string
    {
        $normalized = is_string($type) ? strtolower(trim($type)) : self::TYPE_BOTH;
        $allowed = [self::TYPE_OUTBOX, self::TYPE_INBOX, self::TYPE_BOTH];

        if (! in_array($normalized, $allowed, true)) {
            $this->error(sprintf(
                'Invalid type "%s". Allowed values are: %s',
                $type,
                implode(', ', $allowed),
            ));

            return null;
        }

        return $normalized;
    }

    /**
     * Ensures the retention window is positive.
     */
    private function resolveDays(mixed $daysOption): int
    {
        $days = is_numeric($daysOption) ? (int) $daysOption : 7;

        if ($days <= 0) {
            $this->warn('Retention period must be greater than zero. Falling back to 7 days.');
            return 7;
        }

        return $days;
    }
}
