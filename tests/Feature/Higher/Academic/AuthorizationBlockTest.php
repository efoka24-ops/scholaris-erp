<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Academic;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\EnrolmentEligibility;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationRegistry;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGuards;
use Tests\Support\Higher\Academic\AcademicTestCase;

/**
 * TST-01 / RG-ACA-1 (T213) : inscription refusée sans autorisation MINESUP valide pour le niveau et la date ; levée seulement par
 * l'enregistrement d'une autorisation. La garde est celle que le socle exécute à la validation d'une inscription
 * (EnrolmentGuards, clé `organisation.enrolment.guards.higher`) ; régime BTS pour isoler la règle de la tutelle.
 */
final class AuthorizationBlockTest extends AcademicTestCase
{
    /** @var array{school: string, department: string, program: string, l1: string, l2: string, g1: string} */
    private array $org;

    protected function setUp(): void
    {
        $this->bootAcademic();
        $this->org = $this->buildOrg('bts', 'CG');
    }

    private static function on(string $day): DateTimeImmutable
    {
        return new DateTimeImmutable($day . ' 10:00:00', new DateTimeZone('Africa/Douala'));
    }

    private function refusal(string $structure, string $day = '2026-10-13'): ?string
    {
        return $this->service(EnrolmentGuards::class)->refusal(self::T, self::E, $structure, self::YEAR, 'person-1', self::on($day));
    }

    public function testTst01SansAutorisationLInscriptionEstRefusee(): void
    {
        self::assertStringContainsString('RG-ACA-1', (string) $this->refusal($this->org['g1']));

        $this->authorize($this->org['program'], null);

        self::assertNull($this->refusal($this->org['g1']), 'L\'enregistrement d\'une autorisation lève le blocage.');
        self::assertNull($this->refusal($this->org['l2']));
    }

    public function testLAutorisationCouvreSeulementSesNiveaux(): void
    {
        $this->authorize($this->org['program'], [$this->org['l1']]);

        self::assertNull($this->refusal($this->org['g1']), 'Groupe du niveau 1 autorisé.');
        self::assertStringContainsString('RG-ACA-1', (string) $this->refusal($this->org['l2']), 'Niveau 2 non autorisé.');
        self::assertStringContainsString('niveau', (string) $this->refusal($this->org['program']), 'La filière seule ne suffit pas : un niveau est exigé.');
    }

    public function testLAutorisationDoitEtreValideALaDate(): void
    {
        $authorization = $this->authorize($this->org['program'], null, '2025-09-01', '2026-08-31');

        self::assertNull($this->refusal($this->org['g1'], '2026-03-01'));
        self::assertStringContainsString('RG-ACA-1', (string) $this->refusal($this->org['g1'], '2026-10-13'), 'Expirée.');

        $this->ok('PATCH', '/higher/authorizations/' . $authorization['id'], ['valid_to' => '2027-08-31']);
        self::assertNull($this->refusal($this->org['g1'], '2026-10-13'), 'Prolongée.');
    }

    public function testContratDEligibiliteEtRouteDeControle(): void
    {
        $eligibility = $this->service(EnrolmentEligibility::class);
        self::assertNotNull($eligibility->refusalFor(self::T, self::E, $this->org['l1'], self::YEAR, self::on('2026-10-13')));

        $check = $this->ok('GET', '/higher/eligibility?structure_id=' . $this->org['g1'] . '&academic_year_id=' . self::YEAR);
        self::assertFalse($check['allowed']);

        $this->authorize($this->org['program'], null);
        self::assertNull($eligibility->refusalFor(self::T, self::E, $this->org['l1'], self::YEAR, self::on('2026-10-13')));
        self::assertTrue($this->ok('GET', '/higher/eligibility?structure_id=' . $this->org['g1'])['allowed']);
        self::assertSame('Structure introuvable dans cet établissement.', $eligibility->refusalFor(self::T, self::E, 'inconnue', null, self::on('2026-10-13')));
    }

    public function testRegistreDesAutorisations(): void
    {
        $created = $this->authorize($this->org['program'], [$this->org['l1']], '2025-09-01', '2026-12-31');
        self::assertSame(['ARR-2025-0150', 'MINESUP', [$this->org['l1']], 'valid', 79], [$created['decree_number'], $created['authority'], $created['level_ids'], $created['status'], $created['days_left']]);
        self::assertCount(1, $this->service(AuthorizationRegistry::class)->forStructure(self::T, self::E, $this->org['program']));

        self::assertSame(422, $this->call('POST', '/higher/authorizations', [
            'program_id' => $this->org['program'], 'decree_number' => 'X', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'level_ids' => ['niveau-etranger'],
        ])[0]);
        self::assertSame(422, $this->call('POST', '/higher/authorizations', [
            'program_id' => $this->org['program'], 'decree_number' => 'X', 'valid_from' => '2026-12-31', 'valid_to' => '2026-01-01',
        ])[0]);

        $expiring = $this->call('GET', '/higher/authorizations/expiring?days=90')[1]['data'];
        self::assertSame([$created['id']], array_column($expiring, 'id'));
        self::assertSame([], $this->call('GET', '/higher/authorizations/expiring?days=30')[1]['data']);
        self::assertCount(1, $this->call('GET', '/higher/authorizations?filter[program_id]=' . $this->org['program'])[1]['data']);

        $this->ok('DELETE', '/higher/authorizations/' . $created['id']);
        self::assertSame(404, $this->call('GET', '/higher/authorizations/' . $created['id'])[0]);
        self::assertStringContainsString('RG-ACA-1', (string) $this->refusal($this->org['g1']));
    }
}
