<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Core;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Nursery\Support\WriteGate;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Nursery\FakeApplicationAccess;
use Tests\Support\Nursery\NurseryTestCase;

/** EX-MAT-31 : essai, actif et impayé écrivent ; suspendu 423 en écriture ; fermé 403 ; toute route est gardée, lectures comprises. */
final class WriteGateTest extends NurseryTestCase
{
    public function testTrialActiveEtPastDueAutorisentLEcriture(): void
    {
        $access = new FakeApplicationAccess();
        $gate = new WriteGate($access);
        foreach ([ApplicationAccess::TRIAL, ApplicationAccess::ACTIVE, ApplicationAccess::PAST_DUE] as $state) {
            $access->set('tenant-a', $state);
            $gate->assertWritable('tenant-a');
            $gate->assertReadable('tenant-a');
            self::assertTrue($access->allowsWrites('tenant-a', 'nursery'));
        }
    }

    public function testSuspenduLitEtRefuseLEcritureEn423(): void
    {
        $access = new FakeApplicationAccess();
        $access->set('tenant-a', ApplicationAccess::SUSPENDED);
        $gate = new WriteGate($access);
        $gate->assertReadable('tenant-a');
        try {
            $gate->assertWritable('tenant-a');
            self::fail('Écriture suspendue acceptée.');
        } catch (HttpException $exception) {
            self::assertSame(423, $exception->status);
            self::assertSame(WriteGate::SUSPENDED_TYPE, $exception->type);
        }
    }

    public function testFermeRefuseLEcritureEn403EtLaLectureEn404(): void
    {
        $access = new FakeApplicationAccess();
        $access->set('tenant-a', ApplicationAccess::CLOSED);
        $gate = new WriteGate($access);
        try {
            $gate->assertWritable('tenant-a');
            self::fail('Écriture fermée acceptée.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->status);
        }
        try {
            $gate->assertReadable('tenant-a');
            self::fail('Lecture fermée acceptée.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }
    }

    public function testUnClientQuiNAJamaisOuvertLApplicationNeLitNiNEcrit(): void
    {
        $gate = new WriteGate(new FakeApplicationAccess());
        foreach (['assertWritable' => 403, 'assertReadable' => 404] as $method => $status) {
            try {
                $gate->{$method}('tenant-a');
                self::fail($method . ' accepté.');
            } catch (HttpException $exception) {
                self::assertSame($status, $exception->status);
            }
        }
    }

    public function testChaqueRouteDeLApplicationRespecteLaPorteSuspendueEtFermee(): void
    {
        $this->bootNursery();
        $routes = $this->kernel->routes()->all();
        $nursery = array_values(array_filter($routes, static fn (array $r): bool => str_starts_with($r['path'], '/api/v1/nursery/')));
        self::assertNotSame([], $nursery);

        $this->applications->set(self::TA, ApplicationAccess::SUSPENDED);
        $writes = 0;
        foreach ($nursery as $route) {
            $path = substr((string) preg_replace('/\{[^}]+\}/', 'x', $route['path']), strlen('/api/v1'));
            [$status, $json] = $this->call($route['method'], $path, $route['method'] === 'GET' ? null : []);
            if ($route['method'] === 'GET') {
                self::assertNotSame(423, $status, $route['path']);
                continue;
            }
            ++$writes;
            self::assertSame(423, $status, $route['method'] . ' ' . $route['path']);
            self::assertSame(WriteGate::SUSPENDED_TYPE, $json['type'] ?? null);
        }

        $this->applications->set(self::TA, ApplicationAccess::CLOSED);
        foreach ($nursery as $route) {
            $path = substr((string) preg_replace('/\{[^}]+\}/', 'x', $route['path']), strlen('/api/v1'));
            [$status, $json] = $this->call($route['method'], $path, $route['method'] === 'GET' ? null : []);
            if ($route['method'] === 'GET') {
                self::assertSame(404, $status, 'GET ' . $route['path']);
                continue;
            }
            self::assertSame(403, $status, $route['method'] . ' ' . $route['path']);
            self::assertSame(WriteGate::CLOSED_TYPE, $json['type'] ?? null);
        }
        self::assertGreaterThanOrEqual(0, $writes);
    }

    public function testSansAuthentificationToutesLesRoutesRepondent401(): void
    {
        $this->bootNursery();
        foreach ($this->kernel->routes()->all() as $route) {
            if (!str_starts_with($route['path'], '/api/v1/nursery/')) {
                continue;
            }
            $path = (string) preg_replace('/\{[^}]+\}/', 'x', $route['path']);
            $response = $this->kernel->handle(new \Nyholm\Psr7\ServerRequest($route['method'], $path, ['X-Establishment-Id' => self::EA], $route['method'] === 'GET' ? '' : '{}'));
            self::assertSame(401, $response->getStatusCode(), $route['method'] . ' ' . $route['path']);
        }
    }
}
