<?php

namespace App\Jobs;

use App\Models\YoutubeChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SyncYoutubeChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public int $channelId
    ) {}

    public function handle(): void
    {
        $channel = YoutubeChannel::query()->find($this->channelId);
        if ($channel === null) {
            return;
        }

        $this->channelLogger($channel->youtube_id)->info('', []);
        $this->channelLogger($channel->youtube_id)->info('YouTube channel sync started.', [
            'local_database_channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);

        $channel->update([
            'status' => 'syncing',
            'last_error' => null,
        ]);

        // TODO: invoke yt-dlp sync pipeline here.

        $channel->update([
            'status' => 'idle',
            'last_sync_at' => now(),
        ]);

        $this->channelLogger($channel->youtube_id)->info('YouTube channel sync finished.', [
            'local_database_channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $channel = YoutubeChannel::query()->find($this->channelId);
        if ($channel === null) {
            return;
        }

        $channel->update([
            'status' => 'failed',
            'last_error' => $exception->getMessage(),
        ]);

        $this->channelLogger($channel->youtube_id)->error('YouTube channel sync failed.', [
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
            'path' => $directory . '/sync.log',
        ]);
    }
}
