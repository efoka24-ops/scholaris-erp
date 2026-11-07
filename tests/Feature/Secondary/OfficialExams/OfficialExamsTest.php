<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\OfficialExams;

use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\ExamFeeStatus;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIds;
use Scholaris\Modules\Socle\Assessment\Contracts\ClassResults;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\MediaRef;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Assessment\InMemoryPeople;
use Tests\Support\Secondary\SecondaryTestCase;

final class OfficialExamsTest extends SecondaryTestCase
{
    private const YEAR = 'annee-2026';
    private const MANAGE = ['secondary.profile.manage', 'secondary.settings.manage', 'secondary.profile.read'];
    private const EXAM_MANAGE = ['secondary.exams.manage', 'secondary.exams.lists', 'secondary.exams.read', 'secondary.exams.validate'];

    private InMemoryPeople $people;
    private ExamEnrollments $enrollments;
    private string $school = '';
    private string $classId = '';
    /** @var array<string, string> */
    private array $nationalIds = [];
    /** @var array<string, bool> */
    private array $paid = [];
    /** @var array<string, array{namespace: string, owner: string, content: string, mime: string}> */
    private array $mediaItems = [];

    protected function setUp(): void
    {
        $this->people = new InMemoryPeople();
        $this->enrollments = new ExamEnrollments();
        $nationalIds = &$this->nationalIds;
        $paid = &$this->paid;
        $this->bootSecondary([
            PersonDirectory::class => $this->people,
            EnrollmentDirectory::class => $this->enrollments,
            NationalIds::class => new class ($nationalIds) implements NationalIds {
                /** @param array<string, string> $items */
                public function __construct(private array &$items)
                {
                }
                public function of(string $tenantId, string $establishmentId, string $personId): ?string
                {
                    return $this->items[$personId] ?? null;
                }
                public function statusOf(string $tenantId, string $establishmentId, string $personId): string
                {
                    return isset($this->items[$personId]) ? 'recorded' : 'pending';
                }
                public function personIdByNationalId(string $tenantId, string $establishmentId, string $nationalId): ?string
                {
                    return array_search($nationalId, $this->items, true) ?: null;
                }
                public function normalize(string $tenantId, string $establishmentId, string $nationalId): ?string
                {
                    return strtoupper(trim($nationalId));
                }
                public function record(string $tenantId, string $establishmentId, string $personId, string $nationalId, string $origin, string $actorId): string
                {
                    return $this->items[$personId] = strtoupper(trim($nationalId));
                }
            },
            ExamFeeStatus::class => new class ($paid) implements ExamFeeStatus {
                /** @param array<string, bool> $items */
                public function __construct(private array &$items)
                {
                }
                public function seen(string $tenantId, string $establishmentId, string $personId, ?string $examCode = null): bool
                {
                    return $this->items[$personId] ?? false;
                }
            },
            MediaStore::class => new class ($this->mediaItems) implements MediaStore {
                /** @param array<string, array{namespace: string, owner: string, content: string, mime: string}> $items */
                public function __construct(private array &$items)
                {
                }
                public function put(string $tenantId, string $namespace, string $ownerId, string $content): MediaRef
                {
                    $ref = MediaRef::compose($namespace, $ownerId, str_repeat('0', 26) . '.jpg');
                    $this->items[$ref->value] = ['namespace' => $namespace, 'owner' => $ownerId, 'content' => $content, 'mime' => 'image/jpeg'];
                    return $ref;
                }
                public function signedUrl(string $tenantId, MediaRef $ref, int $ttl, string $route): string
                {
                    return $route . '?tenant=' . $tenantId . '&ref=' . $ref->value . '&expires=1999999999&signature=test';
                }
                public function readSigned(string $tenantId, MediaRef $ref, int $expires, string $signature): \Scholaris\Modules\Socle\People\Contracts\MediaContent
                {
                    $item = $this->items[$ref->value];
                    return new \Scholaris\Modules\Socle\People\Contracts\MediaContent($item['content'], $item['mime'], 'jpg', strlen($item['content']));
                }
                public function delete(string $tenantId, MediaRef $ref): void
                {
                    unset($this->items[$ref->value]);
                }
            },
            ClassResults::class => new class () implements ClassResults {
                public function compute(string $tenantId, string $establishmentId, string $structureId, string $periodId, ?string $periodLabel = null): array
                {
                    return [['person_id' => 'el-1', 'average' => 12.0, 'rank' => 1, 'class_size' => 1, 'mention' => null, 'rule_version' => null, 'subjects' => []]];
                }
            },
        ]);
        $this->school = $this->factory->opened(self::TA, 'leg');
        $this->grant(self::TA, self::ADMIN, $this->school, array_merge(self::MANAGE, self::EXAM_MANAGE));
        $this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $this->school);
        $levels = array_column($this->ok('GET', '/secondary/setup/levels', null, self::ADMIN, self::TA, $this->school), null, 'code');
        $this->classId = (string) $this->ok('POST', '/secondary/setup/classes', ['label' => '3e A', 'level_id' => $levels['3E']['id'], 'academic_year_id' => self::YEAR], self::ADMIN, self::TA, $this->school)['structure_id'];
        foreach (['el-1' => ['Aïcha', 'Mballa'], 'el-2' => ['Brice', 'Ndi']] as $id => [$first, $last]) {
            $this->people->add(self::TA, $id, $first, $last);
            $this->enrollments->add(self::TA, $id, $this->school, $this->classId, self::YEAR);
            $this->nationalIds[$id] = 'MAT-' . strtoupper($id);
            $this->paid[$id] = $id === 'el-1';
        }
    }

    public function testFeeNonConstatesBloqueSansExclusionPourDetteEtGenerationLimiteeAuxInscrits(): void
    {
        $session = $this->session();
        $generated = $this->ok('POST', '/secondary/official-exams/' . $session['id'] . '/candidates/generate', [], self::ADMIN, self::TA, $this->school);
        self::assertSame(2, $generated['added']);
        $candidateRows = $this->ok('GET', '/secondary/official-exams/' . $session['id'] . '/candidates', null, self::ADMIN, self::TA, $this->school);
        self::assertSame(['el-1', 'el-2'], array_column($candidateRows, 'person_id'));
        self::assertSame(['incomplete', 'blocked'], array_column($candidateRows, 'status'));
        self::assertSame('frais non constatés', $candidateRows[1]['status_message']);
        self::assertSame('not_seen', $candidateRows[1]['fee_status']);
        foreach ($this->kernel->routes()->all() as $route) {
            if (!str_contains($route['path'], '/official-exams')) {
                continue;
            }
            self::assertStringNotContainsString('exclude', strtolower($route['path']));
            self::assertStringNotContainsString('debt', strtolower($route['path']));
        }
    }

    public function testResultImportSummaryGenericExportAndSchoolAverageComparison(): void
    {
        $session = $this->session([
            'passing_codes' => ['PASS'], 'comparison_period_id' => 'period-1',
            'export_columns' => [['field' => 'person_id', 'header' => 'candidate_key'], ['field' => 'result_code', 'header' => 'outcome']],
        ]);
        $this->ok('POST', '/secondary/official-exams/' . $session['id'] . '/candidates/generate', [], self::ADMIN, self::TA, $this->school);
        $csv = "key,result_code,result_mark\nel-1,PASS,14\nMAT-EL-2,FAIL,8\n";
        $result = $this->ok('POST', '/secondary/official-exams/' . $session['id'] . '/results/import', ['csv' => $csv], self::ADMIN, self::TA, $this->school);
        self::assertSame(2, $result['imported']);
        self::assertSame(50, $result['summary']['success_rate']);
        self::assertSame(12, $result['summary']['comparison']['school_average']);
        self::assertSame(11, $result['summary']['comparison']['exam_average']);
        $response = $this->raw('GET', '/secondary/official-exams/' . $session['id'] . '/candidates/export', null, self::ADMIN, self::TA, $this->school);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('candidate_key', (string) $response->getBody());
        self::assertStringContainsString('outcome', (string) $response->getBody());
        self::assertStringContainsString('PASS', (string) $response->getBody());
        self::assertSame(422, $this->call('POST', '/secondary/official-exams/' . $session['id'] . '/results/import', ['csv' => "key,result_code,result_mark\nel-1,PASS,not-a-mark\n"], self::ADMIN, self::TA, $this->school)[0]);
    }

    public function testCandidatePhotoUsesPrivateSignedMediaRoute(): void
    {
        $session = $this->session();
        $this->ok('POST', '/secondary/official-exams/' . $session['id'] . '/candidates/generate', [], self::ADMIN, self::TA, $this->school);
        $candidates = $this->ok('GET', '/secondary/official-exams/' . $session['id'] . '/candidates', null, self::ADMIN, self::TA, $this->school);
        $candidate = $candidates[0];
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';
        $updated = $this->ok('POST', '/secondary/official-exams/candidates/' . $candidate['id'] . '/photo', ['photo_base64' => $png], self::ADMIN, self::TA, $this->school);
        self::assertNotNull($updated['photo_url']);

        $path = (string) parse_url($updated['photo_url'], PHP_URL_PATH);
        $query = (string) parse_url($updated['photo_url'], PHP_URL_QUERY);
        $response = $this->raw('GET', substr($path, strlen('/api/v1')) . '?' . $query, null, self::ADMIN, self::TA, $this->school);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testRoutesGatedAndTenantEstablishmentIsolation(): void
    {
        $session = $this->session();
        $other = $this->factory->opened(self::TB, 'leg');
        $this->grant(self::TB, 'admin-b', $other, self::EXAM_MANAGE);
        self::assertSame(404, $this->call('GET', '/secondary/official-exams/' . $session['id'], null, 'admin-b', self::TB, $other)[0]);
        self::assertSame(403, $this->call('GET', '/secondary/official-exams', null, 'no-permission', self::TA, $this->school)[0]);
        $inactive = $this->factory->opened(self::TB, 'autre', null, 'inactive-module-school');
        $this->grant(self::TB, 'inactive-user', $inactive, self::EXAM_MANAGE);
        self::assertSame(409, $this->call('GET', '/secondary/official-exams', null, 'inactive-user', self::TB, $inactive)[0]);
    }

    /** TST-08 (T949) : élève inscrit sur la liste d'examen sans frais constatés sur la carte scolaire → bloqué « frais non constatés ». */
    public function testTst08CandidatSansFraisConstatesBloque(): void
    {
        $session = $this->session(['exam_code' => 'EXA-BAC-DEMO']);
        $candidate = $this->ok('POST', '/secondary/official-exams/' . $session['id'] . '/candidates', ['person_id' => 'el-2'], self::ADMIN, self::TA, $this->school);
        self::assertSame('blocked', $candidate['status']);
        self::assertSame('frais non constatés', $candidate['status_message']);
        self::assertSame('not_seen', $candidate['fee_status']);
        $this->paid['el-2'] = true;
        $rows = array_column($this->ok('GET', '/secondary/official-exams/' . $session['id'] . '/candidates', null, self::ADMIN, self::TA, $this->school), null, 'person_id');
        self::assertNotSame('blocked', $rows['el-2']['status'], 'Le statut suit le relevé de la carte scolaire, rien n’est encaissé ici (RG-FIN-3).');
    }

    /** RG-FIN-1 (T949) : aucune route de l'application ne renvoie ni n'exclut un élève pour impayé. */
    public function testAucuneRouteDExclusionPourImpaye(): void
    {
        foreach ($this->kernel->routes()->all() as $route) {
            $path = strtolower($route['path']);
            if (!str_contains($path, '/secondary/')) {
                continue;
            }
            foreach (['debt', 'unpaid', 'impaye', 'arrears', 'expel'] as $word) {
                self::assertStringNotContainsString($word, $path, $route['path']);
            }
        }
    }

    /** RG-EXA-1 : seul un élève régulièrement inscrit dans la classe d'examen figure sur la liste ; session close figée. */
    public function testSeulUnInscritDeLaClasseDExamenEtSessionClose(): void
    {
        $session = $this->session();
        $this->people->add(self::TA, 'el-3', 'Chantal', 'Owona');
        $this->enrollments->add(self::TA, 'el-3', $this->school, 'autre-classe', self::YEAR);
        self::assertSame(422, $this->call('POST', '/secondary/official-exams/' . $session['id'] . '/candidates', ['person_id' => 'el-3'], self::ADMIN, self::TA, $this->school)[0]);
        self::assertSame(422, $this->call('POST', '/secondary/official-exams/' . $session['id'] . '/candidates', ['person_id' => 'inconnu'], self::ADMIN, self::TA, $this->school)[0]);
        self::assertSame(['added' => 2, 'already_listed' => 0], $this->ok('POST', '/secondary/official-exams/' . $session['id'] . '/candidates/generate', [], self::ADMIN, self::TA, $this->school), 'el-3 hors classe d’examen non généré.');
        self::assertSame($session['id'], $this->ok('GET', '/secondary/official-exams/' . $session['id'], null, self::ADMIN, self::TA, $this->school)['id']);
        self::assertSame(409, $this->call('POST', '/secondary/official-exams/' . $session['id'] . '/candidates', ['person_id' => 'el-1'], self::ADMIN, self::TA, $this->school)[0], 'Déjà listé.');
        $this->ok('PATCH', '/secondary/official-exams/' . $session['id'], ['status' => 'closed'], self::ADMIN, self::TA, $this->school);
        self::assertSame(409, $this->call('POST', '/secondary/official-exams/' . $session['id'] . '/candidates/generate', [], self::ADMIN, self::TA, $this->school)[0]);
        $this->grant(self::TA, 'lecteur', $this->school, ['secondary.exams.read']);
        self::assertSame(403, $this->call('POST', '/secondary/official-exams/' . $session['id'] . '/results/import', ['csv' => "key,result_code\nel-1,PASS\n"], 'lecteur', self::TA, $this->school)[0]);
        self::assertSame(200, $this->raw('GET', '/secondary/official-exams/' . $session['id'] . '/candidates/export', null, 'lecteur', self::TA, $this->school)->getStatusCode());
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function session(array $overrides = []): array
    {
        return $this->ok('POST', '/secondary/official-exams', $overrides + [
            'exam_code' => 'EXA-DEMO', 'exam_label' => 'Examen de démonstration', 'academic_year_id' => self::YEAR,
            'class_structure_id' => $this->classId,
        ], self::ADMIN, self::TA, $this->school);
    }
}

/** Enrollments fixtures with the exact examination year. */
final class ExamEnrollments implements EnrollmentDirectory
{
    /** @var array<string, EnrollmentInfo> */
    private array $items = [];

    public function add(string $tenantId, string $personId, string $establishmentId, string $classId, string $yearId): void
    {
        $id = 'en-' . $personId;
        $this->items[$id] = new EnrollmentInfo($id, $tenantId, $personId, $establishmentId, $classId, $yearId, EnrollmentInfo::ACTIVE);
    }

    public function find(string $tenantId, string $enrollmentId): ?EnrollmentInfo
    {
        $enrollment = $this->items[$enrollmentId] ?? null;

        return $enrollment !== null && $enrollment->tenantId === $tenantId ? $enrollment : null;
    }

    public function forPerson(string $tenantId, string $personId): array
    {
        return array_values(array_filter($this->items, static fn (EnrollmentInfo $item): bool => $item->tenantId === $tenantId && $item->personId === $personId));
    }

    public function personIdsInStructure(string $tenantId, string $structureId): array
    {
        return array_values(array_map(static fn (EnrollmentInfo $item): string => $item->personId, array_filter($this->items, static fn (EnrollmentInfo $item): bool => $item->tenantId === $tenantId && $item->structureId === $structureId)));
    }

    public function personIdsInEstablishment(string $tenantId, string $establishmentId): array
    {
        return array_values(array_map(static fn (EnrollmentInfo $item): string => $item->personId, array_filter($this->items, static fn (EnrollmentInfo $item): bool => $item->tenantId === $tenantId && $item->establishmentId === $establishmentId)));
    }
}
