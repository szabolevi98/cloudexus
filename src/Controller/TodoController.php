<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Acl;
use Cloudexus\Core\Auth;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Account\UserModel;
use Cloudexus\Model\Core\PartnerModel;
use Cloudexus\Model\Crm\DealModel;
use Cloudexus\Model\Crm\TodoModel;
use Cloudexus\Model\Sales\QuoteModel;

/**
 * A teendők: lista, heti naptár, felvétel és szerkesztés. Egy teendő egy
 * partnerhez, üzlethez vagy ajánlathoz köthető, és ismétlődhet.
 */
class TodoController extends BaseController
{
    private TodoModel $todos;

    public function __construct()
    {
        parent::__construct();
        $this->todos = new TodoModel();
        $this->activeMenu = 'todos';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::CRM_VIEW);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'status' => $_GET['status'] ?? 'open',
            'assigned_to' => (int) ($_GET['assigned_to'] ?? 0),
            'type' => (string) ($_GET['type'] ?? ''),
        ];
        $pager = new Paginator(25);

        $this->pageTitle = $this->t('todos.list_title');
        $this->render('todos/list.twig', [
            'todos' => $this->todos->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'today' => date('Y-m-d'),
            'prefill' => ['due_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['due_date'] ?? '')) ? $_GET['due_date'] : ''],
            // Az űrlap előre kitöltve, ha egy partnerről, üzletről vagy ajánlatról jöttek ide.
            'form' => $this->formContext([
                'partner_id' => (int) ($_GET['partner_id'] ?? 0),
                'deal_id' => (int) ($_GET['deal_id'] ?? 0),
                'quote_id' => (int) ($_GET['quote_id'] ?? 0),
            ]),
        ]);
    }

    /** Heti naptár: hétfőtől vasárnapig, alapból a saját teendőim. */
    public function week(): void
    {
        $this->requirePermission(Permissions::CRM_VIEW);

        $date = (string) ($_GET['date'] ?? '');
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false ? $date : date('Y-m-d');
        $monday = date('Y-m-d', strtotime($day . ' -' . (((int) date('N', (int) strtotime($day))) - 1) . ' days'));
        $who = (string) ($_GET['who'] ?? 'me');
        $userId = match (true) {
            $who === 'all' => null,
            ctype_digit($who) => (int) $who,
            default => (int) Auth::id(),
        };

        $this->activeMenu = 'todos';
        $this->pageTitle = $this->t('todos.week_title');
        $this->render('todos/week.twig', [
            'week' => $this->todos->week($monday, $userId),
            'monday' => $monday,
            'prev' => date('Y-m-d', strtotime($monday . ' -7 days')),
            'next' => date('Y-m-d', strtotime($monday . ' +7 days')),
            'this_week' => $monday === date('Y-m-d', strtotime('monday this week')),
            'who' => $who === 'all' || ctype_digit($who) ? $who : 'me',
            'users' => (new UserModel())->all(),
            'today' => date('Y-m-d'),
            'types' => TodoModel::TYPES,
        ]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);

        $data = $this->input();
        $this->todos->create($data + ['created_by' => Auth::id()]);

        $this->flashSuccess($this->t('todos.created'));
        $this->redirect($this->back('/todos'));
    }

    public function editForm(int $id): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);

        $todo = $this->todoOr404($id);
        $this->pageTitle = $this->t('todos.edit');
        $this->render('todos/form.twig', [
            'todo' => $todo,
            'form' => $this->formContext($todo),
            'return' => $this->back('/todos', $_GET['return'] ?? null),
        ]);
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);

        $this->todoOr404($id);
        $this->todos->update($id, $this->input('/todos/' . $id . '/edit'));

        $this->flashSuccess($this->t('todos.updated'));
        $this->redirect($this->back('/todos'));
    }

    public function toggle(int $id): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);

        $next = $this->todos->toggle($id);
        if ($next !== null) {
            $this->flashSuccess($this->t('todos.next_created', ['date' => (string) ($this->todos->find($next)['due_date'] ?? '')]));
        }
        $this->redirect($this->back('/todos'));
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);
        \Cloudexus\Core\Undo::capture($this->t('undo.todo'), '/todos', [['todos', 'id', $id]]);
        $this->todos->delete($id);
        $this->flashSuccess($this->t('todos.deleted'));
        $this->redirect($this->back('/todos'));
    }

    /**
     * Az űrlap választóinak adatai: a felhasználók, és a kiválasztott
     * partner, üzlet, ajánlat felirata.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function formContext(array $values): array
    {
        $partner = (int) ($values['partner_id'] ?? 0);
        $deal = (int) ($values['deal_id'] ?? 0);
        $quote = (int) ($values['quote_id'] ?? 0);

        return [
            'users' => (new UserModel())->all(),
            'types' => TodoModel::TYPES,
            'recurrences' => TodoModel::RECURRENCES,
            'partner_option' => $partner ? (new PartnerModel())->labelsForIds([$partner]) : [],
            'deal_option' => $deal ? (new DealModel())->labelsForIds([$deal]) : [],
            'quote_option' => $quote && Acl::can(Permissions::ORDERS_VIEW) ? (new QuoteModel())->labelsForIds([$quote]) : [],
            'can_quotes' => Acl::can(Permissions::ORDERS_VIEW),
            'prefilled' => $partner || $deal || $quote,
        ];
    }

    /** @return array<string, mixed> */
    private function input(string $back = '/todos'): array
    {
        $title = trim(mb_substr((string) ($_POST['title'] ?? ''), 0, 200));
        if ($title === '') {
            $this->flashError($this->t('todos.title_required'));
            $this->redirect($this->back($back));
        }
        $date = (string) ($_POST['due_date'] ?? '');

        return [
            'title' => $title,
            'type' => (string) ($_POST['type'] ?? 'task'),
            'note' => trim(mb_substr((string) ($_POST['note'] ?? ''), 0, 4000)),
            'due_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
            'due_time' => (string) ($_POST['due_time'] ?? ''),
            'recurrence' => (string) ($_POST['recurrence'] ?? 'none'),
            'partner_id' => (int) ($_POST['partner_id'] ?? 0),
            'deal_id' => (int) ($_POST['deal_id'] ?? 0),
            'quote_id' => Acl::can(Permissions::ORDERS_VIEW) ? (int) ($_POST['quote_id'] ?? 0) : 0,
            'assigned_to' => (int) ($_POST['assigned_to'] ?? 0),
        ];
    }

    /**
     * Hová menjen vissza: a kérésben kapott helyi útvonalra (a heti naptár,
     * egy üzlet, a vezérlőpult), különben az alapértelmezettre. Más oldalra
     * mutató cím nem fogadható el.
     */
    private function back(string $default, ?string $return = null): string
    {
        $return ??= (string) ($_POST['return'] ?? '');

        return preg_match('~^/(?!/)[A-Za-z0-9/_?=&%.\-]*$~', $return) ? $return : $default;
    }

    /** @return array<string, mixed> */
    private function todoOr404(int $id): array
    {
        $todo = $this->todos->find($id);
        if ($todo === null) {
            $this->redirect('/todos');
        }

        return $todo;
    }
}
