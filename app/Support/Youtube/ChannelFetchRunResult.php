<?php

namespace App\Support\Youtube;

class ChannelFetchRunResult
{
    public function __construct(
        public string $outputDirectory,
        public string $channelJsonPath,
        public string $videosJsonPath
    ) {}
}
