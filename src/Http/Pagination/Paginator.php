<?php

declare(strict_types=1);

namespace Sahil\Http\Pagination;

use Sahil\Http\Request;
use Sahil\Orm\PageResult;

/**
 * Builds canonical pagination metadata for JSON APIs, independent of the
 * ORM layer where page results are produced.
 *
 * The standard keys are:
 *
 *   - total:          total number of items
 *   - perPage:        items per page
 *   - currentPage:    current page (1-based)
 *   - lastPage:       last page number
 *   - hasMorePages:   whether more pages exist
 *   - nextPageUrl:    next-page URL or null
 *   - prevPageUrl:    previous-page URL or null
 */
final class Paginator
{
    public function __construct(
        private readonly PageResult $page,
        private readonly int $defaultPerPage = 20,
    ) {
    }

    public static function forRequest(Request $request, PageResult $page): self
    {
        $default = max(1, (int) $request->queryParameter('perPage', (string) $page->perPage));
        $default = min($default, 1000);

        return new self(
            page: $page,
            defaultPerPage: $default,
        );
    }

    public function toArray(): array
    {
        return [
            'total' => $this->total(),
            'perPage' => $this->perPage(),
            'currentPage' => $this->currentPage(),
            'lastPage' => $this->lastPage(),
            'hasMorePages' => $this->hasMorePages(),
            'nextPageUrl' => $this->nextPageUrl(),
            'prevPageUrl' => $this->previousPageUrl(),
        ];
    }

    public function json(): array
    {
        return $this->toArray();
    }

    public function total(): int
    {
        return $this->page->total;
    }

    public function perPage(): int
    {
        return $this->page->perPage;
    }

    public function currentPage(): int
    {
        return $this->page->currentPage;
    }

    public function lastPage(): int
    {
        return $this->page->lastPage();
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage() < $this->lastPage();
    }

    /**
     * @return list<\Sahil\Orm\Model>
     */
    public function items(): array
    {
        return $this->page->result->all();
    }

    private function nextPageUrl(): ?string
    {
        if (!$this->hasMorePages()) {
            return null;
        }

        return '/api/page='.($this->currentPage() + 1).'&perPage='.$this->perPage();
    }

    private function previousPageUrl(): ?string
    {
        if (!$this->hasPreviousPage()) {
            return null;
        }

        return '/api/page='.($this->currentPage() - 1).'&perPage='.$this->perPage();
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage() > 1;
    }
}
