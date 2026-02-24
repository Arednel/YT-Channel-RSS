<?php

namespace App\Support;

use App\Models\YoutubeChannel;
use App\Models\YoutubeVideo;
use App\Support\Youtube\YoutubeChannelReference;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class YoutubeFeedXmlBuilder
{
    private const ATOM_NS = 'http://www.w3.org/2005/Atom';
    private const YT_NS = 'http://www.youtube.com/xml/schemas/2015';
    private const MEDIA_NS = 'http://search.yahoo.com/mrss/';

    /**
     * @return array{path: string, relative_path: string, entry_count: int}
     */
    public function build(YoutubeChannel $channel): array
    {
        $disk = Storage::disk('public');
        $relativePath = 'feeds/' . $channel->youtube_id . '.xml';
        $absolutePath = $disk->path($relativePath);
        $temporaryPath = $absolutePath . '.tmp';

        File::ensureDirectoryExists(dirname($absolutePath));

        /** @var Builder<YoutubeVideo> $videosQuery */
        $videosQuery = $channel->videos()
            ->orderByDesc('published_date')
            ->orderByDesc('youtube_video_id');

        $channelIdentity = $this->resolveChannelIdentity($channel);
        $channelName = $channel->channel_name ?: $channel->youtube_id;
        $channelUrl = $channel->youtube_url;
        $feedUrl = rtrim((string) config('app.url'), '/') . '/feeds/' . $channel->youtube_id;
        $feedPublishedAt = $this->resolveFeedPublishedDate($channel);
        $feedUpdatedAt = $this->resolveFeedUpdatedDate($channel);
        $entryCount = 0;

        try {
            $writer = new \XMLWriter;
            if (! $writer->openUri($temporaryPath)) {
                throw new \RuntimeException('Unable to open feed file for writing: ' . $temporaryPath);
            }

            $writer->setIndent(true);
            $writer->setIndentString(' ');
            $writer->startDocument('1.0', 'UTF-8');

            $writer->startElementNS(null, 'feed', self::ATOM_NS);
            $writer->writeAttributeNs('xmlns', 'yt', null, self::YT_NS);
            $writer->writeAttributeNs('xmlns', 'media', null, self::MEDIA_NS);

            $writer->startElement('link');
            $writer->writeAttribute('rel', 'self');
            $writer->writeAttribute('href', $feedUrl);
            $writer->endElement();

            $writer->startElement('link');
            $writer->writeAttribute('rel', 'alternate');
            $writer->writeAttribute('href', $channelUrl);
            $writer->endElement();

            $writer->writeElement('id', $channelIdentity['feed_id']);

            if ($channelIdentity['channel_id'] !== null) {
                $writer->writeElementNS('yt', 'channelId', self::YT_NS, $channelIdentity['channel_id']);
            }

            $writer->writeElement('title', $channelName);

            $writer->startElement('author');
            $writer->writeElement('name', $channelName);
            $writer->writeElement('uri', $channelUrl);
            $writer->endElement();

            $writer->writeElement('published', $feedPublishedAt->toAtomString());
            $writer->writeElement('updated', $feedUpdatedAt->toAtomString());

            foreach ($videosQuery->cursor() as $video) {
                if ($this->writeEntry($writer, $video, $channelIdentity['channel_id'] ?? $channel->youtube_id, $channelName, $channelUrl)) {
                    $entryCount++;
                }
            }

            $writer->endElement();
            $writer->endDocument();
            $writer->flush();

            File::move($temporaryPath, $absolutePath);
        } catch (\Throwable $exception) {
            File::delete($temporaryPath);
            throw $exception;
        }

        return [
            'path' => $absolutePath,
            'relative_path' => $relativePath,
            'entry_count' => $entryCount,
        ];
    }

    /**
     * @return array{channel_id: ?string, feed_id: string}
     */
    private function resolveChannelIdentity(YoutubeChannel $channel): array
    {
        $channelId = YoutubeChannelReference::normalizeChannelId(
            is_string($channel->youtube_channel_id) ? $channel->youtube_channel_id : null
        ) ?? YoutubeChannelReference::normalizeChannelId(ltrim((string) $channel->youtube_id, '/'));
        $channelJsonPath = base_path('python/yt-dlp_jsons/' . $channel->youtube_id . '/channel.json');

        if (File::exists($channelJsonPath)) {
            try {
                $payload = json_decode(File::get($channelJsonPath), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($payload)) {
                    $channelId = YoutubeChannelReference::resolveChannelIdFromMetadata($payload) ?? $channelId;
                }
            } catch (\JsonException) {
                // Keep fallback identity when channel.json is unreadable.
            }
        }

        if ($channelId !== null) {
            return [
                'channel_id' => $channelId,
                'feed_id' => 'yt:channel:' . $channelId,
            ];
        }

        return [
            'channel_id' => null,
            'feed_id' => 'yt:channel_handle:' . ltrim($channel->youtube_id, '@'),
        ];
    }

    private function resolveFeedPublishedDate(YoutubeChannel $channel): CarbonInterface
    {
        $oldestPublishedDate = $channel->videos()->min('published_date');
        if (is_string($oldestPublishedDate) && $oldestPublishedDate !== '') {
            return \Carbon\Carbon::parse($oldestPublishedDate);
        }

        return $channel->created_at ?? now();
    }

    private function resolveFeedUpdatedDate(YoutubeChannel $channel): CarbonInterface
    {
        $maxUpdatedDate = $channel->videos()
            ->selectRaw('MAX(COALESCE(updated_date, published_date)) as max_updated_date')
            ->value('max_updated_date');
        if (is_string($maxUpdatedDate) && $maxUpdatedDate !== '') {
            return \Carbon\Carbon::parse($maxUpdatedDate);
        }

        return $channel->updated_at ?? now();
    }

    private function writeEntry(
        \XMLWriter $writer,
        YoutubeVideo $video,
        string $channelIdentity,
        string $channelName,
        string $channelUrl
    ): bool {
        $publishedAt = $video->published_date;
        if (! $publishedAt instanceof CarbonInterface) {
            return false;
        }

        $updatedAt = $video->updated_date instanceof CarbonInterface ? $video->updated_date : $publishedAt;
        $videoTitle = $video->video_title;
        $mediaTitle = $video->media_title ?: $videoTitle;

        $writer->startElement('entry');

        $writer->writeElement('id', 'yt:video:' . $video->youtube_video_id);
        $writer->writeElementNS('yt', 'videoId', self::YT_NS, $video->youtube_video_id);
        $writer->writeElementNS('yt', 'channelId', self::YT_NS, $channelIdentity);
        $writer->writeElement('title', $videoTitle);

        $writer->startElement('link');
        $writer->writeAttribute('rel', 'alternate');
        $writer->writeAttribute('href', 'https://www.youtube.com/watch?v=' . $video->youtube_video_id);
        $writer->endElement();

        $writer->startElement('author');
        $writer->writeElement('name', $channelName);
        $writer->writeElement('uri', $channelUrl);
        $writer->endElement();

        $writer->writeElement('published', $publishedAt->toAtomString());
        $writer->writeElement('updated', $updatedAt->toAtomString());

        $writer->startElementNS('media', 'group', self::MEDIA_NS);

        $writer->writeElementNS('media', 'title', self::MEDIA_NS, $mediaTitle);

        $writer->startElementNS('media', 'content', self::MEDIA_NS);
        $writer->writeAttribute('url', $video->media_content_url);
        $writer->writeAttribute('type', 'application/x-shockwave-flash');
        $writer->writeAttribute('width', '640');
        $writer->writeAttribute('height', '390');
        $writer->endElement();

        $writer->startElementNS('media', 'thumbnail', self::MEDIA_NS);
        $writer->writeAttribute('url', $video->media_thumbnail_url);
        $writer->writeAttribute('width', '480');
        $writer->writeAttribute('height', '360');
        $writer->endElement();

        $writer->writeElementNS('media', 'description', self::MEDIA_NS, $video->media_description);

        $writer->endElement();
        $writer->endElement();

        return true;
    }
}
