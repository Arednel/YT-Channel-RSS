<?php

namespace App\Livewire;

use App\Models\Option;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ChannelPaginationSettings extends Component
{
    public string $mode = '100';

    public string $customValue = '';

    public bool $saved = false;

    public function mount(): void
    {
        $this->fillFromSetting();
    }

    public function render(): View
    {
        return view('livewire.channel-pagination-settings', [
            'fixedOptions' => Option::fixedChannelsPerPageOptions(),
            'unlimitedValue' => Option::CHANNELS_PER_PAGE_UNLIMITED,
        ]);
    }

    public function save(): void
    {
        $this->validate([
            'mode' => [
                'required',
                Rule::in([
                    ...array_map('strval', Option::FIXED_CHANNELS_PER_PAGE_OPTIONS),
                    'custom',
                    Option::CHANNELS_PER_PAGE_UNLIMITED,
                ]),
            ],
            'customValue' => ['exclude_unless:mode,custom', 'required', 'integer', 'min:1'],
        ]);

        Option::setChannelsPerPage($this->resolvedValue());
        $this->fillFromSetting();
        $this->saved = true;
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['mode', 'customValue'], true)) {
            return;
        }

        $this->saved = false;
        $this->resetValidation();
    }

    private function fillFromSetting(): void
    {
        $value = Option::channelsPerPage();

        if ($value === Option::CHANNELS_PER_PAGE_UNLIMITED) {
            $this->mode = Option::CHANNELS_PER_PAGE_UNLIMITED;
            $this->customValue = '';

            return;
        }

        if (in_array($value, Option::FIXED_CHANNELS_PER_PAGE_OPTIONS, true)) {
            $this->mode = (string) $value;
            $this->customValue = '';

            return;
        }

        $this->mode = 'custom';
        $this->customValue = (string) $value;
    }

    private function resolvedValue(): int|string
    {
        if ($this->mode === Option::CHANNELS_PER_PAGE_UNLIMITED) {
            return Option::CHANNELS_PER_PAGE_UNLIMITED;
        }

        if ($this->mode === 'custom') {
            return (int) $this->customValue;
        }

        return (int) $this->mode;
    }
}
