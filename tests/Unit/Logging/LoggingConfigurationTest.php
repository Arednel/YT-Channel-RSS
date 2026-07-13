<?php

namespace Tests\Unit\Logging;

use App\Logging\WeeklyRotatingFileHandler;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Monolog\Processor\PsrLogMessageProcessor;
use ReflectionProperty;
use Tests\TestCase;

class LoggingConfigurationTest extends TestCase
{
    private string $logDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDirectory = storage_path('framework/testing/configured-weekly-logs-' . uniqid());
        File::ensureDirectoryExists($this->logDirectory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logDirectory);

        parent::tearDown();
    }

    public function test_weekly_channels_are_configured_with_shared_retention_and_locked_writes(): void
    {
        $this->assertSame(90, config('logging.retention_days'));
        $this->assertContains('single', config('logging.channels.stack.channels'));

        foreach ($this->weeklyChannels() as $channel => $baseFilename) {
            $configuration = config("logging.channels.{$channel}");

            $this->assertSame('monolog', $configuration['driver']);
            $this->assertSame(WeeklyRotatingFileHandler::class, $configuration['handler']);
            $this->assertSame(storage_path('logs/' . $baseFilename), $configuration['handler_with']['filename']);
            $this->assertSame(90, $configuration['handler_with']['retentionDays']);
            $this->assertTrue($configuration['handler_with']['useLocking']);
            $this->assertContains(PsrLogMessageProcessor::class, $configuration['processors']);
        }

        $this->assertSame(
            storage_path('logs/python.log'),
            config('logging.channels.python.path'),
        );
    }

    public function test_all_weekly_channels_perform_real_locked_writes(): void
    {
        $weekStart = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('monday this week')
            ->format('Y-m-d');

        foreach ($this->weeklyChannels() as $channel => $baseFilename) {
            $configuration = config("logging.channels.{$channel}");
            $configuration['handler_with']['filename'] = $this->logDirectory . '/' . $baseFilename;

            $logger = Log::build($configuration);
            $handler = $logger->getHandlers()[0];

            $this->assertInstanceOf(WeeklyRotatingFileHandler::class, $handler);
            $this->assertTrue((new ReflectionProperty($handler, 'useLocking'))->getValue($handler));

            $logger->info("{$channel} configured write");
            $handler->close();

            $weeklyFilename = pathinfo($baseFilename, PATHINFO_FILENAME)
                . '-' . $weekStart . '.log';
            $weeklyPath = $this->logDirectory . '/' . $weeklyFilename;

            $this->assertFileExists($weeklyPath);
            $this->assertStringContainsString("{$channel} configured write", File::get($weeklyPath));
        }
    }

    /**
     * @return array<string, string>
     */
    private function weeklyChannels(): array
    {
        return [
            'single' => 'laravel.log',
            'python' => 'python.log',
            'youtube' => 'youtube.log',
            'yt_dlp_update' => 'yt-dlp-update.log',
        ];
    }
}
