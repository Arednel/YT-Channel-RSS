<?php

namespace App\Jobs;

use App\Jobs\Middleware\PreventOverlappingYoutubeChannel;
use App\Models\YoutubeChannel;
use App\Support\YoutubeBatchManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeleteYoutubeChannelJob implements ShouldQueue
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
            new PreventOverlappingYoutubeChannel($this->channelId),
        ];
    }

    public function handle(YoutubeBatchManager $youtubeBatchManager): void
    {
        $channel = YoutubeChannel::query()->find($this->channelId);
        if ($channel === null) {
            return;
        }

        Log::channel('youtube')->info('YouTube channel delete started.', [
            'channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);

        $batchCanceled = $youtubeBatchManager->cancelActiveVideoBatch($channel);
        if ($batchCanceled) {
            Log::channel('youtube')->info('Active video batch canceled before delete.', [
                'channel_id' => $channel->id,
                'youtube_id' => $channel->youtube_id,
            ]);
        }

        Storage::disk('public')->delete('feeds/' . $channel->youtube_id . '.xml');
        File::deleteDirectory(base_path('python/yt-dlp_jsons/' . $channel->youtube_id));

        $channel->delete();

        Log::channel('youtube')->info('YouTube channel delete finished.', [
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

        $channel->markFailed($exception->getMessage());

        Log::channel('youtube')->error('YouTube channel delete failed.', [
            'channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
            'error' => $exception->getMessage(),
        ]);
    }
}
