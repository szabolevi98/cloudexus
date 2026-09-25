<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Core\Import\CsvReader;
use Cloudexus\Core\Import\PartnerImport;
use Cloudexus\Core\Import\ProductImport;
use Cloudexus\Core\Lang;

final class ImportTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Lang::init('hu', ['hu', 'en'], 'hu');
    }

    /** @param list<string> $lines */
    private function csv(array $lines, string $encoding = 'UTF-8'): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cx');
        $text = implode("\r\n", $lines);
        file_put_contents($path, $encoding === 'UTF-8' ? "\xEF\xBB\xBF" . $text : (string) iconv('UTF-8', $encoding, $text));
        $csv = CsvReader::read($path);
        unlink($path);

        return $csv;
    }

    /** @return array<string, array<string, mixed>> the planned rows by their label */
    private static function byLabel(array $plan): array
    {
        return array_column($plan['rows'], null, 'label');
    }

    public function testTheProductExportComesBackAsItWent(): void
    {
        $id = $this->product(1000, ['sku' => 'P-1', 'name' => 'Kerékpár']);
        $this->pdo()->exec('UPDATE products SET min_stock = 5 WHERE id = ' . $id);

        // The export's own headers, the Hungarian way: semicolons, "Igen", a decimal comma.
        $csv = $this->csv([
            'Cikkszám;Vonalkód;Megnevezés;Kategória;Egység;Nettó ár;ÁFA %;Készlet;Min. készlet;Webshop;Aktív',
            'P-1;;Kerékpár;;;1000,00;27;0;5;Igen;Igen',
            'P-2;5991234567890;Csengő;;;1 250,50;27;;;Igen;Igen',
        ]);
        $import = new ProductImport();
        $plan = $import->plan($csv['headers'], $csv['rows']);
        $rows = self::byLabel($plan);

        self::assertSame([], $plan['missing']);
        self::assertSame('unchanged', $rows['P-1']['action']);
        self::assertSame('create', $rows['P-2']['action']);
        self::assertSame(1250.5, $rows['P-2']['data']['price']);

        self::assertSame(['create' => 1, 'update' => 0], $import->apply($plan['rows']));
        self::assertSame('Csengő', $this->scalar("SELECT pd.name FROM products p JOIN product_description pd ON pd.product_id = p.id WHERE p.sku = 'P-2'"));
        self::assertSame('5991234567890', $this->scalar("SELECT barcode FROM products WHERE sku = 'P-2'"));
    }

    public function testAnUpdateWritesOnlyWhatChangedAndLeavesTheRestAlone(): void
    {
        $id = $this->product(1000, ['sku' => 'P-1', 'name' => 'Kerékpár']);
        $this->pdo()->prepare('INSERT INTO product_links (product_id, linked_product_id, link_type) VALUES (:a, :b, :t)')
            ->execute(['a' => $id, 'b' => $this->product(10, ['sku' => 'P-9']), 't' => 'related']);

        $csv = $this->csv(['sku,price,name', 'P-1,1200,']);
        $plan = (new ProductImport())->plan($csv['headers'], $csv['rows']);
        $row = self::byLabel($plan)['P-1'];

        self::assertSame('update', $row['action']);
        self::assertSame(['price' => 1200.0], $row['data'], 'the empty name cell changes nothing');
        (new ProductImport())->apply($plan['rows']);

        self::assertSame('1200.00', (string) $this->scalar('SELECT price FROM products WHERE id = :id', ['id' => $id]));
        self::assertSame('Kerékpár', $this->scalar('SELECT name FROM product_description WHERE product_id = :id', ['id' => $id]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM product_links WHERE product_id = :id', ['id' => $id]), 'links untouched');
    }

    public function testBadRowsAreRefusedWithTheReasonAndTheRestGoes(): void
    {
        $this->product(1000, ['sku' => 'P-1']);
        $csv = $this->csv([
            'Cikkszám;Megnevezés;Nettó ár;Aktív;Kategória;Készlet',
            ';Névtelen;100;igen;;',
            'P-3;Új termék;sok;igen;;',
            'P-4;Másik;100;talán;;',
            'P-5;Harmadik;;igen;;',
            'P-6;Negyedik;100;igen;Nincs ilyen;12',
            'P-6;Megint;100;igen;;',
        ]);
        $plan = (new ProductImport())->plan($csv['headers'], $csv['rows']);
        $rows = array_column($plan['rows'], null, 'line');

        self::assertSame('error', $rows[2]['action']);
        self::assertSame('error', $rows[3]['action']);
        self::assertStringContainsString('sok', $rows[3]['messages'][0]);
        self::assertSame('error', $rows[4]['action']);
        self::assertSame('error', $rows[5]['action'], 'a new product needs a price');
        self::assertSame('create', $rows[6]['action'], 'an unknown category is a warning, not an error');
        self::assertCount(2, $rows[6]['messages'], 'the category, and the stock column skipped');
        self::assertSame('error', $rows[7]['action'], 'the same SKU twice in one file');
        self::assertSame(['create' => 1, 'update' => 0, 'unchanged' => 0, 'error' => 5], $plan['counts']);
    }

    public function testAHungarianExcelFileIsRead(): void
    {
        $csv = $this->csv(['Cikkszám;Megnevezés;Nettó ár', 'P-7;Árvíztűrő tükörfúrógép;990'], 'Windows-1250');
        $plan = (new ProductImport())->plan($csv['headers'], $csv['rows']);

        self::assertSame('create', $plan['rows'][0]['action']);
        self::assertSame('Árvíztűrő tükörfúrógép', $plan['rows'][0]['data']['name']);
    }

    public function testAFileWithoutTheKeyColumnIsRefusedWhole(): void
    {
        $csv = $this->csv(['Megnevezés;Ár', 'Valami;100']);
        $plan = (new ProductImport())->plan($csv['headers'], $csv['rows']);

        self::assertSame(['sku'], $plan['missing']);
        self::assertSame([], $plan['rows']);
    }

    public function testPartnersAreMatchedByTaxNumberThenByName(): void
    {
        $byTax = $this->partner('Régi Név Kft.');
        $this->pdo()->exec("UPDATE partners SET tax_number = '12345678-2-42' WHERE id = " . $byTax);
        $byName = $this->partner('Név Szerinti Bt.');

        $csv = $this->csv([
            'Név;Típus;Adószám;E-mail;Telefon;Cím;Aktív',
            'Új Név Kft.;Szállító;12345678-2-42;;;;Igen',
            'Név Szerinti Bt.;Mindkettő;;info@nevszerinti.hu;;;Igen',
            'Teljesen Új Zrt.;Vevő;;;+36 1 234 5678;;',
            'Hibás Kft.;;;nem-email;;;',
            'Rossz Típus Kft.;főnök;;;;;',
        ]);
        $import = new PartnerImport();
        $plan = $import->plan($csv['headers'], $csv['rows']);
        $rows = array_column($plan['rows'], null, 'line');

        self::assertSame('update', $rows[2]['action']);
        self::assertSame($byTax, $rows[2]['id']);
        self::assertSame(['name' => 'Új Név Kft.', 'type' => 'supplier'], $rows[2]['data']);
        self::assertSame('update', $rows[3]['action']);
        self::assertSame($byName, $rows[3]['id']);
        self::assertSame('create', $rows[4]['action']);
        self::assertSame('error', $rows[5]['action']);
        self::assertSame('error', $rows[6]['action']);

        self::assertSame(['create' => 1, 'update' => 2], $import->apply($plan['rows']));
        self::assertSame('supplier', $this->scalar('SELECT type FROM partners WHERE id = :id', ['id' => $byTax]));
        self::assertSame('both', $this->scalar('SELECT type FROM partners WHERE id = :id', ['id' => $byName]));
        self::assertSame('customer', $this->scalar("SELECT type FROM partners WHERE name = 'Teljesen Új Zrt.'"));
    }
}
