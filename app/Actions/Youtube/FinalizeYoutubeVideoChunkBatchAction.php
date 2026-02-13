<?php

namespace App\Actions\Youtube;

use App\Jobs\BuildYoutubeFeedJob;
use App\Models\YoutubeChannel;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\DB;

class FinalizeYoutubeVideoChunkBatchAction
{
    public static function handleBatchFinally(Batch $batch): void
    {
        app(self::class)->handle($batch);
    }

    public function handle(Batch $batch): void
    {
        $rawChannelId = $batch->options['channel_id'] ?? null;
        $channelId = $this->normalizeChannelId($rawChannelId);
        if ($channelId === null) {
            return;
        }

        $freshChannel = YoutubeChannel::query()->find($channelId);
        if ($freshChannel === null) {
            return;
        }

        $shouldBuildFeed = DB::transaction(function () use ($batch, $freshChannel): bool {
            $freshChannel->clearActiveVideoBatchId();

            if ($freshChannel->isDeleting()) {
                return false;
            }

            if ($batch->failedJobs > 0) {
                $freshChannel->markFailed('One or more video chunks failed after retries.');

                return false;
            }

            $freshChannel->markBuildingFeed();

            return true;
        });

        if ($shouldBuildFeed) {
            BuildYoutubeFeedJob::dispatch($channelId);
        }
    }

    private function normalizeChannelId(mixed $rawChannelId): ?int
    {
        if (is_int($rawChannelId)) {
            return $rawChannelId;
        }

        if (is_string($rawChannelId) && ctype_digit($rawChannelId)) {
            return (int) $rawChannelId;
        }

        return null;
    }
}
