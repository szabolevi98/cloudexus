<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Auth;
use Cloudexus\Core\Permissions;
use Cloudexus\Core\RoleCode;
use Cloudexus\Core\Session;
use Cloudexus\Core\Totp;
use Cloudexus\Core\TwoFactor;
use Cloudexus\Model\Account\UserModel;

/**
 * A kétlépcsős belépés be- és kikapcsolása a saját profilban, és a
 * visszaállítása a felhasználók kezelőjének, ha valaki a telefonját és a
 * helyreállító kódjait is elvesztette.
 *
 * A kikapcsolás és az új helyreállító kódok újra kérik a jelszót: egy
 * bejelentkezve hagyott gép ne legyen elég a második lépés elvételéhez.
 */
class TwoFactorController extends BaseController
{
    private const SETUP = 'totp_setup';
    private const NEW_CODES = 'recovery_codes_new';

    public function show(): void
    {
        $this->requireAuth();

        $user = (new UserModel())->findById((int) Auth::id());
        $setup = Session::get(self::SETUP);
        $codes = Session::get(self::NEW_CODES);
        Session::remove(self::NEW_CODES);

        $this->pageTitle = $this->t('two_factor.title');
        $this->render('profile-two-factor.twig', [
            'user' => $user,
            'on' => TwoFactor::isOn($user),
            'setup' => is_string($setup) ? $setup : null,
            'qr' => is_string($setup) ? TwoFactor::qr((string) $user['username'], $setup) : null,
            'new_codes' => is_array($codes) ? $codes : null,
            'codes_left' => (new TwoFactor())->recoveryCodesLeft((int) Auth::id()),
        ]);
    }

    public function start(): void
    {
        $this->requireAuth();

        Session::set(self::SETUP, Totp::secret());
        $this->redirect('/profile/two-factor');
    }

    public function confirm(): void
    {
        $this->requireAuth();

        $secret = Session::get(self::SETUP);
        if (!is_string($secret)) {
            $this->redirect('/profile/two-factor');
        }

        $codes = (new TwoFactor())->enable((int) Auth::id(), $secret, (string) ($_POST['code'] ?? ''));
        if ($codes === null) {
            $this->flashError($this->t('two_factor.code_wrong'));
            $this->redirect('/profile/two-factor');
        }

        Session::remove(self::SETUP);
        Session::set(self::NEW_CODES, $codes);
        AuditLog::record(AuditLog::TWO_FACTOR_ON, 'user', Auth::id(), (string) (Auth::user()['username'] ?? ''));

        $this->flashSuccess($this->t('two_factor.turned_on'));
        $this->redirect('/profile/two-factor');
    }

    public function cancel(): void
    {
        $this->requireAuth();

        Session::remove(self::SETUP);
        $this->redirect('/profile/two-factor');
    }

    public function disable(): void
    {
        $this->requireAuth();
        $this->passwordAgain();

        (new TwoFactor())->disable((int) Auth::id());
        AuditLog::record(AuditLog::TWO_FACTOR_OFF, 'user', Auth::id(), (string) (Auth::user()['username'] ?? ''));

        $this->flashSuccess($this->t('two_factor.turned_off'));
        $this->redirect('/profile/two-factor');
    }

    public function recoveryCodes(): void
    {
        $this->requireAuth();
        $this->passwordAgain();

        Session::set(self::NEW_CODES, (new TwoFactor())->newRecoveryCodes((int) Auth::id()));

        $this->flashSuccess($this->t('two_factor.new_codes_made'));
        $this->redirect('/profile/two-factor');
    }

    /** A felhasználók kezelőjének, ha valaki a telefonját és a kódjait is elvesztette. */
    public function reset(int $userId): void
    {
        $this->requirePermission(Permissions::USERS_MANAGE);

        $user = (new UserModel())->findWithRole($userId);
        if ($user === null) {
            $this->redirect('/users');
        }

        // Ahogy a szerkesztésnél: szuper adminhoz csak szuper admin nyúlhat.
        if ($user['role_code'] === RoleCode::SUPER_ADMIN && !Auth::isSuperAdmin()) {
            $this->flashError($this->t('users.super_admin_only'));
            $this->redirect('/users/' . $userId . '/edit');
        }

        (new TwoFactor())->disable($userId);
        AuditLog::record(AuditLog::TWO_FACTOR_OFF, 'user', $userId, (string) $user['username'], ['reset_by_admin' => true]);

        $this->flashSuccess($this->t('two_factor.reset_done', ['name' => $user['full_name']]));
        $this->redirect('/users/' . $userId . '/edit');
    }

    private function passwordAgain(): void
    {
        $user = (new UserModel())->findById((int) Auth::id());

        if (!$user || !password_verify((string) ($_POST['password'] ?? ''), (string) $user['password_hash'])) {
            $this->flashError($this->t('two_factor.password_wrong'));
            $this->redirect('/profile/two-factor');
        }
    }
}
