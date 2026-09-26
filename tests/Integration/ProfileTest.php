<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Model\Account\UserModel;
use Cloudexus\Model\Account\UserTokenModel;

/**
 * Saving one's own profile changes the name, the address and the password,
 * and nothing else: it once wrote a NULL role, which took every permission
 * away from whoever saved it.
 */
final class ProfileTest extends DatabaseTestCase
{
    public function testSavingTheProfileKeepsTheRoleAndTheActiveFlag(): void
    {
        $id = $this->user('super_admin', 'anna');
        $before = (new UserModel())->findById($id);

        (new UserModel())->updateProfile($id, 'anna@kovacs.hu', 'Kovács Anna');

        $after = (new UserModel())->findById($id);
        self::assertSame('Kovács Anna', $after['full_name']);
        self::assertSame('anna@kovacs.hu', $after['email']);
        self::assertSame($before['role_id'], $after['role_id']);
        self::assertSame($before['role'], $after['role']);
        self::assertSame($before['is_active'], $after['is_active']);
        self::assertSame($before['password_hash'], $after['password_hash'], 'no new password asked for, none set');
    }

    public function testANewPasswordSignsTheAppOutEverywhere(): void
    {
        $id = $this->user('warehouse', 'pda');
        $tokens = new UserTokenModel();
        $tokens->issue($id, 'Zebra TC21', 90);

        (new UserModel())->updateProfile($id, 'pda@kovacs.hu', 'PDA', 'a new long password');

        self::assertTrue(password_verify('a new long password', (string) (new UserModel())->findById($id)['password_hash']));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM user_tokens WHERE user_id = :id', ['id' => $id]));
    }

    public function testANewPasswordSignsEveryOtherBrowserOutButTheOneItWasChangedIn(): void
    {
        $id = $this->user('super_admin', 'anna');
        $this->signedIn($id, time() - 60);
        self::assertNotNull(\Cloudexus\Core\Auth::user());

        (new UserModel())->updateProfile($id, 'anna@kovacs.hu', 'Kovács Anna', 'a new long password');
        \Cloudexus\Core\Auth::keepThisSession();
        self::assertNotNull(\Cloudexus\Core\Auth::user(), 'the browser it was changed in stays');

        // Another browser, signed in before the change.
        $this->signedIn($id, time() - 60);
        self::assertNull(\Cloudexus\Core\Auth::user());
    }

    public function testSigningOutEverywhereElseKeepsThisBrowser(): void
    {
        $id = $this->user('super_admin', 'anna');
        $this->signedIn($id, time() - 60);

        \Cloudexus\Core\Auth::endOtherSessions();
        self::assertNotNull(\Cloudexus\Core\Auth::user());

        $this->signedIn($id, time() - 60);
        self::assertNull(\Cloudexus\Core\Auth::user());
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        \Cloudexus\Core\Auth::forget();
        parent::tearDown();
    }

    /** A browser whose session says it signed in at `$at`, as on its next click. */
    private function signedIn(int $id, int $at): void
    {
        $_SESSION = ['user_id' => $id, 'logged_in_at' => $at];
        \Cloudexus\Core\Auth::forget();
    }
}
