<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Option extends Model
{
    public const CHANNELS_PER_PAGE = 'channels_per_page';

    public const CHANNELS_PER_PAGE_UNLIMITED = 'unlimited';

    public const DEFAULT_CHANNELS_PER_PAGE = 100;

    public const FIXED_CHANNELS_PER_PAGE_OPTIONS = [
        10,
        25,
        50,
        100,
        250,
        500,
        1000,
    ];

    protected $fillable = [
        'key',
        'value',
    ];

    public static function channelsPerPage(): int|string
    {
        $option = self::query()
            ->where('key', self::CHANNELS_PER_PAGE)
            ->first(['value']);

        return self::normalizeChannelsPerPage($option?->value ?? self::DEFAULT_CHANNELS_PER_PAGE);
    }

    public static function setChannelsPerPage(int|string $value): void
    {
        self::query()->updateOrCreate(
            ['key' => self::CHANNELS_PER_PAGE],
            ['value' => (string) self::normalizeChannelsPerPage($value)],
        );
    }

    public static function normalizeChannelsPerPage(mixed $value): int|string
    {
        if ($value === self::CHANNELS_PER_PAGE_UNLIMITED) {
            return self::CHANNELS_PER_PAGE_UNLIMITED;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            return (int) $value;
        }

        return self::DEFAULT_CHANNELS_PER_PAGE;
    }

    /**
     * @return array<int, string>
     */
    public static function fixedChannelsPerPageOptions(): array
    {
        return collect(self::FIXED_CHANNELS_PER_PAGE_OPTIONS)
            ->mapWithKeys(static fn (int $value): array => [$value => (string) $value])
            ->all();
    }
}
