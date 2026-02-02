<?php

namespace App\Http\Controllers;

use App\Models\YoutubeChannel;
use Illuminate\Contracts\View\View;

class YoutubeChannelController extends Controller
{
    public function index(): View
    {
        $rssBaseUrl = rtrim(config('app.url'), '/') . '/feeds/';

        $channels = YoutubeChannel::query()
            ->orderBy('id')
            ->get()
            ->each(function (YoutubeChannel $channel) use ($rssBaseUrl): void {
                $channel->rss_url = $rssBaseUrl . $channel->youtube_id;
            });

        return view('Index', [
            'channels' => $channels,
        ]);
    }
}
