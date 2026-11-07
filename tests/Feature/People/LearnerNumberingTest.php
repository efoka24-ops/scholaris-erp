<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\People\Contracts\DuplicateCriteriaProvider;
use Scholaris\Modules\Socle\People\Contracts\LearnerNumbering;
use Scholaris\Modules\Socle\People\Contracts\LearnerNumberProvider;
use Tests\Support\People\PeopleTestCase;

/** PP8 : identifiant d'élève attribué une seule fois, fournisseurs d'application, critères de doublon d'application. SYN-* uniquement. */
final class LearnerNumberingTest extends PeopleTestCase
{
    private SyntheticLearnerNumbers $numbers;
    private SyntheticDuplicateCriteria $criteria;

    protected function setUp(): void
    {
        $this->numbers = new SyntheticLearnerNumbers();
        $this->criteria = new SyntheticDuplicateCriteria();
        $this->bootPeople([
            'people.learner_number.providers.test' => [$this->numbers],
            'people.duplicate_criteria.test' => [$this->criteria],
        ]);
    }

    private function numbering(): LearnerNumbering
    {
        return $this->kernel->container()->get(LearnerNumbering::class);
    }

    private function numberInDb(string $personId): ?string
    {
        $statement = $this->connections()->pdo()->prepare('SELECT learner_number FROM people WHERE id = ?');
        $statement->execute([$personId]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function testIdentifiantAttribueUneSeuleFois(): void
    {
        $person = $this->person('Awa', 'SYN-Mbarga');

        self::assertNull($this->numbering()->find('t1', (string) $person['id']));
        $number = $this->numbering()->assign('t1', (string) $person['id'], 'e1');

        self::assertSame('SYN-LRN-0001', $number);
        self::assertSame('SYN-LRN-0001', $this->numbering()->find('t1', (string) $person['id']));
        self::assertSame('SYN-LRN-0001', $this->numberInDb((string) $person['id']));
        self::assertSame(1, $this->numbers->calls);
    }

    public function testSecondeAttributionRefuseeEtValeurInchangee(): void
    {
        $person = $this->person('Awa', 'SYN-Mbarga');
        $this->numbering()->assign('t1', (string) $person['id'], 'e1');

        try {
            $this->numbering()->assign('t1', (string) $person['id'], 'e1');
            self::fail('La seconde attribution doit être refusée.');
        } catch (HttpException $error) {
            self::assertSame(409, $error->status);
        }

        self::assertSame('SYN-LRN-0001', $this->numberInDb((string) $person['id']));
        self::assertSame(1, $this->numbers->calls, 'Le fournisseur n\'est pas sollicité pour une personne déjà numérotée.');
    }

    public function testLIdentifiantNeSeModifiePasParLApiDesPersonnes(): void
    {
        $person = $this->person('Awa', 'SYN-Mbarga');
        $this->numbering()->assign('t1', (string) $person['id'], 'e1');

        $this->api('PATCH', '/people/' . $person['id'], body: ['learner_number' => 'SYN-PIRATE', 'phone' => '677000111']);

        self::assertSame('SYN-LRN-0001', $this->numberInDb((string) $person['id']));
    }

    public function testSansFournisseurConcerneLAttributionEstRefusee(): void
    {
        $person = $this->person('Awa', 'SYN-Mbarga');
        $this->numbers->supports = false;

        try {
            $this->numbering()->assign('t1', (string) $person['id'], 'e1');
            self::fail('Sans fournisseur concerné, aucun identifiant ne doit être inventé.');
        } catch (HttpException $error) {
            self::assertSame(422, $error->status);
        }
        self::assertNull($this->numberInDb((string) $person['id']));
    }

    public function testUniciteParClientEtIsolation(): void
    {
        $a = $this->person('Awa', 'SYN-Mbarga');
        $b = $this->person('Bilo', 'SYN-Nkoa');
        $other = $this->person('Chi', 'SYN-Tchoua', [], 't2');
        $this->numbers->fixed = 'SYN-LRN-FIXE';

        $this->numbering()->assign('t1', (string) $a['id'], 'e1');
        try {
            $this->numbering()->assign('t1', (string) $b['id'], 'e1');
            self::fail('Un même identifiant ne peut pas être attribué à deux personnes d\'un client.');
        } catch (HttpException $error) {
            self::assertSame(409, $error->status);
        }
        self::assertNull($this->numberInDb((string) $b['id']));

        // Un autre client peut avoir le même identifiant ; une personne d'un autre client reste introuvable.
        self::assertSame('SYN-LRN-FIXE', $this->numbering()->assign('t2', (string) $other['id'], 'e1'));
        try {
            $this->numbering()->assign('t1', (string) $other['id'], 'e1');
            self::fail('Personne d\'un autre client.');
        } catch (HttpException $error) {
            self::assertSame(404, $error->status);
        }
        self::assertNull($this->numbering()->find('t1', (string) $other['id']));
    }

    public function testCritereDeDoublonDApplicationAvecCandidatsEtRestriction(): void
    {
        $existing = $this->person('Awa', 'SYN-Mbarga', ['birth_date' => '2018-02-01']);
        $service = $this->kernel->container()->get(PersonService::class);
        $input = ['first_name' => 'Awa', 'last_name' => 'SYN-Mbarga'];

        // Sans fournisseur actif : identité sans date ni téléphone, aucun candidat (comportement du socle).
        $without = $service->duplicates('t1', $input);
        self::assertCount(0, $without);

        // « Parent commun » : candidat et critère fournis par l'application.
        $this->criteria->candidates = [(string) $existing['id']];
        $this->criteria->extra = ['common_guardian'];
        $found = $service->duplicates('t1', $input);
        self::assertCount(1, $found);
        self::assertSame($existing['id'], $found[0]['person']['id']);
        self::assertSame(['name', 'common_guardian'], $found[0]['criteria']);

        // Sans critère concordant supplémentaire, le nom seul ne suffit pas.
        $this->criteria->extra = [];
        $nameOnly = $service->duplicates('t1', $input);
        self::assertCount(0, $nameOnly);

        // Restriction aux établissements donnés : la personne doit y être inscrite.
        $this->criteria->extra = ['common_guardian'];
        $this->criteria->scope = ['e-syn-autre'];
        $otherScope = $service->duplicates('t1', $input);
        self::assertCount(0, $otherScope);
        $this->criteria->scope = ['e1'];
        $notEnrolled = $service->duplicates('t1', $input);
        self::assertCount(0, $notEnrolled, 'Aucune inscription dans e1.');
        $this->enrolInEstablishment((string) $existing['id'], 'e1');
        $enrolled = $service->duplicates('t1', $input);
        self::assertCount(1, $enrolled);
    }

    private function enrolInEstablishment(string $personId, string $establishmentId): void
    {
        $this->connections()->pdo()->prepare(
            'INSERT INTO enrollments (id, tenant_id, person_id, establishment_id, structure_id, academic_year_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, ?)',
        )->execute(['01J9Z6Q0A1B2C3D4E5F6G7H8J5', 't1', $personId, $establishmentId, 'validated', '2026-10-08T09:00:00+01:00', '2026-10-08T09:00:00+01:00']);
    }
}

/** Fournisseur d'identifiants SYN-LRN-NNNN pour les tests. */
final class SyntheticLearnerNumbers implements LearnerNumberProvider
{
    public bool $supports = true;
    public ?string $fixed = null;
    public int $calls = 0;

    public function supports(string $tenantId, string $establishmentId, string $personId): bool
    {
        return $this->supports;
    }

    public function next(string $tenantId, string $establishmentId, string $personId): string
    {
        ++$this->calls;

        return $this->fixed ?? sprintf('SYN-LRN-%04d', $this->calls);
    }
}

/** Critères de doublon d'une application fictive. */
final class SyntheticDuplicateCriteria implements DuplicateCriteriaProvider
{
    /** @var list<string>|null */
    public ?array $scope = null;
    /** @var list<string> */
    public array $candidates = [];
    /** @var list<string> */
    public array $extra = [];

    public function establishmentScope(string $tenantId): ?array
    {
        return $this->scope;
    }

    public function candidateIds(string $tenantId, array $input): array
    {
        return $this->candidates;
    }

    public function extraCriteria(string $tenantId, array $input, array $existing): array
    {
        return $this->extra;
    }
}
