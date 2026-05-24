<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Options</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Mono:wght@400;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/shared.css') }}?v={{ filemtime(public_path('css/shared.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/options.css') }}?v={{ filemtime(public_path('css/options.css')) }}">
    @livewireStyles
</head>

<body>
    <div class="app-layout">
        <x-app-sidebar active="options" />

        <main class="app-main">
            <nav class="app-toolbar">
                <div class="page-heading">
                    <h1 class="app-toolbar__title">Options</h1>
                    <div class="page-heading__breadcrumb">
                        <a href="{{ route('index', [], false) }}">Channels</a>
                        <span>/</span>
                        <span>Options</span>
                    </div>
                </div>
                <div class="app-toolbar__actions">
                    <button class="theme-toggle" id="theme-toggle" type="button" title="Toggle Light/Dark Mode">
                        <svg class="theme-toggle__icon--sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
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
                        <svg class="theme-toggle__icon--moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" style="display: none;">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                        </svg>
                    </button>
                </div>
            </nav>

            <section class="app-content-grid">
                <div class="options-layout options-layout--settings">
                    <div class="panel options-nav-panel">
                        <ul class="options-nav">
                            <li class="options-nav__item">
                                <a href="{{ route('options', [], false) }}" class="options-nav__link active">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <line x1="8" y1="6" x2="21" y2="6" />
                                        <line x1="8" y1="12" x2="21" y2="12" />
                                        <line x1="8" y1="18" x2="21" y2="18" />
                                        <line x1="3" y1="6" x2="3.01" y2="6" />
                                        <line x1="3" y1="12" x2="3.01" y2="12" />
                                        <line x1="3" y1="18" x2="3.01" y2="18" />
                                    </svg>
                                    Pagination
                                </a>
                            </li>
                        </ul>
                    </div>

                    <div class="panel options-panel">
                        <livewire:channel-pagination-settings />
                    </div>
                </div>
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

    <x-site-footer />

    <script src="{{ asset('js/app-ui.js') }}?v={{ filemtime(public_path('js/app-ui.js')) }}"></script>
    @livewireScripts
</body>

</html>
