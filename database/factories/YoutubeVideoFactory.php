<?php

namespace Database\Factories;

use App\Models\YoutubeChannel;
use App\Models\YoutubeVideo;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YoutubeVideo>
 */
class YoutubeVideoFactory extends Factory
{
    protected $model = YoutubeVideo::class;

    public function definition(): array
    {
        $videoId = 'video-' . $this->faker->unique()->bothify('####??');
        $title = $this->faker->sentence(3);

        return [
            'youtube_video_id' => $videoId,
            'youtube_channel_id' => YoutubeChannel::factory(),
            'video_title' => $title,
            'published_date' => now()->subDay(),
            'updated_date' => now()->subDay(),
            'is_upcoming' => false,
            'scheduled_start_at' => null,
            'media_title' => $title,
            'media_content_url' => 'https://www.youtube.com/v/' . $videoId . '?version=3',
            'media_thumbnail_url' => 'https://example.test/' . $videoId . '.jpg',
            'media_description' => $this->faker->sentence(),
        ];
    }

    public function upcoming(): self
    {
        return $this->state([
            'is_upcoming' => true,
            'scheduled_start_at' => now()->addDay(),
        ]);
    }

    public function forVideoId(string $videoId): self
    {
        return $this->state([
            'youtube_video_id' => $videoId,
            'media_content_url' => 'https://www.youtube.com/v/' . $videoId . '?version=3',
            'media_thumbnail_url' => 'https://example.test/' . $videoId . '.jpg',
        ]);
    }

    public function titled(string $title): self
    {
        return $this->state([
            'video_title' => $title,
            'media_title' => $title,
        ]);
    }

    public function withDescription(string $description): self
    {
        return $this->state([
            'media_description' => $description,
        ]);
    }

    public function publishedAt(CarbonInterface $publishedAt): self
    {
        return $this->state([
            'published_date' => $publishedAt,
            'updated_date' => $publishedAt,
        ]);
    }
}
