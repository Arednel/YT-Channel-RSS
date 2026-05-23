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
    <link rel="stylesheet"
        href="{{ asset('css/templatemo-glass-admin-style.css') }}?v={{ filemtime(public_path('css/templatemo-glass-admin-style.css')) }}">
    @livewireStyles
</head>

<body>
    <div class="app-shell">
        <x-app-sidebar active="options" />

        <main class="main-content">
            <nav class="navbar">
                <div class="page-header">
                    <h1 class="page-title">Options</h1>
                    <div class="page-breadcrumb">
                        <a href="{{ route('index', [], false) }}">Channels</a>
                        <span>/</span>
                        <span>Options</span>
                    </div>
                </div>
                <div class="navbar-right">
                    <button class="nav-btn" id="theme-toggle" type="button" title="Toggle Light/Dark Mode">
                        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
                        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            style="display: none;">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                        </svg>
                    </button>
                </div>
            </nav>

            <section class="content-grid">
                <div class="settings-grid options-settings-grid">
                    <div class="glass-card settings-nav-card">
                        <ul class="settings-nav">
                            <li class="settings-nav-item">
                                <a href="{{ route('options', [], false) }}" class="settings-nav-link active">
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

                    <div class="glass-card options-settings-card">
                        <livewire:channel-pagination-settings />
                    </div>
                </div>
            </section>
        </main>

        <button class="mobile-menu-toggle" type="button" aria-label="Open navigation" aria-controls="sidebar"
            aria-expanded="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="3" y1="12" x2="21" y2="12" />
                <line x1="3" y1="6" x2="21" y2="6" />
                <line x1="3" y1="18" x2="21" y2="18" />
            </svg>
        </button>
    </div>

    <x-site-footer />

    <script
        src="{{ asset('js/templatemo-glass-admin-script.js') }}?v={{ filemtime(public_path('js/templatemo-glass-admin-script.js')) }}"></script>
    @livewireScripts
</body>

</html>
