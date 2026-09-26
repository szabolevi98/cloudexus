<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Auth;
use Cloudexus\Model\Account\UserModel;

class ProfileController extends BaseController
{
    private UserModel $users;

    public function __construct()
    {
        parent::__construct();
        $this->users = new UserModel();
    }

    public function show(): void
    {
        $this->requireAuth();

        $this->pageTitle = $this->t('profile.title');
        $this->render('profile.twig', [
            'user' => $this->users->findById(Auth::id()),
            'mail_enabled' => \Cloudexus\Core\Mailer::isConfigured(),
        ]);
    }

    public function update(): void
    {
        $this->requireAuth();

        $user = $this->users->findById(Auth::id());
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

        if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flashError($this->t('profile.name_email_required'));
            $this->redirect('/profile');
        }

        if ($this->users->usernameOrEmailExists($user['username'], $email, (int) $user['id'])) {
            $this->flashError($this->t('profile.email_taken'));
            $this->redirect('/profile');
        }

        $password = '';
        if ($newPassword !== '') {
            if (!password_verify($currentPassword, $user['password_hash'])) {
                $this->flashError($this->t('profile.current_password_wrong'));
                $this->redirect('/profile');
            }
            if (strlen($newPassword) < 8) {
                $this->flashError($this->t('profile.new_password_too_short'));
                $this->redirect('/profile');
            }
            if ($newPassword !== $newPasswordConfirm) {
                $this->flashError($this->t('profile.new_password_mismatch'));
                $this->redirect('/profile');
            }
            $password = $newPassword;
        }

        $this->users->updateProfile((int) $user['id'], $email, $fullName, $password);
        \Cloudexus\Core\Theme::choose((string) ($_POST['theme'] ?? ''));

        // Az új jelszó minden más böngészőből kiléptet; ez bent marad.
        if ($password !== '') {
            Auth::keepThisSession();
        }

        \Cloudexus\Core\Session::set('user_name', $fullName);

        $this->flashSuccess($this->t('profile.updated') . ($password !== '' ? ' ' . $this->t('profile.password_note') : ''));
        $this->redirect('/profile');
    }

    /** Kijelentkezés minden más böngészőből — egy elhagyott laptop, egy közös gép. */
    public function endSessions(): void
    {
        $this->requireAuth();

        $user = Auth::user();
        Auth::endOtherSessions();
        \Cloudexus\Core\AuditLog::record(\Cloudexus\Core\AuditLog::SIGNED_OUT_ELSEWHERE, 'user', (int) $user['id'], (string) $user['username']);

        $this->flashSuccess($this->t('profile.signed_out_elsewhere'));
        $this->redirect('/profile');
    }

    /** A reggeli összefoglaló be- vagy kikapcsolása. */
    public function digest(): void
    {
        $this->requireAuth();

        $on = ($_POST['digest'] ?? '') === '1';
        $this->users->setDigest((int) Auth::id(), $on);
        $this->flashSuccess($this->t($on ? 'digest.turned_on' : 'digest.turned_off'));
        $this->redirect('/profile');
    }
}
