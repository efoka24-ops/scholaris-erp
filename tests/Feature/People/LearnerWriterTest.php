<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;
use Tests\Support\People\PeopleTestCase;

/** PP9 : création d'un élève avec garants, inscription et clôture (transfert, retrait). RG-ELV-4. SYN-* uniquement. */
final class LearnerWriterTest extends PeopleTestCase
{
    private const LEARNER = ['first_name' => 'Awa', 'last_name' => 'SYN-Mbarga', 'birth_date' => '2018-02-01', 'gender' => 'female'];

    protected function setUp(): void
    {
        $this->bootPeople();
        $this->structures->open = ['s1', 's2'];
    }

    private function writer(): LearnerWriter
    {
        return $this->kernel->container()->get(LearnerWriter::class);
    }

    /** @return array<int|string, mixed> */
    private function enrollmentRow(string $id): array
    {
        $statement = $this->connections()->pdo()->prepare('SELECT * FROM enrollments WHERE id = ?');
        $statement->execute([$id]);

        return (array) $statement->fetch(\PDO::FETCH_ASSOC);
    }

    /** @param list<string> $params */
    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->connections()->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    /**
     * @param list<string> $params
     * @return list<mixed>
     */
    private function column(string $sql, array $params = []): array
    {
        $statement = $this->connections()->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function assertRefused(int $status, callable $call): void
    {
        try {
            $call();
            self::fail('Un refus HTTP ' . $status . ' était attendu.');
        } catch (HttpException $error) {
            self::assertSame($status, $error->status);
        }
    }

    /** @return string identifiant de l'élève créé avec un parent existant et un garant nouveau */
    private function createWithGuardians(): string
    {
        $parent = $this->person('Jean', 'SYN-Mbarga');
        $result = $this->writer()->createLearner('t1', self::LEARNER, [
            ['kind' => 'parent', 'person_id' => (string) $parent['id']],
            ['kind' => 'financial_guarantor', 'identity' => ['first_name' => 'Rose', 'last_name' => 'SYN-Mbarga', 'phone' => '677000222']],
        ], 'admin');

        return $result['person_id'];
    }

    public function testCreationAvecGarantsPuisInscription(): void
    {
        $personId = $this->createWithGuardians();

        self::assertSame('Awa', $this->scalar('SELECT first_name FROM people WHERE id = ?', [$personId]));
        self::assertSame('t1', $this->scalar('SELECT tenant_id FROM people WHERE id = ?', [$personId]));
        self::assertSame(['financial_guarantor', 'parent'], $this->column('SELECT kind FROM person_relations WHERE person_id = ? ORDER BY kind', [$personId]));

        $enrollmentId = $this->writer()->enroll('t1', 'e1', $personId, 's1', 'y2026', '2026-10-01', 'admin');

        $row = $this->enrollmentRow($enrollmentId);
        self::assertSame(['e1', 's1', 'y2026', $personId, '2026-10-01', null], [$row['establishment_id'], $row['structure_id'], $row['academic_year_id'], $row['person_id'], $row['enrolled_on'], $row['closed_on']]);
        self::assertContains($row['status'], ['active', 'conditional']);
        self::assertNotNull($row['internal_number']);

        // Idempotent : la même inscription vivante est reprise, sa date d'entrée n'est pas réécrite.
        self::assertSame($enrollmentId, $this->writer()->enroll('t1', 'e1', $personId, 's1', 'y2026', '2026-10-15', 'admin'));
        self::assertSame('2026-10-01', $this->enrollmentRow($enrollmentId)['enrolled_on']);
    }

    public function testValidationDuDossierEtDesGarants(): void
    {
        $this->assertRefused(422, fn () => $this->writer()->createLearner('t1', ['last_name' => 'SYN-X'], [], null));
        $this->assertRefused(422, fn () => $this->writer()->createLearner('t1', self::LEARNER, [['kind' => 'cousin', 'identity' => ['first_name' => 'A', 'last_name' => 'B']]], null));
        $this->assertRefused(422, fn () => $this->writer()->createLearner('t1', self::LEARNER, [['kind' => 'parent']], null));
        $this->assertRefused(422, fn () => $this->writer()->createLearner('t1', self::LEARNER, [['kind' => 'parent', 'person_id' => '01J9Z6Q0A1B2C3D4E5F6G7H8J9']], null));
        // Un échec laisse le client intact (rien d'écrit à moitié).
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM people'));

        // Mineur sans garant : l'inscription est refusée comme par l'API.
        $alone = $this->writer()->createLearner('t1', self::LEARNER, [], null)['person_id'];
        $this->assertRefused(422, fn () => $this->writer()->enroll('t1', 'e1', $alone, 's1', 'y2026', '2026-10-01', null));
        $this->assertRefused(422, fn () => $this->writer()->enroll('t1', 'e1', $alone, 's1', 'y2026', 'hier', null));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM enrollments'), 'Aucun brouillon laissé derrière.');
    }

    public function testDoublonSignaleSaufConfirmation(): void
    {
        $this->person('Awa', 'SYN-Mbarga', ['birth_date' => '2018-02-01']);

        $this->assertRefused(409, fn () => $this->writer()->createLearner('t1', self::LEARNER, [], null));
        $created = $this->writer()->createLearner('t1', self::LEARNER, [], null, true);

        self::assertNotSame('', $created['person_id']);
    }

    public function testSecondeInscriptionActiveDansUnAutreEtablissementRefusee(): void
    {
        $personId = $this->createWithGuardians();
        $first = $this->writer()->enroll('t1', 'e1', $personId, 's1', 'y2026', '2026-10-01', 'admin');

        $this->assertRefused(409, fn () => $this->writer()->enroll('t1', 'e2', $personId, 's2', 'y2026', '2026-11-02', 'admin'));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM enrollments WHERE establishment_id = 'e2'"));

        // Un transfert clôt l'inscription d'origine à la date d'accueil, puis l'accueil peut inscrire (RG-ELV-4).
        $this->writer()->closeEnrollment('t1', $first, 'transferred', '2026-11-02');
        $closed = $this->enrollmentRow($first);
        self::assertSame(['transferred', '2026-11-02'], [$closed['status'], $closed['closed_on']]);
        self::assertSame('transferred', $this->scalar('SELECT outcome FROM schooling_history WHERE enrollment_id = ?', [$first]));

        $second = $this->writer()->enroll('t1', 'e2', $personId, 's2', 'y2026', '2026-11-02', 'admin');
        self::assertNotSame($first, $second);
        self::assertSame('e2', $this->enrollmentRow($second)['establishment_id']);
    }

    public function testClotureRetraitStatutsEtIsolation(): void
    {
        $personId = $this->createWithGuardians();
        $enrollment = $this->writer()->enroll('t1', 'e1', $personId, 's1', 'y2026', '2026-10-01', 'admin');
        $this->connections()->pdo()->exec("UPDATE enrollments SET status = 'active' WHERE id = '" . $enrollment . "'");

        $this->assertRefused(422, fn () => $this->writer()->closeEnrollment('t1', $enrollment, 'excluded', '2026-11-02'));
        $this->assertRefused(422, fn () => $this->writer()->closeEnrollment('t1', $enrollment, 'withdrawn', '02/11/2026'));
        $this->assertRefused(404, fn () => $this->writer()->closeEnrollment('t2', $enrollment, 'withdrawn', '2026-11-02'));
        self::assertSame('active', $this->enrollmentRow($enrollment)['status']);

        $this->writer()->closeEnrollment('t1', $enrollment, 'withdrawn', '2026-11-02');
        self::assertSame(['withdrawn', '2026-11-02'], [$this->enrollmentRow($enrollment)['status'], $this->enrollmentRow($enrollment)['closed_on']]);
        $this->assertRefused(409, fn () => $this->writer()->closeEnrollment('t1', $enrollment, 'transferred', '2026-11-03'));
    }
}
