<?php

namespace Database\Factories;

use App\Enums\YoutubeChannelStatus;
use App\Models\YoutubeChannel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<YoutubeChannel>
 */
class YoutubeChannelFactory extends Factory
{
    protected $model = YoutubeChannel::class;

    public function definition(): array
    {
        return [
            'youtube_id' => '@' . Str::lower($this->faker->unique()->bothify('channel####??')),
            'youtube_channel_id' => null,
            'channel_name' => $this->faker->company(),
            'status' => YoutubeChannelStatus::Idle,
            'last_sync_at' => null,
            'last_video_id' => null,
            'last_error' => null,
            'active_video_batch_id' => null,
            'video_fetch_progress_current' => null,
            'video_fetch_progress_total' => null,
        ];
    }

    public function idle(): self
    {
        return $this->state([
            'status' => YoutubeChannelStatus::Idle,
        ]);
    }

    public function fetchingVideos(): self
    {
        return $this->state([
            'status' => YoutubeChannelStatus::FetchingVideos,
        ]);
    }

    public function deleting(): self
    {
        return $this->state([
            'status' => YoutubeChannelStatus::Deleting,
        ]);
    }

    public function withActiveBatch(?string $batchId = null): self
    {
        return $this->state([
            'active_video_batch_id' => $batchId ?? (string) Str::orderedUuid(),
        ]);
    }

    public function forYoutubeId(string $youtubeId): self
    {
        return $this->state([
            'youtube_id' => $youtubeId,
        ]);
    }

    public function named(string $channelName): self
    {
        return $this->state([
            'channel_name' => $channelName,
        ]);
    }
}
