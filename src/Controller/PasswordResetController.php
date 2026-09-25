<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\ClientIp;
use Cloudexus\Core\Config;
use Cloudexus\Core\Lang;
use Cloudexus\Core\Mailer;
use Cloudexus\Core\Recaptcha;
use Cloudexus\Core\Session;
use Cloudexus\Model\Account\AuditLogModel;
use Cloudexus\Model\Account\PasswordResetModel;
use Cloudexus\Model\Account\UserModel;

/**
 * Elfelejtett jelszó: egy link e-mailben, egy óráig, egyszer.
 *
 * A válasz mindig ugyanaz, akár van ilyen felhasználó, akár nincs, így az
 * oldalból nem derül ki, ki van a rendszerben. Egy címről negyedóránként
 * legfeljebb öt kérés mehet. Az új jelszó nem léptet be: a kétlépcsős
 * belépés így sem kerülhető meg, és a mobil alkalmazás minden eszközön
 * kilép, mint bármely jelszócserénél.
 */
class PasswordResetController extends BaseController
{
    private const MAX_REQUESTS = 5;
    private const WINDOW_SECONDS = 900;

    public function show(): void
    {
        $this->render('forgot-password.twig', [
            'mail_enabled' => Mailer::isConfigured(),
            'sent' => false,
            'error' => Session::flash('login_error'),
            'recaptcha_site_key' => Recaptcha::siteKey(),
            'recaptcha_enabled' => Recaptcha::enabled(),
        ]);
    }

    public function submit(): void
    {
        if (!Mailer::isConfigured()) {
            $this->redirect('/forgot-password');
        }
        if (!Recaptcha::verify((string) ($_POST['recaptcha_token'] ?? ''), 'forgot')) {
            Session::flash('login_error', $this->t('auth.captcha_failed'));
            $this->redirect('/forgot-password');
        }

        $typed = trim((string) ($_POST['login'] ?? ''));
        $recent = (new AuditLogModel())->countRecentFromIp(AuditLog::PASSWORD_RESET_REQUESTED, ClientIp::get(), self::WINDOW_SECONDS);
        if ($recent >= self::MAX_REQUESTS) {
            Session::flash('login_error', $this->t('auth.too_many_attempts'));
            $this->redirect('/forgot-password');
        }

        $user = $typed === '' ? null : (new UserModel())->findByUsernameOrEmail($typed);
        AuditLog::record(
            AuditLog::PASSWORD_RESET_REQUESTED,
            'user',
            $user ? (int) $user['id'] : null,
            mb_substr($typed, 0, 120),
            null,
            ['id' => $user ? (int) $user['id'] : null, 'name' => $user['full_name'] ?? null]
        );

        if ($user && $user['is_active']) {
            $token = (new PasswordResetModel())->create((int) $user['id']);
            $link = rtrim((string) Config::get('app.base_url'), '/') . '/reset-password/' . $token;
            Mailer::sendNow(
                (string) $user['email'],
                (string) $user['full_name'],
                Lang::get('auth.reset_mail_subject'),
                Lang::get('auth.reset_mail_body', [
                    'name' => (string) $user['full_name'],
                    'username' => (string) $user['username'],
                    'link' => $link,
                    'minutes' => (string) PasswordResetModel::LIFETIME_MINUTES,
                ]),
                'password_reset'
            );
        }

        $this->render('forgot-password.twig', [
            'mail_enabled' => true,
            'sent' => true,
            'notice' => $this->t('auth.forgot_sent'),
        ]);
    }

    public function resetForm(string $token): void
    {
        $user = (new PasswordResetModel())->findUser($token);

        $this->render('reset-password.twig', [
            'valid' => $user !== null,
            'token' => $token,
            'name' => $user['full_name'] ?? '',
            'username' => $user['username'] ?? '',
            'error' => Session::flash('login_error'),
        ]);
    }

    public function reset(string $token): void
    {
        $resets = new PasswordResetModel();
        $user = $resets->findUser($token);
        if ($user === null) {
            $this->redirect('/reset-password/' . rawurlencode($token));
        }

        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 8) {
            Session::flash('login_error', $this->t('profile.new_password_too_short'));
            $this->redirect('/reset-password/' . $token);
        }
        if ($password !== (string) ($_POST['password_confirm'] ?? '')) {
            Session::flash('login_error', $this->t('profile.new_password_mismatch'));
            $this->redirect('/reset-password/' . $token);
        }

        if (!$resets->use((int) $user['reset_id'])) {
            $this->redirect('/reset-password/' . $token);
        }
        (new UserModel())->setPassword((int) $user['id'], $password);
        AuditLog::record(
            AuditLog::PASSWORD_RESET,
            'user',
            (int) $user['id'],
            (string) $user['username'],
            null,
            ['id' => (int) $user['id'], 'name' => (string) $user['full_name']]
        );

        Session::flash('login_notice', $this->t('auth.reset_done'));
        $this->redirect('/login');
    }
}
