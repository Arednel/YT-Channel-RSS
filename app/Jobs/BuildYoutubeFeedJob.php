<?php

namespace App\Jobs;

use App\Enums\YoutubeChannelStatus;
use App\Models\YoutubeChannel;
use App\Support\YoutubeFeedXmlBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class BuildYoutubeFeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
            (new WithoutOverlapping('youtube-feed-build:' . $this->channelId))
                ->releaseAfter(15)
                ->expireAfter(3600),
        ];
    }

    public function handle(YoutubeFeedXmlBuilder $builder): void
    {
        $channel = YoutubeChannel::query()->find($this->channelId);
        if ($channel === null) {
            return;
        }

        if ($channel->status === YoutubeChannelStatus::Deleting) {
            return;
        }

        $this->channelLogger($channel->youtube_id)->info('', []);
        $this->channelLogger($channel->youtube_id)->info('XML feed build started.', [
            'channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);

        $channel->update([
            'status' => YoutubeChannelStatus::BuildingFeed,
        ]);

        $result = $builder->build($channel);

        $freshChannel = YoutubeChannel::query()->find($this->channelId);
        if ($freshChannel !== null && $freshChannel->status !== YoutubeChannelStatus::Deleting) {
            $freshChannel->update([
                'status' => YoutubeChannelStatus::Idle,
                'last_sync_at' => now(),
                'last_error' => null,
            ]);
        }

        $this->channelLogger($channel->youtube_id)->info('XML feed build finished.', [
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

        if ($channel->status === YoutubeChannelStatus::Deleting) {
            return;
        }

        $channel->update([
            'status' => YoutubeChannelStatus::FeedFailed,
            'last_error' => 'Feed build failed: ' . $exception->getMessage(),
        ]);

        $this->channelLogger($channel->youtube_id)->error('XML feed build failed.', [
            'channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
            'error' => $exception->getMessage(),
        ]);
    }

    private function channelLogger(string $youtubeId): \Psr\Log\LoggerInterface
    {
        $directory = storage_path('logs/' . $youtubeId);
        File::ensureDirectoryExists($directory);

        return Log::build([
            'driver' => 'single',
            'path' => $directory . '/feed.log',
        ]);
    }
}
