<?php

namespace Tests\Unit\Youtube;

use App\Support\Youtube\YoutubeChannelReference;
use Tests\TestCase;

class YoutubeChannelReferenceTest extends TestCase
{
    public function test_it_normalizes_percent_encoded_handle_from_raw_value(): void
    {
        $reference = YoutubeChannelReference::normalizeInput(" \t@MateusGuimar%C3%A3es ");
        $expectedHandle = rawurldecode('@MateusGuimar%C3%A3es');

        $this->assertSame($expectedHandle, $reference['youtube_id']);
        $this->assertNull($reference['youtube_channel_id']);
    }

    public function test_it_normalizes_percent_encoded_handle_from_url(): void
    {
        $reference = YoutubeChannelReference::normalizeInput('https://www.youtube.com/@MateusGuimar%C3%A3es/videos');
        $expectedHandle = rawurldecode('@MateusGuimar%C3%A3es');

        $this->assertSame($expectedHandle, $reference['youtube_id']);
        $this->assertNull($reference['youtube_channel_id']);
    }

    public function test_it_normalizes_channel_id_from_channel_url(): void
    {
        $channelId = 'UCABCDEFGHIJKLMN_OPQRSTU';
        $reference = YoutubeChannelReference::normalizeInput('https://m.youtube.com/channel/' . $channelId);

        $this->assertSame($channelId, $reference['youtube_id']);
        $this->assertSame($channelId, $reference['youtube_channel_id']);
    }

    public function test_it_builds_canonical_channel_url_for_uc_channel(): void
    {
        $channelId = 'UCABCDEFGHIJKLMN_OPQRSTU';

        $this->assertSame(
            'https://www.youtube.com/channel/' . $channelId,
            YoutubeChannelReference::canonicalUrl($channelId, $channelId)
        );
    }

    public function test_it_resolves_preferred_handle_from_uploader_url_when_uploader_id_is_missing(): void
    {
        $resolvedHandle = YoutubeChannelReference::resolvePreferredHandleFromMetadata([
            'uploader_url' => 'https://www.youtube.com/@MateusGuimar%C3%A3es/videos',
        ]);

        $this->assertSame(rawurldecode('@MateusGuimar%C3%A3es'), $resolvedHandle);
    }

    public function test_it_resolves_preferred_handle_from_channel_url_when_uploader_url_has_no_handle(): void
    {
        $resolvedHandle = YoutubeChannelReference::resolvePreferredHandleFromMetadata([
            'uploader_url' => 'https://www.youtube.com/channel/UCABCDEFGHIJKLMN_OPQRSTU',
            'channel_url' => 'https://m.youtube.com/@fallback-handle/streams',
        ]);

        $this->assertSame('@fallback-handle', $resolvedHandle);
    }

    public function test_it_resolves_channel_id_from_metadata_preferring_channel_id_then_id(): void
    {
        $channelId = 'UCABCDEFGHIJKLMN_OPQRSTU';

        $resolved = YoutubeChannelReference::resolveChannelIdFromMetadata([
            'channel_id' => $channelId,
            'id' => 'UCZZZZZZZZZZZZZZZZZZZZZZ',
        ]);
        $fallback = YoutubeChannelReference::resolveChannelIdFromMetadata([
            'id' => $channelId,
        ]);

        $this->assertSame($channelId, $resolved);
        $this->assertSame($channelId, $fallback);
    }
}
