<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Core\Config;
use Cloudexus\Core\OutboundUrl;
use Cloudexus\Core\Webhooks;
use Cloudexus\Model\Account\WebhookModel;

final class WebhooksTest extends DatabaseTestCase
{
    private static function deliveries(string $event): int
    {
        $stmt = \Cloudexus\Core\DatabaseConnection::get()->prepare('SELECT COUNT(*) FROM webhook_deliveries WHERE event = :e');
        $stmt->execute(['e' => $event]);

        return (int) $stmt->fetchColumn();
    }

    public function testAnEventGoesToTheActiveWebhooksThatWantIt(): void
    {
        $hooks = new WebhookModel();
        $all = $hooks->create('All', 'https://example.com/a', '*', null);
        $orders = $hooks->create('Orders', 'https://example.com/b', 'order.created,order.cancelled', null);
        $paused = $hooks->create('Paused', 'https://example.com/c', '*', null);
        $hooks->toggle($paused);

        $ids = (new Webhooks())->queueEvent('order.created', ['id' => 1]);
        self::assertCount(2, $ids);
        self::assertCount(1, (new Webhooks())->queueEvent('stock.changed', ['id' => 1]));
        self::assertSame([$all], array_map('intval', array_column($this->pdo()->query("SELECT webhook_id FROM webhook_deliveries WHERE event = 'stock.changed'")->fetchAll(), 'webhook_id')));
        self::assertGreaterThan(0, $orders);
    }

    public function testTheModelsTellWhatHappened(): void
    {
        (new WebhookModel())->create('All', 'https://example.com/a', '*', null);

        $partner = (new \Cloudexus\Model\Core\PartnerModel())->create(['type' => 'customer', 'name' => 'Web Kft.', 'tax_number' => '', 'email' => '', 'phone' => '', 'is_active' => 1]);
        self::assertSame(1, self::deliveries('partner.changed'));

        $product = $this->product(1000);
        $order = (new \Cloudexus\Model\Sales\OrderModel())->create(
            ['partner_id' => $partner, 'shipping_address_id' => null, 'billing_address_id' => null, 'status' => 'confirmed', 'order_date' => date('Y-m-d'), 'created_by' => null],
            [['product_id' => $product, 'quantity' => 2, 'unit_price' => 1000]]
        );
        self::assertSame(1, self::deliveries('order.created'));
        $payload = json_decode((string) $this->scalar("SELECT payload FROM webhook_deliveries WHERE event = 'order.created'"), true);
        self::assertSame($order, $payload['data']['id']);
        self::assertSame(2000.0, (float) $payload['data']['total']);

        (new \Cloudexus\Model\Sales\OrderModel())->cancel($order);
        self::assertSame(1, self::deliveries('order.cancelled'));
    }

    public function testStockChangesAreGatheredFromTheMovementsSinceTheLastRun(): void
    {
        (new WebhookModel())->create('Stock', 'https://example.com/s', 'stock.changed', null);
        $warehouse = $this->warehouse();
        $product = $this->product(100, ['sku' => 'S-1']);
        $webhooks = new Webhooks();

        $this->stockIn($warehouse, $product, 5);
        self::assertSame(0, $webhooks->collectStockChanges(), 'the first run only sets the cursor');

        $this->stockIn($warehouse, $product, 3);
        $this->stockIn($warehouse, $product, 2);
        self::assertSame(1, $webhooks->collectStockChanges(), 'one message per product and warehouse');
        $data = json_decode((string) $this->scalar("SELECT payload FROM webhook_deliveries WHERE event = 'stock.changed'"), true)['data'];
        self::assertSame('S-1', $data['product']['sku']);
        self::assertSame(5.0, (float) $data['change']);
        self::assertSame(10.0, (float) $data['stock_in_warehouse']);

        self::assertSame(0, $webhooks->collectStockChanges(), 'nothing new since');
    }

    public function testAMessageIsSignedAndARefusalIsTriedAgainLater(): void
    {
        $port = random_int(20000, 40000);
        $inbox = sys_get_temp_dir() . '/cx-webhook-' . $port . '.json';
        $receiver = sys_get_temp_dir() . '/cx-webhook-' . $port . '.php';
        file_put_contents($receiver, '<?php file_put_contents(' . var_export($inbox, true) . ', json_encode(["headers" => getallheaders(), "body" => file_get_contents("php://input")])); http_response_code(str_contains($_SERVER["REQUEST_URI"], "fail") ? 500 : 204);');
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $receiver], [1 => ['file', sys_get_temp_dir() . '/cx-webhook-srv.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/cx-webhook-srv.log', 'a']], $pipes);
        usleep(600000);
        Config::set('webhooks.allow_private', 1);

        try {
            $hooks = new WebhookModel();
            $ok = $hooks->create('Local', 'http://127.0.0.1:' . $port . '/in', '*', null);
            $fail = $hooks->create('Failing', 'http://127.0.0.1:' . $port . '/fail', '*', null);
            $webhooks = new Webhooks();
            [$good, $bad] = $webhooks->queueEvent('invoice.paid', ['id' => 7]);

            self::assertSame(1, $webhooks->send([$good]));
            $got = json_decode((string) file_get_contents($inbox), true);
            $secret = (string) $hooks->find($ok)['secret'];
            self::assertSame(Webhooks::signature($got['body'], $secret), $got['headers']['X-Cloudexus-Signature']);
            self::assertSame('invoice.paid', $got['headers']['X-Cloudexus-Event']);
            self::assertSame('delivered', $hooks->delivery($good)['state']);

            self::assertSame(0, $webhooks->send([$bad]));
            $delivery = $hooks->delivery($bad);
            self::assertSame('pending', $delivery['state'], 'tried again later');
            self::assertSame(500, (int) $delivery['response_status']);
            self::assertGreaterThan(0, $fail);
        } finally {
            Config::set('webhooks.allow_private', 0);
            proc_terminate($server);
            @unlink($receiver);
            @unlink($inbox);
        }
    }

    public function testPrivateAddressesAreRefused(): void
    {
        Config::set('webhooks.allow_private', 0);

        foreach (['http://127.0.0.1/x', 'http://10.0.0.5/x', 'http://169.254.169.254/latest', 'http://[::1]/x', 'ftp://example.com/x', 'http://user:pw@example.com/'] as $url) {
            try {
                OutboundUrl::check($url);
                self::fail($url . ' should be refused');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertTrue(OutboundUrl::isPublic('8.8.8.8'));
        self::assertFalse(OutboundUrl::isPublic('100.64.1.1'));
    }
}
