<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Pupils;

use DateTimeImmutable;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\DropoutService;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/** EX-ELV-06 : abandon déclaré ou présumé, relance de la famille, résultat ; tâche `public:dropout-alerts`. Données SYN-*. */
final class DropoutTest extends PublicSchoolsTestCase
{
    private FakeDailyPresence $presence;
    private string $pupil = '';
    private string $other = '';

    protected function setUp(): void
    {
        $this->presence = new FakeDailyPresence();
        $this->bootPublic(true, [DailyPresence::class => $this->presence]);
        $this->seedSchool('t1', 'e1', 'SYN-E1');
        $this->seedSchool('t2', 'e9', 'SYN-E9');
        $this->director('dir1', 't1', 'e1', 'public_schools.pupils.read', 'public_schools.pupils.write');
        $this->director('dir9', 't2', 'e9', 'public_schools.pupils.read', 'public_schools.pupils.write');
        $class = $this->createClass('t1', 'e1', 'SIL A SYN');
        $this->pupil = $this->register('SYN-Absent', $class);
        $this->other = $this->register('SYN-Present', $class);
    }

    private function register(string $last, string $class): string
    {
        [$response, $json] = $this->api('POST', '/public/pupils', 'dir1', 't1', 'e1', [
            'last_name' => $last, 'first_name' => 'Awa', 'birth_date' => '2020-03-14', 'sex' => 'F', 'birth_act_ref' => 'SYN-ACTE-' . $last, 'class_id' => $class,
            'guardians' => [['kind' => 'parent', 'identity' => ['first_name' => 'Jean', 'last_name' => $last, 'phone' => '677000111']]],
        ]);
        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));

        return $json['data']['person_id'];
    }

    public function testDeclarationRelanceEtResultat(): void
    {
        [$declared, $json] = $this->api('POST', '/public/dropouts', 'dir1', 't1', 'e1', ['person_id' => $this->pupil, 'note' => 'Déménagement signalé']);
        self::assertSame(201, $declared->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));
        self::assertSame(['declared', 'dir1', '2026-10-21'], [$json['data']['kind'], $json['data']['declared_by'], $json['data']['detected_on']]);
        $id = $json['data']['id'];

        self::assertSame(409, $this->code('POST', '/public/dropouts', 'dir1', 't1', 'e1', ['person_id' => $this->pupil]), 'Un seul dossier ouvert par élève.');
        self::assertSame(404, $this->code('POST', '/public/dropouts', 'dir1', 't1', 'e1', ['person_id' => 'inconnu']));
        [, $contacted] = $this->api('POST', '/public/dropouts/' . $id . '/contact', 'dir1', 't1', 'e1');
        self::assertNotNull($contacted['data']['family_contacted_at']);
        self::assertSame(422, $this->code('POST', '/public/dropouts/' . $id . '/resolve', 'dir1', 't1', 'e1', ['outcome' => 'Revenu !']));
        [, $resolved] = $this->api('POST', '/public/dropouts/' . $id . '/resolve', 'dir1', 't1', 'e1', ['outcome' => 'returned']);
        self::assertSame(['returned', '2026-10-21'], [$resolved['data']['outcome'], $resolved['data']['resolved_on']]);
        self::assertSame(409, $this->code('POST', '/public/dropouts/' . $id . '/resolve', 'dir1', 't1', 'e1', ['outcome' => 'confirmed']));

        [, $open] = $this->api('GET', '/public/dropouts', 'dir1', 't1', 'e1');
        self::assertSame([], $open['data']);
        [, $all] = $this->api('GET', '/public/dropouts?state=all', 'dir1', 't1', 'e1');
        self::assertCount(1, $all['data']);
        self::assertSame(201, $this->code('POST', '/public/dropouts', 'dir1', 't1', 'e1', ['person_id' => $this->pupil]), 'Un nouveau dossier est possible une fois le précédent résolu.');
    }

    public function testAbandonPresumeApresAbsenceContinueDeJoursOuvres(): void
    {
        $this->settings->values['public_schools.dropout.days'] = 3;
        // Lundi 26 octobre 2026 : trois jours ouvrés = lundi 26, vendredi 23 et jeudi 22 (week-end exclu).
        foreach (['2026-10-26', '2026-10-23', '2026-10-22'] as $day) {
            $this->presence->set('t1', 'e1', $day, [$this->pupil]);
        }
        $this->presence->set('t1', 'e1', '2026-10-23', [$this->pupil, $this->other]);
        $this->presence->set('t1', 'e1', '2026-10-22', [$this->pupil]);

        $service = $this->kernel->container()->get(DropoutService::class);
        $opened = $service->detectPresumed('t1', 'e1', new DateTimeImmutable('2026-10-26'));

        self::assertSame([$this->pupil], $opened);
        [, $list] = $this->api('GET', '/public/dropouts', 'dir1', 't1', 'e1');
        self::assertSame(['presumed', '2026-10-26', null], [$list['data'][0]['kind'], $list['data'][0]['detected_on'], $list['data'][0]['declared_by']]);
        self::assertNotNull($list['data'][0]['family_contacted_at'], 'La famille est relancée.');
        $kinds = array_column($this->messages->sent, 'kind');
        self::assertContains('guardians', $kinds);
        self::assertContains('director', $kinds);
        self::assertSame('public_dropout_followup', $this->messages->sent[count($this->messages->sent) - 1]['template']);

        self::assertSame([], $service->detectPresumed('t1', 'e1', new DateTimeImmutable('2026-10-26')), 'Pas de second dossier tant que le premier est ouvert.');
    }

    public function testUneSerieInterrompueNeDeclencheRien(): void
    {
        $this->settings->values['public_schools.dropout.days'] = 3;
        $this->presence->set('t1', 'e1', '2026-10-21', [$this->pupil]);
        $this->presence->set('t1', 'e1', '2026-10-20', []);
        $this->presence->set('t1', 'e1', '2026-10-19', [$this->pupil]);

        self::assertSame([], $this->kernel->container()->get(DropoutService::class)->detectPresumed('t1', 'e1', new DateTimeImmutable('2026-10-21')));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM pub_dropouts'));
    }

    public function testLeSeuilVientDuParametreEtLaCommandeSauteUnClientSuspendu(): void
    {
        $this->settings->values['public_schools.dropout.days'] = 2;
        foreach (['2026-10-21', '2026-10-20'] as $day) {
            $this->presence->set('t1', 'e1', $day, [$this->pupil]);
        }
        $this->applications->set('t1', 'public_primary', ApplicationAccess::SUSPENDED);
        $command = $this->kernel->container()->get('console.command.public:dropout-alerts');

        ob_start();
        $status = $command->run(['2026-10-21']);
        $output = (string) ob_get_clean();
        self::assertSame(0, $status);
        self::assertStringContainsString('écoles sautées (application non active) : 1', $output);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM pub_dropouts'));

        $this->applications->set('t1', 'public_primary', ApplicationAccess::ACTIVE);
        ob_start();
        $command->run(['2026-10-21']);
        ob_end_clean();
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM pub_dropouts WHERE tenant_id = ?', ['t1']));
    }

    public function testIsolationEntreClientsEtDroits(): void
    {
        [, $json] = $this->api('POST', '/public/dropouts', 'dir1', 't1', 'e1', ['person_id' => $this->pupil]);
        $id = $json['data']['id'];
        $this->teacher('maitre', 't1', 'e1', 'c1', 'public_schools.pupils.read', 'public_schools.pupils.write');

        self::assertSame(404, $this->code('POST', '/public/dropouts/' . $id . '/contact', 'dir9', 't2', 'e9'));
        self::assertSame(404, $this->code('POST', '/public/dropouts/' . $id . '/resolve', 'dir9', 't2', 'e9', ['outcome' => 'returned']));
        self::assertSame(404, $this->code('POST', '/public/dropouts', 'dir9', 't2', 'e9', ['person_id' => $this->pupil]), 'Élève d\'un autre client.');
        self::assertSame([], $this->api('GET', '/public/dropouts?state=all', 'dir9', 't2', 'e9')[1]['data']);
        self::assertSame(403, $this->code('POST', '/public/dropouts', 'maitre', 't1', 'e1', ['person_id' => $this->other]));
        self::assertSame(404, $this->code('GET', '/public/dropouts', 'maitre', 't1', 'e1'));
    }
}
