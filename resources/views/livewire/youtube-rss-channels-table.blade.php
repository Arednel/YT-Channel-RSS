<div class="panel channels-panel" wire:poll.500ms>
    <div class="panel__header">
        <div>
            <h2 class="panel__title">Channels</h2>
            <p class="panel__subtitle">List of channels</p>
        </div>
        <div class="panel__actions">
            <button class="action-button" type="button" wire:click="openModal">Add channel</button>
            <button class="action-button" type="button" wire:click="openDeleteModal">Delete channel</button>
        </div>
    </div>
    <div class="channels-table-scroll">
        <table class="channels-table">
            <thead>
                <tr>
                    <th scope="col"
                        aria-sort="{{ $sortColumn === 'channel' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                        <div class="channels-table__sort-header">
                            <button type="button" class="channels-table__sort-reset" wire:click="resetSort"
                                title="Reset sort to default (ID ascending)" aria-label="Reset table sort">
                                Reset Sorting
                            </button>
                            <button type="button"
                                class="channels-table__sort-button {{ $sortColumn === 'channel' ? 'is-active' : '' }}"
                                wire:click="sortBy('channel')">
                                <span>Channel</span>
                                <span class="channels-table__sort-indicator" aria-hidden="true">
                                    {{ $sortColumn === 'channel' ? strtoupper($sortDirection) : '--' }}
                                </span>
                            </button>
                        </div>
                    </th>
                    <th scope="col"
                        aria-sort="{{ $sortColumn === 'link' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                        <button type="button" class="channels-table__sort-button {{ $sortColumn === 'link' ? 'is-active' : '' }}"
                            wire:click="sortBy('link')">
                            <span>Link</span>
                            <span class="channels-table__sort-indicator" aria-hidden="true">
                                {{ $sortColumn === 'link' ? strtoupper($sortDirection) : '--' }}
                            </span>
                        </button>
                    </th>
                    <th scope="col"
                        aria-sort="{{ $sortColumn === 'rss_link' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                        <button type="button"
                            class="channels-table__sort-button {{ $sortColumn === 'rss_link' ? 'is-active' : '' }}"
                            wire:click="sortBy('rss_link')">
                            <span>RSS Link</span>
                            <span class="channels-table__sort-indicator" aria-hidden="true">
                                {{ $sortColumn === 'rss_link' ? strtoupper($sortDirection) : '--' }}
                            </span>
                        </button>
                    </th>
                    <th scope="col"
                        aria-sort="{{ $sortColumn === 'last_updated' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                        <button type="button"
                            class="channels-table__sort-button {{ $sortColumn === 'last_updated' ? 'is-active' : '' }}"
                            wire:click="sortBy('last_updated')">
                            <span>Last updated</span>
                            <span class="channels-table__sort-indicator" aria-hidden="true">
                                {{ $sortColumn === 'last_updated' ? strtoupper($sortDirection) : '--' }}
                            </span>
                        </button>
                    </th>
                    <th scope="col"
                        aria-sort="{{ $sortColumn === 'status' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                        <button type="button" class="channels-table__sort-button {{ $sortColumn === 'status' ? 'is-active' : '' }}"
                            wire:click="sortBy('status')">
                            <span>Status</span>
                            <span class="channels-table__sort-indicator" aria-hidden="true">
                                {{ $sortColumn === 'status' ? strtoupper($sortDirection) : '--' }}
                            </span>
                        </button>
                    </th>
                </tr>
            </thead>
            <tbody>
                @forelse ($channels as $channel)
                    <tr>
                        <td>
                            <div class="channel-cell">
                                <div class="channel-cell__meta">
                                    <span class="channel-cell__name">{{ $channel->channel_name ?? '-' }}</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <a href="{{ $channel->youtube_url }}" target="_blank" rel="noopener">
                                {{ $channel->youtube_id }}
                            </a>
                        </td>
                        <td>
                            @if ($channel->rss_url)
                                <a href="{{ $channel->rss_url }}" class="channel-rss-link"
                                    data-copy-text="{{ $channel->rss_url }}"
                                    wire:click.prevent="copyRssLink({{ $channel->id }})">
                                    {{ $channel->rss_url }}
                                </a>
                                @if (isset($rssLinkErrors[$channel->id]))
                                    <div class="form-feedback form-feedback--error">{{ $rssLinkErrors[$channel->id] }}</div>
                                @endif
                                @if (isset($rssLinkSuccesses[$channel->id]))
                                    <div class="form-feedback form-feedback--success">{{ $rssLinkSuccesses[$channel->id] }}</div>
                                @endif
                            @else
                                -
                            @endif
                        </td>
                        <td>
                            @if ($channel->updated_at_utc_iso && $channel->updated_at_utc_display)
                                <time class="channel-updated-at" data-local-time
                                    datetime="{{ $channel->updated_at_utc_iso }}">
                                    {{ $channel->updated_at_utc_display }}
                                </time>
                            @else
                                <span class="channel-updated-at">-</span>
                            @endif
                        </td>
                        <td>
                            <span class="channel-status {{ $channel->status_badge_class }}">
                                {{ $channel->status_label }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">No channels found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if (! $isUnlimited && $channels->total() > 0)
        {{ $channels->onEachSide(1)->links('livewire.channel-pagination-links') }}
    @elseif ($isUnlimited)
        <div class="channels-pagination">
            <div class="channels-pagination__summary">
                Showing all {{ $totalChannels }} channels
            </div>
        </div>
    @endif

    @if ($showModal)
        <div class="dialog-backdrop channels-dialog-backdrop" wire:click.self="closeModal"
            wire:keydown.escape.window="closeModal" role="dialog" aria-modal="true">
            <div class="dialog">
                <div class="dialog__header">
                    <h3 class="dialog__title">Add channel</h3>
                    <button class="dialog__close" type="button" wire:click="closeModal">Close</button>
                </div>
                <form wire:submit.prevent="save">
                    <div class="form-field">
                        <label class="form-field__label" for="channel-url">YouTube Channel URL</label>
                        <input id="channel-url" class="form-control" type="text"
                            placeholder="https://youtube.com/@channel or https://youtube.com/channel/UC..."
                            wire:model="channelUrl" autocomplete="off" required>
                        @error('channelUrl')
                            <div class="form-feedback form-feedback--error">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="dialog__actions">
                        <button class="action-button" type="button" wire:click="closeModal">Cancel</button>
                        <button class="action-button" type="submit" wire:loading.attr="disabled"
                            wire:target="save">Save</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($showDeleteModal)
        <div class="dialog-backdrop channels-dialog-backdrop" wire:click.self="closeDeleteModal"
            wire:keydown.escape.window="closeDeleteModal" role="dialog" aria-modal="true">
            <div class="dialog">
                <div class="dialog__header">
                    <h3 class="dialog__title">Delete channel</h3>
                    <button class="dialog__close" type="button" wire:click="closeDeleteModal">Close</button>
                </div>
                <form wire:submit.prevent="{{ $confirmDelete ? 'deleteSelected' : 'promptDelete' }}">
                    <div class="form-field">
                        <label class="form-field__label" for="delete-channel">Select channel</label>
                        <select id="delete-channel" class="form-control" wire:model="deleteChannelId" required>
                            <option value="">Choose a channel...</option>
                            @foreach ($deleteChoices as $channel)
                                <option value="{{ $channel->id }}">
                                    {{ ($channel->channel_name ?? 'Unnamed') . ' - ' . $channel->youtube_id }}
                                </option>
                            @endforeach
                        </select>
                        @error('deleteChannelId')
                            <div class="form-feedback form-feedback--error">{{ $message }}</div>
                        @enderror
                    </div>
                    @if ($confirmDelete)
                        <p class="form-feedback form-feedback--error">This action cannot be undone. Click "Delete" again to confirm.</p>
                    @endif
                    <div class="dialog__actions">
                        <button class="action-button" type="button" wire:click="closeDeleteModal">Cancel</button>
                        <button class="action-button" type="submit" wire:loading.attr="disabled">
                            {{ $confirmDelete ? 'Delete' : 'Delete' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
