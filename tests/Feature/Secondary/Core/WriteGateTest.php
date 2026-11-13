<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Core;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Support\WriteGate;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Secondary\FakeApplicationAccess;
use Tests\Support\Secondary\SecondaryTestCase;

/** T810, EX-SEC-73 : porte du droit d'usage (essai/actif/impayé écrivent ; suspendu 423 ; fermé ou jamais ouvert 403, 404 en lecture). */
final class WriteGateTest extends SecondaryTestCase
{
    public function testEssaiActifEtImpayeDansLeDelaiEcrivent(): void
    {
        $access = new FakeApplicationAccess();
        $gate = new WriteGate($access);
        foreach ([ApplicationAccess::TRIAL, ApplicationAccess::ACTIVE, ApplicationAccess::PAST_DUE] as $state) {
            $access->set('client-a', $state);
            $gate->assertWritable('client-a');
            $gate->assertReadable('client-a');
        }
        $this->addToAssertionCount(1);
    }

    public function testSuspenduLectureSeulement423(): void
    {
        $access = new FakeApplicationAccess();
        $access->set('client-a', ApplicationAccess::SUSPENDED);
        $gate = new WriteGate($access);
        $gate->assertReadable('client-a');
        self::assertSame(423, self::statusOf(static fn () => $gate->assertWritable('client-a')));
    }

    public function testFermeOuJamaisOuvert403EnEcriture404EnLecture(): void
    {
        $access = new FakeApplicationAccess();
        $gate = new WriteGate($access);
        self::assertSame(403, self::statusOf(static fn () => $gate->assertWritable('client-a')));
        self::assertSame(404, self::statusOf(static fn () => $gate->assertReadable('client-a')));
        $access->set('client-a', ApplicationAccess::CLOSED);
        self::assertSame(403, self::statusOf(static fn () => $gate->assertWritable('client-a')));
        self::assertSame(404, self::statusOf(static fn () => $gate->assertReadable('client-a')));
    }

    public function testLesRoutesReellesPassentParLaPorte(): void
    {
        $this->bootSecondary();
        $school = $this->factory->opened(self::TA, 'leg');
        $this->grant(self::TA, self::ADMIN, $school, ['secondary.catalogue.read', 'secondary.settings.manage', 'secondary.profile.read']);
        $body = ['code' => 'XLAT', 'cycle_code' => 'second', 'teaching_kind' => 'general', 'label_fr' => 'Série fictive', 'source_ref' => 'Note fictive de test'];

        $this->applications->set(self::TA, ApplicationAccess::SUSPENDED);
        self::assertSame(200, $this->call('GET', '/secondary/setup/profile', null, self::ADMIN, self::TA, $school)[0]);
        self::assertSame(200, $this->call('GET', '/secondary/catalogue/streams', null, self::ADMIN, self::TA, $school)[0]);
        self::assertSame(423, $this->call('POST', '/secondary/catalogue/streams', $body, self::ADMIN, self::TA, $school)[0]);

        $this->applications->set(self::TA, ApplicationAccess::CLOSED);
        self::assertSame(404, $this->call('GET', '/secondary/setup/profile', null, self::ADMIN, self::TA, $school)[0]);
        self::assertSame(403, $this->call('POST', '/secondary/catalogue/streams', $body, self::ADMIN, self::TA, $school)[0]);

        $this->applications->set(self::TA, ApplicationAccess::TRIAL);
        self::assertSame(201, $this->call('POST', '/secondary/catalogue/streams', $body, self::ADMIN, self::TA, $school)[0]);
    }

    private static function statusOf(\Closure $action): int
    {
        try {
            $action();
        } catch (HttpException $exception) {
            return $exception->status;
        }

        return 200;
    }
}
