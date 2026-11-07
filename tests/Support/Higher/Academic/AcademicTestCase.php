<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Academic;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\Higher\Academic\Application\AcademicClock;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Infrastructure\PdoOrgUnitTree;
use Tests\Fixtures\Higher\HigherOrgFixture;
use Tests\Support\Higher\HigherTestCase;

/**
 * Base des tests d'Academic et de Curriculum : base SQLite migrée (tables hi_* et socle), arbre RÉEL de l'établissement
 * (les écoles, filières et niveaux sont créés par StructureWriter : le périmètre est développé sur les vraies données),
 * paramètres, double authentification, messagerie et horloge déterministes. Deux IPES (clients A et B).
 */
abstract class AcademicTestCase extends HigherTestCase
{
    public const T = HigherOrgFixture::TENANT_A;
    public const E = HigherOrgFixture::ESTABLISHMENT_A;
    public const TB = HigherOrgFixture::TENANT_B;
    public const EB = HigherOrgFixture::ESTABLISHMENT_B;
    public const ADMIN = 'admin-a';
    public const ADMIN_B = 'admin-b';
    public const YEAR = 'AY-2026';
    public const PREVIOUS_YEAR = 'AY-2025';

    /** Toutes les permissions d'Academic et de Curriculum. */
    public const ALL = [
        'higher.settings.read', 'higher.settings.manage', 'higher.curriculum.read', 'higher.curriculum.manage', 'higher.curriculum.approve',
        'higher.tutelage.manage', 'higher.regulation.amend', 'higher.calendar.manage',
    ];

    protected FakeSettings $settings;
    protected RecordingMessenger $messenger;
    protected FakeSecondFactor $secondFactor;

    protected function bootAcademic(string $today = '2026-10-13'): void
    {
        $this->settings = new FakeSettings();
        $this->messenger = new RecordingMessenger();
        $this->secondFactor = new FakeSecondFactor();
        $this->bootHigher([
            OrgUnitTree::class => \DI\get(PdoOrgUnitTree::class),
            OrgUnitSettingsReader::class => $this->settings,
            SettingsReader::class => $this->settings,
            RecentSecondFactor::class => $this->secondFactor,
            Messenger::class => $this->messenger,
            AcademicClock::class => new AcademicClock($today),
        ], true);
        $this->seedEstablishment(self::T, self::E, 'IPES A');
        $this->seedEstablishment(self::TB, self::EB, 'IPES B');
        $this->grantAll(self::T, self::ADMIN, self::E);
        $this->grantAll(self::TB, self::ADMIN_B, self::EB);
    }

    protected function tearDown(): void
    {
        unset($this->settings, $this->messenger, $this->secondFactor);
        parent::tearDown();
    }

    protected function seedEstablishment(string $tenantId, string $id, string $name): void
    {
        $now = '2026-10-01T08:00:00+01:00';
        $this->service(ConnectionFactory::class)->pdo()->prepare(
            'INSERT INTO establishments (id, tenant_id, name, type, branding, modules_enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$id, $tenantId, $name, 'higher', '{}', '[]', $now, $now]);
    }

    /** @param list<string> $permissions */
    protected function grantAll(string $tenantId, string $userId, string $establishmentId, array $permissions = self::ALL): void
    {
        foreach ($permissions as $permission) {
            $this->assign($tenantId, $userId, $establishmentId, $permission, 'establishment');
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function call(string $method, string $path, ?array $body = null, string $user = self::ADMIN, string $tenant = self::T, string $establishment = self::E): array
    {
        [$response, $decoded] = $this->api($method, $path, $user, $tenant, $establishment, $body);

        return [$response->getStatusCode(), $decoded];
    }

    /**
     * Appel qui doit réussir (2xx) ; rend `data`.
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    protected function ok(string $method, string $path, ?array $body = null, string $user = self::ADMIN, string $tenant = self::T, string $establishment = self::E): array
    {
        [$status, $decoded] = $this->call($method, $path, $body, $user, $tenant, $establishment);
        self::assertLessThan(300, $status, $method . ' ' . $path . ' : ' . json_encode($decoded, JSON_UNESCAPED_UNICODE));
        $data = $decoded['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * Organisation type : école EC > département CF > filière (régime donné) > niveaux 1 et 2 > groupe G1 du niveau 1.
     *
     * @return array{school: string, department: string, program: string, l1: string, l2: string, g1: string}
     */
    protected function buildOrg(string $regime = 'lmd', string $code = 'CF', string $schoolCode = 'EC', string $user = self::ADMIN, string $tenant = self::T, string $establishment = self::E): array
    {
        $school = $this->ok('POST', '/higher/schools', ['code' => $schoolCode, 'name' => 'École ' . $schoolCode], $user, $tenant, $establishment)['id'];
        $department = $this->ok('POST', '/higher/departments', ['school_id' => $school, 'code' => 'D' . $code, 'name' => 'Département ' . $code], $user, $tenant, $establishment)['id'];
        $program = $this->ok('POST', '/higher/programs', [
            'department_id' => $department, 'code' => $code, 'name' => 'Comptabilité et finance', 'regime' => $regime,
            'teaching_language' => 'fr', 'study_mode' => 'day', 'duration_years' => 3,
        ], $user, $tenant, $establishment)['id'];
        $l1 = $this->ok('POST', '/higher/levels', ['program_id' => $program, 'ordinal' => 1, 'code' => 'L1', 'name' => 'Licence 1'], $user, $tenant, $establishment)['id'];
        $l2 = $this->ok('POST', '/higher/levels', ['program_id' => $program, 'ordinal' => 2, 'code' => 'L2', 'name' => 'Licence 2'], $user, $tenant, $establishment)['id'];
        $g1 = $this->ok('POST', '/higher/groups', ['level_id' => $l1, 'code' => 'G1', 'name' => 'Groupe 1', 'capacity' => 60], $user, $tenant, $establishment)['id'];

        return ['school' => (string) $school, 'department' => (string) $department, 'program' => (string) $program, 'l1' => (string) $l1, 'l2' => (string) $l2, 'g1' => (string) $g1];
    }

    /**
     * Autorisation MINESUP de la filière (niveaux donnés ; null = tous).
     *
     * @param list<string>|null $levels
     * @return array<string, mixed>
     */
    protected function authorize(string $program, ?array $levels, string $from = '2025-09-01', string $to = '2029-08-31', string $reference = 'ARR-2025-0150'): array
    {
        return $this->ok('POST', '/higher/authorizations', [
            'program_id' => $program, 'decree_number' => $reference, 'decree_date' => '2025-07-15', 'authority' => 'MINESUP',
            'valid_from' => $from, 'valid_to' => $to, 'level_ids' => $levels,
        ]);
    }

    /** @return array<string, mixed> */
    protected function tutelage(string $program, string $year = self::YEAR, string $university = 'Université de Douala', ?string $conventionEnd = null): array
    {
        return $this->ok('POST', '/higher/tutelages', [
            'program_id' => $program, 'academic_year_id' => $year, 'university_name' => $university,
            'convention_reference' => 'CONV-UD-2026', 'convention_ends_on' => $conventionEnd, 'approved_programme_ref' => 'UD/FSEG/LIC-CF/2024',
            'contacts' => [['name' => 'Pr. Ndjock', 'role' => 'Doyen', 'email' => 'doyen@ud.example', 'phone' => '+237600000000']],
        ]);
    }
}
