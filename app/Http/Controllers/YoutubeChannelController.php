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
        return view('Index');
    }

    public function feed(YoutubeChannel $youtubeChannel): BinaryFileResponse
    {
        $disk = Storage::disk('public');
        $relativePath = 'feeds/' . $youtubeChannel->youtube_id . '.xml';

        if (! $disk->exists($relativePath)) {
            abort(404, 'Feed XML not found. Sync the channel first.');
        }

        $response = response()->file($disk->path($relativePath), [
            'Content-Type' => 'application/atom+xml; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Accel-Expires' => '0',
        ]);

        // Avoid conditional 304 responses so RSS readers always parse the latest XML.
        $response->headers->remove('ETag');
        $response->headers->remove('Last-Modified');

        return $response;
    }
}
