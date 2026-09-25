<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Core\Paginator;
use Cloudexus\Model\Core\ProductModel;

/**
 * Every sortable column of every list runs, both ways, against the real
 * schema: a SORTS expression that names a missing column or alias fails here
 * rather than on a page. And every header a template makes sortable exists in
 * the SORTS that feeds it.
 */
final class SortableListsTest extends DatabaseTestCase
{
    /**
     * Which method a SORTS constant feeds, when it is not paginate(), the
     * arguments that come before the filters, and whether it takes filters and
     * a Paginator at all (the aging report does not).
     */
    private const METHODS = [
        'MOVEMENT_SORTS' => ['paginateByType', ['in'], true],
        'TRANSFER_SORTS' => ['paginateTransfers', [], true],
        'OVERVIEW_SORTS' => ['overview', [], true],
        'AGING_SORTS' => ['aging', ['invoice', '2026-12-31'], false],
    ];

    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testEverySortableColumnRunsBothWays(): void
    {
        $lists = $this->sortableLists();
        self::assertNotEmpty($lists, 'no model declares SORTS');

        foreach ($lists as [$class, $constant, $method, $leading, $paged]) {
            $filters = $paged ? $this->emptyFilters($class, $method) : [];
            foreach (array_keys(constant("$class::$constant")) as $key) {
                foreach (['asc', 'desc'] as $dir) {
                    $_GET = ['sort' => $key, 'dir' => $dir];
                    $args = $paged ? [...$leading, $filters, new Paginator(5)] : $leading;
                    $rows = (new $class())->$method(...$args);
                    self::assertIsArray($rows, "$class::$method sorted by $key $dir");
                }
            }
        }
    }

    public function testTheOrderReallyFollowsTheHeaderAndGoesBack(): void
    {
        $this->product(300, ['name' => 'Bicikli']);
        $this->product(100, ['name' => 'Csengő']);
        $this->product(200, ['name' => 'Alváz']);
        $filters = ['q' => '', 'category_id' => 0, 'status' => '', 'updated_since' => ''];
        $names = static fn(array $rows): array => array_column($rows, 'name');

        $_GET = ['sort' => 'price', 'dir' => 'asc'];
        self::assertSame(['Csengő', 'Alváz', 'Bicikli'], $names((new ProductModel())->paginate($filters, new Paginator())));

        $_GET = ['sort' => 'price', 'dir' => 'desc'];
        self::assertSame(['Bicikli', 'Alváz', 'Csengő'], $names((new ProductModel())->paginate($filters, new Paginator())));

        $_GET = [];
        self::assertSame(['Alváz', 'Bicikli', 'Csengő'], $names((new ProductModel())->paginate($filters, new Paginator())), 'no sort: by name, as before');
    }

    public function testEveryHeaderInATemplateIsSortableByItsModel(): void
    {
        $root = dirname(__DIR__, 2);
        $keysByTemplate = [];
        foreach (glob($root . '/src/View/Twig/*/*.twig') ?: [] as $file) {
            if (preg_match_all("/sort_link\\('([a-z0-9_]+)'/", (string) file_get_contents($file), $m) > 0) {
                $keysByTemplate[substr($file, strlen($root) + 1)] = array_unique($m[1]);
            }
        }
        self::assertNotEmpty($keysByTemplate, 'no template uses sort_link');

        // Every key a template offers must be sortable by at least one list's SORTS;
        // the per-list check is the running test above plus the reviews of each list.
        $allKeys = [];
        foreach ($this->sortableLists() as [$class, $constant]) {
            $allKeys = array_merge($allKeys, array_keys(constant("$class::$constant")));
        }
        foreach ($keysByTemplate as $template => $keys) {
            foreach ($keys as $key) {
                self::assertContains($key, $allKeys, "$template offers '$key', which no SORTS knows");
            }
            $inputs = substr_count((string) file_get_contents($root . '/' . $template), 'sort_inputs()');
            self::assertLessThanOrEqual(1, $inputs, "$template carries the order through more than one form");
        }
    }

    /** @return list<array{class-string, string, string, list<mixed>, bool}> */
    private function sortableLists(): array
    {
        $root = dirname(__DIR__, 2);
        $lists = [];
        foreach (glob($root . '/src/Model/*/*.php') ?: [] as $file) {
            $class = 'Cloudexus\\Model\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getReflectionConstants() as $constant) {
                if (!str_ends_with($constant->getName(), 'SORTS')) {
                    continue;
                }
                [$method, $leading, $paged] = self::METHODS[$constant->getName()] ?? ['paginate', [], true];
                $lists[] = [$class, $constant->getName(), $method, $leading, $paged];
            }
        }

        return $lists;
    }

    /**
     * Every $filters['…'] the model reads, empty — what an unfiltered list page
     * passes. The whole file, because some lists build their WHERE in a helper.
     */
    private function emptyFilters(string $class, string $method): array
    {
        $source = (string) file_get_contents((string) (new \ReflectionMethod($class, $method))->getFileName());
        preg_match_all("/\\\$filters\\['([a-z_]+)'\\]/", $source, $m);

        return array_fill_keys(array_unique($m[1]), '');
    }
}
