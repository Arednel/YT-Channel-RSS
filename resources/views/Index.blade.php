<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YouTube RSS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Mono:wght@400;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/templatemo-glass-admin-style.css') }}">
    @livewireStyles
</head>

<body>
    @livewire('youtube-rss-dashboard')

    <!-- Footer -->
    <footer class="site-footer">
        <p>Copyright © 2026</p>
    </footer>
    <script src="{{ asset('js/templatemo-glass-admin-script.js') }}"></script>
    <script src="{{ asset('js/rss-copy-to-clipboard.js') }}"></script>
    <script src="{{ asset('js/localize-datetime.js') }}"></script>
    @livewireScripts
</body>

</html>
