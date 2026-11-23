<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Fees;

use Scholaris\Modules\Apps\Secondary\Fees\Application\DocumentHoldGuard;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Documents\Contracts\IssuanceContext;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * T830, T832 : vue d'ensemble des frais, préparation du privé (préréglages sans écrasement), plans de paiement avec séparation des
 * tâches, blocage de remise des documents levé par un plan en vigueur, garde de module (409). Données fictives.
 */
final class FeesRoutesTest extends SecondaryTestCase
{
    private const BURSAR = 'intendant-a';
    private const FOUNDER = 'promoteur-a';

    private InMemoryEnrollments $enrollments;
    private string $private;

    protected function setUp(): void
    {
        $this->enrollments = new InMemoryEnrollments();
        $debtors = new class () implements DebtorDirectory {
            public function learnerIdsInArrears(string $tenantId, string $establishmentId): array
            {
                return ['eleve-1'];
            }

            public function balanceXaf(string $tenantId, string $establishmentId, string $learnerId): int
            {
                return $learnerId === 'eleve-1' ? 50000 : 0;
            }

            public function isBlocked(string $tenantId, string $establishmentId, string $learnerId, string $scope): bool
            {
                return $learnerId === 'eleve-1' && $scope === 'documents';
            }
        };
        $this->bootSecondary([EnrollmentDirectory::class => $this->enrollments, DebtorDirectory::class => $debtors]);
        $this->private = $this->factory->opened(self::TA, 'lycee_prive');
        $this->enrollments->add(self::TA, 'ins-1', 'eleve-1', $this->private);
        $this->grant(self::TA, self::BURSAR, $this->private, ['secondary.billing.manage', 'secondary.fees.read']);
        $this->grant(self::TA, self::FOUNDER, $this->private, ['secondary.fees.validate']);
    }

    public function testVueDEnsembleEtPreparationDuPriveSansEcrasement(): void
    {
        $overview = $this->ok('GET', '/secondary/fees/overview', null, self::BURSAR, self::TA, $this->private);
        self::assertSame('private', $overview['mode']);
        self::assertContains('apee', $overview['kinds']);
        self::assertSame(['exam_fee'], $overview['refused_kinds']);
        self::assertSame('to_confirm', $overview['confirmation']);

        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, $this->private, 'billing.allocation_sibling_order', 'eldest_first', 'test');
        $setup = $this->ok('POST', '/secondary/fees/private/setup', [], self::BURSAR, self::TA, $this->private);
        self::assertTrue($setup['presets']['billing.allocation_rule']['applied']);
        self::assertFalse($setup['presets']['billing.allocation_sibling_order']['applied'], 'Une valeur déjà posée est conservée.');
        $settings = $this->service(SettingsReader::class);
        self::assertSame('kind_priority', $settings->value(self::TA, $this->private, 'billing.allocation_rule'));
        self::assertSame('eldest_first', $settings->value(self::TA, $this->private, 'billing.allocation_sibling_order'));
        self::assertSame(['registration', 'apee', 'tuition', '*'], $settings->value(self::TA, $this->private, 'billing.allocation_kind_order'));

        $again = $this->ok('POST', '/secondary/fees/private/setup', [], self::BURSAR, self::TA, $this->private);
        self::assertFalse($again['presets']['billing.allocation_rule']['applied'], 'Rejouable sans effet.');
        self::assertSame('kind_priority', $this->ok('GET', '/secondary/fees/overview', null, self::BURSAR, self::TA, $this->private)['allocation_rule']);
    }

    public function testRoutesDuPriveDansUnEtablissementPublic409(): void
    {
        $leg = $this->factory->opened(self::TA, 'leg');
        $this->grant(self::TA, self::BURSAR, $leg, ['secondary.billing.manage']);

        foreach ([['POST', '/secondary/fees/private/setup', []], ['GET', '/secondary/fees/payment-plans', null]] as [$method, $path, $body]) {
            [$status, $json] = $this->call($method, $path, $body, self::BURSAR, self::TA, $leg);
            self::assertSame(409, $status, $path);
            self::assertSame('module_not_enabled', $json['code'] ?? null);
        }
        self::assertSame('public_catalog', $this->ok('GET', '/secondary/fees/overview', null, self::BURSAR, self::TA, $leg)['mode']);
    }

    public function testPlanDePaiementValideParUneAutrePersonneEtBlocageDeRemiseLeve(): void
    {
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, $this->private, 'secondary.fees.blocked_document_types', ['secondary_report_trimester'], 'test');
        $guard = $this->service(DocumentHoldGuard::class);
        $context = new IssuanceContext(self::TA, $this->private, 'secondary_report_trimester', 'eleve-1', [], 'fr', 'u', 'api');
        self::assertNotNull($guard->refusal($context), 'Remise retardée pour impayé.');
        self::assertNull($guard->refusal(new IssuanceContext(self::TA, $this->private, 'secondary_school_certificate', 'eleve-1', [], 'fr', 'u', 'api')), 'Type non listé : jamais bloqué.');

        [$invalid] = $this->call('POST', '/secondary/fees/payment-plans', ['person_id' => 'eleve-1', 'reason' => 'Difficultés', 'installments' => [['due_on' => '2026-12-01', 'amount' => 20000], ['due_on' => '2026-11-01', 'amount' => 30000]]], self::BURSAR, self::TA, $this->private);
        self::assertSame(422, $invalid, 'Dates croissantes exigées.');
        [$stranger] = $this->call('POST', '/secondary/fees/payment-plans', ['person_id' => 'inconnu', 'reason' => 'x', 'installments' => [['due_on' => '2026-11-01', 'amount' => 1000]]], self::BURSAR, self::TA, $this->private);
        self::assertSame(422, $stranger);

        $plan = $this->ok('POST', '/secondary/fees/payment-plans', ['person_id' => 'eleve-1', 'reason' => 'Difficultés passagères', 'installments' => [['due_on' => '2026-11-01', 'amount' => 30000], ['due_on' => '2026-12-01', 'amount' => 20000]]], self::BURSAR, self::TA, $this->private);
        self::assertSame(['pending', 50000], [$plan['status'], $plan['total_amount']]);
        [$duplicate] = $this->call('POST', '/secondary/fees/payment-plans', ['person_id' => 'eleve-1', 'reason' => 'x', 'installments' => [['due_on' => '2026-11-01', 'amount' => 1000]]], self::BURSAR, self::TA, $this->private);
        self::assertSame(409, $duplicate);

        [$self] = $this->call('POST', '/secondary/fees/payment-plans/' . $plan['id'] . '/approve', [], self::BURSAR, self::TA, $this->private);
        self::assertSame(403, $self, 'L\'intendant n\'a pas le droit de validation.');
        $this->grant(self::TA, self::BURSAR, $this->private, ['secondary.fees.validate']);
        [$own] = $this->call('POST', '/secondary/fees/payment-plans/' . $plan['id'] . '/approve', [], self::BURSAR, self::TA, $this->private);
        self::assertSame(403, $own, 'Séparation des tâches.');

        $approved = $this->ok('POST', '/secondary/fees/payment-plans/' . $plan['id'] . '/approve', [], self::FOUNDER, self::TA, $this->private);
        self::assertSame('approved', $approved['status']);
        self::assertTrue($approved['in_force']);
        self::assertNull($guard->refusal($context), 'Un plan validé en vigueur lève le blocage.');
        self::assertContains('secondary.payment_plan.approved', $this->audit->actions());

        $list = $this->ok('GET', '/secondary/fees/payment-plans?filter[status]=approved', null, self::BURSAR, self::TA, $this->private);
        self::assertCount(1, $list);
        $cancelled = $this->ok('POST', '/secondary/fees/payment-plans/' . $plan['id'] . '/cancel', ['reason' => 'Échéances non tenues'], self::BURSAR, self::TA, $this->private);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertNotNull($guard->refusal($context));
    }

    public function testUnAutreClientNeVoitPasLesPlans(): void
    {
        $plan = $this->ok('POST', '/secondary/fees/payment-plans', ['person_id' => 'eleve-1', 'reason' => 'x', 'installments' => [['due_on' => '2026-11-01', 'amount' => 1000]]], self::BURSAR, self::TA, $this->private);
        $other = $this->factory->opened(self::TB, 'lycee_prive');
        $this->grant(self::TB, 'intendant-b', $other, ['secondary.fees.read']);

        [$status] = $this->call('GET', '/secondary/fees/payment-plans/' . $plan['id'], null, 'intendant-b', self::TB, $other);
        self::assertSame(404, $status);
    }
}
