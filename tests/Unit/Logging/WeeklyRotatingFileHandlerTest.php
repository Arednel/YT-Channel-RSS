<?php

namespace Tests\Unit\Logging;

use App\Logging\WeeklyRotatingFileHandler;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class WeeklyRotatingFileHandlerTest extends TestCase
{
    private string $logDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDirectory = storage_path('framework/testing/weekly-logs-' . uniqid());
        File::ensureDirectoryExists($this->logDirectory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logDirectory);

        parent::tearDown();
    }

    public function test_it_uses_utc_monday_boundaries_for_deterministic_filenames(): void
    {
        $handler = new WeeklyRotatingFileHandler($this->logDirectory . '/laravel.log');

        $handler->handle($this->record('2026-07-19 23:59:59', 'Sunday entry'));
        $handler->handle($this->record('2026-07-20 00:00:00', 'Monday entry'));
        $handler->close();

        $this->assertStringContainsString(
            'Sunday entry',
            File::get($this->logDirectory . '/laravel-2026-07-13.log'),
        );
        $this->assertStringContainsString(
            'Monday entry',
            File::get($this->logDirectory . '/laravel-2026-07-20.log'),
        );
        $this->assertFileDoesNotExist($this->logDirectory . '/laravel.log');
    }

    public function test_it_appends_within_a_week_and_switches_files_on_monday(): void
    {
        $handler = new WeeklyRotatingFileHandler($this->logDirectory . '/laravel.log');

        $handler->handle($this->record('2026-07-13 00:00:00', 'Monday entry'));
        $handler->handle($this->record('2026-07-19 23:59:59', 'Sunday entry'));
        $handler->handle($this->record('2026-07-20 00:00:00', 'Next Monday entry'));
        $handler->close();

        $firstWeek = File::get($this->logDirectory . '/laravel-2026-07-13.log');
        $secondWeek = File::get($this->logDirectory . '/laravel-2026-07-20.log');

        $this->assertStringContainsString('Monday entry', $firstWeek);
        $this->assertStringContainsString('Sunday entry', $firstWeek);
        $this->assertStringNotContainsString('Next Monday entry', $firstWeek);
        $this->assertStringContainsString('Next Monday entry', $secondWeek);
    }

    public function test_it_normalizes_invalid_retention_values_to_ninety_days(): void
    {
        foreach ([null, '', 'invalid', 0, '0', -1, '-1'] as $value) {
            $this->assertSame(90, WeeklyRotatingFileHandler::normalizeRetentionDays($value));
        }

        $this->assertSame(45, WeeklyRotatingFileHandler::normalizeRetentionDays('45'));
    }

    public function test_it_deletes_only_matching_monday_archives_after_the_complete_week_cutoff(): void
    {
        foreach (
            [
                'laravel-2026-04-06.log',
                'laravel-2026-04-07.log',
                'laravel-2026-04-13.log',
                'laravel-2026-02-30.log',
                'laravel-2026-07-13.log',
                'laravel-2026-07-20.log',
                'laravel-invalid.log',
                'laravel.log',
                'python-2026-04-06.log',
            ] as $filename
        ) {
            File::put($this->logDirectory . '/' . $filename, $filename);
        }

        $handler = new WeeklyRotatingFileHandler($this->logDirectory . '/laravel.log', 90);
        $handler->handle($this->record('2026-07-13 12:00:00', 'Cleanup entry'));
        $handler->close();

        $this->assertFileDoesNotExist($this->logDirectory . '/laravel-2026-04-06.log');

        foreach (
            [
                'laravel-2026-04-07.log',
                'laravel-2026-04-13.log',
                'laravel-2026-02-30.log',
                'laravel-2026-07-13.log',
                'laravel-2026-07-20.log',
                'laravel-invalid.log',
                'laravel.log',
                'python-2026-04-06.log',
            ] as $filename
        ) {
            $this->assertFileExists($this->logDirectory . '/' . $filename);
        }
    }

    public function test_it_cleans_up_at_the_exact_expiry_instant_on_a_same_week_write(): void
    {
        $archive = $this->logDirectory . '/laravel-2026-04-13.log';
        File::put($archive, 'Archive pending expiry');

        $handler = new WeeklyRotatingFileHandler($this->logDirectory . '/laravel.log', 90);
        $handler->handle($this->record('2026-07-18 23:59:59', 'Before expiry'));
        $this->assertFileExists($archive);

        $handler->handle($this->record('2026-07-19 00:00:00', 'At expiry'));
        $handler->close();

        $this->assertFileDoesNotExist($archive);
        $this->assertStringContainsString(
            'At expiry',
            File::get($this->logDirectory . '/laravel-2026-07-13.log'),
        );
    }

    public function test_future_archives_and_oversized_retention_do_not_block_writes(): void
    {
        $futureArchive = $this->logDirectory . '/laravel-9999-12-27.log';
        $oldArchive = $this->logDirectory . '/laravel-2026-04-06.log';
        File::put($futureArchive, 'Future archive');
        File::put($oldArchive, 'Old archive');

        $handler = new WeeklyRotatingFileHandler($this->logDirectory . '/laravel.log', PHP_INT_MAX);
        $handler->handle($this->record('2026-07-13 12:00:00', 'Oversized retention write'));
        $handler->close();

        $this->assertFileExists($futureArchive);
        $this->assertFileExists($oldArchive);
        $this->assertStringContainsString(
            'Oversized retention write',
            File::get($this->logDirectory . '/laravel-2026-07-13.log'),
        );
    }

    public function test_concurrent_removal_is_successful_cleanup(): void
    {
        $archive = $this->logDirectory . '/laravel-2026-04-06.log';
        File::put($archive, 'Expired archive');

        $handler = new class($this->logDirectory . '/laravel.log', 90) extends WeeklyRotatingFileHandler
        {
            public array $cleanupFailures = [];

            protected function deleteArchive(string $archive): bool
            {
                File::delete($archive);

                return false;
            }

            protected function reportCleanupFailure(string $archive): void
            {
                $this->cleanupFailures[] = $archive;
            }
        };

        $handler->handle($this->record('2026-07-13 12:00:00', 'Concurrent cleanup write'));
        $handler->close();

        $this->assertFileDoesNotExist($archive);
        $this->assertSame([], $handler->cleanupFailures);
        $this->assertStringContainsString(
            'Concurrent cleanup write',
            File::get($this->logDirectory . '/laravel-2026-07-13.log'),
        );
    }

    public function test_cleanup_failure_is_reported_without_blocking_the_current_write(): void
    {
        $archive = $this->logDirectory . '/laravel-2026-04-06.log';
        File::put($archive, 'Expired archive');

        $handler = new class($this->logDirectory . '/laravel.log', 90) extends WeeklyRotatingFileHandler
        {
            public array $deleteAttempts = [];

            public array $cleanupFailures = [];

            protected function deleteArchive(string $archive): bool
            {
                $this->deleteAttempts[] = $archive;

                return false;
            }

            protected function reportCleanupFailure(string $archive): void
            {
                $this->cleanupFailures[] = $archive;
            }
        };

        $handler->handle($this->record('2026-07-13 12:00:00', 'Write survives cleanup'));
        $handler->close();

        $this->assertFileExists($archive);
        $this->assertSame(['laravel-2026-04-06.log'], array_map('basename', $handler->deleteAttempts));
        $this->assertSame(['laravel-2026-04-06.log'], array_map('basename', $handler->cleanupFailures));
        $this->assertStringContainsString(
            'Write survives cleanup',
            File::get($this->logDirectory . '/laravel-2026-07-13.log'),
        );
    }

    private function record(string $datetime, string $message): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable($datetime, new DateTimeZone('UTC')),
            channel: 'testing',
            level: Level::Info,
            message: $message,
        );
    }
}
