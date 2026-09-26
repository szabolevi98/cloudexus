<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Model\Account\IdempotencyKeyModel;

/**
 * The Idempotency-Key as every change but a stock booking uses it (see
 * ApiController::idempotent()): claimed before the request is worked on,
 * answered, or let go when the request fails.
 */
final class IdempotencyTest extends DatabaseTestCase
{
    public function testAnAnsweredKeyGivesTheFirstAnswerBackAndOnlyForTheSameRequest(): void
    {
        $keys = new IdempotencyKeyModel();

        self::assertNull($keys->claim('a1', 'order-1042', 'hash-a'));
        $keys->complete('a1', 'order-1042', 201, '{"data":{"id":7}}');

        $again = $keys->claim('a1', 'order-1042', 'hash-a');
        self::assertSame(201, (int) $again['status_code']);
        self::assertSame('{"data":{"id":7}}', $again['response']);
        self::assertSame('hash-a', $again['request_hash']);

        // Another owner's key of the same name is its own.
        self::assertNull($keys->claim('u5', 'order-1042', 'hash-b'));
    }

    public function testAnUnansweredKeyIsBusyUntilItIsLetGoOrAbandoned(): void
    {
        $keys = new IdempotencyKeyModel();

        self::assertNull($keys->claim('a1', 'partner-9', 'hash-a'));
        $busy = $keys->claim('a1', 'partner-9', 'hash-a');
        self::assertNull($busy['status_code']);
        self::assertSame(0, (int) $busy['abandoned']);
        self::assertFalse($keys->takeOver('a1', 'partner-9'), 'A claim a moment old was taken over.');

        // The request failed: its key goes, and the corrected one can use it.
        $keys->release('a1', 'partner-9');
        self::assertNull($keys->claim('a1', 'partner-9', 'hash-b'));

        // A claim whose request died long ago is taken over.
        $this->pdo()->exec("UPDATE api_idempotency_keys SET created_at = NOW() - INTERVAL 5 MINUTE WHERE idempotency_key = 'partner-9'");
        self::assertSame(1, (int) $keys->claim('a1', 'partner-9', 'hash-b')['abandoned']);
        self::assertTrue($keys->takeOver('a1', 'partner-9'));
    }

    public function testAnAnsweredKeyIsNotLetGo(): void
    {
        $keys = new IdempotencyKeyModel();

        $keys->claim('a1', 'order-7', 'hash-a');
        $keys->complete('a1', 'order-7', 200, '{}');
        $keys->release('a1', 'order-7');

        self::assertSame(200, (int) $keys->claim('a1', 'order-7', 'hash-a')['status_code']);
    }
}
