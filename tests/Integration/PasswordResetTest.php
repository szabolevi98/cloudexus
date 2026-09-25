<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Model\Account\PasswordResetModel;

final class PasswordResetTest extends DatabaseTestCase
{
    public function testALinkWorksOnceForItsUser(): void
    {
        $id = $this->user('manager', 'anna');
        $resets = new PasswordResetModel();
        $token = $resets->create($id);

        $user = $resets->findUser($token);
        self::assertNotNull($user);
        self::assertSame($id, (int) $user['id']);

        self::assertTrue($resets->use((int) $user['reset_id']));
        self::assertFalse($resets->use((int) $user['reset_id']), 'not twice, even at the same moment');
        self::assertNull($resets->findUser($token));
    }

    public function testOnlyTheHashIsKept(): void
    {
        $token = (new PasswordResetModel())->create($this->user('manager', 'anna'));

        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM password_resets WHERE token_hash = :t', ['t' => $token]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM password_resets WHERE token_hash = :t', ['t' => hash('sha256', $token)]));
    }

    public function testANewerLinkTakesTheOlderOnesPlace(): void
    {
        $id = $this->user('manager', 'anna');
        $resets = new PasswordResetModel();
        $old = $resets->create($id);
        $new = $resets->create($id);

        self::assertNull($resets->findUser($old));
        self::assertNotNull($resets->findUser($new));
    }

    public function testAnExpiredLinkOrADeactivatedUserGetsNothing(): void
    {
        $id = $this->user('manager', 'anna');
        $resets = new PasswordResetModel();

        $expired = $resets->create($id);
        $this->pdo()->exec('UPDATE password_resets SET expires_at = NOW() - INTERVAL 1 MINUTE');
        self::assertNull($resets->findUser($expired));

        $token = $resets->create($id);
        $this->pdo()->exec('UPDATE users SET is_active = 0 WHERE id = ' . $id);
        self::assertNull($resets->findUser($token));

        self::assertNull($resets->findUser('not-a-token'));
    }
}
