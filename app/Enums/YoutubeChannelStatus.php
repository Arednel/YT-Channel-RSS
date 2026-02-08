<?php

namespace App\Enums;

enum YoutubeChannelStatus: string
{
    case Idle = 'idle';
    case Queued = 'queued';
    case Syncing = 'syncing';
    case FetchingVideoList = 'fetching video list';
    case FetchingVideos = 'fetching videos';
    case BuildingFeed = 'building feed';
    case Deleting = 'deleting';
    case Failed = 'failed';
    case FeedFailed = 'feed failed';

    /**
     * @return list<self>
     */
    public static function busyStatuses(): array
    {
        return [
            self::Queued,
            self::Syncing,
            self::FetchingVideoList,
            self::FetchingVideos,
            self::BuildingFeed,
            self::Deleting,
        ];
    }

    /**
     * @return list<string>
     */
    public static function busyValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::busyStatuses()
        );
    }

    public static function resolve(mixed $status): ?self
    {
        if ($status instanceof self) {
            return $status;
        }

        if (is_string($status)) {
            return self::tryFrom($status);
        }

        return null;
    }

    public function badgeClass(): string
    {
        if ($this->isBusy()) {
            return 'pending';
        }

        return match ($this) {
            self::Failed, self::FeedFailed => 'failed',
            default => 'completed',
        };
    }

    public function isBusy(): bool
    {
        return in_array($this, self::busyStatuses(), true);
    }

    public function canCopyRssLink(): bool
    {
        return $this === self::Idle;
    }
}
