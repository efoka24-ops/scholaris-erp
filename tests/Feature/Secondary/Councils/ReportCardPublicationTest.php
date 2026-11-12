<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Councils;

use Scholaris\Modules\Apps\Secondary\Notifications\Application\ReportCardNotifier;
use Scholaris\Modules\Apps\Secondary\Notifications\Contracts\ReportCardNotices;
use Scholaris\Modules\Socle\Assessment\Contracts\IssuedReportCards;
use Scholaris\Modules\Socle\Messaging\Contracts\EssentialMessenger;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Primary\SelectiveMessenger;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * S-NOT05 : publication des bulletins du secondaire aux familles. SMS « bulletin disponible » (sec.not05) aux garants des élèves dont
 * le bulletin est émis, message essentiel par défaut (D-BUL, 12 novembre 2026), une seule fois par bulletin et par garant ; paramètre
 * secondary.report_card.sms_essential à faux : consentement exigé ; désinscription respectée ; résumé juste. Données fictives.
 */
final class ReportCardPublicationTest extends SecondaryTestCase
{
    private const COUNCIL = '01SNOT05COUNCIL00000000001';
    private const CLASS_ID = 'classe-3e-a';
    private SelectiveMessenger $messenger;
    private InMemoryPeople $people;
    private string $school = '';

    protected function setUp(): void
    {
        $this->messenger = new SelectiveMessenger();
        $this->people = new InMemoryPeople();
        $this->bootSecondary([Messenger::class => $this->messenger, EssentialMessenger::class => $this->messenger, PersonDirectory::class => $this->people]);
        $this->school = $this->factory->opened(self::TA, 'ces');
        $this->grant(self::TA, 'chef-n5', $this->school, ['secondary.reportcard.validate']);
        $this->grant(self::TA, 'prof-n5', $this->school, ['secondary.reportcard.read']);
        foreach ([['eleve-1', 'Aïcha'], ['eleve-2', 'Bello'], ['eleve-3', 'Clarisse']] as [$id, $first]) {
            $this->people->add(self::TA, $id, $first, 'Exemple');
        }
        foreach (['g-1' => 'eleve-1', 'g-2' => 'eleve-1', 'g-3' => 'eleve-2', 'g-4' => 'eleve-3'] as $guardian => $learner) {
            $this->people->add(self::TA, $guardian, 'Garant', ucfirst($guardian), '+2376000000' . substr($guardian, 2) . '0');
            $this->people->link(self::TA, $guardian, $learner);
        }
        $this->council('validated');
        $this->line('eleve-1', 14.5, 1, 'Admis en classe supérieure');
        $this->line('eleve-2', 11.25, 2, null);
        $this->line('eleve-3', 8.0, 3, 'Redouble');
        $this->request('eleve-1', 'issued', 'doc-1');
        $this->request('eleve-2', 'issued', 'doc-2');
        $this->request('eleve-3', 'failed', null);
    }

    public function testPublicationEnvoieLeSmsEssentielUneSeuleFoisParBulletinEtParGarant(): void
    {
        self::assertTrue($this->service(SettingsReader::class)->value(self::TA, $this->school, ReportCardNotices::SETTING), 'Essentiel par défaut (migration plateforme).');
        $this->messenger->withoutConsent[] = 'g-2';

        $first = $this->publish();
        self::assertSame(['published' => 2, 'notified' => 3, 'families' => 3, 'already_notified' => 0, 'not_notified' => []], $first, 'Le garant sans consentement recueilli est prévenu (message essentiel) ; l\'élève sans bulletin émis est ignoré.');
        $sms = $this->messenger->of(ReportCardNotifier::TEMPLATE);
        self::assertSame(['g-1', 'g-2', 'g-3'], array_column($sms, 'person'));
        self::assertSame([true, true, true], array_column($sms, 'essential'));
        self::assertSame([
            'child_name' => 'Aïcha Exemple', 'period_label' => 'Trimestre 1', 'average' => '14,50', 'rank' => '1',
            'decision' => 'Admis en classe supérieure', 'link' => '/secondaire/famille',
        ], $sms[0]['variables']);
        self::assertSame('-', $sms[2]['variables']['decision']);
        self::assertContains('secondary.reportcards.published', $this->audit->actions());

        $again = $this->publish();
        self::assertSame(['published' => 2, 'notified' => 0, 'families' => 3, 'already_notified' => 3, 'not_notified' => []], $again, 'Republier ne renvoie pas le SMS.');
        self::assertCount(3, $this->messenger->of(ReportCardNotifier::TEMPLATE));

        // Un bulletin émis plus tard (échec repris) : seule sa famille est prévenue.
        $this->pdo()->prepare('UPDATE report_card_requests SET status = ?, document_id = ? WHERE person_id = ?')->execute(['issued', 'doc-3', 'eleve-3']);
        self::assertSame(['published' => 3, 'notified' => 1, 'families' => 4, 'already_notified' => 3, 'not_notified' => []], $this->publish());
        self::assertSame('g-4', $this->messenger->of(ReportCardNotifier::TEMPLATE)[3]['person']);
    }

    public function testDesinscriptionEtConsentementRevoqueRespectesEtResumeParMotif(): void
    {
        $this->messenger->blocked['g-1'] = 'opted_out';
        $this->messenger->blocked['g-3'] = 'consent_revoked';

        $summary = $this->publish();
        self::assertSame(['published' => 2, 'notified' => 1, 'families' => 3, 'already_notified' => 0, 'not_notified' => ['opted_out' => 1, 'consent_revoked' => 1]], $summary);
        self::assertSame(['g-2'], array_column($this->messenger->of(ReportCardNotifier::TEMPLATE), 'person'));

        // Un envoi bloqué n'est pas journalisé : il est retenté à la publication suivante, une fois le refus levé.
        unset($this->messenger->blocked['g-3']);
        self::assertSame(['published' => 2, 'notified' => 1, 'families' => 3, 'already_notified' => 1, 'not_notified' => ['opted_out' => 1]], $this->publish());
    }

    public function testParametreFauxLeConsentementEstExige(): void
    {
        self::assertTrue($this->service(SettingsWriter::class)->presetIfAbsent(self::TA, $this->school, ReportCardNotices::SETTING, false, self::ADMIN));
        $this->messenger->withoutConsent[] = 'g-2';

        $summary = $this->publish();
        self::assertSame(['published' => 2, 'notified' => 2, 'families' => 3, 'already_notified' => 0, 'not_notified' => ['consent_missing' => 1]], $summary);
        self::assertSame([false, false], array_column($this->messenger->of(ReportCardNotifier::TEMPLATE), 'essential'), 'Messenger ordinaire.');
    }

    public function testDroitsEtatDuConseilEtIsolation(): void
    {
        $path = '/secondary/councils/' . self::COUNCIL . '/report-cards/publish';
        self::assertSame(403, $this->call('POST', $path, [], 'prof-n5', self::TA, $this->school)[0]);
        self::assertSame(404, $this->call('POST', '/secondary/councils/conseil-inconnu/report-cards/publish', [], 'chef-n5', self::TA, $this->school)[0]);
        $other = $this->factory->opened(self::TB, 'ces');
        $this->grant(self::TB, 'chef-b', $other, ['secondary.reportcard.validate']);
        self::assertSame(404, $this->call('POST', $path, [], 'chef-b', self::TB, $other)[0], 'Conseil d\'un autre client introuvable.');
        self::assertSame([], $this->service(IssuedReportCards::class)->issuedFor(self::TB, $other, self::COUNCIL));
        self::assertSame([], $this->messenger->sent);

        $this->pdo()->prepare('UPDATE class_councils SET status = ? WHERE id = ?')->execute(['open', self::COUNCIL]);
        self::assertSame(409, $this->call('POST', $path, [], 'chef-n5', self::TA, $this->school)[0], 'Conseil non validé.');
        $this->pdo()->prepare('UPDATE class_councils SET status = ? WHERE id = ?')->execute(['validated', self::COUNCIL]);
        $this->pdo()->prepare('UPDATE report_card_requests SET status = ? WHERE council_id = ?')->execute(['queued', self::COUNCIL]);
        self::assertSame(409, $this->call('POST', $path, [], 'chef-n5', self::TA, $this->school)[0], 'Aucun bulletin émis.');
        self::assertSame([], $this->messenger->sent);
    }

    /** @return array<string, mixed> */
    private function publish(): array
    {
        return $this->ok('POST', '/secondary/councils/' . self::COUNCIL . '/report-cards/publish', [], 'chef-n5', self::TA, $this->school);
    }

    private function council(string $status): void
    {
        $this->pdo()->prepare(
            'INSERT INTO class_councils (id, tenant_id, establishment_id, structure_id, period_id, period_label, status, held_on, rule_version, summary, created_by, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 1, ?, ?, ?)',
        )->execute([self::COUNCIL, self::TA, $this->school, self::CLASS_ID, 'periode-t1', 'Trimestre 1', $status, '{}', 'censeur', '2026-10-20T10:00:00+01:00']);
    }

    private function line(string $personId, float $average, int $rank, ?string $decision): void
    {
        $this->pdo()->prepare(
            'INSERT INTO council_lines (id, tenant_id, establishment_id, council_id, person_id, average, rank_in_class, unjustified_absences, decision) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
        )->execute(['ln-' . $personId, self::TA, $this->school, self::COUNCIL, $personId, $average, $rank, $decision]);
    }

    private function request(string $personId, string $status, ?string $documentId): void
    {
        $this->pdo()->prepare(
            'INSERT INTO report_card_requests (id, tenant_id, establishment_id, council_id, person_id, period_id, status, document_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute(['rq-' . $personId, self::TA, $this->school, self::COUNCIL, $personId, 'periode-t1', $status, $documentId, '2026-10-20T11:00:00+01:00']);
    }
}
