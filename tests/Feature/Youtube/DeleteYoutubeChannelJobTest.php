<?php

namespace Tests\Feature\Youtube;

use App\Jobs\DeleteYoutubeChannelJob;
use App\Jobs\FetchYoutubeVideoChunkJob;
use App\Models\YoutubeChannel;
use App\Models\YoutubeVideo;
use App\Support\YoutubeBatchManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeleteYoutubeChannelJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_cancels_active_batch_and_deletes_channel_records(): void
    {
        Storage::fake('public');

        $youtubeId = '@delete-cleanup-' . uniqid();
        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId($youtubeId)
            ->named('Delete Me')
            ->create();

        YoutubeVideo::factory()
            ->for($channel, 'channel')
            ->forVideoId('delete-video-001')
            ->titled('Delete Video')
            ->withDescription('delete fixture')
            ->publishedAt(now())
            ->create();

        Storage::disk('public')->put('feeds/' . $youtubeId . '.xml', '<feed />');

        $batch = Bus::batch([
            new FetchYoutubeVideoChunkJob(
                channelId: $channel->id,
                youtubeId: $youtubeId,
                chunkIndex: 0,
                chunkSize: 1,
                sourceFile: 'video_id_chunks/chunk_00001.jsonl',
            ),
        ])->name('delete-cleanup-batch')->dispatch();
        $channel->setActiveVideoBatchId($batch->id);

        $job = new DeleteYoutubeChannelJob($channel->id);
        $job->handle(app(YoutubeBatchManager::class));

        $this->assertDatabaseMissing('youtube_channels', [
            'id' => $channel->id,
        ]);
        $this->assertDatabaseMissing('youtube_videos', [
            'youtube_channel_id' => $channel->id,
        ]);

        $freshBatch = Bus::findBatch($batch->id);
        $this->assertNotNull($freshBatch);
        $this->assertTrue($freshBatch->cancelled());
    }
}
