<?php

namespace Cloudexus\Core;

/**
 * Small offset-based paginator. Reads the current page from $_GET['page'],
 * gets its total set by the model's paginate() query, and exposes a
 * Twig-friendly array for the shared pagination partial.
 */
class Paginator
{
    public int $page;
    public int $perPage;
    public int $total = 0;

    public function __construct(int $perPage = 25)
    {
        $this->perPage = max(1, $perPage);
        $this->page = max(1, (int) ($_GET['page'] ?? 1));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    /** Pulls the page back into range once the total is known. */
    public function clamp(): void
    {
        if ($this->page > $this->pages()) {
            $this->page = $this->pages();
        }
    }

    /**
     * @param array $filters Current filter values, so pagination links keep them
     *                       (and the column order, see Sort).
     */
    public function toTwig(array $filters = []): array
    {
        unset($filters['page']);
        $filters += Sort::params();

        return [
            'page' => $this->page,
            'pages' => $this->pages(),
            'total' => $this->total,
            'from' => $this->total > 0 ? $this->offset() + 1 : 0,
            'to' => min($this->offset() + $this->perPage, $this->total),
            'filters' => $filters,
        ];
    }
}
