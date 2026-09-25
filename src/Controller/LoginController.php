<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Auth;
use Cloudexus\Core\ClientIp;
use Cloudexus\Core\Config;
use Cloudexus\Core\Recaptcha;
use Cloudexus\Core\Session;
use Cloudexus\Core\TwoFactor;
use Cloudexus\Model\Account\AuditLogModel;
use Cloudexus\Model\Account\UserModel;

class LoginController extends BaseController
{
    private const FAILED_LOGIN_WINDOW_SECONDS = 900;
    private const PENDING = 'two_factor_pending';

    public function show(): void
    {
        if (Auth::check()) {
            $this->redirect($this->homePath());
        }

        $this->render('login.twig', [
            'error' => Session::flash('login_error'),
            'notice' => Session::flash('login_notice'),
            'mail_enabled' => \Cloudexus\Core\Mailer::isConfigured(),
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

        $user = $username === '' || $password === '' ? null : Auth::verifyPassword($username, $password);
        if ($user === null) {
            Session::flash('login_error', $this->t('auth.invalid_credentials'));
            $this->redirect('/login');
        }

        // Kétlépcsős belépésnél a jelszó csak a második kérdésig visz el. A
        // rossz kódok is sikertelen belépésnek számítanak, így a kitalált
        // jelszó sem ad korlátlan próbálkozást a kódokra.
        if (TwoFactor::isOn($user)) {
            Session::regenerate();
            Session::set(self::PENDING, ['user' => (int) $user['id'], 'username' => $username, 'at' => time()]);
            $this->redirect('/login/code');
        }

        Auth::signIn($user);
        $this->redirect($this->homePath());
    }

    /** A második kérdés: kód az alkalmazásból, vagy egy helyreállító kód. */
    public function showCode(): void
    {
        if ($this->pending() === null) {
            $this->redirect('/login');
        }

        $this->render('login.twig', [
            'code_step' => true,
            'error' => Session::flash('login_error'),
        ]);
    }

    public function submitCode(): void
    {
        $pending = $this->pending();
        if ($pending === null) {
            Session::flash('login_error', $this->t('auth.code_expired'));
            $this->redirect('/login');
        }

        $maxFailures = (int) Config::get('session.login_max_failures', 10);
        if ((new AuditLogModel())->countRecentFromIp(AuditLog::LOGIN_FAILED, ClientIp::get(), self::FAILED_LOGIN_WINDOW_SECONDS) >= $maxFailures) {
            Session::remove(self::PENDING);
            Session::flash('login_error', $this->t('auth.too_many_attempts'));
            $this->redirect('/login');
        }

        $user = (new UserModel())->findById($pending['user']);
        if (!$user || !$user['is_active'] || !TwoFactor::isOn($user) || !(new TwoFactor())->check($user, (string) ($_POST['code'] ?? ''))) {
            Auth::recordFailure($pending['username'], $user ?: null, ['wrong_code' => true]);
            Session::flash('login_error', $this->t('auth.code_wrong'));
            $this->redirect('/login/code');
        }

        Session::remove(self::PENDING);
        Auth::signIn($user);
        $this->redirect($this->homePath());
    }

    /**
     * Aki az imént jó jelszót adott meg, ha az imént volt. Öt perc, utána
     * újra a jelszó jön.
     *
     * @return array{user: int, username: string, at: int}|null
     */
    private function pending(): ?array
    {
        $pending = Session::get(self::PENDING);

        if (!is_array($pending) || !isset($pending['user'], $pending['username'], $pending['at']) || time() - (int) $pending['at'] > 300) {
            return null;
        }

        return ['user' => (int) $pending['user'], 'username' => (string) $pending['username'], 'at' => (int) $pending['at']];
    }

    public function logout(): void
    {
        Auth::logout();
        $this->redirect('/login');
    }
}
