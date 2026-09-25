<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Core\Auth;
use Cloudexus\Core\Totp;
use Cloudexus\Core\TwoFactor;
use Cloudexus\Model\Account\UserModel;

final class TwoFactorTest extends DatabaseTestCase
{
    public function testAWrongFirstCodeLeavesItOff(): void
    {
        $me = $this->user('manager');
        $service = new TwoFactor();

        self::assertNull($service->enable($me, Totp::secret(), '000000'));
        self::assertFalse(TwoFactor::isOn((array) (new UserModel())->findById($me)));
        self::assertSame(0, $service->recoveryCodesLeft($me));
    }

    public function testOnWithAProvenCodeAndEachCodeWorksOnce(): void
    {
        $me = $this->user('manager');
        $service = new TwoFactor();
        $secret = Totp::secret();

        // The code of the step before: the one after enabling is still to come.
        // Worked out once, so a step that ends mid-test does not make it another code.
        $code = Totp::code($secret, Totp::step() - 1);
        $codes = $service->enable($me, $secret, $code);
        self::assertIsArray($codes);
        self::assertCount(TwoFactor::RECOVERY_CODES, $codes);

        $user = (array) (new UserModel())->findById($me);
        self::assertTrue(TwoFactor::isOn($user));

        // The same code that turned it on does not sign anybody in.
        self::assertFalse($service->check($user, $code));

        // A recovery code works once, typed with or without its dash.
        self::assertTrue($service->check($user, strtoupper(str_replace('-', '', $codes[0]))));
        self::assertFalse($service->check($user, $codes[0]));
        self::assertSame(TwoFactor::RECOVERY_CODES - 1, $service->recoveryCodesLeft($me));

        $service->disable($me);
        self::assertFalse(TwoFactor::isOn((array) (new UserModel())->findById($me)));
        self::assertSame(0, $service->recoveryCodesLeft($me));
    }

    public function testANewAppCodeSignsInOnceOnly(): void
    {
        $me = $this->user('manager');
        $service = new TwoFactor();
        $secret = Totp::secret();
        $service->enable($me, $secret, Totp::code($secret, Totp::step() - 1));

        $now = Totp::code($secret, Totp::step());
        $user = (array) (new UserModel())->findById($me);
        self::assertTrue($service->check($user, substr($now, 0, 3) . ' ' . substr($now, 3)), 'with a space in the middle, as the apps show it');

        $user = (array) (new UserModel())->findById($me);
        self::assertFalse($service->check($user, $now), 'the same code again');
    }

    public function testNewRecoveryCodesReplaceTheOldOnes(): void
    {
        $me = $this->user('manager');
        $service = new TwoFactor();
        $secret = Totp::secret();
        $old = (array) $service->enable($me, $secret, Totp::code($secret, Totp::step() - 1));

        $new = $service->newRecoveryCodes($me);
        $user = (array) (new UserModel())->findById($me);

        self::assertFalse($service->check($user, $old[0]));
        self::assertTrue($service->check($user, $new[0]));
    }

    public function testThePasswordAloneDoesNotSignIn(): void
    {
        $this->user('manager', 'anna');
        $_SESSION = [];

        $user = Auth::verifyPassword('anna', 'x');

        self::assertNotNull($user);
        self::assertFalse(Auth::check(), 'checking the password is not signing in');
        self::assertNull(Auth::verifyPassword('anna', 'wrong'));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'login_failed'"));
    }
}
