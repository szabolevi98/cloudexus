<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Account\AuditLogModel;

/** Az audit napló: csak olvasható, a felületről nem szerkeszthető és nem törölhető. */
class AuditController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        $this->activeMenu = 'audit';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::AUDIT_VIEW);

        $date = static fn(string $key): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$key] ?? '') ? $_GET[$key] : '';
        $action = (string) ($_GET['action'] ?? '');
        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'user_id' => ctype_digit((string) ($_GET['user_id'] ?? '')) ? (string) $_GET['user_id'] : '',
            'action' => in_array($action, AuditLog::ACTIONS, true) ? $action : '',
            'entity_type' => preg_match('/^[a-z_]{1,40}$/', $_GET['entity_type'] ?? '') ? $_GET['entity_type'] : '',
            'from' => $date('from'),
            'to' => $date('to'),
        ];

        $logs = new AuditLogModel();
        $pager = new Paginator(50);

        $this->pageTitle = $this->t('audit.title');
        $this->render('audit/list.twig', [
            'entries' => $logs->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'users' => $logs->users(),
            'actions' => AuditLog::ACTIONS,
            'entity_types' => $logs->entityTypes(),
        ]);
    }
}
