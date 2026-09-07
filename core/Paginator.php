<?php

// Result of QueryBuilder::paginate() / simplePaginate(). Iterates the page's
// items, knows the totals, builds page URLs from the current request and
// renders Bootstrap 5 pagination with links(). In templates:
//   {foreach $posts as $post}...{/foreach}   {$posts->links()|raw}

class Paginator implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable
{
    protected Collection $items;
    protected ?int $total;
    protected int $perPage;
    protected int $currentPage;
    protected bool $hasMore;
    protected string $path;
    protected string $pageName;
    protected array $query;

    public function __construct(iterable $items, ?int $total, int $perPage, int $currentPage, array $options = [])
    {
        $this->items = Collection::make($items);
        $this->total = $total === null ? null : max(0, $total);
        $this->perPage = max(1, $perPage);
        $this->currentPage = max(1, $currentPage);
        $this->hasMore = (bool) ($options['has_more'] ?? ($total !== null && $this->currentPage < $this->lastPage()));
        $this->path = (string) ($options['path'] ?? (function_exists('request_uri') ? request_uri() : '/'));
        $this->pageName = (string) ($options['page_name'] ?? 'page');
        $this->query = (array) ($options['query'] ?? []);

        unset($this->query[$this->pageName]);
    }

    // A "simple" paginator only knows whether there is a next page.
    public static function simple(iterable $items, int $perPage, int $currentPage, bool $hasMore, array $options = []): static
    {
        return new static($items, null, $perPage, $currentPage, $options + ['has_more' => $hasMore]);
    }

    // ------------------------------------------------------------- facts --

    public function items(): Collection
    {
        return $this->items;
    }

    public function total(): ?int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function lastPage(): ?int
    {
        return $this->total === null ? null : max(1, (int) ceil($this->total / $this->perPage));
    }

    public function firstItem(): ?int
    {
        return $this->items->isEmpty() ? null : ($this->currentPage - 1) * $this->perPage + 1;
    }

    public function lastItem(): ?int
    {
        return $this->items->isEmpty() ? null : $this->firstItem() + $this->items->count() - 1;
    }

    public function count(): int
    {
        return $this->items->count();
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    public function isNotEmpty(): bool
    {
        return $this->items->isNotEmpty();
    }

    public function hasPages(): bool
    {
        return $this->currentPage > 1 || $this->hasMorePages();
    }

    public function hasMorePages(): bool
    {
        return $this->hasMore;
    }

    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    public function onLastPage(): bool
    {
        return !$this->hasMorePages();
    }

    // -------------------------------------------------------------- urls --

    public function url(int $page): string
    {
        $query = $this->query + [$this->pageName => max(1, $page)];

        return $this->path . '?' . http_build_query($query);
    }

    public function nextPageUrl(): ?string
    {
        return $this->hasMorePages() ? $this->url($this->currentPage + 1) : null;
    }

    public function previousPageUrl(): ?string
    {
        return $this->currentPage > 1 ? $this->url($this->currentPage - 1) : null;
    }

    public function withPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    // Extra query parameters carried on every page link.
    public function appends(array $query): static
    {
        $this->query = array_merge($this->query, $query);

        unset($this->query[$this->pageName]);

        return $this;
    }

    public function withQueryString(): static
    {
        return $this->appends(function_exists('query') ? query() : []);
    }

    public function setPageName(string $name): static
    {
        $this->pageName = $name;

        return $this;
    }

    // ------------------------------------------------------------ render --

    // Page numbers to show: 1 … [window around current] … last, with null
    // entries where an ellipsis belongs.
    public function elements(int $onEachSide = 2): array
    {
        $last = $this->lastPage();

        if ($last === null) {
            return [];
        }

        $window = range(max(1, $this->currentPage - $onEachSide), min($last, $this->currentPage + $onEachSide));
        $elements = [];

        if ($window[0] > 1) {
            $elements[] = 1;

            if ($window[0] > 2) {
                $elements[] = null;
            }
        }

        foreach ($window as $page) {
            $elements[] = $page;
        }

        if (end($window) < $last) {
            if (end($window) < $last - 1) {
                $elements[] = null;
            }

            $elements[] = $last;
        }

        return $elements;
    }

    // Bootstrap 5 markup. Returns '' when there is a single page.
    public function links(int $onEachSide = 2): string
    {
        if (!$this->hasPages()) {
            return '';
        }

        $e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $html = '<nav aria-label="Pagination"><ul class="pagination">';

        $html .= $this->onFirstPage()
            ? '<li class="page-item disabled"><span class="page-link" aria-hidden="true">&laquo;</span></li>'
            : '<li class="page-item"><a class="page-link" href="' . $e($this->previousPageUrl()) . '" rel="prev" aria-label="Previous">&laquo;</a></li>';

        foreach ($this->elements($onEachSide) as $page) {
            if ($page === null) {
                $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            } elseif ($page === $this->currentPage) {
                $html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $page . '</span></li>';
            } else {
                $html .= '<li class="page-item"><a class="page-link" href="' . $e($this->url($page)) . '">' . $page . '</a></li>';
            }
        }

        $html .= $this->hasMorePages()
            ? '<li class="page-item"><a class="page-link" href="' . $e($this->nextPageUrl()) . '" rel="next" aria-label="Next">&raquo;</a></li>'
            : '<li class="page-item disabled"><span class="page-link" aria-hidden="true">&raquo;</span></li>';

        return $html . '</ul></nav>';
    }

    // ----------------------------------------------------- serialisation --

    public function toArray(): array
    {
        return [
            'data' => $this->items->toArray(),
            'current_page' => $this->currentPage,
            'per_page' => $this->perPage,
            'from' => $this->firstItem(),
            'to' => $this->lastItem(),
            'total' => $this->total,
            'last_page' => $this->lastPage(),
            'path' => $this->path,
            'first_page_url' => $this->url(1),
            'last_page_url' => $this->lastPage() === null ? null : $this->url($this->lastPage()),
            'next_page_url' => $this->nextPageUrl(),
            'prev_page_url' => $this->previousPageUrl(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }

    public function getIterator(): ArrayIterator
    {
        return $this->items->getIterator();
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->items->offsetExists($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items->offsetGet($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->items->offsetSet($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->items->offsetUnset($offset);
    }

    // Forward collection methods: $paginator->pluck('id'), ->map(...), ...
    public function __call(string $method, array $arguments)
    {
        return $this->items->$method(...$arguments);
    }
}
