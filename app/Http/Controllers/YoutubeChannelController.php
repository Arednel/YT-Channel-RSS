<?php

namespace App\Http\Controllers;

use App\Models\YoutubeChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    public function feed(YoutubeChannel $youtubeChannel): BinaryFileResponse
    {
        $disk = Storage::disk('public');
        $relativePath = 'feeds/' . $youtubeChannel->youtube_id . '.xml';

        if (! $disk->exists($relativePath)) {
            abort(404, 'Feed XML not found. Sync the channel first.');
        }

        return response()->file($disk->path($relativePath));
    }
}
