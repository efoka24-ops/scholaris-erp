<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\Grades;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Scholaris\Modules\Apps\Primary\Grades\Infrastructure\PrimaryCouncilLines;
use Scholaris\Modules\Apps\Primary\Grades\Infrastructure\PrimaryRuleScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Primary\PrimaryTestCase;

/** EX-PRI-20, 21, 31 : feuille de compétences (lot, client_uuid, version, verrouillage, correction approuvée), conduite, conseil, portées. */
final class CompetencySheetTest extends PrimaryTestCase
{
    private const PERIOD = 'PER-T1';

    private InMemoryEnrollments $enrollments;
    private InMemoryPeople $people;
    /** @var array{level: string, classes: list<string>} */
    private array $level;
    /** @var list<string> */
    private array $competencies = [];

    protected function setUp(): void
    {
        $this->enrollments = new InMemoryEnrollments();
        $this->people = new InMemoryPeople();
        $this->bootPrimary([EnrollmentDirectory::class => $this->enrollments, PersonDirectory::class => $this->people]);
        $this->ok('POST', '/primary/referentials/example/apply', ['academic_year_id' => 'ay1']);
        // La première classe du modèle (niveau 1) reçoit 40 élèves fictifs.
        $profiles = $this->call('GET', '/primary/level-profiles?sort=sort_order')[1]['data'];
        $levelId = $profiles[0]['level_structure_id'];
        $this->competencies = array_column($this->call('GET', '/primary/competencies?filter[level_structure_id]=' . $levelId)[1]['data'], 'id');
        $class = $this->classOf($levelId);
        $this->level = ['level' => $levelId, 'classes' => [$class]];
        for ($i = 1; $i <= 40; ++$i) {
            $this->people->add(self::TA, 'e' . $i, 'Prénom' . $i, 'Nom' . sprintf('%02d', $i));
            $this->enrollments->add(self::TA, 'en' . $i, 'e' . $i, self::EA, $class, 'ay1');
        }
    }

    public function testFeuilleDeQuaranteElevesEnUnLotRejouableSansDoublon(): void
    {
        $class = $this->level['classes'][0];
        $entries = [];
        foreach (range(1, 40) as $i) {
            foreach ($this->competencies as $c => $competencyId) {
                $entries[] = ['person_id' => 'e' . $i, 'competency_id' => $competencyId, 'scale_code' => $c % 2 === 0 ? 'acquired' : 'in_progress', 'client_uuid' => sprintf('u-%02d-%d', $i, $c)];
            }
        }
        $first = $this->ok('PUT', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD, ['entries' => $entries]);
        self::assertSame(120, $first['saved']);
        $again = $this->ok('PUT', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD, ['entries' => $entries]);
        self::assertSame([0, 120], [$again['saved'], $again['duplicates']], 'Même client_uuid rejoué : aucun doublon.');

        $sheet = $this->ok('GET', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD);
        self::assertCount(120, $sheet['marks']);
        self::assertCount(40, $sheet['learners']);
        self::assertCount(3, $sheet['competencies']);
        self::assertSame(['acquired', 'in_progress', 'not_acquired'], array_column($sheet['scale'], 'code'));
        self::assertSame('open', $sheet['status']);
        self::assertSame(1, $sheet['marks'][0]['version']);
    }

    public function testVersionPerimeeRefuseSansEcraserEtValidation(): void
    {
        $class = $this->level['classes'][0];
        $cell = ['person_id' => 'e1', 'competency_id' => $this->competencies[0]];
        $this->ok('PUT', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD, ['entries' => [$cell + ['scale_code' => 'acquired', 'client_uuid' => 'a1']]]);
        $update = $this->ok('PUT', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD, ['entries' => [$cell + ['scale_code' => 'not_acquired', 'version' => 1, 'client_uuid' => 'a2']]]);
        self::assertSame(1, $update['saved']);
        self::assertSame(2, $update['marks'][0]['version']);

        // Un second appareil, resté à la version 1, ne peut pas écraser (et son lot entier est refusé).
        [$status, $problem] = $this->call('PUT', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD, ['entries' => [
            ['person_id' => 'e2', 'competency_id' => $this->competencies[0], 'scale_code' => 'acquired', 'client_uuid' => 'b0'],
            $cell + ['scale_code' => 'acquired', 'version' => 1, 'client_uuid' => 'b1'],
        ]]);
        self::assertSame(409, $status);
        self::assertNotEmpty($problem['errors']);
        $sheet = $this->ok('GET', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD);
        self::assertCount(1, $sheet['marks'], 'Rien du lot refusé n\'est enregistré.');
        self::assertSame('not_acquired', $sheet['marks'][0]['scale_code']);

        self::assertSame(422, $this->call('PUT', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD, ['entries' => [$cell + ['scale_code' => 'excellent']]])[0]);
        self::assertSame(422, $this->call('PUT', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD, ['entries' => [['person_id' => 'inconnu', 'competency_id' => $this->competencies[0]]]])[0]);
        self::assertSame(422, $this->call('PUT', "/primary/classes/$class/competency-sheet", ['entries' => [$cell]])[0], 'Période obligatoire.');
        self::assertSame(422, $this->call('PUT', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD, ['entries' => []])[0]);
    }

    public function testVerrouillageEtCorrectionApprouveeAvecHistorique(): void
    {
        $class = $this->level['classes'][0];
        $cell = ['person_id' => 'e1', 'competency_id' => $this->competencies[0]];
        $saved = $this->ok('PUT', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD, ['entries' => [$cell + ['scale_code' => 'in_progress', 'client_uuid' => 'c1']]]);
        $markId = $saved['marks'][0]['id'];

        self::assertSame(409, $this->call('POST', "/primary/classes/$class/competency-sheet/publish?period_id=" . self::PERIOD)[0], 'Publier exige le verrouillage.');
        self::assertSame('locked', $this->ok('POST', "/primary/classes/$class/competency-sheet/lock?period_id=" . self::PERIOD)['status']);
        self::assertSame(409, $this->call('POST', "/primary/classes/$class/competency-sheet/lock?period_id=" . self::PERIOD)[0]);
        self::assertSame(409, $this->call('PUT', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD, ['entries' => [$cell + ['scale_code' => 'acquired', 'version' => 1]]])[0], 'Modification directe refusée.');

        // Le maître demande une correction motivée ; la direction l'approuve.
        $this->assign(self::TA, 'maitre', self::EA, 'primary.competency.enter', Scope::CLASS_GROUP, $class);
        $this->assign(self::TA, 'maitre', self::EA, 'primary.competency.read', Scope::CLASS_GROUP, $class);
        self::assertSame(422, $this->call('POST', "/primary/competency-marks/$markId/corrections", ['new_code' => 'acquired', 'reason' => ' '], 'maitre')[0]);
        $correction = $this->call('POST', "/primary/competency-marks/$markId/corrections", ['new_code' => 'acquired', 'reason' => 'Erreur de saisie constatée'], 'maitre');
        self::assertSame(201, $correction[0]);
        $id = $correction[1]['data']['id'];
        self::assertSame(403, $this->call('POST', "/primary/competency-corrections/$id/approve", null, 'maitre')[0], 'Le maître n\'approuve pas.');
        self::assertSame('approved', $this->ok('POST', "/primary/competency-corrections/$id/approve")['status']);
        self::assertSame(409, $this->call('POST', "/primary/competency-corrections/$id/reject")[0]);

        $history = $this->ok('GET', "/primary/competency-marks/$markId/history")['items'];
        self::assertCount(2, $history);
        self::assertSame(['in_progress', 'acquired'], [$history[1]['previous_code'], $history[1]['new_code']]);
        self::assertSame('Erreur de saisie constatée', $history[1]['reason']);
        $sheet = $this->ok('GET', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD);
        self::assertSame(['acquired', 2], [$sheet['marks'][0]['scale_code'], $sheet['marks'][0]['version']]);
        self::assertContains('primary.competency_correction.approved', $this->audit->actions());

        self::assertTrue($this->ok('POST', "/primary/classes/$class/competency-sheet/publish?period_id=" . self::PERIOD)['published']);
        $pending = $this->ok('GET', '/primary/competency-corrections?status=pending')['items'];
        self::assertSame([], $pending);
    }

    /**
     * C110-PR2 (C-117) : import de la feuille par fichier CSV joint (multipart, champ « file ») : modèle téléchargé (une ligne par
     * élève, une colonne par compétence), refus de tout le fichier si une ligne est fausse, puis enregistrement versionné.
     */
    public function testImportDeLaFeuilleParFichierCsvJoint(): void
    {
        $class = $this->level['classes'][0];
        $template = $this->raw('GET', "/primary/classes/$class/competency-sheet/import-template?period_id=" . self::PERIOD);
        self::assertSame(200, $template->getStatusCode(), (string) $template->getBody());
        self::assertStringStartsWith('text/csv', $template->getHeaderLine('Content-Type'));
        $lines = preg_split('/\r\n/', trim(substr((string) $template->getBody(), 3)));
        self::assertIsArray($lines);
        $header = explode(';', $lines[0]);
        self::assertSame(['identifiant', 'nom'], array_slice($header, 0, 2));
        $codes = array_slice($header, 2);
        self::assertCount(3, $codes, 'Une colonne par compétence du niveau.');
        self::assertCount(41, $lines, 'Une ligne par élève.');

        // Ligne fausse : code hors échelle et élève inconnu ; rien n'est enregistré.
        $bad = "identifiant;nom;{$codes[0]}\r\ne1;Nom01;excellent\r\ninconnu;X;acquired\r\n";
        [$status, $problem] = $this->upload($class, $bad);
        self::assertSame(422, $status);
        self::assertCount(2, $problem['errors']['entries'] ?? [], (string) json_encode($problem));
        self::assertStringContainsString('Ligne 2', $problem['errors']['entries'][0]);
        self::assertCount(0, $this->ok('GET', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD)['marks']);

        // Fichier juste (Windows-1252, séparateur « , », casse libre, cellule vide ignorée).
        $good = mb_convert_encoding("identifiant,nom,{$codes[0]},{$codes[1]}\r\ne1,Élève 1,ACQUIRED,in_progress\r\ne2,Élève 2,,not_acquired\r\n", 'Windows-1252', 'UTF-8');
        [$status, $done] = $this->upload($class, $good);
        self::assertSame(200, $status, (string) json_encode($done));
        self::assertSame(['created' => 3, 'updated' => 0, 'unchanged' => 0], $done['data']);
        // Réimport modifié : mise à jour versionnée, sans conflit, valeurs identiques inchangées.
        [, $again] = $this->upload($class, "identifiant;nom;{$codes[0]};{$codes[1]}\r\ne1;;acquired;acquired\r\ne2;;;not_acquired\r\n");
        self::assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 2], $again['data']);
        $sheet = $this->ok('GET', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD);
        self::assertCount(3, $sheet['marks']);

        // Autre client : classe introuvable ; feuille verrouillée : 409.
        $this->grantAll(self::TB, 'directeur-b', self::EB);
        self::assertSame(404, $this->upload($class, $good, 'directeur-b', self::TB, self::EB)[0]);
        $this->ok('POST', "/primary/classes/$class/competency-sheet/lock?period_id=" . self::PERIOD);
        self::assertSame(409, $this->upload($class, $good)[0]);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function upload(string $class, string $csv, string $userId = self::DIRECTOR, string $tenantId = self::TA, string $establishmentId = self::EA): array
    {
        $request = (new ServerRequest('POST', "/api/v1/primary/classes/$class/competency-sheet/import?period_id=" . self::PERIOD, [
            'Authorization' => 'Bearer ' . $userId . ':' . $tenantId, 'X-Establishment-Id' => $establishmentId, 'Content-Type' => 'multipart/form-data; boundary=x',
        ]))->withQueryParams(['period_id' => self::PERIOD])
            ->withUploadedFiles(['file' => new UploadedFile(Stream::create($csv), strlen($csv), UPLOAD_ERR_OK, 'feuille.csv', 'text/csv')]);
        $response = $this->kernel->handle($request);
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }

    public function testMaitreDUneAutreClasseEtAutreClient(): void
    {
        $class = $this->level['classes'][0];
        $other = $this->makeLevel(self::TA, self::EA, 'Autre niveau', 1)['classes'][0];
        $cell = ['person_id' => 'e1', 'competency_id' => $this->competencies[0], 'scale_code' => 'acquired'];

        $this->assign(self::TA, 'maitre-b', self::EA, 'primary.competency.enter', Scope::CLASS_GROUP, $other);
        $this->assign(self::TA, 'maitre-b', self::EA, 'primary.competency.read', Scope::CLASS_GROUP, $other);
        self::assertSame(404, $this->call('GET', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD, null, 'maitre-b')[0]);
        self::assertSame(403, $this->call('PUT', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD, ['entries' => [$cell]], 'maitre-b')[0]);
        self::assertContains('primary.scope.denied', $this->audit->actions());
        $this->assign(self::TA, 'maitre-b', self::EA, 'primary.conduct.read', Scope::CLASS_GROUP, $other);
        self::assertSame(404, $this->call('GET', "/primary/classes/$class/conduct?period_id=" . self::PERIOD, null, 'maitre-b')[0]);

        $this->grantAll(self::TB, 'directeur-b', self::EB);
        self::assertSame(404, $this->call('GET', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD, null, 'directeur-b', self::TB, self::EB)[0]);
        self::assertSame(404, $this->call('PUT', "/primary/classes/$class/competency-sheet?period_id=" . self::PERIOD, ['entries' => [$cell]], 'directeur-b', self::TB, self::EB)[0]);
    }

    public function testConduiteReporteeAuConseilEtPorteesDeRegles(): void
    {
        $class = $this->level['classes'][0];
        $saved = $this->ok('PUT', "/primary/classes/$class/conduct?period_id=" . self::PERIOD, ['entries' => [
            ['person_id' => 'e1', 'scale_code' => 'very_good', 'remark' => 'Exemplaire'],
            ['person_id' => 'e2', 'scale_code' => 'fair'],
        ]]);
        self::assertSame(2, $saved['saved']);
        $this->ok('PUT', "/primary/classes/$class/conduct?period_id=" . self::PERIOD, ['entries' => [['person_id' => 'e2', 'scale_code' => 'good']]]);
        self::assertSame(422, $this->call('PUT', "/primary/classes/$class/conduct?period_id=" . self::PERIOD, ['entries' => [['person_id' => 'e1', 'scale_code' => 'inconnu']]])[0]);

        $get = $this->ok('GET', "/primary/classes/$class/conduct?period_id=" . self::PERIOD);
        self::assertSame(['very_good', 'good', 'fair', 'poor'], array_column($get['scale'], 'code'));
        self::assertCount(2, $get['marks']);

        $lines = $this->service(PrimaryCouncilLines::class)->prefill(self::TA, self::EA, $class, self::PERIOD, ['e1', 'e2', 'e3']);
        self::assertSame(['e1' => ['conduct' => 'Très bonne'], 'e2' => ['conduct' => 'Bonne']], $lines);
        self::assertSame([], $this->service(PrimaryCouncilLines::class)->prefill(self::TB, self::EB, $class, self::PERIOD, ['e1']), 'Autre client : rien.');

        $scopes = $this->service(PrimaryRuleScopes::class);
        self::assertSame([$class, $this->level['level']], $scopes->scopesOf(self::TA, self::EA, $class));
        self::assertSame([$this->level['level']], $scopes->scopesOf(self::TA, self::EA, $this->level['level']));
        self::assertSame([], $scopes->scopesOf(self::TB, self::EB, $class));
    }

    private function classOf(string $levelId): string
    {
        $classes = $this->service(\Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree::class)->structureDescendantsOf(self::TA, self::EA, $levelId);

        return $classes[1];
    }
}
