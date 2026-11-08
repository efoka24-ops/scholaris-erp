<?php

declare(strict_types=1);

namespace Tests\Acceptance\Secondary;

use Scholaris\Modules\Apps\Secondary\Catalogue\Contracts\StreamDirectory;
use Scholaris\Modules\Apps\Secondary\Policies\RoleMatrix;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationData;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationRegistry;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * Recette E4-C, tests par type TST-T1 à TST-T7 (T951 à T956) SUR LE VRAI SOCLE : aucune doublure de personnes, d'inscriptions,
 * d'organisation ni de facturation (seuls l'authentification, les permissions et le droit d'usage sont doublés par
 * SecondaryTestCase). Chaque établissement est créé par la fabrique (T950), ouvert PAR L'ASSISTANT (API), puis on vérifie que
 * cycles, modules, rôles proposés et routes suivent le profil de type, avec de vrais élèves inscrits par API-01. Données fictives.
 *
 * Les valeurs de profil sont les valeurs de départ amorcées « à confirmer » (ProfileFactory::EXPECTED) : un échec signale un
 * changement du profil de type, pas une règle officielle.
 */
final class TypeProfilesAcceptanceTest extends SecondaryTestCase
{
    private const YEAR = 'annee-recette-2026';
    private const RIGHTS = [
        'secondary.profile.manage', 'secondary.profile.read', 'secondary.settings.manage', 'secondary.enrol.manage', 'secondary.enrol.read',
        'secondary.enrol.validate', 'secondary.workshops.read', 'secondary.workshops.manage', 'secondary.workshops.session', 'secondary.internships.manage',
        'secondary.orientation.manage', 'secondary.fees.read', 'secondary.billing.manage', 'billing.read', 'billing.manage',
    ];

    private int $pupils = 0;

    protected function setUp(): void
    {
        $this->bootSecondary();
    }

    /** Établissement du type, ouvert par l'assistant avec les valeurs du profil (POST /secondary/setup/profile/open). */
    private function open(string $code, array $body = []): string
    {
        $school = $this->factory->establishment(self::TA, $code);
        $this->grant(self::TA, self::ADMIN, $school, self::RIGHTS);
        $this->ok('POST', '/secondary/setup/profile/open', $body + ['reason' => 'Ouverture de recette (fictive)'], self::ADMIN, self::TA, $school);

        return $school;
    }

    /** @return array<string, mixed> profil lu par l'API */
    private function profile(string $school): array
    {
        return $this->ok('GET', '/secondary/setup/profile', null, self::ADMIN, self::TA, $school);
    }

    /** @return array<string, string> code => état du module */
    private function states(string $school): array
    {
        return array_column($this->profile($school)['modules'], 'state', 'code');
    }

    /** @return list<string> modules actifs */
    private function active(string $school): array
    {
        return array_keys(array_filter($this->states($school), static fn (string $state): bool => $state === 'active'));
    }

    /**
     * Rôles proposés à l'établissement : la matrice des 24 rôles, chacun seulement si son module est actif (null = noyau).
     *
     * @return list<string>
     */
    private function proposedRoles(string $school): array
    {
        $active = $this->active($school);

        return array_values(array_map(static fn (array $role): string => $role['code'], array_filter(RoleMatrix::ROLES, static fn (array $role): bool => $role['module'] === null || in_array($role['module'], $active, true))));
    }

    /**
     * Niveaux d'exemple chargés et autorisés d'ouverture (registre du socle, RG-ORG-3) sauf demande contraire.
     *
     * @return array<string, array<string, mixed>> code => niveau
     */
    private function levels(string $school, bool $authorize = true): array
    {
        $levels = array_column($this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $school), null, 'code');
        self::assertNotSame([], $levels);
        if ($authorize) {
            foreach ($levels as $level) {
                $this->authorize($school, (string) $level['structure_id'], 'AUT-' . $level['code']);
            }
        }

        return $levels;
    }

    private function authorize(string $school, string $structureId, string $reference): void
    {
        $this->service(AuthorizationRegistry::class)->register(self::TA, $school, new AuthorizationData($structureId, $reference, '2026-01-01', '2030-12-31', 'MINESEC (fictif)'), 'recette');
    }

    /** Série ou spécialité du catalogue ouverte dans l'établissement (premier élément proposé qui convient). */
    private function stream(string $school, string $cycle, string $teaching, string $kind): string
    {
        foreach ($this->service(StreamDirectory::class)->forEstablishment(self::TA, $school) as $stream) {
            if ($stream->cycleCode === $cycle && $stream->teachingKind === $teaching && $stream->streamKind === $kind && $stream->sectionCode === 'fr') {
                return (string) $this->ok('POST', '/secondary/setup/streams', ['stream_ref_id' => $stream->id], self::ADMIN, self::TA, $school)['id'];
            }
        }
        self::fail('Aucune ' . $kind . ' ' . $cycle . ' ' . $teaching . ' proposée.');
    }

    /** @return array<string, mixed> classe créée (profil de classe) */
    private function schoolClass(string $school, array $level, string $label, ?string $streamId = null): array
    {
        return $this->ok('POST', '/secondary/setup/classes', array_filter(['label' => $label, 'level_id' => $level['id'], 'academic_year_id' => self::YEAR, 'stream_id' => $streamId]), self::ADMIN, self::TA, $school);
    }

    /**
     * Inscription réelle (API-01) : fiche du socle, garant, inscription du socle, dossier secondaire, matricule.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function enrol(string $school, string $classId, string $first): array
    {
        ++$this->pupils;

        return $this->call('POST', '/secondary/enrolments', [
            'identity' => ['last_name' => 'RECETTE', 'first_name' => $first, 'birth_date' => '2011-05-04', 'gender' => 'female'],
            'guardians' => [['kind' => 'parent', 'identity' => ['last_name' => 'RECETTE', 'first_name' => 'Parent', 'phone' => '+237600000' . sprintf('%03d', $this->pupils)]]],
            'class_id' => $classId, 'entry_date' => '2026-09-07', 'national_id' => sprintf('CM2026-%06d', 500 + $this->pupils),
        ], self::ADMIN, self::TA, $school);
    }

    /** @return array<string, mixed> dossier de l'élève inscrit */
    private function enrolled(string $school, string $classId, string $first): array
    {
        [$status, $json] = $this->enrol($school, $classId, $first);
        self::assertSame(201, $status, (string) json_encode($json, JSON_UNESCAPED_UNICODE));

        return $json['data'];
    }

    private function code(string $method, string $path, string $school, ?array $body = null): int
    {
        return $this->call($method, $path, $body, self::ADMIN, self::TA, $school)[0];
    }

    private function assertModuleOff(string $path, string $school): void
    {
        [$status, $json] = $this->call('GET', $path, null, self::ADMIN, self::TA, $school);
        self::assertSame([409, 'module_not_enabled'], [$status, $json['code'] ?? null], $path);
    }

    /** @return array<string, mixed> fiche de TP d'exemple (valeurs fournies par le test) */
    private function sheet(string $school, string $workshopId): array
    {
        return $this->ok('POST', '/secondary/workshops/sheets', [
            'workshop_id' => $workshopId, 'title' => 'TP de recette', 'objective' => 'Exercice fictif', 'materials' => ['Matière A'], 'tools' => ['Outil A'],
            'safety_rules' => ['Port des EPI requis'], 'criteria' => [['code' => 'execution', 'label' => 'Exécution', 'points' => 12], ['code' => 'safety', 'label' => 'Sécurité', 'points' => 8]],
            'base_score' => 20, 'safety_cap' => 8,
        ], self::ADMIN, self::TA, $school);
    }

    public function testTstT1LegCyclesEtSeriesAteliersInactifsPuisActivesParLAssistant(): void
    {
        $leg = $this->open('leg');
        $profile = $this->profile($leg);
        self::assertSame(['first', 'second'], $profile['cycles']);
        self::assertSame('public', $profile['ownership'], 'Propriété fixée par le type.');
        self::assertSame(['fees_public_catalog', 'official_exams', 'orientation', 'second_cycle'], self::sorted($this->active($leg)));
        self::assertNotContains('sec_chef_travaux', $this->proposedRoles($leg), 'Aucun rôle d\'atelier sans le module.');
        self::assertContains('sec_conseiller_orientation', $this->proposedRoles($leg));

        $levels = $this->levels($leg);
        self::assertArrayHasKey('2NDE', $levels, 'Niveaux du 2nd cycle proposés.');
        $series = $this->stream($leg, 'second', 'general', 'series');
        self::assertSame(422, $this->code('POST', '/secondary/setup/classes', $leg, ['label' => '2nde sans série', 'level_id' => $levels['2NDE']['id'], 'academic_year_id' => self::YEAR]), 'RG-ORG-2 : au 2nd cycle, une série.');
        $class = $this->schoolClass($leg, $levels['2NDE'], '2nde A (recette)', $series);
        $pupil = $this->enrolled($leg, (string) $class['id'], 'Awa');
        self::assertSame('recorded', $pupil['national_id_status'], 'Matricule enregistré à l\'inscription.');
        self::assertSame(200, $this->code('GET', '/secondary/orientation?class_id=' . $class['structure_id'] . '&academic_year_id=' . self::YEAR, $leg), 'Orientation active au LEG.');

        foreach (['/secondary/workshops', '/secondary/internships'] as $path) {
            $this->assertModuleOff($path, $leg);
        }
        $this->ok('POST', '/secondary/setup/profile/changes', ['kind' => 'modules', 'value' => [...$this->active($leg), 'workshops'], 'reason' => 'Atelier de démonstration'], self::ADMIN, self::TA, $leg);
        self::assertSame(200, $this->code('GET', '/secondary/workshops', $leg), 'Activé par l\'assistant : la route répond.');
        self::assertSame(201, $this->code('POST', '/secondary/workshops', $leg, ['label' => 'Atelier LEG (fictif)']));
        $this->assertModuleOff('/secondary/internships', $leg);
        self::assertContains('sec_chef_travaux', $this->proposedRoles($leg), 'Rôle d\'atelier proposé une fois le module actif.');
    }

    public function testTstT2LyceeTechniqueSeanceDAtelierEtObservationSansProtection(): void
    {
        $school = $this->open('lycee_technique');
        $states = $this->states($school);
        self::assertSame(['active', 'active'], [$states['workshops'], $states['internships']]);
        self::assertContains('sec_chef_travaux', $this->proposedRoles($school));
        self::assertNotEmpty(array_filter($this->service(StreamDirectory::class)->forEstablishment(self::TA, $school), static fn ($s): bool => $s->streamKind === 'specialty'), 'Spécialités proposées.');

        $levels = array_values(array_filter($this->levels($school), static fn (array $l): bool => $l['teaching_kind'] === 'technical' && $l['cycle_code'] === 'first'));
        $class = $this->schoolClass($school, $levels[0], '1re année MEM (recette)', $this->stream($school, 'first', 'technical', 'specialty'));
        $pupil = $this->enrolled($school, (string) $class['id'], 'Bello');

        $workshop = $this->ok('POST', '/secondary/workshops', ['label' => 'Atelier mécanique (fictif)'], self::ADMIN, self::TA, $school);
        $sheet = $this->sheet($school, (string) $workshop['id']);
        $machine = $this->ok('POST', '/secondary/workshops/workstations', ['workshop_id' => $workshop['id'], 'label' => 'Tour', 'machine' => true, 'ppe_required' => ['goggles']], self::ADMIN, self::TA, $school);
        $session = $this->ok('POST', '/secondary/workshops/' . $workshop['id'] . '/sessions', [
            'workshop_id' => $workshop['id'], 'structure_id' => $class['structure_id'], 'sheet_id' => $sheet['id'], 'session_date' => '2026-10-22',
            'teacher_person_id' => 'enseignant-fictif', 'client_uuid' => 'recette-t2',
            'learners' => [['person_id' => $pupil['person_id'], 'workstation_id' => $machine['id'], 'ppe_items' => []]],
        ], self::ADMIN, self::TA, $school);
        self::assertSame('observation', $session['session']['attendance'][0]['mode'], 'RG-ATL-1 : sans protection, en observation.');
        self::assertNull($session['session']['attendance'][0]['workstation_id'], 'Pas de poste machine.');
        self::assertSame(200, $this->code('GET', '/secondary/internships', $school), 'Stages actifs.');
    }

    public function testTstT3CesPremierCycleSansSerieNiOrientation(): void
    {
        $ces = $this->open('ces');
        $profile = $this->profile($ces);
        self::assertSame(['first'], $profile['cycles']);
        $states = $this->states($ces);
        self::assertSame(['unavailable', 'unavailable'], [$states['orientation'], $states['second_cycle']], 'Aucun menu d\'orientation : le module ne peut pas s\'activer.');
        self::assertNotContains('sec_conseiller_orientation', $this->proposedRoles($ces));
        self::assertSame([], array_values(array_filter($this->service(StreamDirectory::class)->forEstablishment(self::TA, $ces), static fn ($s): bool => $s->cycleCode === 'second')), 'Aucune série.');

        $levels = $this->levels($ces);
        self::assertSame(['3E', '4E', '5E', '6E'], self::sorted(array_keys($levels)), 'Seulement les niveaux du 1er cycle.');
        self::assertSame(422, $this->code('POST', '/secondary/setup/levels', $ces, ['code' => '2NDE', 'label' => 'Seconde', 'cycle_code' => 'second', 'section_code' => 'fr', 'teaching_kind' => 'general']), 'Classe de 2nd cycle refusée (RG-ORG-1).');
        self::assertSame(422, $this->code('POST', '/secondary/setup/profile/changes', $ces, ['kind' => 'modules', 'value' => [...$this->active($ces), 'orientation'], 'reason' => 'Essai']), 'Orientation inactivable sans 2nd cycle.');
        $this->assertModuleOff('/secondary/orientation', $ces);
        $class = $this->schoolClass($ces, $levels['6E'], '6e A (recette)');
        self::assertSame('recorded', $this->enrolled($ces, (string) $class['id'], 'Chantal')['national_id_status']);
    }

    public function testTstT4CeticPremierCycleTechniqueAvecAteliers(): void
    {
        $cetic = $this->open('cetic');
        $profile = $this->profile($cetic);
        self::assertSame([['first'], ['technical']], [$profile['cycles'], $profile['teaching_kinds']]);
        self::assertSame(422, $this->code('POST', '/secondary/setup/levels', $cetic, ['code' => '2NDE', 'label' => 'Seconde technique', 'cycle_code' => 'second', 'section_code' => 'fr', 'teaching_kind' => 'technical']), '2nd cycle refusé.');
        $levels = $this->levels($cetic);
        self::assertSame([], array_values(array_filter($levels, static fn (array $l): bool => $l['cycle_code'] !== 'first' || $l['teaching_kind'] !== 'technical')));
        $first = array_values($levels)[0];
        $class = $this->schoolClass($cetic, $first, '1re année (recette)', $this->stream($cetic, 'first', 'technical', 'specialty'));
        $pupil = $this->enrolled($cetic, (string) $class['id'], 'Djibril');

        $workshop = $this->ok('POST', '/secondary/workshops', ['label' => 'Atelier CETIC (fictif)'], self::ADMIN, self::TA, $cetic);
        $bench = $this->ok('POST', '/secondary/workshops/workstations', ['workshop_id' => $workshop['id'], 'label' => 'Établi', 'machine' => false, 'ppe_required' => []], self::ADMIN, self::TA, $cetic);
        $session = $this->ok('POST', '/secondary/workshops/' . $workshop['id'] . '/sessions', [
            'workshop_id' => $workshop['id'], 'structure_id' => $class['structure_id'], 'sheet_id' => $this->sheet($cetic, (string) $workshop['id'])['id'],
            'session_date' => '2026-10-22', 'teacher_person_id' => 'enseignant-fictif', 'client_uuid' => 'recette-t4',
            'learners' => [['person_id' => $pupil['person_id'], 'workstation_id' => $bench['id']]],
        ], self::ADMIN, self::TA, $cetic);
        self::assertSame('workstation', $session['session']['attendance'][0]['mode'], 'Atelier disponible.');
    }

    /** @return array<string, array{0: string, 1: string}> TST-T5 et TST-T6 */
    public static function privateTypes(): array
    {
        return ['TST-T5 collège privé' => ['college_prive', '6E'], 'TST-T6 lycée privé' => ['lycee_prive', '6E']];
    }

    /** @dataProvider privateTypes */
    public function testTstT5T6PriveAutorisationsFraisPrivesEtPaieSimplifiee(string $code, string $levelCode): void
    {
        [$status, $json] = $this->call('POST', '/secondary/setup/profile/open', ['reason' => 'Sans propriété'], self::ADMIN, self::TA, $probe = $this->factory->establishment(self::TA, $code, 'e-sonde-' . str_replace('_', '-', $code)));
        self::assertSame(403, $status, 'Sans droit sur la sonde.');
        $this->grant(self::TA, self::ADMIN, $probe, self::RIGHTS);
        [$status, $json] = $this->call('POST', '/secondary/setup/profile/open', ['reason' => 'Sans propriété'], self::ADMIN, self::TA, $probe);
        self::assertSame(422, $status);
        self::assertArrayHasKey('ownership', $json['errors'] ?? [], 'Propriété exigée : le type ne la fixe pas.');

        $school = $this->open($code, ['ownership' => 'private_faith']);
        $active = $this->active($school);
        foreach (['fees_private', 'authorizations', 'payroll_simple'] as $module) {
            self::assertContains($module, $active, $code . ' : ' . $module);
        }
        self::assertNotContains('fees_public_catalog', $active, 'Garde de catalogue public inactive.');
        self::assertContains('sec_brigade_controle', $this->proposedRoles($school), 'Rôle de contrôle des autorisations proposé.');

        $levels = $this->levels($school, false);
        $class = $this->schoolClass($school, $levels[$levelCode], '6e A (recette)');
        [$refused, $problem] = $this->enrol($school, (string) $class['id'], 'Esther');
        self::assertSame(422, $refused, 'Inscription sans autorisation d\'ouverture : refus.');
        self::assertStringContainsString('RG-ORG-3', (string) json_encode($problem, JSON_UNESCAPED_UNICODE));
        $this->authorize($school, (string) $levels[$levelCode]['structure_id'], 'AUT-PRIVE-6E');
        self::assertSame('recorded', $this->enrolled($school, (string) $class['id'], 'Esther')['national_id_status'], 'Avec autorisation valide : inscrite.');

        self::assertSame(201, $this->code('POST', '/fee-items', $school, ['kind' => 'other', 'name' => 'Reprographie', 'amount_xaf' => 2000]), 'Frais libre du privé : pas de catalogue public.');
        $this->assertModuleOff('/secondary/fees/catalog', $school);
        self::assertSame(200, $this->code('GET', '/secondary/staff/payroll/rules', $school), 'Paie simplifiée active.');
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM se_fee_requests')->fetchColumn(), 'Aucune demande d\'ajout au catalogue.');
    }

    public function testTstT7AutreSansValeurParDefautModulesChoisisALaMain(): void
    {
        $school = $this->factory->establishment(self::TA, 'autre');
        $this->grant(self::TA, self::ADMIN, $school, self::RIGHTS);
        $draft = $this->profile($school);
        self::assertSame([null, [], [], null], [$draft['ownership'], $draft['cycles'], $draft['teaching_kinds'], $draft['head_title']], 'Aucune valeur par défaut au-delà du noyau.');
        self::assertSame([], array_values(array_filter($draft['modules'], static fn (array $m): bool => $m['default_for_type'] || $m['state'] === 'active')));

        [$status, $json] = $this->call('POST', '/secondary/setup/profile/open', ['reason' => 'Ouverture'], self::ADMIN, self::TA, $school);
        self::assertSame(422, $status);
        foreach (['ownership', 'cycles', 'teaching_kinds', 'head_title'] as $field) {
            self::assertArrayHasKey($field, $json['errors'] ?? [], $field . ' exigé par l\'assistant');
        }
        $this->ok('POST', '/secondary/setup/profile/open', [
            'ownership' => 'private_secular', 'cycles' => ['first'], 'teaching_kinds' => ['general'], 'head_title' => 'directeur',
            'modules' => ['fees_private'], 'reason' => 'Établissement atypique (fictif)',
        ], self::ADMIN, self::TA, $school);
        self::assertSame(['fees_private'], $this->active($school), 'Seulement le module choisi.');
        self::assertSame(array_values(array_map(static fn (array $r): string => $r['code'], array_filter(RoleMatrix::ROLES, static fn (array $r): bool => $r['module'] === null))), $this->proposedRoles($school), 'Rôles du noyau seulement.');
        foreach (['/secondary/workshops', '/secondary/orientation', '/secondary/staff/payroll/rules', '/secondary/fees/catalog'] as $path) {
            $this->assertModuleOff($path, $school);
        }

        $levels = $this->levels($school);
        $class = $this->schoolClass($school, $levels['6E'], '6e unique (recette)');
        self::assertSame('recorded', $this->enrolled($school, (string) $class['id'], 'Fadimatou')['national_id_status'], 'Le noyau (inscription) fonctionne.');
    }

    public function testIsolationDesProfilsEntreClients(): void
    {
        $leg = $this->open('leg');
        $this->grant(self::TB, self::ADMIN, $leg, self::RIGHTS);
        $class = $this->schoolClass($leg, $this->levels($leg)['6E'], '6e A (recette)');
        $pupil = $this->enrolled($leg, (string) $class['id'], 'Grace');
        self::assertNotSame([], $this->ok('GET', '/secondary/setup/levels', null, self::ADMIN, self::TA, $leg));

        [$status, $json] = $this->call('GET', '/secondary/setup/levels', null, self::ADMIN, self::TB, $leg);
        self::assertTrue($status === 404 || ($status === 200 && ($json['data'] ?? null) === []), 'Autre client : aucun niveau visible.');
        self::assertFalse((bool) ($this->call('GET', '/secondary/setup/profile', null, self::ADMIN, self::TB, $leg)[1]['data']['stored'] ?? false), 'Autre client : profil non ouvert.');
        self::assertSame(404, $this->call('GET', '/secondary/enrolment/students/' . $pupil['person_id'], null, self::ADMIN, self::TB, $leg)[0], 'Autre client : élève introuvable.');
        self::assertContains($this->call('POST', '/secondary/setup/classes', ['label' => 'Intrusion', 'level_id' => $class['level_id'], 'academic_year_id' => self::YEAR], self::ADMIN, self::TB, $leg)[0], [404, 409], 'Autre client : aucune écriture.');
    }

    /** @param list<string> $values */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
