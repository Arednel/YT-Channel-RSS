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
    /** @var array<int, string> */
    public array $rssLinkErrors = [];
    /** @var array<int, string> */
    public array $rssLinkSuccesses = [];
    /** @var array<int, int> */
    public array $rssLinkSuccessExpiresAt = [];

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
        $statusById = $channels->pluck('status_label', 'id');
        $nowTs = now()->getTimestamp();

        foreach (array_keys($this->rssLinkErrors) as $channelId) {
            $statusLabel = $statusById->get($channelId);
            if (! is_string($statusLabel) || $statusLabel === YoutubeChannelStatus::Idle->value) {
                unset($this->rssLinkErrors[$channelId]);
            }
        }

        foreach (array_keys($this->rssLinkSuccesses) as $channelId) {
            $expiresAt = $this->rssLinkSuccessExpiresAt[$channelId] ?? 0;
            if (! is_int($expiresAt) || $expiresAt <= $nowTs) {
                unset($this->rssLinkSuccesses[$channelId], $this->rssLinkSuccessExpiresAt[$channelId]);
            }
        }

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

    public function copyRssLink(int $channelId): void
    {
        $channel = YoutubeChannel::query()->find($channelId);
        if ($channel === null) {
            return;
        }

        $status = $channel->status;
        if ($status !== YoutubeChannelStatus::Idle) {
            $statusLabel = $channel->status_label;
            $this->rssLinkErrors[$channel->id] = $this->isFetchingStatus($statusLabel)
                ? 'RSS is being fetched now. Please wait until status is idle.'
                : 'RSS link can be copied only when status is idle.';
            unset($this->rssLinkSuccesses[$channel->id], $this->rssLinkSuccessExpiresAt[$channel->id]);

            return;
        }

        unset($this->rssLinkErrors[$channel->id]);
        $this->rssLinkSuccesses[$channel->id] = 'Copied to clipboard.';
        $this->rssLinkSuccessExpiresAt[$channel->id] = now()->addSeconds(3)->getTimestamp();

        $this->dispatch('rss-copy-to-clipboard', text: $channel->rss_url);
    }

    private function extractYoutubeId(string $url): ?string
    {
        if (preg_match('/@[^\/\?\#]+/i', $url, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    private function isFetchingStatus(string $statusLabel): bool
    {
        return in_array($statusLabel, [
            YoutubeChannelStatus::Syncing->value,
            YoutubeChannelStatus::FetchingVideoList->value,
            YoutubeChannelStatus::FetchingVideos->value,
            YoutubeChannelStatus::BuildingFeed->value,
            YoutubeChannelStatus::Queued->value,
        ], true);
    }
}
