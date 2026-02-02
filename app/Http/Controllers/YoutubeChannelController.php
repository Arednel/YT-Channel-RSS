<?php

namespace App\Http\Controllers;

use App\Models\YoutubeChannel;
use Illuminate\Contracts\View\View;

class YoutubeChannelController extends Controller
{
    public function index(): View
    {
        $channels = YoutubeChannel::query()
            ->orderBy('id')
            ->get();

        return view('Index', [
            'channels' => $channels,
        ]);
    }
}
