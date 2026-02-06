<div class="glass-card table-card" wire:poll.250ms>
    <div class="card-header">
        <div>
            <h2 class="card-title">Channels</h2>
            <p class="card-subtitle">List of channels</p>
        </div>
        <div class="card-actions">
            <button class="card-btn" type="button" wire:click="openModal">Add channel</button>
            <button class="card-btn" type="button" wire:click="openDeleteModal">Delete channel</button>
        </div>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Channel</th>
                    <th>Link</th>
                    <th>RSS Link</th>
                    <th>Last updated</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($channels as $channel)
                    <tr>
                        <td>
                            <div class="table-user">
                                <div class="table-user-info">
                                    <span class="table-user-name">{{ $channel->channel_name ?? '-' }}</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <a href="{{ 'https://www.youtube.com/' . $channel->youtube_id }}" target="_blank"
                                rel="noopener">
                                {{ $channel->youtube_id }}
                            </a>
                        </td>
                        <td>
                            @if ($channel->rss_url)
                                <a href="{{ $channel->rss_url }}" class="copy-rss-link"
                                    data-copy-text="{{ $channel->rss_url }}">
                                    {{ $channel->rss_url }}
                                </a>
                            @else
                                -
                            @endif
                        </td>
                        <td>
                            <span class="table-amount">{{ $channel->updated_at?->format('Y-m-d H:i') ?? '-' }}</span>
                        </td>
                        <td>
                            <span class="status-badge {{ $channel->status_badge_class }}">
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

    @if ($showModal)
        <div class="modal-backdrop" wire:click.self="closeModal" wire:keydown.escape.window="closeModal" role="dialog"
            aria-modal="true">
            <div class="modal-window">
                <div class="modal-header">
                    <h3 class="modal-title">Add channel</h3>
                    <button class="modal-close" type="button" wire:click="closeModal">Close</button>
                </div>
                <form wire:submit.prevent="save">
                    <div class="form-group">
                        <label class="form-label" for="channel-url">Channel URL</label>
                        <input id="channel-url" class="form-input" type="url"
                            placeholder="https://www.youtube.com/@channel" wire:model="channelUrl" autocomplete="off"
                            required>
                        @error('channelUrl')
                            <div class="form-error">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="modal-actions">
                        <button class="card-btn" type="button" wire:click="closeModal">Cancel</button>
                        <button class="card-btn" type="submit" wire:loading.attr="disabled"
                            wire:target="save">Save</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($showDeleteModal)
        <div class="modal-backdrop" wire:click.self="closeDeleteModal" wire:keydown.escape.window="closeDeleteModal"
            role="dialog" aria-modal="true">
            <div class="modal-window">
                <div class="modal-header">
                    <h3 class="modal-title">Delete channel</h3>
                    <button class="modal-close" type="button" wire:click="closeDeleteModal">Close</button>
                </div>
                <form wire:submit.prevent="{{ $confirmDelete ? 'deleteSelected' : 'promptDelete' }}">
                    <div class="form-group">
                        <label class="form-label" for="delete-channel">Select channel</label>
                        <select id="delete-channel" class="form-input" wire:model="deleteChannelId" required>
                            <option value="">Choose a channel...</option>
                            @foreach ($channels as $channel)
                                <option value="{{ $channel->id }}">
                                    {{ ($channel->channel_name ?? 'Unnamed') . ' - ' . $channel->youtube_id }}
                                </option>
                            @endforeach
                        </select>
                        @error('deleteChannelId')
                            <div class="form-error">{{ $message }}</div>
                        @enderror
                    </div>
                    @if ($confirmDelete)
                        <p class="form-error">This action cannot be undone. Click "Delete" again to confirm.</p>
                    @endif
                    <div class="modal-actions">
                        <button class="card-btn" type="button" wire:click="closeDeleteModal">Cancel</button>
                        <button class="card-btn" type="submit" wire:loading.attr="disabled">
                            {{ $confirmDelete ? 'Delete' : 'Delete' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
