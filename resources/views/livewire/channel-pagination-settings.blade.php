<form wire:submit.prevent="save">
    <div class="settings-panel">
        <h3 class="settings-panel__title">Channel table pagination</h3>
        <div class="settings-field settings-field--stacked">
            <div class="settings-field__label">
                <label class="settings-field__label-title" for="channels-per-page">Channels per page</label>
                <span class="settings-field__label-description">Choose how many channels the list renders at once.</span>
            </div>
            <select id="channels-per-page" class="settings-control settings-control--wide" wire:model.live="mode">
                @foreach ($fixedOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }} channels per page</option>
                @endforeach
                <option value="custom">Custom value</option>
                <option value="{{ $unlimitedValue }}">Unlimited</option>
            </select>
        </div>

        @if ($mode === 'custom')
            <div class="settings-field settings-field--stacked">
                <div class="settings-field__label">
                    <label class="settings-field__label-title" for="channels-custom-per-page">Custom channels per page</label>
                    <span class="settings-field__label-description">Use any positive whole number.</span>
                </div>
                <input id="channels-custom-per-page" class="form-control settings-control--number" type="number" min="1"
                    step="1" wire:model="customValue">
                @error('customValue')
                    <div class="form-feedback form-feedback--error">{{ $message }}</div>
                @enderror
            </div>
        @endif

        @error('mode')
            <div class="form-feedback form-feedback--error">{{ $message }}</div>
        @enderror

        @if ($saved)
            <div class="form-feedback form-feedback--success" wire:dirty.remove wire:target="mode,customValue">Channel pagination setting saved.</div>
        @endif

        <div class="settings-actions">
            <button class="action-button" type="submit">Save pagination</button>
        </div>
    </div>
</form>
