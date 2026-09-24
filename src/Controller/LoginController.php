<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Auth;
use Cloudexus\Core\ClientIp;
use Cloudexus\Core\Config;
use Cloudexus\Core\Recaptcha;
use Cloudexus\Core\Session;
use Cloudexus\Model\Account\AuditLogModel;

class LoginController extends BaseController
{
    private const FAILED_LOGIN_WINDOW_SECONDS = 900;

    public function show(): void
    {
        if (Auth::check()) {
            $this->redirect($this->homePath());
        }

        $this->render('login.twig', [
            'error' => Session::flash('login_error'),
            'recaptcha_site_key' => Recaptcha::siteKey(),
            'recaptcha_enabled' => Recaptcha::enabled(),
        ]);
    }

    public function submit(): void
    {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $recaptchaToken = (string) ($_POST['recaptcha_token'] ?? '');

        if (!Recaptcha::verify($recaptchaToken, 'login')) {
            Session::flash('login_error', $this->t('auth.captcha_failed'));
            $this->redirect('/login');
        }

        // Jelszópróbálgatás ellen: ennyi sikertelen belépés után az IP 15
        // percig nem próbálkozhat. A számlálás az audit napló sikertelen
        // belépés-soraiból megy, ugyanúgy, mint az API-n.
        $maxFailures = (int) Config::get('session.login_max_failures', 10);
        if ((new AuditLogModel())->countRecentFromIp(AuditLog::LOGIN_FAILED, ClientIp::get(), self::FAILED_LOGIN_WINDOW_SECONDS) >= $maxFailures) {
            Session::flash('login_error', $this->t('auth.too_many_attempts'));
            $this->redirect('/login');
        }

        if ($username === '' || $password === '' || !Auth::attempt($username, $password)) {
            Session::flash('login_error', $this->t('auth.invalid_credentials'));
            $this->redirect('/login');
        }

        $this->redirect($this->homePath());
    }

    public function logout(): void
    {
        Auth::logout();
        $this->redirect('/login');
    }
}
