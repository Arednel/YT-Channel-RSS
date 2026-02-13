<?php

namespace Tests\Concerns;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

trait FakesYoutubePythonProcesses
{
    /**
     * @param callable(array<string, mixed>, string):(?array<string, mixed>) $rowMapper
     */
    protected function fakeYoutubeChunkProcessFromSource(callable $rowMapper): void
    {
        Process::fake(function (PendingProcess $process) use ($rowMapper) {
            $command = $process->command;
            if (! is_array($command)) {
                return Process::result('', 'Process command must be array.', 1);
            }

            if ($this->isChannelFetchScript($command)) {
                return Process::result();
            }

            if (! $this->isVideoChunkScript($command)) {
                return Process::result('', 'Unexpected process call: ' . json_encode($command), 1);
            }

            $sourceDirectory = $this->commandOptionValue($command, '--source-dir');
            $sourceFile = $this->commandOptionValue($command, '--source-file');
            $jsonlOutput = $this->commandOptionValue($command, '--jsonl-output');
            if (! is_string($sourceDirectory) || ! is_string($sourceFile) || ! is_string($jsonlOutput)) {
                return Process::result('', 'Missing expected chunk process options.', 1);
            }

            $sourcePath = rtrim($sourceDirectory, '\\/') . DIRECTORY_SEPARATOR . ltrim($sourceFile, '\\/');
            if (! File::exists($sourcePath)) {
                return Process::result('', 'Chunk source file missing: ' . $sourcePath, 1);
            }

            $rows = [];
            foreach (File::lines($sourcePath) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $entry = json_decode($line, true);
                if (! is_array($entry)) {
                    continue;
                }

                $videoId = $entry['id'] ?? null;
                if (! is_string($videoId) || $videoId === '') {
                    continue;
                }

                $row = $rowMapper($entry, $videoId);
                if (! is_array($row)) {
                    continue;
                }

                if (! isset($row['id']) || ! is_string($row['id']) || $row['id'] === '') {
                    $row['id'] = $videoId;
                }

                $rows[] = $row;
            }

            $this->writeJsonlRows($jsonlOutput, $rows);

            return Process::result();
        });

        Process::preventStrayProcesses();
    }

    /**
     * @param list<list<array<string, mixed>>> $rowsByCall
     */
    protected function fakeYoutubeChunkProcessWithRowsByCall(array $rowsByCall): void
    {
        $callIndex = 0;

        Process::fake(function (PendingProcess $process) use (&$callIndex, $rowsByCall) {
            $command = $process->command;
            if (! is_array($command)) {
                return Process::result('', 'Process command must be array.', 1);
            }

            if ($this->isChannelFetchScript($command)) {
                return Process::result();
            }

            if (! $this->isVideoChunkScript($command)) {
                return Process::result('', 'Unexpected process call: ' . json_encode($command), 1);
            }

            $jsonlOutput = $this->commandOptionValue($command, '--jsonl-output');
            if (! is_string($jsonlOutput)) {
                return Process::result('', 'Missing --jsonl-output option.', 1);
            }

            if ($rowsByCall === []) {
                $rows = [];
            } else {
                $rows = $rowsByCall[min($callIndex, count($rowsByCall) - 1)];
            }
            $callIndex++;

            $this->writeJsonlRows($jsonlOutput, $rows);

            return Process::result();
        });

        Process::preventStrayProcesses();
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    protected function writeJsonlRows(string $jsonlOutput, array $rows): void
    {
        $encodedRows = array_map(
            static fn(array $row): string => json_encode(
                $row,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            $rows
        );

        File::ensureDirectoryExists(dirname($jsonlOutput));
        File::put(
            $jsonlOutput,
            $encodedRows === [] ? '' : implode(PHP_EOL, $encodedRows) . PHP_EOL
        );
    }

    /**
     * @param array<int, mixed> $command
     */
    protected function commandOptionValue(array $command, string $option): ?string
    {
        $index = array_search($option, $command, true);
        if (! is_int($index)) {
            return null;
        }

        $value = $command[$index + 1] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<int, mixed> $command
     */
    private function isVideoChunkScript(array $command): bool
    {
        return str_ends_with($this->normalizedScriptPath($command), 'video_fetch_chunk.py');
    }

    /**
     * @param array<int, mixed> $command
     */
    private function isChannelFetchScript(array $command): bool
    {
        return str_ends_with($this->normalizedScriptPath($command), 'channel_fetch.py');
    }

    /**
     * @param array<int, mixed> $command
     */
    private function normalizedScriptPath(array $command): string
    {
        foreach ($command as $segment) {
            if (! is_string($segment)) {
                continue;
            }

            $normalized = str_replace('\\', '/', $segment);
            if (str_ends_with($normalized, '.py')) {
                return $normalized;
            }
        }

        return '';
    }
}
