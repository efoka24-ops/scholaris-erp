<?php

declare(strict_types=1);

namespace Tests\Isolation\Higher\Billing;

use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Fixtures\Higher\HigherOrgFixture;
use Tests\Support\Higher\Billing\HigherFinanceTestCase;

/**
 * Isolation FIN et DOC de l'IPES (TST-03/TST-12) : autre client, autre école (comptable limité à IT), WriteGate (suspendu :
 * lecture seule, 423 en écriture ; fermé : 404 en lecture).
 */
final class BillingScopeIsolationTest extends HigherFinanceTestCase
{
    /** @var array<string, mixed> */
    private array $account;

    protected function setUp(): void
    {
        $this->bootFinance();
        $this->seedFeeCatalog();
        $this->cdcGrid();
        $this->account = $this->openAccount();
        $this->assign(self::T, 'comptable-it', self::E, 'billing.manage', Scope::ORG_UNIT, 'IT');
        $this->assign(self::T, 'comptable-it', self::E, 'higher.billing.read', Scope::ORG_UNIT, 'IT');
        $this->assign(HigherOrgFixture::TENANT_B, 'comptable-b', HigherOrgFixture::ESTABLISHMENT_B, 'billing.manage', Scope::ESTABLISHMENT);
        $this->assign(HigherOrgFixture::TENANT_B, 'comptable-b', HigherOrgFixture::ESTABLISHMENT_B, 'higher.billing.read', Scope::ESTABLISHMENT);
    }

    public function testUnAutreClientNeVoitNiNeModifieRien(): void
    {
        [$read] = $this->api('GET', '/higher/billing/accounts/' . $this->account['id'], 'comptable-b', HigherOrgFixture::TENANT_B, HigherOrgFixture::ESTABLISHMENT_B);
        self::assertSame(404, $read->getStatusCode());
        [, $list] = $this->api('GET', '/higher/billing/accounts', 'comptable-b', HigherOrgFixture::TENANT_B, HigherOrgFixture::ESTABLISHMENT_B);
        self::assertSame([], $list['data']);
        [$scholarship] = $this->api('POST', '/higher/billing/accounts/' . $this->account['id'] . '/scholarships', 'comptable-b', HigherOrgFixture::TENANT_B, HigherOrgFixture::ESTABLISHMENT_B, ['scholarship_kind' => 'merit', 'reason' => 'x']);
        self::assertSame(404, $scholarship->getStatusCode());
        [$crossHeader] = $this->api('GET', '/higher/billing/accounts/' . $this->account['id'], 'comptable-b', HigherOrgFixture::TENANT_B, self::E);
        self::assertContains($crossHeader->getStatusCode(), [403, 404]);
    }

    public function testUnComptableDUneAutreEcoleEstHorsPerimetre(): void
    {
        [$read] = $this->api('GET', '/higher/billing/accounts/' . $this->account['id'], 'comptable-it', self::T, self::E);
        self::assertSame(404, $read->getStatusCode(), 'Lecture hors périmètre : existence cachée');
        [, $list] = $this->api('GET', '/higher/billing/accounts', 'comptable-it', self::T, self::E);
        self::assertSame([], $list['data']);
        [, $grids] = $this->api('GET', '/higher/billing/fee-grids', 'comptable-it', self::T, self::E);
        self::assertSame([], $grids['data']);
        [$open] = $this->api('POST', '/higher/billing/accounts', 'comptable-it', self::T, self::E, [
            'person_id' => 'yann', 'academic_year_id' => self::YEAR, 'school_id' => 'EC', 'program_id' => 'CF', 'level_id' => 'CF-L1',
        ]);
        self::assertSame(403, $open->getStatusCode(), 'Écriture hors périmètre : 403 journalisé');
        self::assertContains('higher.scope.denied', array_column($this->audit->entries, 'action'));
        [$grid] = $this->api('POST', '/higher/billing/fee-grids', 'comptable-it', self::T, self::E, [
            'academic_year_id' => self::YEAR, 'school_id' => 'IT', 'label' => 'Informatique', 'fees' => [['fee_code' => 'hi.pension', 'amount_xaf' => 400000]], 'start_year' => 2026,
        ]);
        self::assertSame(201, $grid->getStatusCode(), 'Son école reste accessible');
    }

    public function testApplicationSuspendueLectureSeuleEtFermeeInvisible(): void
    {
        $this->applications->set(self::T, ApplicationAccess::SUSPENDED);
        [$read] = $this->api('GET', '/higher/billing/accounts/' . $this->account['id'], 'comptable', self::T, self::E);
        self::assertSame(200, $read->getStatusCode());
        [$status] = $this->pay($this->account['id'], 'comptable', 1000, 'transfer', 'suspended-pay-01');
        self::assertSame(423, $status);
        [$issue] = $this->issueDocument('comptable', 'DOC-06', 'marie', 'EC');
        self::assertSame(423, $issue);
        [$portal] = $this->api('POST', '/higher/notifications/reminders/run', 'comptable', self::T, self::E, []);
        self::assertSame(423, $portal->getStatusCode());

        $this->applications->set(self::T, ApplicationAccess::CLOSED);
        [$closed] = $this->api('GET', '/higher/billing/accounts/' . $this->account['id'], 'comptable', self::T, self::E);
        self::assertSame(404, $closed->getStatusCode());
    }
}
