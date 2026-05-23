<?php

namespace App\Livewire;

use App\Actions\Youtube\DispatchSyncYoutubeChannelJobAction;
use App\Jobs\DeleteYoutubeChannelJob;
use App\Models\Option;
use App\Models\YoutubeChannel;
use App\Support\Youtube\YoutubeChannelReference;
use App\Support\YoutubeBatchManager;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class YoutubeRssChannelsTable extends Component
{
    use WithPagination;

    /** @var list<string> */
    private const SORTABLE_COLUMNS = ['id', 'channel', 'link', 'rss_link', 'last_updated', 'status'];

    private const CHANNEL_URL_MESSAGE = 'Use a YouTube channel link like https://youtube.com/@channel or https://youtube.com/channel/UC...';

    private YoutubeBatchManager $youtubeBatchManager;

    #[Modelable]
    public string $search = '';

    #[Url(as: 'sort', except: 'id')]
    public string $sortColumn = 'id';

    #[Url(as: 'dir', except: 'asc')]
    public string $sortDirection = 'asc';

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
            'channelUrl' => [
                'required',
                'string',
                'max:255',
                'url:https',
                'starts_with:https://youtube.com/,https://www.youtube.com/,https://m.youtube.com/',
                // starts_with validates allowed hosts; regex validates supported channel path shapes.
                'regex:/^https:\/\/[^\/]+\/(?:@[^\/?#\s]+|channel\/UC[A-Za-z0-9_-]{22})(?:[\/?#].*)?$/u',
            ],
            'deleteChannelId' => ['nullable', 'integer', 'exists:youtube_channels,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'channelUrl.url' => self::CHANNEL_URL_MESSAGE,
            'channelUrl.starts_with' => self::CHANNEL_URL_MESSAGE,
            'channelUrl.regex' => self::CHANNEL_URL_MESSAGE,
        ];
    }

    public function boot(YoutubeBatchManager $youtubeBatchManager): void
    {
        $this->youtubeBatchManager = $youtubeBatchManager;
    }

    public function render(): View
    {
        $this->sortColumn = $this->normalizeSortColumn($this->sortColumn);
        $this->sortDirection = $this->normalizeSortDirection($this->sortDirection);
        $search = $this->normalizeSearch($this->search);

        // Build query with server-side filtering and ordering.
        $query = YoutubeChannel::query();
        $this->applySearch($query, $search);
        $this->applySorting($query);

        $perPage = Option::channelsPerPage();
        $isUnlimited = $perPage === Option::CHANNELS_PER_PAGE_UNLIMITED;
        $channels = $isUnlimited
            ? $query->get()
            : $query->paginate((int) $perPage);
        $this->addUtcUpdatedAtViewFields(
            $channels instanceof LengthAwarePaginator ? $channels->getCollection() : $channels
        );

        $channelsById = YoutubeChannel::query()
            ->select(['id', 'status'])
            ->get()
            ->keyBy('id');
        $deleteChoices = YoutubeChannel::query()
            ->orderByRaw("CASE WHEN channel_name IS NULL OR channel_name = '' THEN 1 ELSE 0 END")
            ->orderBy('channel_name')
            ->orderBy('youtube_id')
            ->get(['id', 'channel_name', 'youtube_id']);

        // Build quick lookup/time references used by transient UI-message cleanup.
        $nowTs = now()->getTimestamp();

        // Drop stale error hints when the channel is gone or is back to idle.
        foreach (array_keys($this->rssLinkErrors) as $channelId) {
            $channel = $channelsById->get($channelId);
            if (! $channel instanceof YoutubeChannel || $channel->isIdle()) {
                unset($this->rssLinkErrors[$channelId]);
            }
        }

        // Expire short-lived success hints after their TTL.
        foreach (array_keys($this->rssLinkSuccesses) as $channelId) {
            $expiresAt = $this->rssLinkSuccessExpiresAt[$channelId] ?? 0;
            if (! is_int($expiresAt) || $expiresAt <= $nowTs) {
                unset($this->rssLinkSuccesses[$channelId], $this->rssLinkSuccessExpiresAt[$channelId]);
            }
        }

        // Render the table with the latest channel snapshot.
        return view('livewire.youtube-rss-channels-table', [
            'channels' => $channels,
            'deleteChoices' => $deleteChoices,
            'isUnlimited' => $isUnlimited,
            'totalChannels' => $channels instanceof LengthAwarePaginator ? $channels->total() : $channels->count(),
        ]);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        $column = $this->normalizeSortColumn($column);

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
            $this->resetPage();

            return;
        }

        $this->sortColumn = $column;
        $this->sortDirection = 'asc';
        $this->resetPage();
    }

    public function resetSort(): void
    {
        $this->sortColumn = 'id';
        $this->sortDirection = 'asc';
        $this->resetPage();
    }

    public function openModal(): void
    {
        $this->resetValidation();
        $this->showDeleteModal = false;
        $this->confirmDelete = false;
        $this->deleteChannelId = null;
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
        $this->showModal = false;
        $this->channelUrl = '';
        $this->deleteChannelId = null;
        $this->confirmDelete = false;
        $this->showDeleteModal = true;
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->confirmDelete = false;
    }

    public function save(DispatchSyncYoutubeChannelJobAction $dispatchSyncYoutubeChannelJob): void
    {
        // Validate the add-channel input field only.
        $this->validateOnly('channelUrl');

        $youtubeReference = YoutubeChannelReference::normalizeInput($this->channelUrl);
        if ($youtubeReference === null) {
            $this->addError('channelUrl', self::CHANNEL_URL_MESSAGE);

            return;
        }

        $youtubeId = $youtubeReference['youtube_id'];
        $youtubeChannelId = $youtubeReference['youtube_channel_id'];
        if (YoutubeChannel::query()->matchingReference($youtubeId, $youtubeChannelId)->exists()) {
            $this->addError('channelUrl', 'This channel is already added.');

            return;
        }

        $channel = YoutubeChannel::query()->create([
            'youtube_id' => $youtubeId,
            'youtube_channel_id' => $youtubeChannelId,
        ]);
        $dispatchSyncYoutubeChannelJob->handle($channel);

        $this->showModal = false;
        $this->channelUrl = '';
        $this->resetPage();
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
            $this->youtubeBatchManager->cancelActiveVideoBatch($channel);
            $channel->markDeleting();
        }

        DeleteYoutubeChannelJob::dispatch($this->deleteChannelId);

        $this->showDeleteModal = false;
        $this->confirmDelete = false;
        $this->deleteChannelId = null;
        $this->resetPage();
    }

    public function copyRssLink(int $channelId): void
    {
        $channel = YoutubeChannel::query()->find($channelId);
        if ($channel === null) {
            return;
        }

        if (! $channel->canCopyRssLink()) {
            $this->rssLinkErrors[$channel->id] = $channel->isBusy()
                ? 'RSS is being created/updated right now. Please wait until status is idle.'
                : 'RSS link can be copied only when status is idle.';
            unset($this->rssLinkSuccesses[$channel->id], $this->rssLinkSuccessExpiresAt[$channel->id]);

            return;
        }

        unset($this->rssLinkErrors[$channel->id]);
        $this->rssLinkSuccesses[$channel->id] = 'Copied to clipboard.';
        $this->rssLinkSuccessExpiresAt[$channel->id] = now()->addSeconds(3)->getTimestamp();

        $this->dispatch('rss-copy-to-clipboard', text: $channel->rss_url);
    }

    /**
     * @param  EloquentCollection<int, YoutubeChannel>  $channels
     * @return EloquentCollection<int, YoutubeChannel>
     */
    private function addUtcUpdatedAtViewFields(EloquentCollection $channels): EloquentCollection
    {
        $channels->each(function (YoutubeChannel $channel): void {
            $updatedAtUtc = $channel->updated_at?->copy()->utc();

            $channel->setAttribute(
                'updated_at_utc_iso',
                $updatedAtUtc?->format('Y-m-d\\TH:i:s\\Z')
            );

            $channel->setAttribute(
                'updated_at_utc_display',
                $updatedAtUtc?->format('Y-m-d H:i')
            );
        });

        return $channels;
    }

    private function normalizeSearch(string $search): string
    {
        return trim(preg_replace('/\s+/', ' ', $search) ?? '');
    }

    private function applySorting(Builder $query): void
    {
        $direction = $this->normalizeSortDirection($this->sortDirection);

        // Each virtual column maps to one or more concrete DB columns.
        switch ($this->normalizeSortColumn($this->sortColumn)) {
            case 'channel':
                $query
                    ->orderByRaw("CASE WHEN channel_name IS NULL OR channel_name = '' THEN 1 ELSE 0 END")
                    ->orderBy('channel_name', $direction)
                    ->orderBy('id');
                break;

            case 'link':
            case 'rss_link':
                $query
                    ->orderBy('youtube_id', $direction)
                    ->orderBy('id');
                break;

            case 'last_updated':
                $query
                    ->orderByRaw('CASE WHEN updated_at IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('updated_at', $direction)
                    ->orderBy('id');
                break;

            case 'status':
                $query
                    ->orderBy('status', $direction)
                    ->orderBy('id');
                break;

            default:
                $query->orderBy('id', $direction);
        }
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';
        // Allow matching pasted YouTube URLs and local feed URLs against stored ids.
        $youtubeNeedle = preg_replace('#^https?://(www\.)?youtube\.com/#i', '', $search) ?? $search;
        $feedNeedle = preg_replace(
            '#^'.preg_quote(rtrim((string) config('app.url'), '/').'/feeds/', '#').'(.+?)(?:\.xml)?$#i',
            '$1',
            $search
        ) ?? $search;

        $query->where(function (Builder $searchQuery) use ($like, $youtubeNeedle, $feedNeedle): void {
            $searchQuery
                ->where('channel_name', 'like', $like)
                ->orWhere('youtube_id', 'like', $like)
                ->orWhere('youtube_channel_id', 'like', $like)
                ->orWhere('status', 'like', $like)
                ->orWhere('updated_at', 'like', $like);

            if ($youtubeNeedle !== '') {
                $searchQuery->orWhere('youtube_id', 'like', '%'.$youtubeNeedle.'%')
                    ->orWhere('youtube_channel_id', 'like', '%'.$youtubeNeedle.'%');
            }

            if ($feedNeedle !== '') {
                $searchQuery->orWhere('youtube_id', 'like', '%'.$feedNeedle.'%')
                    ->orWhere('youtube_channel_id', 'like', '%'.$feedNeedle.'%');
            }
        });
    }

    private function normalizeSortColumn(string $column): string
    {
        return in_array($column, self::SORTABLE_COLUMNS, true) ? $column : 'id';
    }

    private function normalizeSortDirection(string $direction): string
    {
        return $direction === 'desc' ? 'desc' : 'asc';
    }
}
