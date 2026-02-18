<?php

namespace App\Support\Youtube;

final class YoutubeChannelReference
{
    /**
     * @return array{youtube_id: string, youtube_channel_id: ?string}|null
     */
    public static function normalizeInput(string $input): ?array
    {
        $candidate = self::clean($input);
        if ($candidate === '') {
            return null;
        }

        $fromUrl = self::extractFromUrl($candidate);
        if ($fromUrl !== null) {
            return $fromUrl;
        }

        $candidatePath = ltrim(self::decode($candidate), '/');

        $handle = self::normalizeHandle($candidatePath);
        if ($handle !== null) {
            return [
                'youtube_id' => $handle,
                'youtube_channel_id' => null,
            ];
        }

        if (preg_match('/^channel\/([^\/\?\#\s]+)$/i', $candidatePath, $matches) === 1) {
            $channelId = self::normalizeChannelId($matches[1]);
            if ($channelId !== null) {
                return [
                    'youtube_id' => $channelId,
                    'youtube_channel_id' => $channelId,
                ];
            }
        }

        $channelId = self::normalizeChannelId($candidatePath);
        if ($channelId !== null) {
            return [
                'youtube_id' => $channelId,
                'youtube_channel_id' => $channelId,
            ];
        }

        return null;
    }

    public static function normalizeHandle(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $decoded = self::decode(self::clean($value));
        if ($decoded === '') {
            return null;
        }

        if (! str_starts_with($decoded, '@')) {
            return null;
        }

        if (preg_match('/^@[^\/\?\#\s]+$/u', $decoded) !== 1) {
            return null;
        }

        return $decoded;
    }

    public static function normalizeChannelId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $decoded = self::decode(self::clean($value));
        if (preg_match('/^UC[A-Za-z0-9_-]{22}$/', $decoded) !== 1) {
            return null;
        }

        return $decoded;
    }

    public static function canonicalPath(string $youtubeId, ?string $youtubeChannelId = null): string
    {
        $normalizedYoutubeId = self::decode(self::clean($youtubeId));
        $handle = self::normalizeHandle($normalizedYoutubeId);
        if ($handle !== null) {
            return $handle;
        }

        $channelId = self::normalizeChannelId($youtubeChannelId)
            ?? self::normalizeChannelId($normalizedYoutubeId);

        if ($channelId !== null) {
            return 'channel/' . $channelId;
        }

        return ltrim($normalizedYoutubeId, '/');
    }

    public static function canonicalUrl(string $youtubeId, ?string $youtubeChannelId = null): string
    {
        return 'https://www.youtube.com/' . self::canonicalPath($youtubeId, $youtubeChannelId);
    }

    public static function isChannelId(string $value): bool
    {
        return self::normalizeChannelId($value) !== null;
    }

    /**
     * @return array{youtube_id: string, youtube_channel_id: ?string}|null
     */
    private static function extractFromUrl(string $value): ?array
    {
        $parts = parse_url($value);
        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = self::decode((string) ($parts['path'] ?? ''));
        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => $segment !== ''
        ));

        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($appHost !== '' && $host === $appHost && count($segments) >= 2 && strtolower($segments[0]) === 'feeds') {
            return self::normalizeInput($segments[1]);
        }

        if (! in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            return null;
        }

        $firstSegment = $segments[0] ?? '';
        $secondSegment = $segments[1] ?? '';

        $handle = self::normalizeHandle($firstSegment);
        if ($handle !== null) {
            return [
                'youtube_id' => $handle,
                'youtube_channel_id' => null,
            ];
        }

        if (strtolower($firstSegment) === 'channel') {
            $channelId = self::normalizeChannelId($secondSegment);
            if ($channelId !== null) {
                return [
                    'youtube_id' => $channelId,
                    'youtube_channel_id' => $channelId,
                ];
            }
        }

        parse_str((string) ($parts['query'] ?? ''), $queryParams);
        $channelIdFromQuery = self::normalizeChannelId((string) ($queryParams['channel_id'] ?? ''));
        if ($channelIdFromQuery !== null) {
            return [
                'youtube_id' => $channelIdFromQuery,
                'youtube_channel_id' => $channelIdFromQuery,
            ];
        }

        if (preg_match('/@[^\/\?\#\s]+/u', $path, $matches) === 1) {
            $matchedHandle = self::normalizeHandle($matches[0]);
            if ($matchedHandle !== null) {
                return [
                    'youtube_id' => $matchedHandle,
                    'youtube_channel_id' => null,
                ];
            }
        }

        return null;
    }

    private static function clean(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? $value;

        return trim($value);
    }

    private static function decode(string $value): string
    {
        $decoded = $value;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = rawurldecode($decoded);
            if ($candidate === $decoded) {
                break;
            }

            $decoded = $candidate;
        }

        return $decoded;
    }
}
