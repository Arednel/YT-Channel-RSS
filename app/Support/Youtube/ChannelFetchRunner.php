<?php

namespace App\Support\Youtube;

use App\Models\YoutubeChannel;
use App\Support\PythonBinaryResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class ChannelFetchRunner
{
    public function run(YoutubeChannel $channel): ChannelFetchRunResult
    {
        $channelUrl = 'https://www.youtube.com/' . ltrim($channel->youtube_id, '/');
        $outputDirectory = base_path('python/yt-dlp_jsons/' . $channel->youtube_id);
        File::ensureDirectoryExists($outputDirectory);

        $pythonLogFile = (string) config('logging.channels.python.path', storage_path('logs/python.log'));
        $pythonLogDirectory = dirname($pythonLogFile);
        if ($pythonLogDirectory !== '' && $pythonLogDirectory !== '.') {
            File::ensureDirectoryExists($pythonLogDirectory);
        }

        $pythonTimeoutSeconds = max(60, (int) config('youtube.python_process_timeout_seconds', 600));

        $command = [
            PythonBinaryResolver::resolve(),
            base_path('python/yt-dlp/channel_fetch.py'),
            '--channel-url',
            $channelUrl,
            '--out-dir',
            $outputDirectory,
            '--log-file',
            $pythonLogFile,
        ];

        $processResult = Process::timeout($pythonTimeoutSeconds)->run($command);

        if ($processResult->failed()) {
            $errorOutput = trim($processResult->errorOutput()) ?: trim($processResult->output());
            throw new \RuntimeException($errorOutput ?: 'yt-dlp channel fetch failed.');
        }

        return new ChannelFetchRunResult(
            outputDirectory: $outputDirectory,
            channelJsonPath: $outputDirectory . '/channel.json',
            videosJsonPath: $outputDirectory . '/videos.jsonl',
        );
    }
}
