<?php

namespace App\Actions\Youtube;

use App\Jobs\SyncYoutubeChannelJob;
use App\Models\YoutubeChannel;
use App\Support\Youtube\VideoChunkPlan;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class DispatchYoutubeVideoChunkBatchAction
{
    public function handle(YoutubeChannel $channel, VideoChunkPlan $plan): Batch
    {
        $channelId = $channel->id;
        $lastVideoId = $plan->lastVideoId;
        $jobs = $plan->jobs;

        return DB::transaction(function () use ($channel, $channelId, $jobs, $lastVideoId): Batch {
            $freshChannel = YoutubeChannel::query()->find($channelId);
            if ($freshChannel === null) {
                throw new \RuntimeException('Channel deleted during sync dispatch.');
            }

            $freshChannel->markFetchingVideos($lastVideoId);

            $batch = Bus::batch($jobs)
                ->name('youtube_video_chunks:' . $channel->youtube_id)
                ->withOption('channel_id', $channelId)
                ->allowFailures()
                ->finally([SyncYoutubeChannelJob::class, 'handleVideoBatchFinally'])
                ->dispatch();

            $freshChannel->setActiveVideoBatchId($batch->id);

            return $batch;
        });
    }
}
