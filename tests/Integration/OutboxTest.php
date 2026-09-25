<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Core\Mailer;
use Cloudexus\Core\Outbox;

final class OutboxTest extends DatabaseTestCase
{
    /** @var list<array{to: string, attachment: ?string}> each delivery, in turn */
    private array $delivered = [];

    /** @var list<?string> what the mail server says to each try, in turn; null is "taken" */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec('DELETE FROM outbox');
        $this->delivered = [];
        $this->answers = [];
    }

    private function outbox(): Outbox
    {
        return new Outbox(null, function (string $to, string $name, string $subject, string $body, ?string $attachmentName): ?string {
            $this->delivered[] = ['to' => $to, 'attachment' => $attachmentName];

            return $this->answers === [] ? null : array_shift($this->answers);
        });
    }

    private function row(int $id): ?array
    {
        $statement = $this->pdo()->query('SELECT * FROM outbox WHERE id = ' . $id);

        return ($statement ? $statement->fetch() : false) ?: null;
    }

    public function testWhatIsDueIsSentOnceAndMarkedSent(): void
    {
        $outbox = $this->outbox();
        $id = $outbox->add('anna@example.com', 'Anna', 'Hello', 'Text');

        self::assertSame(1, $outbox->sendDue());
        self::assertSame(0, $outbox->sendDue());
        self::assertSame([['to' => 'anna@example.com', 'attachment' => null]], $this->delivered);
        self::assertSame('sent', $this->row($id)['state']);
    }

    public function testTheAttachmentGoesWithTheMessage(): void
    {
        $outbox = $this->outbox();
        $outbox->add('anna@example.com', 'Anna', 'Invoice', 'Text', 'invoice', 'SZLA-2026-0001.pdf', "%PDF-1.4\x00\xff binary");

        $outbox->sendDue();

        self::assertSame('SZLA-2026-0001.pdf', $this->delivered[0]['attachment']);
    }

    public function testARefusedMessageWaitsLongerEachTimeAndThenStaysFailed(): void
    {
        $outbox = $this->outbox();
        $id = $outbox->add('anna@example.com', 'Anna', 'Hello', 'Text');
        $this->answers = ['Connection refused'];

        $outbox->sendDue();
        $row = $this->row($id);
        self::assertSame('waiting', $row['state']);
        self::assertSame('1', (string) $row['attempts']);
        self::assertSame('Connection refused', $row['last_error']);
        self::assertSame('1', (string) $this->scalar('SELECT next_attempt_at > NOW() FROM outbox WHERE id = :id', ['id' => $id]));

        // Not due yet: not tried again.
        self::assertSame(0, $outbox->sendDue());

        for ($attempts = 1; $attempts < count(Outbox::BACKOFF) + 1; $attempts++) {
            $outbox->failed($id, $attempts, 'Still refused');
        }
        self::assertSame('failed', $this->row($id)['state']);
    }

    public function testAFailedOneTriedAgainByHandGoes(): void
    {
        $outbox = $this->outbox();
        $id = $outbox->add('anna@example.com', 'Anna', 'Hello', 'Text');
        $outbox->failed($id, count(Outbox::BACKOFF), 'Refused');

        self::assertSame(['waiting' => 0, 'failed' => 1, 'sent_today' => 0], $outbox->counts());
        self::assertTrue($outbox->retry($id));
        self::assertSame(1, $outbox->sendDue());
        self::assertSame(['waiting' => 0, 'failed' => 0, 'sent_today' => 1], $outbox->counts());
    }

    public function testARunThatDiedGivesItsMessagesBack(): void
    {
        $outbox = $this->outbox();
        $id = $outbox->add('anna@example.com', 'Anna', 'Hello', 'Text');
        $this->pdo()->exec("UPDATE outbox SET state = 'sending', taken_at = NOW() - INTERVAL 1 HOUR WHERE id = " . $id);

        self::assertSame(1, $outbox->sendDue());
        self::assertSame('sent', $this->row($id)['state']);
    }

    public function testOldSentMessagesAreCleared(): void
    {
        $outbox = $this->outbox();
        $old = $outbox->add('anna@example.com', 'Anna', 'Old', 'Text');
        $new = $outbox->add('anna@example.com', 'Anna', 'New', 'Text');
        $outbox->sendDue();
        $this->pdo()->exec('UPDATE outbox SET sent_at = NOW() - INTERVAL 40 DAY WHERE id = ' . $old);

        self::assertSame(1, $outbox->prune());
        self::assertNull($this->row($old));
        self::assertSame('sent', $this->row($new)['state']);
    }

    public function testNothingGoesToAnAddressMailCannotReach(): void
    {
        self::assertTrue(Mailer::reachable('anna@kovacs.hu'));
        self::assertFalse(Mailer::reachable('anna@example.test'));
        self::assertFalse(Mailer::reachable('pda@cloudexus.local'));
        self::assertFalse(Mailer::reachable('not an address'));
    }
}
