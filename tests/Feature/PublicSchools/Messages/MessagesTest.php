<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Messages;

use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateRenderer;
use Tests\Support\PublicSchools\PublicSchoolsTestCase;

/**
 * T640 : communication des écoles publiques (EX-COM-01, EX-COM-05, RG-COM-1, RG-COM-2) : SMS par classe, niveau ou école,
 * implémentation de PublicMessages, annonces territoriales et modèles bilingues.
 */
final class MessagesTest extends PublicSchoolsTestCase
{
    protected function setUp(): void
    {
        $this->bootPublic();
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>}
     */
    private function send(string $user, array $body): array
    {
        return $this->api('POST', '/public/messages', $user, $body + ['template_key' => 'pub.class_notice', 'variables' => ['details' => 'Réunion des parents samedi à 9 h.']]);
    }

    public function testUnEnseignantEcritAuxFamillesDeSaClasse(): void
    {
        [$response, $json] = $this->send('teacher', ['audience' => 'class', 'target' => 'c1']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(2, $json['data']['recipients_count']);
        self::assertSame(1, $json['data']['classes_count']);
        self::assertFalse($json['data']['replayed']);
        $sent = $this->messenger->of('pub.class_notice');
        self::assertSame(['p-parent1', 'p-parent2'], array_column($sent, 'person'));
        self::assertSame('Réunion des parents samedi à 9 h.', $sent[0]['variables']['details']);
        self::assertSame('CP1', $sent[0]['variables']['class_name']);
        self::assertSame('École publique de Bonamoussadi', $sent[0]['variables']['establishment_name']);
        self::assertCount(1, $this->rows('SELECT id FROM pub_message_batches'));
        self::assertCount(1, $this->rows("SELECT action FROM audit_log WHERE action = 'public_schools.message.sent'"));
    }

    public function testLeRejouDUnEnvoiAvecLeMemeIdentifiantNEnvoieRienDeNouveau(): void
    {
        $uuid = self::ulid();
        $this->send('teacher', ['audience' => 'class', 'target' => 'c1', 'client_uuid' => $uuid]);

        [$response, $json] = $this->send('teacher', ['audience' => 'class', 'target' => 'c1', 'client_uuid' => $uuid]);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($json['data']['replayed']);
        self::assertCount(2, $this->messenger->sent);
        self::assertCount(1, $this->rows('SELECT id FROM pub_message_batches'));
    }

    public function testSeulLeDirecteurEcritAUnNiveauOuATouteLEcole(): void
    {
        [$teacherLevel] = $this->send('teacher', ['audience' => 'level', 'target' => 'CP']);
        [$teacherSchool] = $this->send('teacher', ['audience' => 'school']);
        [$otherClass] = $this->send('teacher', ['audience' => 'class', 'target' => 'c2']);
        [$level, $levelJson] = $this->send('director', ['audience' => 'level', 'target' => 'CP']);
        [$school, $schoolJson] = $this->send('director', ['audience' => 'school']);

        self::assertSame(403, $teacherLevel->getStatusCode());
        self::assertSame(403, $teacherSchool->getStatusCode());
        self::assertSame(403, $otherClass->getStatusCode());
        self::assertSame(201, $level->getStatusCode(), (string) $level->getBody());
        self::assertSame(1, $levelJson['data']['classes_count']);
        self::assertSame(2, $levelJson['data']['recipients_count']);
        self::assertSame(201, $school->getStatusCode(), (string) $school->getBody());
        self::assertSame(2, $schoolJson['data']['classes_count']);
        self::assertCount(2, $this->rows('SELECT id FROM pub_message_batches'));
        self::assertCount(3, $this->rows('SELECT structure_id FROM pub_message_batch_classes'));
    }

    public function testLesEntreesInvalidesSontRefuseesParChamp(): void
    {
        [$audience, $audienceJson] = $this->send('director', ['audience' => 'planete']);
        [$target, $targetJson] = $this->send('director', ['audience' => 'level']);
        [$template, $templateJson] = $this->send('director', ['audience' => 'school', 'template_key' => 'absence_notice']);
        [$variable, $variableJson] = $this->send('director', ['audience' => 'school', 'variables' => ['password' => 'x']]);
        [$long, $longJson] = $this->send('director', ['audience' => 'school', 'variables' => ['details' => str_repeat('a', 201)]]);
        [$unknown] = $this->send('director', ['audience' => 'level', 'target' => 'CM2']);
        [$noClass] = $this->send('director', ['audience' => 'class', 'target' => 'c-inconnue']);

        self::assertSame(422, $audience->getStatusCode());
        self::assertArrayHasKey('audience', $audienceJson['errors']);
        self::assertSame(422, $target->getStatusCode());
        self::assertArrayHasKey('target', $targetJson['errors']);
        self::assertSame(422, $template->getStatusCode());
        self::assertArrayHasKey('template_key', $templateJson['errors']);
        self::assertSame(422, $variable->getStatusCode());
        self::assertArrayHasKey('variables', $variableJson['errors']);
        self::assertSame(422, $long->getStatusCode());
        self::assertArrayHasKey('variables.details', $longJson['errors']);
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame(404, $noClass->getStatusCode());
        self::assertSame([], $this->messenger->sent);
    }

    public function testLesModelesDiffusablesSontUnParametre(): void
    {
        $this->settings->values['public_schools.messages.broadcast_templates'] = ['pub.not14.parent'];

        [$default] = $this->send('director', ['audience' => 'school']);
        [$closure, $closureJson] = $this->send('director', ['audience' => 'school', 'template_key' => 'pub.not14.parent', 'variables' => ['date' => '2026-10-12', 'details' => 'Deuil national.']]);

        self::assertSame(422, $default->getStatusCode());
        self::assertSame(201, $closure->getStatusCode(), (string) $closure->getBody());
        self::assertSame(2, $closureJson['data']['recipients_count']);
    }

    public function testSansPermissionOuAvecUnPerimetreVideAucunMessageNePart(): void
    {
        [$outsider] = $this->send('outsider', ['audience' => 'class', 'target' => 'c1']);
        [$other] = $this->send('inspector2', ['audience' => 'class', 'target' => 'c1']);

        self::assertSame(403, $outsider->getStatusCode());
        self::assertContains($other->getStatusCode(), [403, 404]);
        self::assertSame([], $this->messenger->sent);
    }

    public function testLeJournalDesEnvoisEstPagineEtNeContientAucunDestinataire(): void
    {
        $this->send('director', ['audience' => 'class', 'target' => 'c1']);
        $this->send('director', ['audience' => 'class', 'target' => 'c2']);

        [, $first] = $this->api('GET', '/public/messages?per_page=1', 'director');
        [, $second] = $this->api('GET', '/public/messages?per_page=1&cursor=' . $first['meta']['next_cursor'], 'director');
        [$outsider] = $this->api('GET', '/public/messages', 'outsider');
        [$tooMany] = $this->api('GET', '/public/messages?per_page=201', 'director');

        self::assertCount(1, $first['data']);
        self::assertNotNull($first['meta']['next_cursor']);
        self::assertCount(1, $second['data']);
        self::assertNotSame($first['data'][0]['id'], $second['data'][0]['id']);
        self::assertStringNotContainsString('p-parent', (string) json_encode([$first, $second]));
        self::assertSame(404, $outsider->getStatusCode());
        self::assertSame(422, $tooMany->getStatusCode());
    }

    public function testLeContratPublicMessagesRespecteLesMessagesEssentielsEtLesPannes(): void
    {
        $messages = $this->container()->get(PublicMessages::class);

        self::assertTrue($messages->isEssential('t1', 'pub.not14.parent'));
        self::assertFalse($messages->isEssential('t1', 'pub.class_notice'));
        self::assertSame(2, $messages->notifyClass('t1', 'e1', 'c1', 'pub.class_notice', ['details' => 'Test']));
        self::assertSame(1, $messages->notifyGuardians('t1', 'e1', 's1', 'pub.not01.parent', ['reference' => 'SYN-2026-0000001']));
        self::assertSame('Yann Foka', $this->messenger->of('pub.not01.parent')[0]['variables']['child_name']);
        self::assertSame(0, $messages->notifyGuardians('t1', 'e1', 's3', 'pub.not01.parent'), 'Aucun garant : zéro destinataire.');
        self::assertSame(0, $messages->notifyGuardians('t2', 'e1', 's1', 'pub.not01.parent'), 'Un autre client ne trouve pas l\'élève.');
        self::assertSame(0, $messages->notifyClass('t1', 'e2', 'c1', 'pub.class_notice'), 'La classe n\'est pas dans cette école.');
        self::assertTrue($messages->notifyDirector('t1', 'e1', 'pub.not12.director', ['class_name' => 'CP1', 'details' => '3', 'date' => self::DAY]));
        self::assertFalse($messages->notifyDirector('t1', 'e2', 'pub.not12.director'), 'Une école sans directeur connu ne prévient personne.');

        $this->messenger->fail = true;
        self::assertSame(0, $messages->notifyClass('t1', 'e1', 'c1', 'pub.class_notice'), 'Une panne de messagerie ne remonte pas à l\'appelant.');
        self::assertFalse($messages->notifyDirector('t1', 'e1', 'pub.not12.director'));
    }

    public function testLaListeDesMessagesEssentielsEstUnParametre(): void
    {
        $this->settings->values['public_schools.messages.essential_templates'] = ['pub.not14.parent', 'pub.not13.director'];

        self::assertTrue($this->container()->get(PublicMessages::class)->isEssential('t1', 'pub.not13.director'));
    }

    public function testUnTerritoireNEstAnnonceQuParSonInspection(): void
    {
        [$response, $json] = $this->api('POST', '/public/announcements', 'inspector', ['territory_id' => 'arr-1', 'title' => 'Calendrier des évaluations', 'body' => 'Les évaluations commencent le 12 octobre.']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, $json['data']['establishments_count']);
        $published = $this->rows("SELECT establishment_id, origin_territory_id, created_by FROM announcements WHERE title = 'Calendrier des évaluations'");
        self::assertSame([['establishment_id' => 'e1', 'origin_territory_id' => 'arr-1', 'created_by' => 'inspector']], $published);
        self::assertCount(1, $this->rows('SELECT id FROM pub_territory_announcements'));
        self::assertCount(1, $this->rows("SELECT action FROM audit_log WHERE action = 'public_schools.announcement.published'"));
    }

    public function testUneAnnonceHorsDuTerritoireDeLAuteurEstRefuseeEtJournalisee(): void
    {
        [$parent] = $this->api('POST', '/public/announcements', 'inspector', ['territory_id' => 'dep-1', 'title' => 'Annonce', 'body' => 'Texte.']);
        [$sibling] = $this->api('POST', '/public/announcements', 'inspector', ['territory_id' => 'arr-2', 'title' => 'Annonce', 'body' => 'Texte.']);
        [$director] = $this->api('POST', '/public/announcements', 'director', ['territory_id' => 'arr-1', 'title' => 'Annonce', 'body' => 'Texte.']);
        [$teacher] = $this->api('POST', '/public/announcements', 'teacher', ['territory_id' => 'arr-1', 'title' => 'Annonce', 'body' => 'Texte.']);

        self::assertSame(403, $parent->getStatusCode(), 'Un inspecteur d\'arrondissement n\'annonce pas à tout le département.');
        self::assertSame(403, $sibling->getStatusCode());
        self::assertSame(403, $director->getStatusCode());
        self::assertSame(403, $teacher->getStatusCode());
        self::assertSame([], $this->rows('SELECT id FROM announcements'));
        self::assertNotSame([], $this->rows("SELECT action FROM audit_log WHERE action = 'public_schools.scope.denied'"));
    }

    public function testUneAnnonceInvalideOuVersUnTerritoireInconnuEstRefusee(): void
    {
        [$unknown] = $this->api('POST', '/public/announcements', 'inspector', ['territory_id' => 'arr-inconnu', 'title' => 'Annonce', 'body' => 'Texte.']);
        [$noTerritory, $noTerritoryJson] = $this->api('POST', '/public/announcements', 'inspector', ['title' => 'Annonce', 'body' => 'Texte.']);
        [$noTitle, $noTitleJson] = $this->api('POST', '/public/announcements', 'inspector', ['territory_id' => 'arr-1', 'body' => 'Texte.']);
        [$badDate, $badDateJson] = $this->api('POST', '/public/announcements', 'inspector', ['territory_id' => 'arr-1', 'title' => 'A', 'body' => 'B', 'expires_at' => 'bientôt']);

        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame(422, $noTerritory->getStatusCode());
        self::assertArrayHasKey('territory_id', $noTerritoryJson['errors']);
        self::assertSame(422, $noTitle->getStatusCode());
        self::assertArrayHasKey('title', $noTitleJson['errors']);
        self::assertSame(422, $badDate->getStatusCode());
        self::assertArrayHasKey('expires_at', $badDateJson['errors']);
        self::assertSame([], $this->rows('SELECT id FROM announcements'));
    }

    public function testLesModelesBilinguesDeNotificationExistentAvecDesVariablesAutorisees(): void
    {
        $keys = [
            'pub.not01.parent', 'pub.not03.parent', 'pub.not03.director', 'pub.not04.parent', 'pub.not05.parent', 'pub.not08.parent', 'pub.not09.parent',
            'pub.not10.payer', 'pub.not11.supervisor', 'pub.not12.director', 'pub.not12.inspection', 'pub.not13.director', 'pub.not13.inspection',
            'pub.not14.parent', 'pub.class_notice',
        ];
        $rows = $this->rows("SELECT template_key, locale, channel, body, variables, category, tenant_id FROM message_templates WHERE template_key LIKE 'pub.%'");
        foreach ($keys as $key) {
            $locales = array_column(array_filter($rows, static fn (array $row): bool => $row['template_key'] === $key), 'locale');
            sort($locales);
            self::assertSame(['en', 'fr'], $locales, $key . ' : modèle FR et EN attendus.');
        }
        foreach ($rows as $row) {
            self::assertSame('platform', $row['tenant_id']);
            $declared = json_decode((string) $row['variables'], true);
            self::assertSame([], array_diff(TemplateRenderer::placeholders((string) $row['body']), $declared), $row['template_key'] . ' : variable du texte non déclarée.');
            self::assertSame([], array_diff($declared, TemplateRenderer::ALLOWED), $row['template_key'] . ' : variable hors liste blanche du socle.');
            self::assertSame($row['template_key'] === 'pub.not14.parent' ? 'essential' : 'standard', $row['category'], $row['template_key']);
        }
    }
}
