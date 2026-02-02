<?php

namespace App\Jobs;

use App\Models\YoutubeChannel;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class SyncYoutubeChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public int $channelId
    ) {}

    public function handle(): void
    {
        $channel = YoutubeChannel::query()->find($this->channelId);
        if ($channel === null) {
            return;
        }

        $this->channelLogger($channel->youtube_id)->info('', []);
        $this->channelLogger($channel->youtube_id)->info('YouTube channel sync started.', [
            'local_database_channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);

        $channel->update([
            'status' => 'syncing',
            'last_error' => null,
        ]);

        $python = $this->resolvePythonBinary();

        $channelUrl = 'https://www.youtube.com/' . ltrim($channel->youtube_id, '/');
        $outputDirectory = base_path('python/yt-dlp_jsons/' . $channel->youtube_id);
        File::ensureDirectoryExists($outputDirectory);

        $logDirectory = base_path('python/logs/' . $channel->youtube_id);
        File::ensureDirectoryExists($logDirectory);
        $pythonLogFile = $logDirectory . '/channel_fetch_' . now()->format('Ymd_His') . '.log';

        $script = base_path('python/yt-dlp/channel_fetch.py');
        $process = new Process([
            $python,
            $script,
            '--channel-url',
            $channelUrl,
            '--out-dir',
            $outputDirectory,
            '--log-file',
            $pythonLogFile,
        ]);
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            throw new \RuntimeException($errorOutput ?: 'yt-dlp channel fetch failed.');
        }

        $channelJsonPath = $outputDirectory . '/channel.json';
        $videosJsonPath = $outputDirectory . '/videos.jsonl';

        if (File::exists($channelJsonPath)) {
            $channelData = json_decode(File::get($channelJsonPath), true);
            $channelName = $channelData['channel'] ?? $channelData['uploader'] ?? $channelData['title'] ?? null;

            $channel->update([
                'channel_name' => $channelName,
            ]);
        }

        if (File::exists($videosJsonPath)) {
            $lastVideoId = null;
            $lastVideoDate = null;

            foreach (File::lines($videosJsonPath) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $video = json_decode($line, true);
                if (! is_array($video) || empty($video['id'])) {
                    continue;
                }

                $publishedDate = null;
                if (! empty($video['upload_date'])) {
                    try {
                        $publishedDate = Carbon::createFromFormat('Ymd', $video['upload_date'])->startOfDay();
                    } catch (\Throwable) {
                        $publishedDate = null;
                    }
                }

                if ($publishedDate instanceof Carbon && ($lastVideoDate === null || $publishedDate->gt($lastVideoDate))) {
                    $lastVideoDate = $publishedDate;
                    $lastVideoId = $video['id'];
                }
            }

            if ($lastVideoId !== null) {
                $channel->update(['last_video_id' => $lastVideoId]);
            }
        }

        $channel->update([
            'status' => 'idle',
            'last_sync_at' => now(),
        ]);

        $this->channelLogger($channel->youtube_id)->info('YouTube channel sync finished.', [
            'local_database_channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $channel = YoutubeChannel::query()->find($this->channelId);
        if ($channel === null) {
            return;
        }

        $channel->update([
            'status' => 'failed',
            'last_error' => $exception->getMessage(),
        ]);

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

    private function resolvePythonBinary(): string
    {
        $candidates = [
            base_path('python/venv/Scripts/python.exe'),
            base_path('python/venv/bin/python'),
            'python3',
            'python',
        ];

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, base_path('python/venv')) && File::exists($candidate)) {
                return $candidate;
            }
        }

        return 'python';
    }
}
