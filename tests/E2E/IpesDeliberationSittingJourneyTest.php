<?php

declare(strict_types=1);

namespace Tests\E2E;

use Tests\E2E\Support\BuildsIpes;
use Tests\E2E\Support\BuildsIpesGrades;
use Tests\E2E\Support\RealStackTestCase;

/**
 * C-116 (défaut C110-I1) sur la pile réelle : notes IPES verrouillées → simulation du jury (11,55 / MGP 2,40 / 30 crédits) →
 * séance du socle ouverte et close par le directeur d'école (président) → PV signé → résultats publiés par la scolarité →
 * relevé DOC-09 émis. Le PV porte la moyenne de la simulation, pas « n/a ». Données fictives.
 */
final class IpesDeliberationSittingJourneyTest extends RealStackTestCase
{
    use BuildsIpes;
    use BuildsIpesGrades;

    private const INSTITUTE = [
        'name' => 'Institut Supérieur Fictif de la Séance', 'city' => 'Garoua', 'region' => 'Nord',
        'address' => 'Quartier fictif, Garoua', 'first' => 'Abdoulaye', 'last' => 'Fictif-Directeur',
        'email' => 'direction@seance-recette.test', 'phone' => '+237 699 30 00 03', 'normalized' => '+237699300003',
        'regime' => 'lmd', 'school' => 'ESG', 'program' => 'CF', 'program_name' => 'Comptabilité et finance',
    ];

    protected function setUp(): void
    {
        $this->bootRealStack(['app' => ['url' => 'https://app.scholaris.test']]);
    }

    public function testSeanceDuSocleSurLesNotesIpesJusquAuReleveDoc09(): void
    {
        $ctx = $this->onboard(self::INSTITUTE);
        $this->buildAcademic($ctx, 'lmd', 'ESG', 'CF', 'Comptabilité et finance');
        $this->staff($ctx);
        $this->curriculum($ctx);
        $this->feeCatalog($ctx);
        $application = $this->application($ctx, 'Marie', 'Fictive-Seance');
        $this->admit($ctx, (string) $application['id']);
        $enrolment = $this->as($ctx, 'scol', 'POST', '/higher/enrolments', ['application_id' => $application['id'], 'group_id' => $ctx['org']['g1']])['data'];
        $y = self::startYear();
        $this->as($ctx, 'compta', 'POST', '/higher/billing/fee-grids', [
            'academic_year_id' => $ctx['year'], 'school_id' => $ctx['org']['school'], 'program_id' => $ctx['org']['program'], 'level_id' => $ctx['org']['l1'],
            'study_mode' => 'day', 'label' => 'Niveau 1, cours du jour', 'start_year' => $y,
            'fees' => [['fee_code' => 'hi.inscription', 'amount_xaf' => 50000], ['fee_code' => 'hi.pension', 'amount_xaf' => 450000]],
        ], 201);
        $account = $this->as($ctx, 'compta', 'POST', '/higher/billing/accounts', [
            'person_id' => $enrolment['person_id'], 'academic_year_id' => $ctx['year'], 'school_id' => $ctx['org']['school'], 'program_id' => $ctx['org']['program'],
            'level_id' => $ctx['org']['l1'], 'study_mode' => 'day',
        ], 201)['data'];
        $this->as($ctx, 'compta', 'POST', '/higher/billing/accounts/' . $account['id'] . '/payments', [
            'amount_xaf' => 200000, 'channel' => 'orange_money', 'external_reference' => 'OM-SEANCE-0001',
        ], 201, ['Idempotency-Key' => 'c116-' . $account['id']]);
        $validated = $this->as($ctx, 'scol', 'POST', '/higher/enrolments/' . $enrolment['id'] . '/validate', [])['data'];
        $ctx['student'] = ['person' => (string) $enrolment['person_id'], 'enrolment' => $validated];
        $student = $ctx['student']['person'];
        $this->teaching($ctx);

        // Jury, notes verrouillées des 9 EC et simulation (TST-04).
        $this->deliberationJourney($ctx);

        $sitting = [
            'structure_id' => $ctx['org']['g1'], 'period_id' => 'S1', 'period_label' => 'Semestre 1', 'exam_session' => 'normal',
            'president_id' => $ctx['people']['dir'], 'members_expected' => 3,
        ];
        // Le directeur d'école (R04, président) ouvre et conduit la séance.
        $opened = $this->res($ctx, 'dir', 'POST', '/deliberations', $sitting, 201);
        $line = $this->lineOf($opened, $student);
        self::assertEqualsWithDelta(11.55, (float) $line['average'], 0.001, 'Moyenne à l\'ouverture : ' . json_encode($line));
        $sid = (string) $opened['id'];
        $this->as($ctx, 'dir', 'POST', '/deliberations/' . $sid . '/attendance', ['members_present' => [$ctx['people']['dir'], $ctx['people']['chef']]]);
        $this->as($ctx, 'dir', 'POST', '/deliberations/' . $sid . '/start');
        $this->recent2fa($ctx, 'dir');
        $closed = $this->res($ctx, 'dir', 'POST', '/deliberations/' . $sid . '/close');
        $line = $this->lineOf($closed, $student);
        self::assertEqualsWithDelta(11.55, (float) $line['average'], 0.001);
        self::assertSame(30, (int) $line['credits_earned']);
        self::assertSame(30, (int) $line['credits_total']);
        self::assertSame('Admis par compensation', $line['decision']);
        self::assertStringContainsString('moyenne 11.55 · crédits 30/30 · décision : Admis par compensation', (string) $closed['minutes']);

        $this->as($ctx, 'dir', 'POST', '/deliberations/' . $sid . '/sign');

        // La scolarité (R07) publie après la signature du président.
        $this->recent2fa($ctx, 'scol');
        $published = $this->as($ctx, 'scol', 'POST', '/results/publish', ['structure_id' => $ctx['org']['g1'], 'period_id' => 'S1'])['data'];
        $mine = array_values(array_filter($published, static fn (array $r): bool => $r['person_id'] === $student));
        self::assertCount(1, $mine);
        self::assertSame('published', $mine[0]['status'] ?? 'published');
        self::assertEqualsWithDelta(11.55, (float) $mine[0]['average'], 0.001);

        // Relevé DOC-09 : type et modèles installés depuis le catalogue IPES, profil documentaire de l'école (administrateur).
        foreach ($this->as($ctx, 'admin', 'GET', '/higher/documents/catalog')['data'] as $type) {
            if (($type['socle']['type']['code'] ?? null) !== 'hi_doc09') {
                continue;
            }
            $this->as($ctx, 'admin', 'POST', '/document-types', array_filter($type['socle']['type'], static fn ($v): bool => $v !== null), 201);
            foreach ($type['socle']['templates'] as $template) {
                $stored = $this->res($ctx, 'admin', 'POST', '/document-templates', ['type_code' => 'hi_doc09'] + $template, 201);
                $this->as($ctx, 'admin', 'POST', '/document-templates/' . $stored['id'] . '/validate', []);
            }
        }
        $this->as($ctx, 'admin', 'PUT', '/higher/documents/schools/' . $ctx['org']['school'] . '/profile', [
            'school_code' => 'ESG', 'display_name' => 'École supérieure de gestion (fictive)', 'header_text' => 'République du Cameroun', 'default_locale' => 'fr',
        ]);
        $preview = $this->res($ctx, 'scol', 'GET', '/higher/deliberation/transcripts?person_id=' . $student . '&period_id=S1');
        self::assertStringContainsString('11.55', (string) json_encode($preview));
        $issued = $this->res($ctx, 'scol', 'POST', '/higher/deliberation/transcripts', ['person_id' => $student, 'period_id' => 'S1'], 201);
        self::assertNotSame('', (string) ($issued['number'] ?? ''), (string) json_encode($issued));
    }

    /**
     * Double authentification récente : enrôlement si l'acteur n'en a pas, sinon reconnexion avec code TOTP.
     *
     * @param array<string, mixed> $ctx
     */
    private function recent2fa(array &$ctx, string $who): void
    {
        if (isset($this->totpSecrets[$ctx['logins'][$who][0]])) {
            $this->relogin($ctx, $who);

            return;
        }
        $this->enrol2fa($ctx, $who);
    }

    /**
     * @param array<string, mixed> $sitting
     * @return array<string, mixed>
     */
    private function lineOf(array $sitting, string $person): array
    {
        foreach ($sitting['lines'] ?? [] as $line) {
            if ($line['person_id'] === $person) {
                return $line;
            }
        }
        self::fail('Ligne absente du PV : ' . json_encode($sitting));
    }
}
