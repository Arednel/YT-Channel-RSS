<?php

namespace App\Jobs;

use App\Actions\Youtube\FinalizeYoutubeVideoChunkBatchAction;
use App\Actions\Youtube\RunYoutubeChannelSyncAction;
use App\Jobs\Middleware\PreventOverlappingYoutubeChannel;
use App\Jobs\Middleware\RateLimitYoutubeSync;
use App\Models\YoutubeChannel;
use App\Support\Youtube\YtDlpAutoUpdateManager;
use Illuminate\Bus\Batch;
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
    ) {
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new PreventOverlappingYoutubeChannel($this->channelId),
            new RateLimitYoutubeSync,
        ];
    }

    public function handle(
        RunYoutubeChannelSyncAction $runYoutubeChannelSync,
        YtDlpAutoUpdateManager $ytDlpAutoUpdateManager
    ): void
    {
        $runYoutubeChannelSync->handle($this->channelId);
        $ytDlpAutoUpdateManager->recordSuccess(YtDlpAutoUpdateManager::CHANNEL_UPDATE_JOB);
    }

    public static function handleVideoBatchFinally(Batch $batch): void
    {
        app(FinalizeYoutubeVideoChunkBatchAction::class)->handle($batch);
    }

    public function failed(\Throwable $exception): void
    {
        app(YtDlpAutoUpdateManager::class)->recordFailure(
            YtDlpAutoUpdateManager::CHANNEL_UPDATE_JOB,
            $exception->getMessage()
        );

        $channel = YoutubeChannel::query()->find($this->channelId);
        if ($channel === null) {
            return;
        }

        $channel->markFailed($exception->getMessage());

        $channel->clearActiveVideoBatchId();

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
