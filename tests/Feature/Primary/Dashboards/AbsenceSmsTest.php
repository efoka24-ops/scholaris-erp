<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\Dashboards;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\Primary\Dashboards\Application\AbsenceAlerts;
use Scholaris\Modules\Socle\Assessment\Contracts\ClassResults;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateRenderer;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Billing\RecordingMessenger;
use Tests\Support\Primary\FakeClassResults;
use Tests\Support\Primary\FakePresence;
use Tests\Support\Primary\PrimaryTestCase;

/**
 * C-104 : SMS aux parents pour absences répétées (NOT-PRI-10), envoyé par `primary:alerts` à la création de l'alerte donc une seule
 * fois par série, désactivable par l'école (`primary.alert.sms`) ; une panne de passerelle n'empêche pas la détection. Données fictives.
 */
final class AbsenceSmsTest extends PrimaryTestCase
{
    private FakePresence $presence;
    private RecordingMessenger $messenger;
    private string $class;

    protected function setUp(): void
    {
        $enrollments = new InMemoryEnrollments();
        $people = new InMemoryPeople();
        $this->presence = new FakePresence();
        $this->messenger = new RecordingMessenger();
        $this->bootPrimary([
            EnrollmentDirectory::class => $enrollments, PersonDirectory::class => $people, DailyPresence::class => $this->presence,
            ClassResults::class => new FakeClassResults(), Messenger::class => $this->messenger,
        ], '2026-10-21');
        $this->seedYear(self::TA, self::EA);
        $level = $this->makeLevel(self::TA, self::EA, 'CE1', 1);
        $this->ok('POST', '/primary/level-profiles', ['level_structure_id' => $level['level'], 'sort_order' => 1]);
        $this->class = $level['classes'][0];
        foreach (['a1', 'a2'] as $id) {
            $people->add(self::TA, $id, 'Prénom', 'Nom' . $id);
            $people->add(self::TA, 'p-' . $id, 'Parent', 'Nom' . $id, '+237600000001');
            $people->link(self::TA, 'p-' . $id, $id);
            $enrollments->add(self::TA, 'en' . $id, $id, self::EA, $this->class, self::YEAR);
        }
        // Lundi 19 et mardi 20 octobre 2026 : quatre demi-journées consécutives pour a1.
        foreach (['2026-10-19', '2026-10-20'] as $day) {
            foreach (['morning', 'afternoon'] as $part) {
                $this->presence->set(self::TA, self::EA, $day, $this->class, $part, ['a1']);
            }
        }
    }

    public function testUnSmsParSerieAuxResponsablesDeLEleve(): void
    {
        $alerts = $this->service(AbsenceAlerts::class);

        self::assertSame(1, $alerts->run('2026-10-20')['alerts']);
        self::assertSame(1, $alerts->smsSent);
        $sent = $this->messenger->of('pri.not10.absence_streak');
        self::assertCount(1, $sent);
        self::assertSame('p-a1', $sent[0]['person'], 'Le responsable de a1, pas celui de a2.');
        self::assertSame(['date' => '2026-10-19', 'details' => '4', 'establishment_name' => 'École A'], array_intersect_key($sent[0]['variables'], array_flip(['date', 'details', 'establishment_name'])));
        self::assertNotSame('', (string) $sent[0]['variables']['child_name']);

        self::assertSame(0, $alerts->run('2026-10-20')['alerts'], 'Même série : pas de nouvelle alerte.');
        self::assertSame(0, $alerts->smsSent, 'Et pas de nouveau SMS.');
        self::assertCount(1, $this->messenger->of('pri.not10.absence_streak'));
    }

    public function testLEcoleDesactiveLeSmsSansPerdreLAlerte(): void
    {
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'primary.alert.sms', false, self::DIRECTOR);
        $alerts = $this->service(AbsenceAlerts::class);

        self::assertSame(1, $alerts->run('2026-10-20')['alerts'], 'L\'alerte du tableau de bord reste.');
        self::assertSame([], $this->messenger->sent);
        self::assertSame(0, $alerts->smsSent);
    }

    public function testUnePanneDePasserelleNEmpecheNiLaDetectionNiLesAlertes(): void
    {
        $this->messenger->fail = true;
        $alerts = $this->service(AbsenceAlerts::class);

        self::assertSame(1, $alerts->run('2026-10-20')['alerts']);
        self::assertSame(0, $alerts->smsSent);
        self::assertCount(1, $this->ok('GET', '/primary/alerts')['items']);
    }

    public function testLeModeleExisteEnFrancaisEtEnAnglaisAvecDesVariablesAutorisees(): void
    {
        $pdo = $this->kernel->container()->get(ConnectionFactory::class)->pdo();
        $rows = $pdo->query("SELECT locale, body, variables FROM message_templates WHERE template_key = 'pri.not10.absence_streak' ORDER BY locale")->fetchAll(\PDO::FETCH_ASSOC);

        self::assertSame(['en', 'fr'], array_column($rows, 'locale'));
        foreach ($rows as $row) {
            self::assertSame([], array_values(array_diff(TemplateRenderer::placeholders((string) $row['body']), TemplateRenderer::ALLOWED)), $row['locale']);
            self::assertSame([], array_values(array_diff(json_decode((string) $row['variables'], true), TemplateRenderer::ALLOWED)));
        }
    }
}
