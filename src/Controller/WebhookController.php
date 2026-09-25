<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Auth;
use Cloudexus\Core\OutboundUrl;
use Cloudexus\Core\Permissions;
use Cloudexus\Core\Webhooks;
use Cloudexus\Model\Account\WebhookModel;

/**
 * API → Webhookok: kinek szóljon a rendszer, és miről; a kézbesítések, a
 * fogadó válaszával; egy próbaüzenet (ping); egy elakadt üzenet újraküldése.
 */
class WebhookController extends BaseController
{
    private WebhookModel $hooks;

    public function __construct()
    {
        parent::__construct();
        $this->hooks = new WebhookModel();
        $this->activeMenu = 'webhooks';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        $this->pageTitle = $this->t('webhooks.title');
        $this->render('webhooks/list.twig', [
            'hooks' => $this->hooks->all(),
            'events' => Webhooks::EVENTS,
        ]);
    }

    public function show(int $id): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        $hook = $this->hookOr404($id);
        $this->pageTitle = $this->t('webhooks.title') . ': ' . $hook['name'];
        $this->render('webhooks/show.twig', [
            'hook' => $hook,
            'events' => Webhooks::EVENTS,
            'chosen' => $hook['events'] === '*' ? Webhooks::EVENTS : array_map('trim', explode(',', (string) $hook['events'])),
            'deliveries' => $this->hooks->deliveries($id),
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        [$name, $url, $events] = $this->input('/webhooks');
        $id = $this->hooks->create($name, $url, $events, Auth::id());
        AuditLog::record(AuditLog::CREATE, 'webhook', $id, $name, ['url' => $url]);

        $this->flashSuccess($this->t('webhooks.created'));
        $this->redirect('/webhooks/' . $id);
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        $this->hookOr404($id);
        [$name, $url, $events] = $this->input('/webhooks/' . $id);
        $this->hooks->update($id, $name, $url, $events);
        AuditLog::record(AuditLog::UPDATE, 'webhook', $id, $name, ['url' => $url]);

        $this->flashSuccess($this->t('webhooks.updated'));
        $this->redirect('/webhooks/' . $id);
    }

    public function toggle(int $id): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        $this->hookOr404($id);
        $this->hooks->toggle($id);
        $this->redirect('/webhooks/' . $id);
    }

    public function secret(int $id): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        $hook = $this->hookOr404($id);
        $this->hooks->newSecret($id);
        AuditLog::record(AuditLog::UPDATE, 'webhook', $id, (string) $hook['name'], ['token' => true]);

        $this->flashSuccess($this->t('webhooks.secret_changed'));
        $this->redirect('/webhooks/' . $id);
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        $hook = $this->hookOr404($id);
        $this->hooks->delete($id);
        AuditLog::record(AuditLog::DELETE, 'webhook', $id, (string) $hook['name']);

        $this->flashSuccess($this->t('webhooks.deleted'));
        $this->redirect('/webhooks');
    }

    /** Egy "ping" azonnal, hogy kiderüljön, válaszol-e a cím. */
    public function ping(int $id): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        $hook = $this->hookOr404($id);
        $webhooks = new Webhooks();
        $delivery = $webhooks->queue($id, 'ping', ['webhook' => ['id' => $id, 'name' => $hook['name']], 'by' => Auth::name()]);
        $ok = $webhooks->send([$delivery]) > 0;

        $ok ? $this->flashSuccess($this->t('webhooks.ping_ok')) : $this->flashError($this->t('webhooks.ping_failed'));
        $this->redirect('/webhooks/' . $id);
    }

    public function retry(int $id, int $deliveryId): void
    {
        $this->requirePermission(Permissions::API_MANAGE);

        $delivery = $this->hooks->delivery($deliveryId);
        if ($delivery !== null && (int) $delivery['webhook_id'] === $id && (new Webhooks())->retry($deliveryId)) {
            $this->flashSuccess($this->t('webhooks.retry_queued'));
        }
        $this->redirect('/webhooks/' . $id);
    }

    /** @return array{0: string, 1: string, 2: string} név, cím, események */
    private function input(string $back): array
    {
        $name = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 80));
        $url = trim(mb_substr((string) ($_POST['url'] ?? ''), 0, 500));
        $chosen = array_values(array_intersect(Webhooks::EVENTS, (array) ($_POST['events'] ?? [])));
        $events = ($_POST['all_events'] ?? '') === '1' || count($chosen) === count(Webhooks::EVENTS) ? '*' : implode(',', $chosen);

        if ($name === '' || $url === '') {
            $this->flashError($this->t('webhooks.required'));
            $this->redirect($back);
        }
        if ($events === '') {
            $this->flashError($this->t('webhooks.no_events'));
            $this->redirect($back);
        }
        try {
            OutboundUrl::check($url);
        } catch (\InvalidArgumentException $e) {
            $this->flashError($this->t($e->getMessage()));
            $this->redirect($back);
        }

        return [$name, $url, $events];
    }

    /** @return array<string, mixed> */
    private function hookOr404(int $id): array
    {
        $hook = $this->hooks->find($id);
        if ($hook === null) {
            $this->redirect('/webhooks');
        }

        return $hook;
    }
}
