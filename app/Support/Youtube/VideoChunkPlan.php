<?php

namespace App\Support\Youtube;

use App\Jobs\FetchYoutubeVideoChunkJob;

class VideoChunkPlan
{
    /**
     * @param list<FetchYoutubeVideoChunkJob> $jobs
     */
    public function __construct(
        public array $jobs,
        public ?string $lastVideoId,
        public int $chunkCount,
        public int $chunkSize,
        public int $queuedVideoCount,
        public int $skippedExistingCount,
        public int $recheckedUpcomingCount,
        public int $encodingSkippedCount
    ) {}

    public function hasJobs(): bool
    {
        return $this->jobs !== [];
    }
}
