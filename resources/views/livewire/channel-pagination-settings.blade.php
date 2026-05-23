<form wire:submit.prevent="save">
    <div class="settings-section">
        <h3 class="settings-section-title">Channel table pagination</h3>
        <div class="settings-row settings-row--stacked">
            <div class="settings-label">
                <label class="settings-label-title" for="channels-per-page">Channels per page</label>
                <span class="settings-label-desc">Choose how many channels the list renders at once.</span>
            </div>
            <select id="channels-per-page" class="settings-select settings-select--wide" wire:model.live="mode">
                @foreach ($fixedOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }} channels per page</option>
                @endforeach
                <option value="custom">Custom value</option>
                <option value="{{ $unlimitedValue }}">Unlimited</option>
            </select>
        </div>

        @if ($mode === 'custom')
            <div class="settings-row settings-row--stacked">
                <div class="settings-label">
                    <label class="settings-label-title" for="channels-custom-per-page">Custom channels per page</label>
                    <span class="settings-label-desc">Use any positive whole number.</span>
                </div>
                <input id="channels-custom-per-page" class="form-input settings-number-input" type="number" min="1"
                    step="1" wire:model="customValue">
                @error('customValue')
                    <div class="form-error">{{ $message }}</div>
                @enderror
            </div>
        @endif

        @error('mode')
            <div class="form-error">{{ $message }}</div>
        @enderror

        @if ($saved)
            <div class="form-success" wire:dirty.remove wire:target="mode,customValue">Channel pagination setting saved.</div>
        @endif

        <div class="btn-group">
            <button class="card-btn" type="submit">Save pagination</button>
        </div>
    </div>
</form>
