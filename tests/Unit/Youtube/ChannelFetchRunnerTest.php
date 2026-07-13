<?php

namespace Tests\Unit\Youtube;

use App\Models\YoutubeChannel;
use App\Support\Youtube\ChannelFetchRunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ChannelFetchRunnerTest extends TestCase
{
    public function test_it_passes_normalized_retention_to_the_python_process(): void
    {
        config()->set('logging.retention_days', '45');
        $channel = new YoutubeChannel([
            'youtube_id' => '@retention-' . uniqid(),
        ]);
        $outputDirectory = base_path('python/yt-dlp_jsons/' . $channel->youtube_id);

        Process::fake(fn() => Process::result());
        Process::preventStrayProcesses();

        try {
            app(ChannelFetchRunner::class)->run($channel);

            Process::assertRan(function (PendingProcess $process): bool {
                return $process->environment === ['LOG_RETENTION_DAYS' => '45']
                    && is_array($process->command)
                    && in_array(base_path('python/yt-dlp/channel_fetch.py'), $process->command, true);
            });
        } finally {
            File::deleteDirectory($outputDirectory);
        }
    }
}
