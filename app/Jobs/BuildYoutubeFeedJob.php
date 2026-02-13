<?php

namespace App\Jobs;

use App\Jobs\Middleware\PreventOverlappingYoutubeFeedBuild;
use App\Models\YoutubeChannel;
use App\Support\YoutubeFeedXmlBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class BuildYoutubeFeedJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public int $channelId
    ) {
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new PreventOverlappingYoutubeFeedBuild($this->channelId),
        ];
    }

    public function handle(YoutubeFeedXmlBuilder $builder): void
    {
        $channel = YoutubeChannel::query()->find($this->channelId);
        if ($channel === null) {
            return;
        }

        if ($channel->isDeleting()) {
            return;
        }

        Log::channel('youtube')->info('XML feed build started.', [
            'channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);

        $channel->markBuildingFeed();

        $result = $builder->build($channel);

        $freshChannel = YoutubeChannel::query()->find($this->channelId);
        if ($freshChannel !== null && ! $freshChannel->isDeleting()) {
            $freshChannel->markIdle();
        }

        Log::channel('youtube')->info('XML feed build finished.', [
            'channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
            'entry_count' => $result['entry_count'],
            'path' => $result['path'],
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $channel = YoutubeChannel::query()->find($this->channelId);
        if ($channel === null) {
            return;
        }

        if ($channel->isDeleting()) {
            return;
        }

        $channel->markFeedFailed('Feed build failed: ' . $exception->getMessage());

        Log::channel('youtube')->error('XML feed build failed.', [
            'channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
            'error' => $exception->getMessage(),
        ]);
    }
}
