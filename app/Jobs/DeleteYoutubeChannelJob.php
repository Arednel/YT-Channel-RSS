<?php

namespace App\Jobs;

use App\Enums\YoutubeChannelStatus;
use App\Models\YoutubeChannel;
use App\Support\YoutubeBatchManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeleteYoutubeChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public int $channelId
    ) {
    }

    public function handle(): void
    {
        $channel = YoutubeChannel::query()->find($this->channelId);
        if ($channel === null) {
            return;
        }

        $this->channelLogger($channel->youtube_id)->info('', []);
        $this->channelLogger($channel->youtube_id)->info('YouTube channel delete started.', [
            'channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);

        $batchCanceled = YoutubeBatchManager::cancelActiveVideoBatch($channel);
        if ($batchCanceled) {
            $this->channelLogger($channel->youtube_id)->info('Active video batch canceled before delete.', [
                'channel_id' => $channel->id,
                'youtube_id' => $channel->youtube_id,
            ]);
        }

        Storage::disk('public')->delete('feeds/' . $channel->youtube_id . '.xml');
        File::deleteDirectory(base_path('python/yt-dlp_jsons/' . $channel->youtube_id));

        $channel->delete();

        $this->channelLogger($channel->youtube_id)->info('YouTube channel delete finished.', [
            'channel_id' => $channel->id,
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
            'status' => YoutubeChannelStatus::Failed,
            'last_error' => $exception->getMessage(),
        ]);

        $this->channelLogger($channel->youtube_id)->error('YouTube channel delete failed.', [
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
            'path' => $directory . '/delete.log',
        ]);
    }
}
