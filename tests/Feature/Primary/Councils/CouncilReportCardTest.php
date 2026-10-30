<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\Councils;

use Scholaris\Modules\Socle\Assessment\Contracts\AdmissionDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\ClassResults;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Billing\RecordingMessenger;
use Tests\Support\Primary\FakeClassResults;
use Tests\Support\Primary\PrimaryTestCase;

/**
 * T540-T542, T545 : conseil des maîtres (compétences, conduite, moyennes facultatives, rang, absences, décision motivée), bulletins
 * émis en lot (figés, numérotés, QR), publication avec SMS NOT-PRI-02, portail parent, rectificatif, récapitulatif annuel, admissions
 * transmises au passage de classe. Règles d'exemple fournies par le test (aucun barème officiel).
 */
final class CouncilReportCardTest extends PrimaryTestCase
{
    private InMemoryEnrollments $enrollments;
    private InMemoryPeople $people;
    private RecordingMessenger $messenger;
    private FakeClassResults $results;
    /** @var array<int, array{id: string, starts_on: string, ends_on: string, sequences: list<array{id: string, starts_on: string}>}> */
    private array $terms;
    private string $class;
    private string $finalClass;
    /** @var list<string> */
    private array $competencies;

    protected function setUp(): void
    {
        $this->enrollments = new InMemoryEnrollments();
        $this->people = new InMemoryPeople();
        $this->messenger = new RecordingMessenger();
        $this->results = new FakeClassResults();
        $link = new class () implements UserPersonLink {
            public function personIdForUser(string $tenantId, string $userId): ?string
            {
                return str_starts_with($userId, 'parent-') ? 'g-' . substr($userId, 7) : null;
            }
        };
        $this->bootPrimary([
            EnrollmentDirectory::class => $this->enrollments, PersonDirectory::class => $this->people, Messenger::class => $this->messenger,
            UserPersonLink::class => $link, ClassResults::class => $this->results,
        ]);
        $this->terms = $this->seedYear(self::TA, self::EA);
        $this->ok('POST', '/primary/referentials/example/apply', ['academic_year_id' => self::YEAR]);
        $profiles = $this->call('GET', '/primary/level-profiles?sort=sort_order')[1]['data'];
        $tree = $this->service(OrgUnitTree::class);
        $first = $profiles[0]['level_structure_id'];
        $this->class = $tree->structureDescendantsOf(self::TA, self::EA, $first)[1];
        $final = array_values(array_filter($profiles, static fn (array $p): bool => $p['is_final_level']))[0]['level_structure_id'];
        $this->finalClass = $tree->structureDescendantsOf(self::TA, self::EA, $final)[1];
        $this->competencies = array_column($this->call('GET', '/primary/competencies?filter[level_structure_id]=' . $first)[1]['data'], 'id');
        for ($i = 1; $i <= 4; ++$i) {
            $this->people->add(self::TA, "e$i", "Prénom$i", "Nom$i");
            $this->people->add(self::TA, "g-$i", "Parent$i", "Nom$i", '+237600000' . $i);
            $this->people->link(self::TA, "g-$i", "e$i");
            $this->enrollments->add(self::TA, "en$i", "e$i", self::EA, $this->class, self::YEAR);
            $this->assign(self::TA, "parent-$i", self::EA, 'self.read', Scope::ESTABLISHMENT);
        }
        $this->people->add(self::TA, 'f1', 'Finaliste', 'Nom');
        $this->enrollments->add(self::TA, 'enf1', 'f1', self::EA, $this->finalClass, self::YEAR);
    }

    public function testConseilDuPremierTrimestreBulletinsEmisPubliesEtConsultes(): void
    {
        $t1 = $this->terms[1];
        $this->results->averages[$t1['id']] = ['e1' => 14.5, 'e2' => 12.0, 'e3' => 12.0, 'e4' => null];
        $this->ok('PUT', "/primary/classes/{$this->class}/competency-sheet?period_id={$t1['id']}", ['entries' => [
            ['person_id' => 'e1', 'competency_id' => $this->competencies[0], 'scale_code' => 'acquired'],
            ['person_id' => 'e1', 'competency_id' => $this->competencies[1], 'scale_code' => 'in_progress'],
            ['person_id' => 'e2', 'competency_id' => $this->competencies[0], 'scale_code' => 'not_acquired'],
        ]]);
        $this->ok('PUT', "/primary/classes/{$this->class}/conduct?period_id={$t1['id']}", ['entries' => [['person_id' => 'e1', 'scale_code' => 'very_good']]]);
        self::assertSame(422, $this->call('POST', '/primary/councils', ['class_id' => $this->class, 'period_id' => $t1['id'], 'starts_on' => '2026-09-08'])[0], 'Date de début discordante.');
        self::assertSame(422, $this->call('POST', '/primary/councils', ['class_id' => $this->class, 'period_id' => $t1['sequences'][0]['id'], 'starts_on' => $t1['starts_on']])[0], 'Une séquence n\'a pas de conseil.');

        $council = $this->ok('POST', '/primary/councils', ['class_id' => $this->class, 'period_id' => $t1['id'], 'starts_on' => $t1['starts_on'], 'period_label' => 'Trimestre 1', 'held_on' => '2026-11-26']);
        self::assertSame(409, $this->call('POST', '/primary/councils', ['class_id' => $this->class, 'period_id' => $t1['id'], 'starts_on' => $t1['starts_on']])[0]);
        self::assertFalse($council['is_final_term']);
        self::assertTrue($council['numeric_enabled']);
        $lines = array_column($council['lines'], null, 'person_id');
        self::assertCount(4, $lines);
        self::assertSame([14.5, 1], [$lines['e1']['average'], $lines['e1']['rank']]);
        self::assertSame([2, 2], [$lines['e2']['rank'], $lines['e3']['rank']], 'Ex æquo au même rang.');
        self::assertNull($lines['e4']['average']);
        self::assertSame(['acquired' => 1, 'in_progress' => 1], $lines['e1']['competencies']);
        self::assertSame('very_good', $lines['e1']['conduct_code']);
        self::assertNull($lines['e1']['proposal'], 'Pas de décision de fin d\'année au 1er trimestre.');

        $id = $council['id'];
        $e1 = $lines['e1']['id'];
        self::assertSame(422, $this->call('PATCH', "/primary/councils/$id/lines/$e1", ['decision' => 'admitted'])[0]);
        $this->ok('PATCH', "/primary/councils/$id/lines/$e1", ['appraisal' => 'Très bon trimestre', 'remediation_flag' => false]);
        $refreshed = $this->ok('POST', "/primary/councils/$id/refresh");
        self::assertSame('Très bon trimestre', array_column($refreshed['lines'], null, 'person_id')['e1']['appraisal'], 'Le recalcul n\'écrase pas la saisie.');

        self::assertSame(409, $this->call('POST', "/primary/councils/$id/report-cards/issue")[0], 'Émission après validation seulement.');
        self::assertSame(409, $this->call('POST', "/primary/councils/$id/validate")[0], 'Feuille de compétences non verrouillée.');
        $this->ok('POST', "/primary/classes/{$this->class}/competency-sheet/lock?period_id={$t1['id']}");
        $validated = $this->ok('POST', "/primary/councils/$id/validate");
        self::assertSame('validated', $validated['status']);
        self::assertStringContainsString('Procès-verbal', (string) $validated['minutes']);
        self::assertSame(409, $this->call('PATCH', "/primary/councils/$id/lines/$e1", ['appraisal' => 'x'])[0]);

        $preview = $this->ok('GET', "/primary/councils/$id/report-cards/preview?person_id=e1");
        self::assertSame('Rang : 1er sur 4', $preview['variables']['rank_line']);
        self::assertStringContainsString('Moyenne générale : 14,50', (string) $preview['variables']['average_line']);
        self::assertStringContainsString('Acquis', (string) $preview['variables']['competencies_block']);

        $issued = $this->ok('POST', "/primary/councils/$id/report-cards/issue");
        self::assertSame([4, 0], [$issued['issued'], $issued['failed']]);
        self::assertSame(4, $this->ok('POST', "/primary/councils/$id/report-cards/issue")['skipped'], 'Émission idempotente.');
        $card = array_values(array_filter($issued['items'], static fn (array $c): bool => $c['person_id'] === 'e1'))[0];
        self::assertNotNull($card['number']);
        self::assertSame('issued', $card['status']);
        self::assertSame(409, $this->call('POST', "/primary/councils/$id/reopen", ['reason' => 'erreur'])[0], 'Réouverture impossible après émission.');

        self::assertSame([], $this->ok('GET', '/primary/family/children/e1/report-cards', null, 'parent-1')['items'], 'Non publié : invisible.');
        $published = $this->ok('POST', "/primary/councils/$id/report-cards/publish");
        self::assertSame([4, 4], [$published['published'], $published['notified']]);
        $sms = $this->messenger->of('pri.not02.report_card_ready');
        self::assertCount(4, $sms);
        self::assertSame('Trimestre 1', $sms[0]['variables']['period_label']);
        $family = $this->ok('GET', '/primary/family/children/e1/report-cards', null, 'parent-1')['items'];
        self::assertCount(1, $family);
        self::assertSame('Très bon trimestre', $family[0]['snapshot']['appraisal']);
        self::assertSame($card['number'], $family[0]['snapshot']['number']);
        self::assertSame(404, $this->call('GET', '/primary/family/children/e1/report-cards', null, 'parent-2')[0], 'Enfant d\'une autre famille.');

        $rectified = $this->call('POST', "/primary/report-cards/{$card['id']}/rectify", ['reason' => 'Appréciation corrigée', 'appraisal' => 'Excellent trimestre']);
        self::assertSame(201, $rectified[0], (string) json_encode($rectified[1]));
        self::assertSame([2, 'published', 'Excellent trimestre'], [$rectified[1]['data']['revision'], $rectified[1]['data']['status'], $rectified[1]['data']['snapshot']['appraisal']]);
        self::assertSame('superseded', $this->ok('GET', "/primary/report-cards/{$card['id']}")['status']);
        self::assertSame('Excellent trimestre', $this->ok('GET', '/primary/family/children/e1/report-cards', null, 'parent-1')['items'][0]['snapshot']['appraisal']);
    }

    public function testRangMasqueParParametreEtIsolation(): void
    {
        $t1 = $this->terms[1];
        $this->results->averages[$t1['id']] = ['e1' => 11.0];
        $this->setSetting('primary.report_card.show_rank', false);
        $council = $this->ok('POST', '/primary/councils', ['class_id' => $this->class, 'period_id' => $t1['id'], 'starts_on' => $t1['starts_on']]);
        $preview = $this->ok('GET', "/primary/councils/{$council['id']}/report-cards/preview?person_id=e1");
        self::assertSame('', $preview['variables']['rank_line'], 'show_rank=false : aucun rang imprimé.');
        self::assertNull($preview['snapshot']['numeric']['rank']);

        $other = $this->makeLevel(self::TA, self::EA, 'Autre', 1)['classes'][0];
        $this->assign(self::TA, 'maitre-b', self::EA, 'primary.council.read', Scope::CLASS_GROUP, $other);
        $this->assign(self::TA, 'maitre-a', self::EA, 'primary.council.read', Scope::CLASS_GROUP, $this->class);
        self::assertSame(404, $this->call('GET', "/primary/councils/{$council['id']}", null, 'maitre-b')[0]);
        self::assertSame(200, $this->call('GET', "/primary/councils/{$council['id']}", null, 'maitre-a')[0]);
        self::assertSame(403, $this->call('POST', "/primary/councils/{$council['id']}/validate", null, 'maitre-a')[0], 'Le maître ne préside pas.');
        self::assertSame([], $this->ok('GET', '/primary/councils', null, 'maitre-b')['items']);
        $this->grantAll(self::TB, 'directeur-b', self::EB);
        self::assertSame(404, $this->call('GET', "/primary/councils/{$council['id']}", null, 'directeur-b', self::TB, self::EB)[0]);
        self::assertSame(404, $this->call('POST', "/primary/councils/{$council['id']}/validate", null, 'directeur-b', self::TB, self::EB)[0]);
        self::assertSame(404, $this->call('POST', "/primary/councils/{$council['id']}/report-cards/issue", null, 'directeur-b', self::TB, self::EB)[0]);
    }

    public function testDernierTrimestreDecisionMotiveeRecapitulatifAnnuelEtAdmissions(): void
    {
        $this->results->averages[$this->terms[1]['id']] = ['e1' => 12.0, 'e2' => 8.0, 'e3' => 9.0, 'e4' => null];
        $this->results->averages[$this->terms[2]['id']] = ['e1' => 14.0, 'e2' => 8.0, 'e3' => 9.5, 'e4' => null];
        $this->results->averages[$this->terms[3]['id']] = ['e1' => 13.0, 'e2' => 9.0, 'e3' => 10.0, 'e4' => null];
        foreach ([1, 2] as $n) {
            $c = $this->ok('POST', '/primary/councils', ['class_id' => $this->class, 'period_id' => $this->terms[$n]['id'], 'starts_on' => $this->terms[$n]['starts_on']]);
            $this->ok('POST', "/primary/councils/{$c['id']}/validate");
        }
        $t3 = $this->terms[3];
        $council = $this->ok('POST', '/primary/councils', ['class_id' => $this->class, 'period_id' => $t3['id'], 'starts_on' => $t3['starts_on'], 'sequences' => $t3['sequences']]);
        self::assertTrue($council['is_final_term']);
        $lines = array_column($council['lines'], null, 'person_id');
        self::assertEquals(13.0, $lines['e1']['annual_average'], 'Moyenne annuelle = moyenne des trimestres, poids 1 (règles de base).');
        self::assertSame('admitted', $lines['e1']['proposal']);
        self::assertSame('adjourned', $lines['e2']['proposal']);
        self::assertCount(3, $lines['e1']['terms']);
        self::assertNull($lines['e4']['proposal'], 'Sans moyenne : pas de proposition, le conseil décide.');

        self::assertSame(422, $this->call('POST', "/primary/councils/{$council['id']}/validate")[0], 'Décision manquante pour e4.');
        self::assertSame(422, $this->call('PATCH', "/primary/councils/{$council['id']}/lines/{$lines['e3']['id']}", ['decision' => 'admitted'])[0], 'Motif obligatoire.');
        $this->ok('PATCH', "/primary/councils/{$council['id']}/lines/{$lines['e3']['id']}", ['decision' => 'admitted', 'reason' => 'Progrès constants']);
        $this->ok('PATCH', "/primary/councils/{$council['id']}/lines/{$lines['e4']['id']}", ['decision' => 'adjourned']);
        $this->ok('POST', "/primary/councils/{$council['id']}/validate");

        $admitted = $this->service(AdmissionDirectory::class)->admittedPersonIds(self::TA, self::EA, self::YEAR);
        sort($admitted);
        self::assertSame(['e1', 'e3'], $admitted, 'Admis du conseil transmis au passage de classe (YearPromotion).');

        $issued = $this->ok('POST', "/primary/councils/{$council['id']}/report-cards/issue");
        self::assertSame(8, $issued['issued'], 'Bulletin du 3e trimestre + récapitulatif annuel.');
        $annual = array_values(array_filter($issued['items'], static fn (array $c): bool => $c['person_id'] === 'e3' && $c['kind'] === 'annual'))[0];
        $snapshot = $this->ok('GET', "/primary/report-cards/{$annual['id']}")['snapshot'];
        self::assertSame('Admis(e) en classe supérieure', $snapshot['decision']['label']);
        self::assertCount(3, $snapshot['annual']['terms']);

        // Niveau final : « fin de cycle », jamais promu.
        $this->results->averages[$t3['id']]['f1'] = 15.0;
        $final = $this->ok('POST', '/primary/councils', ['class_id' => $this->finalClass, 'period_id' => $t3['id'], 'starts_on' => $t3['starts_on']]);
        self::assertSame('cycle_end', $final['lines'][0]['proposal']);
    }

    private function setSetting(string $key, mixed $value): void
    {
        $this->service(\Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, $key, $value, self::DIRECTOR);
    }
}
