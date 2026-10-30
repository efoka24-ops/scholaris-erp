<?php

declare(strict_types=1);

namespace Tests\Feature;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Tests\Support\AppTestCase;

final class ApprovalsTest extends AppTestCase
{
    private Approvals $approvals;

    protected function setUp(): void
    {
        $this->boot();
        $this->approvals = $this->kernel->container()->get(Approvals::class);
    }

    private function request(): string
    {
        return $this->approvals->request('t1', 'payment.reversal', 'payment', 'p1', 'Erreur de saisie du caissier', 'caissiere');
    }

    public function testLeDemandeurNePeutPasValiderSaPropreDemande(): void
    {
        $id = $this->request();

        try {
            $this->approvals->approve('t1', $id, 'caissiere');
            self::fail('Refus attendu.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        self::assertSame(Approvals::PENDING, $this->approvals->find('t1', $id)['status']);
    }

    public function testUneAutrePersonneValideEtLaDecisionEstJournalisee(): void
    {
        $id = $this->request();
        $this->approvals->approve('t1', $id, 'comptable');

        $row = $this->approvals->find('t1', $id);
        self::assertSame(Approvals::APPROVED, $row['status']);
        self::assertSame('comptable', $row['approved_by']);

        $audit = $this->kernel->container()->get(AuditLog::class);
        self::assertSame(2, $audit->verify('t1')->checked, 'Demande + validation journalisées.');
    }

    public function testUneDemandeDejaTraiteeNePeutPasEtreRetraitee(): void
    {
        $id = $this->request();
        $this->approvals->reject('t1', $id, 'comptable');

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(409);
        $this->approvals->approve('t1', $id, 'directeur');
    }

    public function testLeMotifEstObligatoire(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(422);
        $this->approvals->request('t1', 'grade.correction', 'grade', 'g1', '   ', 'enseignant');
    }

    public function testUneDemandeEstInvisibleDepuisUnAutreClient(): void
    {
        $id = $this->request();

        self::assertNull($this->approvals->find('t2', $id));
        $this->expectException(HttpException::class);
        $this->approvals->approve('t2', $id, 'comptable');
    }
}
