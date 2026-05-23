<nav class="channel-pagination" role="navigation" aria-label="Channel pagination">
    <div class="channel-pagination__summary">
        Showing {{ $paginator->firstItem() }}-{{ $paginator->lastItem() }} of {{ $paginator->total() }} channels
    </div>

    @if ($paginator->hasPages())
        <div class="channel-pagination__controls">
            @if ($paginator->onFirstPage())
                <button type="button" disabled>Previous</button>
            @else
                <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')"
                    wire:loading.attr="disabled">Previous</button>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="channel-pagination__ellipsis">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page === $paginator->currentPage())
                            <span class="channel-pagination__page is-active" aria-current="page">{{ $page }}</span>
                        @else
                            <button type="button" wire:key="channel-page-{{ $page }}"
                                wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                                wire:loading.attr="disabled" aria-label="Go to page {{ $page }}">
                                {{ $page }}
                            </button>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')"
                    wire:loading.attr="disabled">Next</button>
            @else
                <button type="button" disabled>Next</button>
            @endif
        </div>
    @endif
</nav>
