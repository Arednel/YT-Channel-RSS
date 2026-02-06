<?php

namespace App\Enums;

enum YoutubeChannelStatus: string
{
    case Idle = 'idle';
    case Queued = 'queued';
    case Syncing = 'syncing';
    case FetchingVideoList = 'fetching video list';
    case FetchingVideos = 'fetching videos';
    case Deleting = 'deleting';
    case Failed = 'failed';

    public function badgeClass(): string
    {
        return match ($this) {
            self::Queued, self::Syncing, self::FetchingVideoList, self::FetchingVideos, self::Deleting => 'pending',
            self::Failed => 'failed',
            default => 'completed',
        };
    }
}

