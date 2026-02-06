<?php

namespace App\Livewire;

use App\Enums\YoutubeChannelStatus;
use App\Jobs\DeleteYoutubeChannelJob;
use App\Jobs\SyncYoutubeChannelJob;
use App\Models\YoutubeChannel;
use App\Support\YoutubeBatchManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class YoutubeRssChannelsTable extends Component
{
    public string $channelUrl = '';
    public ?int $deleteChannelId = null;
    public bool $showModal = false;
    public bool $showDeleteModal = false;
    public bool $confirmDelete = false;

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'channelUrl' => ['required', 'url', 'max:255'],
            'deleteChannelId' => ['nullable', 'integer', 'exists:youtube_channels,id'],
        ];
    }

    public function render(): View
    {
        $channels = YoutubeChannel::query()
            ->orderBy('id')
            ->get();

        return view('livewire.youtube-rss-channels-table', [
            'channels' => $channels,
        ]);
    }

    public function openModal(): void
    {
        $this->resetValidation();
        $this->channelUrl = '';
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    public function openDeleteModal(): void
    {
        $this->resetValidation();
        $this->deleteChannelId = null;
        $this->confirmDelete = false;
        $this->showDeleteModal = true;
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->confirmDelete = false;
    }

    public function save(): void
    {
        $this->validateOnly('channelUrl');

        $youtubeId = $this->extractYoutubeId($this->channelUrl);
        if ($youtubeId === null) {
            $this->addError('channelUrl', 'Could not find a YouTube @handle in the URL.');
            return;
        }

        if (YoutubeChannel::query()->where('youtube_id', $youtubeId)->exists()) {
            $this->addError('channelUrl', 'This channel is already added.');
            return;
        }

        $channel = YoutubeChannel::query()->create([
            'youtube_id' => $youtubeId,
            'status' => YoutubeChannelStatus::Queued,
        ]);

        SyncYoutubeChannelJob::dispatch($channel->id);

        $this->showModal = false;
        $this->channelUrl = '';
    }

    public function promptDelete(): void
    {
        $this->validateOnly('deleteChannelId');
        $this->confirmDelete = true;
    }

    public function deleteSelected(): void
    {
        $this->validateOnly('deleteChannelId');

        if ($this->deleteChannelId === null) {
            return;
        }

        $channel = YoutubeChannel::query()->find($this->deleteChannelId);
        if ($channel !== null) {
            YoutubeBatchManager::cancelActiveVideoBatch($channel);
            $channel->update(['status' => YoutubeChannelStatus::Deleting]);
        }

        DeleteYoutubeChannelJob::dispatch($this->deleteChannelId);

        $this->showDeleteModal = false;
        $this->confirmDelete = false;
        $this->deleteChannelId = null;
    }

    private function extractYoutubeId(string $url): ?string
    {
        if (preg_match('/@[^\/\?\#]+/i', $url, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }
}
