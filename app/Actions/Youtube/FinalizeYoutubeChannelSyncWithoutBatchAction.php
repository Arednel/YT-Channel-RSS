<?php

namespace App\Actions\Youtube;

use App\Models\YoutubeChannel;
use Illuminate\Support\Facades\DB;

class FinalizeYoutubeChannelSyncWithoutBatchAction
{
    public function handle(int $channelId, bool $shouldBuildFeed): void
    {
        DB::transaction(function () use ($channelId, $shouldBuildFeed): void {
            $freshChannel = YoutubeChannel::query()->find($channelId);
            if ($freshChannel === null || $freshChannel->isDeleting()) {
                return;
            }

            if ($shouldBuildFeed) {
                $freshChannel->markBuildingFeed(true);

                return;
            }

            $freshChannel->markIdle();
        });
    }
}
