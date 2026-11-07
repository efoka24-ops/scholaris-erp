<?php

declare(strict_types=1);

namespace Tests\Support\Assessment;

use DI\Container;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\Authenticator;
use Scholaris\Core\Security\EstablishmentAccess;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\TeachingScope;
use Tests\Support\StubAccess;
use Tests\Support\StubAuthenticator;

/**
 * Base des tests du module Assessment : Kernel de test (SQLite en mémoire migrée) et doublures des contrats des autres
 * modules (personnes, inscriptions, enseignement, assiduité, approbations, paramètres, documents, droits).
 *
 * Utilisateurs prêts : teacher (saisit), censor (saisit, valide, publie), director (approuve les corrections),
 * council (conseil de classe), jury (délibération), reader (lecture), parent (aucun droit du module).
 */
abstract class AssessmentTestCase extends TestCase
{
    protected Kernel $kernel;
    protected StubAccess $access;
    protected Permissions $permissions;
    protected ArraySettings $settings;
    protected InMemoryPeople $people;
    protected InMemoryEnrollments $enrollments;
    protected FixedTeaching $teaching;
    protected FixedActors $actors;
    protected FixedSecondFactor $secondFactor;
    protected FixedAttendance $attendance;
    protected InMemoryApprovals $approvals;
    protected RecordingIssuer $issuer;
    protected RecordingMessenger $messenger;

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $overrides liaisons de conteneur supplémentaires (stratégies, gardes...)
     */
    protected function bootAssessment(array $settings = [], array $overrides = []): void
    {
        $this->permissions = new Permissions();
        $this->settings = new ArraySettings($settings);
        $this->people = new InMemoryPeople();
        $this->enrollments = new InMemoryEnrollments();
        $this->teaching = new FixedTeaching();
        $this->actors = new FixedActors();
        $this->secondFactor = new FixedSecondFactor();
        $this->attendance = new FixedAttendance();
        $this->approvals = new InMemoryApprovals();
        $this->issuer = new RecordingIssuer();
        $this->messenger = new RecordingMessenger();
        $this->access = new StubAccess();

        $this->kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['cors_origins' => [], 'idempotent_paths' => [], 'rate_limits' => []],
        ]), [
            Authenticator::class => new StubAuthenticator(),
            EstablishmentAccess::class => $this->access,
            PermissionChecker::class => $this->permissions,
            Clock::class => new FixedClock(),
            PersonDirectory::class => $this->people,
            EnrollmentDirectory::class => $this->enrollments,
            SettingsReader::class => $this->settings,
            TeachingScope::class => $this->teaching,
            ConsentChecker::class => new AllConsents(),
            ActorDirectory::class => $this->actors,
            RecentSecondFactor::class => $this->secondFactor,
            AttendanceSummaryProvider::class => $this->attendance,
            Approvals::class => $this->approvals,
            DocumentIssuer::class => $this->issuer,
            Messenger::class => $this->messenger,
        ] + $overrides);
        $this->container()->get(Migrator::class)->migrate();

        foreach (['teacher', 'teacher2', 'censor', 'director', 'council', 'jury', 'reader', 'parent'] as $user) {
            $this->access->grant($user, 'e1');
            $this->access->grant($user, 'e2');
        }
        $this->permissions->grant('teacher', 'assessment.enter');
        $this->permissions->grant('teacher2', 'assessment.enter');
        $this->permissions->grant('censor', 'assessment.read', 'assessment.enter', 'assessment.validate', 'assessment.publish');
        $this->permissions->grant('director', 'assessment.read', 'assessment.correction.approve', 'assessment.publish');
        $this->permissions->grant('council', 'assessment.read', 'assessment.council.manage');
        $this->permissions->grant('jury', 'assessment.read', 'assessment.deliberation.manage');
        $this->permissions->grant('reader', 'assessment.read');
    }

    protected function container(): Container
    {
        return $this->kernel->container();
    }

    protected function connections(): ConnectionFactory
    {
        return $this->container()->get(ConnectionFactory::class);
    }

    /** @param array<string, string> $headers */
    protected function call(string $method, string $path, array $headers = [], string $body = ''): ResponseInterface
    {
        $request = new ServerRequest($method, $path, $headers, $body);
        parse_str($request->getUri()->getQuery(), $query);

        return $this->kernel->handle($request->withQueryParams($query));
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function api(string $method, string $path, string $user, ?array $body = null, string $tenant = 't1', string $establishment = 'e1', array $headers = []): array
    {
        $headers += ['Authorization' => 'Bearer ' . $user . ':' . $tenant, 'X-Establishment-Id' => $establishment];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $this->call($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));

        return [$response, $this->decode($response)];
    }

    /** @return array<string, mixed> */
    protected function decode(ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();
        $decoded = $raw !== '' && str_contains($response->getHeaderLine('Content-Type'), 'json') ? json_decode($raw, true) : [];

        return is_array($decoded) ? $decoded : [];
    }

    /** Envoie un fichier brut (import CSV). */
    protected function upload(string $path, string $user, string $content, string $establishment = 'e1'): ResponseInterface
    {
        return $this->call('POST', '/api/v1' . $path, [
            'Authorization' => 'Bearer ' . $user . ':t1', 'X-Establishment-Id' => $establishment, 'Content-Type' => 'text/csv',
        ], $content);
    }

    /**
     * Classe c1 de l'établissement e1 : quatre élèves avec matricule (p1 Ada, p2 Boris, p3 Chloé, p4 Dani), et l'enseignant
     * « teacher » (personne t1) responsable de tous.
     */
    protected function seedClass(string $structure = 'c1', string $establishment = 'e1'): void
    {
        $names = ['p1' => ['Ada', 'Abega'], 'p2' => ['Boris', 'Bello'], 'p3' => ['Chloé', 'Chi'], 'p4' => ['Dani', 'Dikongue']];
        foreach ($names as $id => [$first, $last]) {
            $this->people->add('t1', $id, $first, $last);
            $this->enrollments->add('t1', $id, $establishment, $structure, 'MAT-' . strtoupper($id));
        }
        $this->teaching->classes['t1'] = array_keys($names);
        $this->actors->persons['teacher'] = 't1';
        $this->actors->persons['jury'] = 'm1';
    }

    protected function subject(string $code = 'MATH', string $kind = 'subject', float $coefficient = 2.0, int $credits = 0): string
    {
        [$response, $json] = $this->api('POST', '/subjects', 'censor', ['code' => $code, 'name' => 'Matière ' . $code, 'kind' => $kind, 'coefficient' => $coefficient, 'credits' => $credits]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (string) $json['data']['id'];
    }

    /** @param array<string, mixed> $extra */
    protected function assessment(string $subjectId, string $title = 'Devoir', float $weight = 1.0, float $max = 20.0, array $extra = [], string $user = 'censor'): string
    {
        [$response, $json] = $this->api('POST', '/assessments', $user, [
            'subject_id' => $subjectId, 'structure_id' => 'c1', 'period_id' => 'seq1', 'academic_year_id' => 'ay1', 'kind' => 'devoir',
            'title' => $title, 'weight' => $weight, 'max_score' => $max,
        ] + $extra);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (string) $json['data']['id'];
    }

    /**
     * Saisit une note par l'API (utilisateur « censor » par défaut).
     *
     * @param array<string, mixed> $cell
     * @return array<string, mixed>
     */
    protected function grade(string $assessmentId, string $personId, array $cell, string $user = 'censor'): array
    {
        [$response, $json] = $this->api('POST', '/grades', $user, ['assessment_id' => $assessmentId, 'person_id' => $personId] + $cell);
        self::assertContains($response->getStatusCode(), [200, 201], (string) $response->getBody());

        return $json['data'];
    }

    /**
     * Classe notée : mathématiques (coefficient 2) et français (coefficient 1), un devoir chacun, toutes les notes saisies.
     * Moyennes : p1 15,33 (rang 1), p2 12,00 (2), p4 10,33 (3), p3 7,67 (4).
     *
     * @return array{math: string, french: string, math_devoir: string, french_devoir: string}
     */
    protected function gradedClass(bool $lock = false): array
    {
        $math = $this->subject('MATH', 'subject', 2.0);
        $french = $this->subject('FRAN', 'subject', 1.0);
        $mathDevoir = $this->assessment($math, 'Devoir de maths');
        $frenchDevoir = $this->assessment($french, 'Devoir de français');
        foreach (['p1' => [16, 14], 'p2' => [12, 12], 'p3' => [8, 7], 'p4' => [10, 11]] as $person => [$m, $f]) {
            $this->grade($mathDevoir, $person, ['value' => $m]);
            $this->grade($frenchDevoir, $person, ['value' => $f]);
        }
        if ($lock) {
            $this->lock($mathDevoir);
            $this->lock($frenchDevoir);
        }

        return ['math' => $math, 'french' => $french, 'math_devoir' => $mathDevoir, 'french_devoir' => $frenchDevoir];
    }

    /** Passe l'épreuve à l'état donné (« censor » a les droits du cycle par défaut). */
    protected function moveTo(string $assessmentId, string $state): void
    {
        [$response] = $this->api('POST', '/assessments/' . $assessmentId . '/transition', 'censor', ['to' => $state]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    protected function lock(string $assessmentId): void
    {
        $this->moveTo($assessmentId, 'entry');
        $this->moveTo($assessmentId, 'locked');
    }

    /** @return list<array<string, mixed>> */
    protected function rows(string $sql): array
    {
        return $this->connections()->pdo()->query($sql)->fetchAll();
    }
}
