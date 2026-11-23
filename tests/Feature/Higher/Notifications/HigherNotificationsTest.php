<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Notifications;

use Scholaris\Modules\Apps\Higher\Notifications\Application\ReminderRunner;
use Scholaris\Modules\Apps\Higher\Notifications\Contracts\HigherNotifier;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Fixtures\Higher\HigherOrgFixture;
use Tests\Support\Higher\Billing\HigherFinanceTestCase;

/**
 * Notifications NOT-01 à NOT-11 (T238) reçues par le Messenger du socle : destinataires du catalogue, consentement du garant
 * pour les absences (RG-INS-7), dédoublonnage, échec d'envoi sans effet sur l'opération, relances sautées pour un client suspendu.
 */
final class HigherNotificationsTest extends HigherFinanceTestCase
{
    protected function setUp(): void
    {
        $this->bootFinance();
    }

    public function testChaqueNotificationDuCdcArriveAuMessengerAvecSonModele(): void
    {
        $notifier = $this->service(HigherNotifier::class);
        $variables = [
            'NOT-01' => ['full_name' => 'Marie Ngo', 'reference' => 'CAND-0042', 'establishment_name' => 'ISL'],
            'NOT-02' => ['full_name' => 'Marie Ngo', 'reference' => 'CAND-0042', 'label' => 'Relevé du bac'],
            'NOT-03' => ['full_name' => 'Marie Ngo', 'reference' => 'CAND-0042', 'label' => 'Admis', 'link' => 'https://x.cm/d'],
            'NOT-04' => ['full_name' => 'Marie Ngo', 'school_year' => '2026-2027', 'reference' => '26EC-CF-0042'],
            'NOT-05' => ['change_label' => 'Cours déplacé', 'subject' => 'Microéconomie', 'date' => '2026-10-20', 'time' => '08:00'],
            'NOT-06' => ['full_name' => 'Marie Ngo', 'absence_date' => '2026-10-20', 'subject' => 'Microéconomie'],
            'NOT-07' => ['full_name' => 'Marie Ngo', 'subject' => 'Microéconomie', 'details' => '3 sur 12 séances'],
            'NOT-08' => ['label' => 'Pension · tranche 2/3', 'amount' => 120000, 'due_on' => '2027-01-15'],
            'NOT-09' => ['label' => 'Pension · tranche 2/3', 'amount' => 20000, 'due_on' => '2027-01-15'],
            'NOT-10' => ['amount' => 170000, 'receipt_number' => 'R-ISL-26-00001', 'balance' => 240000],
            'NOT-11' => ['subject' => 'Microéconomie', 'details' => 'CC non saisi', 'due_on' => '2027-01-31'],
        ];
        foreach ($variables as $code => $values) {
            $results = $notifier->notify(self::T, self::E, $code, 'marie', $values);
            self::assertSame('sent', $results[0]['status'], $code);
        }
        $keys = array_unique(array_column($this->messenger->sent, 'template'));
        self::assertCount(11, $keys);
        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^higher\.not(0[1-9]|1[01])\.[a-z_]+$/', $key);
        }
        // NOT-04 : étudiant et garant ; NOT-06 sans consentement : étudiant seul.
        self::assertSame(['marie', 'papa'], array_column($this->messenger->of('higher.not04.enrolment_validated'), 'person'));
        self::assertSame(['marie'], array_column($this->messenger->of('higher.not06.absence_recorded'), 'person'));

        $this->consents->give(self::T, 'marie', ConsentChecker::PARENT_ACCESS);
        $notifier->notify(self::T, self::E, 'NOT-07', 'marie', $variables['NOT-07']);
        self::assertSame(['marie', 'marie', 'papa'], array_column($this->messenger->of('higher.not07.attendance_threshold'), 'person'), 'Garant avec consentement (RG-INS-7)');
    }

    public function testDedoublonnageEtEchecDEnvoiSansEffet(): void
    {
        $notifier = $this->service(HigherNotifier::class);
        $notifier->notify(self::T, self::E, 'NOT-10', 'marie', ['amount' => 1000, 'receipt_number' => 'R1', 'balance' => 0], 'payment:p1');
        $again = $notifier->notify(self::T, self::E, 'NOT-10', 'marie', ['amount' => 1000, 'receipt_number' => 'R1', 'balance' => 0], 'payment:p1');
        self::assertSame('duplicate', $again[0]['status']);
        self::assertCount(1, $this->messenger->sent);

        $this->messenger->fail = true;
        $failed = $notifier->notify(self::T, self::E, 'NOT-10', 'marie', ['amount' => 1000, 'receipt_number' => 'R2', 'balance' => 0], 'payment:p2');
        self::assertSame('failed', $failed[0]['status']);

        $this->assign(self::T, 'comptable', self::E, 'messaging.read', 'establishment');
        [, $log] = $this->api('GET', '/higher/notifications/log?person_id=marie', 'comptable', self::T, self::E);
        self::assertSame(['failed', 'sent'], array_column($log['data'], 'delivery_status'));
    }

    public function testCatalogueEtModelesAInstallerDansLaMessagerie(): void
    {
        $this->assign(self::T, 'marie', self::E, 'higher.access', 'establishment');
        [$response, $json] = $this->api('GET', '/higher/notifications/catalog', 'marie', self::T, self::E);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['NOT-01', 'NOT-02', 'NOT-03', 'NOT-04', 'NOT-05', 'NOT-06', 'NOT-07', 'NOT-08', 'NOT-09', 'NOT-10', 'NOT-11'], array_column($json['data'], 'code'));
        self::assertSame(['NOT-08', 'NOT-09', 'NOT-10'], array_column(array_filter($json['data'], static fn (array $n): bool => $n['wired']), 'code'));

        // Les modèles s'installent tels quels par l'API de la messagerie du socle (variables de sa liste blanche).
        $this->assign(self::T, 'admin', self::E, 'messaging.templates.manage', 'establishment');
        foreach ($json['data'] as $notification) {
            foreach ($notification['templates'] as $template) {
                [$created] = $this->api('POST', '/message-templates', 'admin', self::T, self::E, array_filter($template, static fn ($v): bool => $v !== null));
                self::assertSame(201, $created->getStatusCode(), $notification['code'] . ' ' . $template['channel'] . ' ' . $template['locale'] . ' : ' . $created->getBody());
            }
        }

        [$denied] = $this->api('GET', '/higher/notifications/catalog', 'yann', self::T, self::E);
        self::assertSame(403, $denied->getStatusCode());
    }

    public function testLesRelancesPlanifieesSautentUnClientSuspenduEtNeRelancentPasUneTrancheSoldee(): void
    {
        $this->seedFeeCatalog();
        $this->cdcGrid();
        $account = $this->openAccount();
        $this->clock->set('2026-10-10T10:00:00+01:00');
        $this->pay($account['id'], 'comptable', 200000, 'transfer', 'reminder-transfer-1');

        $runner = $this->service(ReminderRunner::class);
        // J+1 de la tranche 1 (soldée) : aucune relance.
        self::assertSame(0, $runner->runAll('2026-10-16'));

        $this->applications->set(HigherOrgFixture::TENANT_A, ApplicationAccess::SUSPENDED);
        self::assertSame(0, $runner->runAll('2027-01-08'), 'Client suspendu : sauté');
        $this->applications->set(HigherOrgFixture::TENANT_A, ApplicationAccess::ACTIVE);
        self::assertSame(1, $runner->runAll('2027-01-08'), 'J-7 de la tranche 2');

        [$forbidden] = $this->api('POST', '/higher/notifications/reminders/run', 'caissier', self::T, self::E, []);
        self::assertSame(403, $forbidden->getStatusCode());
    }
}
