<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\SettingModel;

class SettingsController extends BaseController
{
    private SettingModel $settings;

    public function __construct()
    {
        parent::__construct();
        $this->settings = new SettingModel();
        $this->activeMenu = 'settings-company';
    }

    public function company(): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $this->pageTitle = $this->t('settings.company_title');
        $this->render('settings/company.twig', [
            'company' => $this->settings->company(),
        ]);
    }

    public function companyUpdate(): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $fields = ['name', 'address', 'tax_number', 'bank_account', 'email', 'phone'];
        $pairs = [];
        foreach ($fields as $field) {
            $pairs['company.' . $field] = trim($_POST[$field] ?? '');
        }

        if ($pairs['company.name'] === '') {
            $this->flashError($this->t('settings.company_name_required'));
            $this->redirect('/settings/company');
        }

        $before = $this->settings->company();
        $this->settings->setMany($pairs);
        $changed = array_values(array_filter($fields, static fn(string $f): bool => (string) ($before[$f] ?? '') !== $pairs['company.' . $f]));
        if ($changed) {
            AuditLog::record(
                AuditLog::UPDATE,
                'settings',
                null,
                $this->t('nav.settings_company'),
                ['fields' => implode(', ', array_map(fn(string $f): string => $this->t('settings.' . ($f === 'name' ? 'company_name' : $f)), $changed))]
            );
        }
        $this->flashSuccess($this->t('settings.company_saved'));
        $this->redirect('/settings/company');
    }
}
