<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Notifications;

use Scholaris\Modules\Apps\Secondary\Notifications\Application\FamilyNotifier;
use Scholaris\Modules\Apps\Secondary\Notifications\Contracts\FamilyNotifications;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Billing\RecordingMessenger;
use Tests\Support\Secondary\SecondaryTestCase;

/** NOT-01 et NOT-09 : modèles du secondaire, garants de l'élève, une seule fois par événement et destinataire, échec sans effet. */
final class FamilyNotificationsTest extends SecondaryTestCase
{
    private RecordingMessenger $messenger;
    private InMemoryPeople $people;
    private InMemoryEnrollments $enrollments;
    private string $school;

    protected function setUp(): void
    {
        $this->messenger = new RecordingMessenger();
        $this->people = new InMemoryPeople();
        $this->enrollments = new InMemoryEnrollments();
        $this->bootSecondary([Messenger::class => $this->messenger, PersonDirectory::class => $this->people, EnrollmentDirectory::class => $this->enrollments]);
        $this->school = $this->factory->opened(self::TA, 'ces');
        $class = $this->service(StructureWriter::class)->createStructure(self::TA, $this->school, new StructureDraft(StructureDraft::SCHOOL_CLASS, '6e A'));
        $this->people->add(self::TA, 'eleve-1', 'Aïcha', 'Exemple');
        $this->people->add(self::TA, 'parent-1', 'Mariam', 'Exemple', '+237600000001');
        $this->people->add(self::TA, 'parent-2', 'Joseph', 'Exemple', '+237600000002');
        $this->people->link(self::TA, 'parent-1', 'eleve-1');
        $this->people->link(self::TA, 'parent-2', 'eleve-1');
        $this->enrollments->add(self::TA, 'ins-1', 'eleve-1', $this->school, $class, 'ay-2026');
    }

    public function testNot01InscriptionValideeUneSeuleFoisParGarant(): void
    {
        $notifier = $this->service(FamilyNotifications::class);
        self::assertSame(2, $notifier->enrolmentValidated(self::TA, $this->school, 'ins-1'));
        self::assertSame(0, $notifier->enrolmentValidated(self::TA, $this->school, 'ins-1'), 'Rejeu : aucun doublon.');
        $sent = $this->messenger->of(FamilyNotifier::TEMPLATE_NOT01);
        self::assertSame(['parent-1', 'parent-2'], array_column($sent, 'person'));
        self::assertSame('Aïcha Exemple', $sent[0]['variables']['child_name']);
        self::assertSame('6e A', $sent[0]['variables']['class_name']);
        self::assertSame(['child_name', 'class_name', 'school_year', 'establishment_name'], array_keys($sent[0]['variables']));
        self::assertSame(0, $notifier->enrolmentValidated(self::TA, $this->school, 'inconnue'));
        self::assertSame(0, $notifier->enrolmentValidated(self::TB, $this->school, 'ins-1'), 'Autre client : rien.');
    }

    public function testNot09PaiementRecuEtEchecDEnvoiSansEffet(): void
    {
        $notifier = $this->service(FamilyNotifications::class);
        $this->messenger->fail = true;
        self::assertSame(0, $notifier->paymentReceived(self::TA, $this->school, 'eleve-1', 'REC-0001', 120000));
        $this->messenger->fail = false;
        self::assertSame(2, $notifier->paymentReceived(self::TA, $this->school, 'eleve-1', 'REC-0001', 120000), 'Un échec n\'est pas journalisé : nouvel essai possible.');
        self::assertSame(0, $notifier->paymentReceived(self::TA, $this->school, 'eleve-1', 'REC-0001', 120000));
        $sent = $this->messenger->of(FamilyNotifier::TEMPLATE_NOT09);
        self::assertSame('120 000', $sent[0]['variables']['amount']);
        self::assertSame(['receipt_number', 'amount', 'child_name', 'establishment_name'], array_keys($sent[0]['variables']));
    }

    public function testLesVariablesCorrespondentAuxModelesDuSocle(): void
    {
        foreach ([FamilyNotifier::TEMPLATE_NOT01, FamilyNotifier::TEMPLATE_NOT09] as $key) {
            $rows = $this->rows('SELECT locale, variables FROM message_templates WHERE template_key = ?', [$key]);
            self::assertCount(2, $rows, $key . ' FR et EN');
        }
    }

    /**
     * @param list<string> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll(\PDO::FETCH_ASSOC));
    }
}
