<div class="app-layout">
    <x-app-sidebar active="index" />

    <main class="app-main">
        <nav class="app-toolbar">
            <h1 class="app-toolbar__title">Channels</h1>
            <div class="app-toolbar__actions">
                <div class="channel-search">
                    <svg class="channel-search__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="m21 21-4.34-4.34" />
                        <circle cx="11" cy="11" r="8" />
                    </svg>
                    <input type="text" class="channel-search__input" placeholder="Search..."
                        wire:model.live.debounce.300ms="search" wire:keydown.escape="$set('search', '')"
                        autocomplete="off" aria-label="Search channels table">
                </div>
                <button class="theme-toggle" id="theme-toggle" type="button" title="Toggle Light/Dark Mode">
                    <svg class="theme-toggle__icon--sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="4" />
                        <path d="M12 2v2" />
                        <path d="M12 20v2" />
                        <path d="M4.93 4.93l1.41 1.41" />
                        <path d="M17.66 17.66l1.41 1.41" />
                        <path d="M2 12h2" />
                        <path d="M20 12h2" />
                        <path d="M6.34 17.66l-1.41 1.41" />
                        <path d="M19.07 4.93l-1.41 1.41" />
                    </svg>
                    <svg class="theme-toggle__icon--moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        style="display: none;">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                    </svg>
                </button>
            </div>
        </nav>

        <section class="app-content-grid">
            <livewire:youtube-rss-channels-table wire:model.live.debounce.300ms="search" />
        </section>
    </main>

    <button class="app-sidebar-toggle" type="button" aria-label="Open navigation" aria-controls="app-sidebar"
        aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="3" y1="12" x2="21" y2="12" />
            <line x1="3" y1="6" x2="21" y2="6" />
            <line x1="3" y1="18" x2="21" y2="18" />
        </svg>
    </button>
</div>
