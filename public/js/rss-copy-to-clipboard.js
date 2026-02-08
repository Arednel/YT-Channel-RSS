/* RSS link clipboard handler for Livewire event dispatches. */

function fallbackCopy(text) {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.top = '0';
    area.style.opacity = '0';
    area.style.pointerEvents = 'none';
    document.body.appendChild(area);
    area.select();
    document.execCommand('copy');
    document.body.removeChild(area);
}

window.addEventListener('rss-copy-to-clipboard', function (event) {
    const text = event && event.detail ? event.detail.text : null;
    if (typeof text !== 'string' || text === '') {
        return;
    }

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(text).catch(function () {
            fallbackCopy(text);
        });
        return;
    }

    fallbackCopy(text);
});