<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Services;

use Scholaris\Modules\Apps\Secondary\Services\Infrastructure\HealthCipher;
use Scholaris\Modules\Apps\Secondary\Setup\Application\ModuleSwitch;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * FIX-AFF : le journal des consultations de l'infirmerie donne le nom de l'auteur de la consultation et celui de l'élève,
 * pas seulement leurs identifiants techniques ; un compte sans personne liée garde un nom null. Données fictives.
 */
final class InfirmaryAccessLogNamesTest extends SecondaryTestCase
{
    private const NURSE = 'infirmier-a';
    private const HEAD = 'chef-a';

    private string $school;

    protected function setUp(): void
    {
        $enrollments = new InMemoryEnrollments();
        $people = new InMemoryPeople();
        $people->add(self::TA, 'eleve-1', 'Awa', 'Fictive');
        $people->add(self::TA, 'pers-infirmier', 'Paul', 'Soignant');
        $links = new class () implements UserPersonLink {
            public function personIdForUser(string $tenantId, string $userId): ?string
            {
                return $userId === 'infirmier-a' ? 'pers-infirmier' : null;
            }
        };
        $this->bootSecondary([
            EnrollmentDirectory::class => $enrollments,
            PersonDirectory::class => $people,
            UserPersonLink::class => $links,
            HealthCipher::class => new HealthCipher('cle-de-test-fictive'),
        ]);
        $this->school = $this->factory->opened(self::TA, 'lycee_prive');
        $this->service(ModuleSwitch::class)->enable(self::TA, $this->school, 'infirmary', 'Ouverture de l\'infirmerie', 'admin');
        $enrollments->add(self::TA, 'ins-1', 'eleve-1', $this->school, 'classe-1');
        $this->grant(self::TA, self::NURSE, $this->school, ['secondary.infirmary.manage']);
        $this->grant(self::TA, self::HEAD, $this->school, ['secondary.settings.manage']);
    }

    public function testLeJournalDonneLesNomsDeLAuteurEtDeLEleve(): void
    {
        $this->ok('PUT', '/secondary/services/infirmary/records/eleve-1', ['allergies' => 'Arachide (fictif)'], self::NURSE, self::TA, $this->school);
        $this->ok('GET', '/secondary/services/infirmary/records/eleve-1', null, self::NURSE, self::TA, $this->school);

        [$status, $log] = $this->call('GET', '/secondary/services/infirmary/access-log', null, self::HEAD, self::TA, $this->school);
        self::assertSame(200, $status);
        self::assertNotEmpty($log['data']);
        foreach ($log['data'] as $line) {
            self::assertSame('Paul Soignant', $line['user_name']);
            self::assertSame(self::NURSE, $line['user_id']);
            if ($line['person_id'] !== null) {
                self::assertSame('Awa Fictive', $line['student_name']);
            }
        }
        self::assertStringNotContainsString('Arachide', json_encode($log, JSON_THROW_ON_ERROR));
    }
}
