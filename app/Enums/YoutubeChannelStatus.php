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

    public function badgeClass(): string
    {
        return match ($this) {
            self::Queued, self::Syncing, self::FetchingVideoList, self::FetchingVideos, self::BuildingFeed, self::Deleting => 'pending',
            self::Failed, self::FeedFailed => 'failed',
            default => 'completed',
        };
    }
}
