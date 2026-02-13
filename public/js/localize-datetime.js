/* Localize UTC datetime strings to the user's local timezone. */

(function () {
    'use strict';

    const selector = 'time[data-local-time][datetime]';

    function pad2(value) {
        return String(value).padStart(2, '0');
    }

    function formatLocalDateTime(date) {
        return (
            [
                date.getFullYear(),
                pad2(date.getMonth() + 1),
                pad2(date.getDate()),
            ].join('-') +
            ' ' +
            [pad2(date.getHours()), pad2(date.getMinutes())].join(':')
        );
    }

    function localizeElement(element) {
        const isoValue = element.getAttribute('datetime');
        if (typeof isoValue !== 'string' || isoValue === '') {
            return;
        }

        const date = new Date(isoValue);
        if (Number.isNaN(date.getTime())) {
            return;
        }

        const localizedValue = formatLocalDateTime(date);
        if (element.textContent !== localizedValue) {
            element.textContent = localizedValue;
        }
    }

    function localizeInNode(node) {
        if (!(node instanceof Element)) {
            return;
        }

        if (node.matches(selector)) {
            localizeElement(node);
        }

        node.querySelectorAll(selector).forEach(localizeElement);
    }

    function localizeAll() {
        document.querySelectorAll(selector).forEach(localizeElement);
    }

    function observeMutations() {
        const observer = new MutationObserver(function (mutations) {
            for (const mutation of mutations) {
                if (mutation.type === 'attributes') {
                    localizeInNode(mutation.target);
                    continue;
                }

                if (mutation.type === 'characterData') {
                    localizeInNode(mutation.target.parentElement);
                    continue;
                }

                mutation.addedNodes.forEach(localizeInNode);
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['datetime'],
            characterData: true,
        });
    }

    function boot() {
        localizeAll();
        observeMutations();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
        return;
    }

    boot();
})();
