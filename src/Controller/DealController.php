<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Acl;
use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Auth;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Account\UserModel;
use Cloudexus\Model\Core\PartnerModel;
use Cloudexus\Model\Crm\DealModel;

/**
 * Az értékesítési folyamat: az üzletek táblája szakaszonként, húzással
 * mozgatva, az üzlet adatlapja, és a kapcsolat az árajánlattal. A CRM jogai
 * vonatkoznak rá.
 */
class DealController extends BaseController
{
    private DealModel $deals;

    public function __construct()
    {
        parent::__construct();
        $this->deals = new DealModel();
        $this->activeMenu = 'deals';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::CRM_VIEW);

        $owner = (string) ($_GET['owner'] ?? '');
        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'owner' => $owner,
            'owner_id' => $owner === 'me' ? (int) Auth::id() : (int) $owner,
        ];
        $board = $this->deals->board($filters);

        $this->pageTitle = $this->t('deals.title');
        $this->render('deals/board.twig', [
            'board' => $board,
            'filters' => $filters,
            'users' => (new UserModel())->all(),
            'totals' => self::openTotals($board),
            'closed_days' => DealModel::CLOSED_DAYS,
        ]);
    }

    /** A választók keresője (select2). */
    public function search(): void
    {
        $this->requirePermission(Permissions::CRM_VIEW);
        $this->json($this->deals->search(trim((string) ($_GET['q'] ?? '')), (int) ($_GET['page'] ?? 1)));
    }

    public function createForm(): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);

        $partnerId = (int) ($_GET['partner_id'] ?? 0);
        $stage = (string) ($_GET['stage'] ?? 'lead');
        $this->pageTitle = $this->t('deals.new');
        $this->render('deals/form.twig', [
            'deal' => null,
            'stage' => DealModel::isOpen($stage) ? $stage : 'lead',
            'partner_option' => $partnerId ? (new PartnerModel())->labelsForIds([$partnerId]) : [],
            'users' => (new UserModel())->all(),
            'stages' => DealModel::STAGES,
            'me' => Auth::id(),
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);

        $data = $this->input('/deals/create');
        $stage = (string) ($_POST['stage'] ?? 'lead');
        $id = $this->deals->create($data + ['stage' => DealModel::isOpen($stage) ? $stage : 'lead', 'created_by' => Auth::id()]);
        AuditLog::record(AuditLog::CREATE, 'deal', $id, $data['title']);

        $this->flashSuccess($this->t('deals.created'));
        $this->redirect('/deals/' . $id);
    }

    public function show(int $id): void
    {
        $this->requirePermission(Permissions::CRM_VIEW);

        $deal = $this->dealOr404($id);
        $this->pageTitle = $deal['title'];
        $this->render('deals/show.twig', [
            'deal' => $deal,
            'stages' => DealModel::STAGES,
            'can_quote' => Acl::can(Permissions::ORDERS_MANAGE),
            'can_see_quotes' => Acl::can(Permissions::ORDERS_VIEW),
            'todos' => (new \Cloudexus\Model\Crm\TodoModel())->forDeal($id),
            'today' => date('Y-m-d'),
        ]);
    }

    public function editForm(int $id): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);

        $deal = $this->dealOr404($id);
        $this->pageTitle = $this->t('deals.edit') . ': ' . $deal['title'];
        $this->render('deals/form.twig', [
            'deal' => $deal,
            'stage' => $deal['stage'],
            'partner_option' => (new PartnerModel())->labelsForIds([(int) $deal['partner_id']]),
            'users' => (new UserModel())->all(),
            'stages' => DealModel::STAGES,
            'me' => Auth::id(),
        ]);
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);

        $deal = $this->dealOr404($id);
        $data = $this->input('/deals/' . $id . '/edit');
        $this->deals->update($id, $data);
        AuditLog::record(AuditLog::UPDATE, 'deal', $id, $data['title']);

        $this->flashSuccess($this->t('deals.updated'));
        $this->redirect('/deals/' . $id);
    }

    /**
     * Szakaszváltás: a tábláról húzással (fetch, JSON választ vár), vagy az
     * adatlap gombjairól (űrlap, visszairányít).
     */
    public function move(int $id): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);

        $deal = $this->dealOr404($id);
        $stage = (string) ($_POST['stage'] ?? '');
        $reason = trim(mb_substr((string) ($_POST['reason'] ?? ''), 0, 255));
        $order = array_values(array_filter(array_map('intval', (array) ($_POST['order'] ?? []))));
        $ajax = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

        $moved = $this->deals->move($id, $stage, $order, $reason !== '' ? $reason : null);
        if ($moved && $stage !== $deal['stage']) {
            AuditLog::record(AuditLog::UPDATE, 'deal', $id, (string) $deal['title'], ['stage' => $stage]);
        }

        if ($ajax) {
            if (!$moved) {
                http_response_code(422);
            }
            $this->json($moved ? ['ok' => true] + $this->afterMove($id) : ['ok' => false]);
        }
        if (!$moved) {
            $this->flashError($this->t($stage === 'lost' ? 'deals.lost_reason_required' : 'deals.bad_stage'));
        } else {
            $this->flashSuccess($this->t('deals.moved', ['stage' => $this->t('deals.stage.' . $stage)]));
        }
        $this->redirect('/deals/' . $id);
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);

        $deal = $this->dealOr404($id);
        \Cloudexus\Core\Undo::capture($this->t('undo.deal', ['title' => $deal['title']]), '/deals', [['deals', 'id', $id]]);
        $this->deals->delete($id);
        AuditLog::record(AuditLog::DELETE, 'deal', $id, (string) $deal['title']);

        $this->flashSuccess($this->t('deals.deleted'));
        $this->redirect('/deals');
    }

    /**
     * A húzás utáni állapot a táblának: az oszlopok összegei (ugyanazzal a
     * szűréssel, amivel a tábla látszik), a fejléc összesítője, és az
     * áthelyezett kártya valószínűsége.
     *
     * @return array{board: array<string, array{count: int, amount: string, weighted: string}>, summary: string, deal: array{chance: int, is_open: bool}}
     */
    private function afterMove(int $id): array
    {
        $owner = (string) ($_POST['owner'] ?? '');
        $board = $this->deals->board([
            'q' => trim((string) ($_POST['q'] ?? '')),
            'owner_id' => $owner === 'me' ? (int) Auth::id() : (int) $owner,
        ]);
        $totals = self::openTotals($board);
        $deal = (array) $this->deals->findById($id);

        return [
            'board' => array_map(static fn(array $column): array => [
                'count' => $column['count'],
                'amount' => \Cloudexus\Core\Currency::format($column['amount']),
                'weighted' => \Cloudexus\Core\Currency::format($column['weighted']),
            ], $board),
            'summary' => $this->t('deals.open_totals', [
                'count' => $totals['count'],
                'amount' => \Cloudexus\Core\Currency::format($totals['amount']),
                'weighted' => \Cloudexus\Core\Currency::format($totals['weighted']),
            ]),
            'deal' => ['chance' => (int) ($deal['chance'] ?? 0), 'is_open' => (bool) ($deal['is_open'] ?? false)],
        ];
    }

    /**
     * @param array<string, array{count: int, amount: float, weighted: float}> $board
     * @return array{count: int, amount: float, weighted: float} a nyitott szakaszok együtt
     */
    private static function openTotals(array $board): array
    {
        $open = array_intersect_key($board, array_flip(DealModel::OPEN_STAGES));

        return [
            'count' => (int) array_sum(array_column($open, 'count')),
            'amount' => (float) array_sum(array_column($open, 'amount')),
            'weighted' => (float) array_sum(array_column($open, 'weighted')),
        ];
    }

    /** @return array{title: string, partner_id: int, amount: float, probability: ?int, expected_close: ?string, owner_id: ?int, note: string} */
    private function input(string $back): array
    {
        $title = trim(mb_substr((string) ($_POST['title'] ?? ''), 0, 190));
        if ($title === '' || empty($_POST['partner_id'])) {
            $this->flashError($this->t('deals.required'));
            $this->redirect($back);
        }
        $probability = trim((string) ($_POST['probability'] ?? ''));
        $close = (string) ($_POST['expected_close'] ?? '');

        return [
            'title' => $title,
            'partner_id' => (int) $_POST['partner_id'],
            'amount' => max(0.0, (float) str_replace([' ', ','], ['', '.'], (string) ($_POST['amount'] ?? '0'))),
            'probability' => $probability === '' ? null : max(0, min(100, (int) $probability)),
            'expected_close' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $close) ? $close : null,
            'owner_id' => (int) ($_POST['owner_id'] ?? 0) ?: null,
            'note' => trim(mb_substr((string) ($_POST['note'] ?? ''), 0, 4000)),
        ];
    }

    /** @return array<string, mixed> */
    private function dealOr404(int $id): array
    {
        $deal = $this->deals->findById($id);
        if ($deal === null) {
            $this->flashError($this->t('deals.not_found'));
            $this->redirect('/deals');
        }

        return $deal;
    }
}
