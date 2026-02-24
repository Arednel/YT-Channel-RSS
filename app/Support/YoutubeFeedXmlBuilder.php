<?php

namespace App\Support;

use App\Models\YoutubeChannel;
use App\Models\YoutubeVideo;
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

        $channelUCID = $channel->youtube_channel_id;
        $channelUCIDFullLink = "https://www.youtube.com/channel/" . $channel->youtube_channel_id;
        $channelName = $channel->channel_name ?: $channel->youtube_id;
        $feedUrl = $channel->rss_url;
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

            $writer->writeElement('id', "yt:channel:" . $channelUCID);

            $writer->writeElement('yt:channelId', $channelUCID);

            $writer->writeElement('title', $channelName);

            $writer->startElement('link');
            $writer->writeAttribute('rel', 'alternate');
            $writer->writeAttribute('href', $channelUCIDFullLink);
            $writer->endElement();

            $writer->startElement('author');
            $writer->writeElement('name', $channelName);
            $writer->writeElement('uri', $channelUCIDFullLink);
            $writer->endElement();

            $writer->writeElement('published', $feedPublishedAt->toAtomString());
            $writer->writeElement('updated', $feedUpdatedAt->toAtomString());

            foreach ($videosQuery->cursor() as $video) {
                if ($this->writeEntry($writer, $video, $channelUCID, $channelName, $channelUCIDFullLink)) {
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
        string $channelUCID,
        string $channelName,
        string $channelUCIDFullLink
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
        $writer->writeElement('yt:videoId', $video->youtube_video_id);
        $writer->writeElement('yt:channelId', $channelUCID);
        $writer->writeElement('title', $videoTitle);

        $writer->startElement('link');
        $writer->writeAttribute('rel', 'alternate');
        $writer->writeAttribute('href', 'https://www.youtube.com/watch?v=' . $video->youtube_video_id);
        $writer->endElement();

        $writer->startElement('author');
        $writer->writeElement('name', $channelName);
        $writer->writeElement('uri', $channelUCIDFullLink);
        $writer->endElement();

        $writer->writeElement('published', $publishedAt->toAtomString());
        $writer->writeElement('updated', $updatedAt->toAtomString());

        $writer->startElement('media:group');

        $writer->writeElement('media:title', $mediaTitle);

        $writer->startElement('media:content');
        $writer->writeAttribute('url', $video->media_content_url);
        $writer->writeAttribute('type', 'application/x-shockwave-flash');
        $writer->writeAttribute('width', '640');
        $writer->writeAttribute('height', '390');
        $writer->endElement();

        $writer->startElement('media:thumbnail');
        $writer->writeAttribute('url', $video->media_thumbnail_url);
        $writer->writeAttribute('width', '480');
        $writer->writeAttribute('height', '360');
        $writer->endElement();

        $writer->writeElement('media:description', $video->media_description);

        $writer->endElement();
        $writer->endElement();

        return true;
    }
}
