<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Setup;

use Scholaris\Modules\Apps\Secondary\Catalogue\Contracts\StreamDirectory;
use Scholaris\Modules\Apps\Secondary\Setup\Application\CurriculumService;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\CoefficientBook;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\SchoolRules;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * T815, T820 à T822, T838 : ouverture des 7 types, niveaux, séries, classes (RG-ORG-1, RG-ORG-2), matières, coefficients
 * versionnés (RG-ORG-4, gel, copie), salles, règlement intérieur, changements de profil journalisés (EX-SEC-04). Données fictives.
 */
final class SetupStructureTest extends SecondaryTestCase
{
    private const MANAGE = ['secondary.profile.manage', 'secondary.settings.manage', 'secondary.profile.read'];
    private const YEAR = 'annee-2026';
    private const NEXT = 'annee-2027';

    protected function setUp(): void
    {
        $this->bootSecondary();
    }

    /** @return string établissement ouvert par la route */
    private function open(string $code, array $body = []): string
    {
        $school = $this->factory->establishment(self::TA, $code);
        $this->grant(self::TA, self::ADMIN, $school, self::MANAGE);
        $this->ok('POST', '/secondary/setup/profile/open', $body + ['reason' => 'Ouverture de test'], self::ADMIN, self::TA, $school);

        return $school;
    }

    public function testOuvertureDesSeptTypesEtProprieteExigeeQuandLeProfilNeLaFixePas(): void
    {
        foreach (['leg', 'lycee_technique', 'ces', 'cetic'] as $code) {
            $school = $this->open($code);
            $data = $this->ok('GET', '/secondary/setup/profile', null, self::ADMIN, self::TA, $school);
            self::assertTrue($data['stored'], $code);
            self::assertSame('public', $data['ownership'], $code);
        }
        foreach (['college_prive', 'lycee_prive'] as $code) {
            $school = $this->factory->establishment(self::TA, $code);
            $this->grant(self::TA, self::ADMIN, $school, self::MANAGE);
            [$status, $problem] = $this->call('POST', '/secondary/setup/profile/open', [], self::ADMIN, self::TA, $school);
            self::assertSame(422, $status, $code);
            self::assertArrayHasKey('ownership', $problem['errors'] ?? []);
            self::assertSame(422, $this->call('POST', '/secondary/setup/profile/open', ['ownership' => 'public'], self::ADMIN, self::TA, $school)[0], 'Propriété hors des options du type.');
            $data = $this->ok('POST', '/secondary/setup/profile/open', ['ownership' => 'private_faith'], self::ADMIN, self::TA, $school);
            self::assertSame('private_faith', $data['ownership']);
            self::assertSame(409, $this->call('POST', '/secondary/setup/profile/open', ['ownership' => 'private_faith'], self::ADMIN, self::TA, $school)[0], 'Déjà ouvert.');
        }
        $other = $this->factory->establishment(self::TA, 'autre');
        $this->grant(self::TA, self::ADMIN, $other, self::MANAGE);
        [$status, $problem] = $this->call('POST', '/secondary/setup/profile/open', [], self::ADMIN, self::TA, $other);
        self::assertSame(422, $status);
        foreach (['ownership', 'cycles', 'teaching_kinds', 'head_title'] as $field) {
            self::assertArrayHasKey($field, $problem['errors'] ?? [], $field . ' exigé pour un profil sans valeur par défaut (T956).');
        }
        $data = $this->ok('POST', '/secondary/setup/profile/open', [
            'ownership' => 'private_secular', 'cycles' => ['first', 'second'], 'teaching_kinds' => ['general', 'technical'], 'head_title' => 'proviseur',
            'modules' => ['second_cycle', 'workshops', 'library'],
        ], self::ADMIN, self::TA, $other);
        $states = array_column($data['modules'], 'state', 'code');
        self::assertSame('active', $states['workshops']);
        self::assertSame('active', $states['library']);
        self::assertSame('inactive', $states['fees_private'], 'Modules choisis à la main.');
    }

    public function testNiveauxSeriesEtClassesSuiventLeProfil(): void
    {
        $ces = $this->open('ces');
        $levels = $this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $ces);
        self::assertSame(['6E', '5E', '4E', '3E'], array_column($levels, 'code'), 'CES : 1er cycle général seulement.');
        self::assertSame('to_confirm', $levels[0]['confirmation']);
        [$status] = $this->call('POST', '/secondary/setup/levels', ['code' => '2NDE', 'label' => 'Seconde', 'cycle_code' => 'second', 'section_code' => 'fr', 'teaching_kind' => 'general'], self::ADMIN, self::TA, $ces);
        self::assertSame(422, $status, 'RG-ORG-1 : niveau de 2nd cycle refusé.');

        $leg = $this->open('leg');
        $levels = array_column($this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $leg), null, 'code');
        self::assertCount(7, $levels);
        $streams = $this->service(StreamDirectory::class)->forEstablishment(self::TA, $leg);
        $refC = null;
        foreach ($streams as $stream) {
            if ($stream->code === 'C' && $stream->cycleCode === 'second') {
                $refC = $stream->id;
            }
        }
        self::assertNotNull($refC);
        $streamC = $this->ok('POST', '/secondary/setup/streams', ['stream_ref_id' => $refC], self::ADMIN, self::TA, $leg);
        [$status, $problem] = $this->call('POST', '/secondary/setup/classes', ['label' => '2nde A', 'level_id' => $levels['2NDE']['id'], 'academic_year_id' => self::YEAR], self::ADMIN, self::TA, $leg);
        self::assertSame(422, $status, 'RG-ORG-2 : série obligatoire au 2nd cycle.');
        self::assertArrayHasKey('stream_id', $problem['errors'] ?? []);
        $class = $this->ok('POST', '/secondary/setup/classes', ['label' => '2nde C', 'level_id' => $levels['2NDE']['id'], 'stream_id' => $streamC['id'], 'academic_year_id' => self::YEAR, 'capacity' => 60], self::ADMIN, self::TA, $leg);
        self::assertSame('second', $class['cycle_code']);
        self::assertSame('C', $this->pdo()->query("SELECT code FROM se_streams WHERE id = '" . $streamC['id'] . "'")->fetchColumn());
        $sixth = $this->ok('POST', '/secondary/setup/classes', ['label' => '6e A', 'level_id' => $levels['6E']['id'], 'academic_year_id' => self::YEAR], self::ADMIN, self::TA, $leg);
        self::assertNull($sixth['stream_id']);
        self::assertSame(422, $this->call('POST', '/secondary/setup/classes', ['label' => '6e C', 'level_id' => $levels['6E']['id'], 'stream_id' => $streamC['id'], 'academic_year_id' => self::YEAR], self::ADMIN, self::TA, $leg)[0], 'Série de 2nd cycle sur un niveau de 1er cycle.');
        self::assertCount(2, $this->ok('GET', '/secondary/setup/classes?academic_year_id=' . self::YEAR, null, self::ADMIN, self::TA, $leg));

        $this->grant(self::TA, 'scolarite', $leg, ['secondary.enrol.manage']);
        self::assertSame(201, $this->call('POST', '/secondary/setup/classes', ['label' => '6e B', 'level_id' => $levels['6E']['id'], 'academic_year_id' => self::YEAR], 'scolarite', self::TA, $leg)[0], 'La scolarité crée les classes.');
        self::assertSame(403, $this->call('POST', '/secondary/setup/levels/examples', [], 'scolarite', self::TA, $leg)[0]);
    }

    /** C-117 (parcours e2e 17) : l'enseignant liste les classes pour ouvrir sa feuille de notes, sans lire le reste du paramétrage. */
    public function testLEnseignantListeLesClassesSansLireLeParametrage(): void
    {
        $leg = $this->open('leg');
        $levels = array_column($this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $leg), null, 'code');
        $this->ok('POST', '/secondary/setup/classes', ['label' => '6e A', 'level_id' => $levels['6E']['id'], 'academic_year_id' => self::YEAR], self::ADMIN, self::TA, $leg);
        $this->grant(self::TA, 'professeur', $leg, ['secondary.grades.enter', 'secondary.attendance.take']);
        self::assertSame(['6e A'], array_column($this->ok('GET', '/secondary/setup/classes?academic_year_id=' . self::YEAR, null, 'professeur', self::TA, $leg), 'label'));
        self::assertSame(403, $this->call('GET', '/secondary/setup/levels', null, 'professeur', self::TA, $leg)[0]);
        self::assertSame(403, $this->call('POST', '/secondary/setup/classes', ['label' => '6e B', 'level_id' => $levels['6E']['id'], 'academic_year_id' => self::YEAR], 'professeur', self::TA, $leg)[0]);
        $other = $this->factory->opened(self::TB, 'leg', null, 'e-leg-b');
        $this->grant(self::TB, 'professeur-b', $other, ['secondary.grades.enter']);
        self::assertSame([], $this->ok('GET', '/secondary/setup/classes', null, 'professeur-b', self::TB, $other));
    }

    public function testCoefficientsVersionnesVerrouillesGelesEtCopies(): void
    {
        $leg = $this->open('leg');
        $levels = array_column($this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $leg), null, 'code');
        $maths = $this->ok('POST', '/secondary/setup/subjects', ['code' => 'MATH', 'label' => 'Mathématiques', 'label_en' => 'Mathematics'], self::ADMIN, self::TA, $leg);
        $club = $this->ok('POST', '/secondary/setup/subjects', ['code' => 'CLUB', 'label' => 'Club journal'], self::ADMIN, self::TA, $leg);
        $line = ['academic_year_id' => self::YEAR, 'level_code' => '6E', 'subject_ref' => $maths['id'], 'coefficient' => 4, 'weekly_hours' => 5, 'subject_role' => 'official', 'origin' => 'example', 'valid_from' => '2026-09-01', 'reason' => 'Valeur d\'exemple saisie'];
        $first = $this->ok('POST', '/secondary/setup/coefficients', $line, self::ADMIN, self::TA, $leg);
        self::assertSame(1, $first['version_no']);
        self::assertSame('to_confirm', $first['confirmation']);
        $second = $this->ok('POST', '/secondary/setup/coefficients', ['coefficient' => 3.5, 'reason' => 'Correction après conseil d\'enseignement'] + $line, self::ADMIN, self::TA, $leg);
        self::assertSame(2, $second['version_no']);
        self::assertSame(self::ADMIN, $second['changed_by']);
        $projet = $this->ok('POST', '/secondary/setup/coefficients', ['subject_ref' => $club['id'], 'coefficient' => 1, 'weekly_hours' => 1, 'subject_role' => 'project', 'origin' => 'custom'] + $line, self::ADMIN, self::TA, $leg);
        self::assertFalse($projet['counts_in_average'], 'RG-ORG-4 : matière de projet hors moyenne.');
        self::assertCount(2, $this->ok('GET', '/secondary/setup/coefficients?academic_year_id=' . self::YEAR, null, self::ADMIN, self::TA, $leg));
        self::assertCount(3, $this->ok('GET', '/secondary/setup/coefficients?academic_year_id=' . self::YEAR . '&history=1', null, self::ADMIN, self::TA, $leg), 'Historique complet, rien supprimé.');
        $book = $this->service(CoefficientBook::class)->forLevel(self::TA, $leg, self::YEAR, '6E');
        self::assertCount(2, $book);
        self::assertSame(3.5, $book[0]->coefficient);
        self::assertSame(422, $this->call('POST', '/secondary/setup/coefficients', ['origin' => 'official'] + $line, self::ADMIN, self::TA, $leg)[0], 'Valeur officielle sans source.');

        $this->pdo()->prepare('UPDATE se_subject_coefficients SET locked = 1 WHERE id = ?')->execute([$second['id']]);
        [$status, $problem] = $this->call('POST', '/secondary/setup/coefficients', $line, self::ADMIN, self::TA, $leg);
        self::assertSame(422, $status);
        self::assertSame(CurriculumService::LOCKED, $problem['type'] ?? null);

        $this->ok('POST', '/secondary/setup/coefficients/freeze', ['academic_year_id' => self::YEAR, 'reason' => 'Fin d\'année'], self::ADMIN, self::TA, $leg);
        self::assertSame(409, $this->call('POST', '/secondary/setup/coefficients', ['subject_ref' => $club['id']] + $line, self::ADMIN, self::TA, $leg)[0], 'Année gelée.');
        $copy = $this->ok('POST', '/secondary/setup/coefficients/copy', ['from_academic_year_id' => self::YEAR, 'to_academic_year_id' => self::NEXT, 'valid_from' => '2027-09-01', 'reason' => 'Préparation de l\'année'], self::ADMIN, self::TA, $leg);
        self::assertSame(2, $copy['copied']);
        $next = $this->service(CoefficientBook::class)->forLevel(self::TA, $leg, self::NEXT, '6E');
        self::assertTrue($next[0]->locked, 'Une ligne verrouillée le reste.');
        $old = $this->ok('GET', '/secondary/setup/coefficients?academic_year_id=' . self::YEAR, null, self::ADMIN, self::TA, $leg);
        self::assertSame(3.5, $old[0]['coefficient'], 'L\'année gelée n\'est pas recalculée.');
        self::assertSame($levels['6E']['code'], '6E');
    }

    public function testChangementsDeProfilJournalisesSansPerte(): void
    {
        $school = $this->open('college_prive', ['ownership' => 'private_secular']);
        $levels = array_column($this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $school), null, 'code');
        $this->ok('POST', '/secondary/setup/classes', ['label' => '6e A', 'level_id' => $levels['6E']['id'], 'academic_year_id' => self::YEAR], self::ADMIN, self::TA, $school);
        self::assertSame(422, $this->call('POST', '/secondary/setup/profile/changes', ['kind' => 'modules', 'value' => ['second_cycle']], self::ADMIN, self::TA, $school)[0], 'Motif obligatoire.');
        self::assertSame(422, $this->call('POST', '/secondary/setup/profile/changes', ['kind' => 'modules', 'value' => ['second_cycle'], 'reason' => 'Essai'], self::ADMIN, self::TA, $school)[0], 'Second cycle sans cycle 2.');

        $data = $this->ok('POST', '/secondary/setup/profile/changes', ['kind' => 'subtype', 'value' => 'lycee_prive', 'reason' => 'Ouverture d\'un second cycle (Q21)'], self::ADMIN, self::TA, $school);
        self::assertSame('lycee_prive', $data['subtype']);
        self::assertSame(['first', 'second'], $data['cycles']);
        self::assertSame('active', array_column($data['modules'], 'state', 'code')['second_cycle']);
        self::assertCount(1, $this->ok('GET', '/secondary/setup/classes', null, self::ADMIN, self::TA, $school), 'Les classes existantes sont conservées.');

        $this->ok('POST', '/secondary/setup/profile/changes', ['kind' => 'modules', 'value' => ['second_cycle', 'orientation', 'official_exams', 'fees_private', 'authorizations', 'payroll_simple', 'workshops'], 'reason' => 'Atelier de démonstration'], self::ADMIN, self::TA, $school);
        $data = $this->ok('POST', '/secondary/setup/profile/changes', ['kind' => 'modules', 'value' => ['second_cycle', 'orientation', 'official_exams', 'fees_private', 'authorizations', 'payroll_simple'], 'reason' => 'Atelier fermé'], self::ADMIN, self::TA, $school);
        self::assertSame('read_only', array_column($data['modules'], 'state', 'code')['workshops'], 'EX-SEC-04 : module coupé en lecture seule.');
        [$status] = $this->call('POST', '/secondary/setup/profile/changes', ['kind' => 'cycles', 'value' => ['second'], 'reason' => 'Fermeture du 1er cycle'], self::ADMIN, self::TA, $school);
        self::assertSame(409, $status, 'Un cycle qui porte des classes ne se retire pas.');
        $data = $this->ok('POST', '/secondary/setup/profile/changes', ['kind' => 'cycles', 'value' => ['first'], 'reason' => 'Retour au seul 1er cycle'], self::ADMIN, self::TA, $school);
        self::assertSame('read_only', array_column($data['modules'], 'state', 'code')['second_cycle']);

        $changes = $this->ok('GET', '/secondary/setup/profile/changes', null, self::ADMIN, self::TA, $school);
        self::assertSame(['opened', 'subtype', 'modules', 'modules', 'cycles'], array_column($changes, 'change_kind'));
        self::assertSame('Atelier fermé', $changes[3]['reason']);
        self::assertSame('college_prive', $changes[1]['previous_value']['subtype']);
    }

    public function testSallesEtReglementInterieur(): void
    {
        $leg = $this->open('leg');
        self::assertSame(422, $this->call('POST', '/secondary/setup/rooms', ['room_id' => 'salle-1', 'label' => 'Atelier bois', 'room_kind' => 'workshop'], self::ADMIN, self::TA, $leg)[0], 'Atelier sans module workshops.');
        $room = $this->ok('POST', '/secondary/setup/rooms', ['room_id' => 'salle-1', 'label' => 'Laboratoire SVT', 'room_kind' => 'lab', 'capacity' => 40, 'equipment' => 'Microscopes'], self::ADMIN, self::TA, $leg);
        self::assertSame('lab', $room['room_kind']);
        $this->ok('POST', '/secondary/setup/rooms', ['room_id' => 'salle-1', 'label' => 'Laboratoire SVT 1', 'room_kind' => 'lab'], self::ADMIN, self::TA, $leg);
        self::assertCount(1, $this->ok('GET', '/secondary/setup/rooms', null, self::ADMIN, self::TA, $leg));

        $rules = array_column($this->ok('GET', '/secondary/setup/rules', null, self::ADMIN, self::TA, $leg), null, 'key');
        self::assertNull($rules['admission_age_limits']['value'], 'Aucune valeur livrée (Q2).');
        self::assertSame('to_confirm', $rules['admission_age_limits']['confirmation']);
        self::assertSame(422, $this->call('PUT', '/secondary/setup/rules/admission_age_limits', ['value' => [1, 2], 'valid_from' => '2026-09-01', 'reason' => 'Essai'], self::ADMIN, self::TA, $leg)[0]);
        self::assertSame(404, $this->call('PUT', '/secondary/setup/rules/regle_inconnue', ['value' => 1, 'valid_from' => '2026-09-01', 'reason' => 'Essai'], self::ADMIN, self::TA, $leg)[0]);
        $rule = $this->ok('PUT', '/secondary/setup/rules/admission_age_limits', ['value' => ['6E' => ['max_age' => 13]], 'valid_from' => '2026-09-01', 'reason' => 'Règlement voté (jeu fictif)'], self::ADMIN, self::TA, $leg);
        self::assertSame('establishment', $rule['source']);
        self::assertSame(1, $rule['version_no']);
        self::assertSame(['6E' => ['max_age' => 13]], $this->service(SchoolRules::class)->value(self::TA, $leg, 'admission_age_limits'));
    }

    public function testDroitsEtIsolation(): void
    {
        $leg = $this->open('leg');
        $this->access->grant('lecteur', $leg);
        self::assertSame(403, $this->call('GET', '/secondary/setup/levels', null, 'lecteur', self::TA, $leg)[0]);
        $this->grant(self::TA, 'censeur', $leg, ['secondary.settings.write']);
        self::assertSame(403, $this->call('POST', '/secondary/setup/profile/changes', ['kind' => 'head_title', 'value' => 'proviseur', 'reason' => 'x'], 'censeur', self::TA, $leg)[0]);
        $level = $this->ok('POST', '/secondary/setup/levels', ['code' => '6E', 'label' => 'Sixième', 'cycle_code' => 'first', 'section_code' => 'fr', 'teaching_kind' => 'general'], 'censeur', self::TA, $leg);
        $other = $this->factory->opened(self::TB, 'leg', null, 'e-leg-b');
        $this->grant(self::TB, 'admin-b', $other, self::MANAGE);
        self::assertSame(404, $this->call('PATCH', '/secondary/setup/levels/' . $level['id'], ['label' => 'Intrus'], 'admin-b', self::TB, $other)[0]);
        self::assertSame([], $this->ok('GET', '/secondary/setup/levels', null, 'admin-b', self::TB, $other));
        $notOpened = $this->factory->establishment(self::TA, 'ces');
        $this->grant(self::TA, self::ADMIN, $notOpened, self::MANAGE);
        self::assertSame(409, $this->call('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $notOpened)[0], 'Profil non ouvert.');
    }
}
