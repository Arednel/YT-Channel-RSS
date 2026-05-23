<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class YoutubeRssChannels extends Component
{
    #[Url(as: 'search', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->search = $this->normalizeSearch($this->search);
    }

    public function updatedSearch(string $value): void
    {
        $this->search = $this->normalizeSearch($value);
    }

    public function render(): View
    {
        return view('livewire.youtube-rss-channels');
    }

    private function normalizeSearch(string $search): string
    {
        return trim(preg_replace('/\s+/', ' ', $search) ?? '');
    }
}
