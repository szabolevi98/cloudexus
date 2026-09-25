<?php

namespace Cloudexus\Tests\Integration;

use Cloudexus\Model\Core\PartnerContactModel;

final class PartnerContactTest extends DatabaseTestCase
{
    /** @param array<string, mixed> $extra */
    private function contact(int $partner, string $name, array $extra = [], ?int $id = null): int
    {
        return (new PartnerContactModel())->save($partner, $id, $extra + [
            'name' => $name, 'position' => '', 'email' => '', 'phone' => '', 'note' => '', 'is_primary' => false, 'receives_invoices' => false,
        ]);
    }

    public function testAPartnerHasOnePrimaryAndOneInvoiceContact(): void
    {
        $partner = $this->partner();
        $anna = $this->contact($partner, 'Anna', ['is_primary' => true, 'receives_invoices' => true]);
        $bela = $this->contact($partner, 'Béla', ['is_primary' => true]);

        $contacts = array_column((new PartnerContactModel())->forPartner($partner), null, 'id');
        self::assertSame(0, (int) $contacts[$anna]['is_primary'], 'the newer primary takes over');
        self::assertSame(1, (int) $contacts[$bela]['is_primary']);
        self::assertSame(1, (int) $contacts[$anna]['receives_invoices'], 'and the invoices stay with Anna');
        self::assertSame($bela, (int) array_key_first($contacts), 'the primary first');
    }

    public function testDocumentsGoToTheRightPerson(): void
    {
        $partner = $this->partner();
        $contacts = new PartnerContactModel();
        self::assertSame('info@ceg.hu', $contacts->recipientFor($partner, true, 'info@ceg.hu'), 'no contacts: the partner\'s own address');

        $this->contact($partner, 'Anna', ['email' => 'anna@ceg.hu', 'is_primary' => true]);
        $this->contact($partner, 'Könyvelő', ['email' => 'konyveles@ceg.hu', 'receives_invoices' => true]);

        self::assertSame('konyveles@ceg.hu', $contacts->recipientFor($partner, true, 'info@ceg.hu'), 'an invoice: to whoever gets the invoices');
        self::assertSame('anna@ceg.hu', $contacts->recipientFor($partner, false, 'info@ceg.hu'), 'a quote: to the primary contact');
    }

    public function testADeletedContactLeavesTheActivitiesInPlace(): void
    {
        $partner = $this->partner();
        $anna = $this->contact($partner, 'Anna');
        $activity = (new \Cloudexus\Model\Crm\PartnerActivityModel())->create([
            'partner_id' => $partner, 'contact_id' => $anna, 'type' => 'call', 'subject' => 'Egyeztetés', 'note' => '', 'activity_date' => date('Y-m-d H:i:s'), 'created_by' => null,
        ]);
        self::assertSame('Anna', (new \Cloudexus\Model\Crm\PartnerActivityModel())->forPartner($partner)[0]['contact_name']);

        (new PartnerContactModel())->delete($partner, $anna);

        self::assertNull($this->scalar('SELECT contact_id FROM partner_activities WHERE id = :id', ['id' => $activity]));
        self::assertSame([], (new PartnerContactModel())->forPartner($partner));
    }
}
