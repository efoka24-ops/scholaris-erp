<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Workshops;

use Scholaris\Modules\Apps\Secondary\Catalogue\Contracts\StreamDirectory;

/**
 * Tests par type d'établissement du lot E3-C (T951 à T954), sur les profils de type amorcés (« à confirmer ») : les cycles et les
 * modules viennent du profil, jamais d'un test du code de type. Données fictives.
 */
final class WorkshopTypeProfilesTest extends WorkshopWorld
{
    /** TST-T1 : LEG, cycles 1 et 2, séries proposées, `workshops` inactif ; activation par l'assistant, puis la route répond. */
    public function testLegAteliersInactifsPuisActivesParLAssistant(): void
    {
        $leg = $this->school('leg');
        $profile = $this->ok('GET', '/secondary/setup/profile', null, self::ADMIN, self::TA, $leg);
        self::assertSame(['first', 'second'], $profile['cycles']);
        self::assertNotEmpty(array_filter($this->service(StreamDirectory::class)->forEstablishment(self::TA, $leg), static fn ($s): bool => $s->streamKind === 'series'), 'Séries proposées.');
        foreach (['/secondary/workshops', '/secondary/internships'] as $path) {
            [$status, $problem] = $this->call('GET', $path, null, self::ADMIN, self::TA, $leg);
            self::assertSame(409, $status, $path);
            self::assertSame('module_not_enabled', $problem['code'] ?? null, $path);
        }
        $modules = array_merge($profile['active_modules'] ?? array_values(array_map(static fn (array $m): string => $m['code'], array_filter($profile['modules'], static fn (array $m): bool => $m['state'] === 'active'))), ['workshops']);
        $this->ok('POST', '/secondary/setup/profile/changes', ['kind' => 'modules', 'value' => $modules, 'reason' => 'Ouverture d’un atelier de démonstration'], self::ADMIN, self::TA, $leg);
        self::assertSame([], $this->ok('GET', '/secondary/workshops', null, self::ADMIN, self::TA, $leg), 'Module activé : la route répond.');
        self::assertSame(201, $this->call('POST', '/secondary/workshops', ['label' => 'Atelier LEG fictif'], self::ADMIN, self::TA, $leg)[0]);
        self::assertSame(409, $this->call('GET', '/secondary/internships', null, self::ADMIN, self::TA, $leg)[0], 'Chaque module est gardé séparément.');
    }

    /** TST-T2 : lycée technique, ateliers et stages actifs, spécialités proposées, séance créée, élève sans protection en observation. */
    public function testLyceeTechniqueSeanceEtObservationSansProtection(): void
    {
        $school = $this->school('lycee_technique');
        $states = array_column($this->ok('GET', '/secondary/setup/profile', null, self::ADMIN, self::TA, $school)['modules'], 'state', 'code');
        self::assertSame('active', $states['workshops']);
        self::assertSame('active', $states['internships']);
        self::assertNotEmpty(array_filter($this->service(StreamDirectory::class)->forEstablishment(self::TA, $school), static fn ($s): bool => $s->streamKind === 'specialty'), 'Spécialités proposées.');
        $classId = $this->firstCycleClass($school, ['learner-sans-epi']);
        $workshop = $this->ok('POST', '/secondary/workshops', ['label' => 'Atelier fictif'], self::ADMIN, self::TA, $school);
        $sheet = $this->exampleSheet($school, $workshop['id']);
        $machine = $this->ok('POST', '/secondary/workshops/workstations', ['workshop_id' => $workshop['id'], 'label' => 'Machine', 'machine' => true, 'ppe_required' => ['goggles']], self::ADMIN, self::TA, $school);
        $session = $this->ok('POST', '/secondary/workshops/' . $workshop['id'] . '/sessions', [
            'workshop_id' => $workshop['id'], 'structure_id' => $classId, 'sheet_id' => $sheet['id'], 'session_date' => '2026-10-22', 'teacher_person_id' => 'teacher-fictive',
            'client_uuid' => 'uuid-t2', 'learners' => [['person_id' => 'learner-sans-epi', 'workstation_id' => $machine['id'], 'ppe_items' => []]],
        ], self::ADMIN, self::TA, $school);
        self::assertSame('observation', $session['session']['attendance'][0]['mode']);
        self::assertNull($session['session']['attendance'][0]['workstation_id']);
        self::assertSame([], $this->ok('GET', '/secondary/internships', null, self::ADMIN, self::TA, $school));
    }

    /** TST-T3 : CES, 1er cycle seulement, aucune série ; niveau de 2nd cycle refusé ; orientation indisponible (aucun menu). */
    public function testCesPremierCycleSeulementSansOrientation(): void
    {
        $ces = $this->school('ces');
        $profile = $this->ok('GET', '/secondary/setup/profile', null, self::ADMIN, self::TA, $ces);
        self::assertSame(['first'], $profile['cycles']);
        $states = array_column($profile['modules'], 'state', 'code');
        self::assertSame('unavailable', $states['orientation'], 'Aucun menu d’orientation : le module ne peut pas s’activer.');
        self::assertSame('inactive', $states['workshops']);
        self::assertSame([], array_values(array_filter($this->service(StreamDirectory::class)->forEstablishment(self::TA, $ces), static fn ($s): bool => $s->cycleCode === 'second')), 'Aucune série.');
        self::assertSame(422, $this->call('POST', '/secondary/setup/levels', ['code' => '2NDE', 'label' => 'Seconde', 'cycle_code' => 'second', 'section_code' => 'fr', 'teaching_kind' => 'general'], self::ADMIN, self::TA, $ces)[0], 'RG-ORG-1 : 2nd cycle refusé.');
        self::assertSame(409, $this->call('GET', '/secondary/workshops', null, self::ADMIN, self::TA, $ces)[0]);
    }

    /** TST-T4 : CETIC, 1er cycle technique, ateliers actifs ; 2nd cycle refusé ; atelier disponible. */
    public function testCeticPremierCycleTechniqueAvecAteliers(): void
    {
        $cetic = $this->school('cetic');
        $profile = $this->ok('GET', '/secondary/setup/profile', null, self::ADMIN, self::TA, $cetic);
        self::assertSame(['first'], $profile['cycles']);
        self::assertSame(['technical'], $profile['teaching_kinds']);
        self::assertSame(422, $this->call('POST', '/secondary/setup/levels', ['code' => '2NDE', 'label' => 'Seconde technique', 'cycle_code' => 'second', 'section_code' => 'fr', 'teaching_kind' => 'technical'], self::ADMIN, self::TA, $cetic)[0], '2nd cycle refusé.');
        $classId = $this->firstCycleClass($cetic, ['learner-cetic']);
        $workshop = $this->ok('POST', '/secondary/workshops', ['label' => 'Atelier CETIC fictif'], self::ADMIN, self::TA, $cetic);
        $sheet = $this->exampleSheet($cetic, $workshop['id']);
        $bench = $this->ok('POST', '/secondary/workshops/workstations', ['workshop_id' => $workshop['id'], 'label' => 'Établi', 'machine' => false, 'ppe_required' => []], self::ADMIN, self::TA, $cetic);
        $session = $this->ok('POST', '/secondary/workshops/' . $workshop['id'] . '/sessions', [
            'workshop_id' => $workshop['id'], 'structure_id' => $classId, 'sheet_id' => $sheet['id'], 'session_date' => '2026-10-22', 'teacher_person_id' => 'teacher-fictive',
            'client_uuid' => 'uuid-t4', 'learners' => [['person_id' => 'learner-cetic', 'workstation_id' => $bench['id']]],
        ], self::ADMIN, self::TA, $cetic);
        self::assertSame('workstation', $session['session']['attendance'][0]['mode'], 'Atelier disponible.');
    }
}
