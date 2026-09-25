<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Auth;
use Cloudexus\Core\Config;
use Cloudexus\Core\Mailer;
use Cloudexus\Core\Outbox;
use Cloudexus\Core\Permissions;

/**
 * Beállítások → E-mail: merre megy a levél, mi vár, mi ment el, mi akadt el
 * és miért — egy elakadt levél innen kézzel újraküldhető —, és egy próbalevél
 * a saját címre, hogy kiderüljön, működik-e.
 */
class EmailController extends BaseController
{
    private const STATES = ['waiting', 'failed', 'sent'];

    public function __construct()
    {
        parent::__construct();
        $this->activeMenu = 'settings-email';
    }

    public function show(): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $outbox = new Outbox();
        $counts = $outbox->counts();
        $state = in_array($_GET['state'] ?? '', self::STATES, true) ? (string) $_GET['state'] : ($counts['failed'] > 0 ? 'failed' : 'waiting');

        $this->pageTitle = $this->t('email.title');
        $this->render('settings/email.twig', [
            'transport' => Mailer::transport(),
            'from_address' => (string) Config::get('mail.from_address', ''),
            'counts' => $counts,
            'state' => $state,
            'messages' => $outbox->messages($state),
            'my_email' => (string) (Auth::user()['email'] ?? ''),
        ]);
    }

    public function retry(int $id): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        if ((new Outbox())->retry($id)) {
            $this->flashSuccess($this->t('email.retry_queued'));
        }
        $this->redirect('/settings/email?state=waiting');
    }

    public function test(): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $address = (string) (Auth::user()['email'] ?? '');
        $sent = Mailer::sendNow(
            $address,
            (string) Auth::name(),
            $this->t('email.test_subject'),
            $this->t('email.test_body', ['url' => (string) Config::get('app.base_url')]),
            'test'
        );

        if ($sent) {
            $this->flashSuccess($this->t('email.test_sent', ['address' => $address]));
        } else {
            $this->flashError($this->t(Mailer::isConfigured() ? 'email.test_failed' : 'email.off', ['address' => $address]));
        }
        $this->redirect('/settings/email');
    }
}
