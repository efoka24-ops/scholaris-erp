<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Organisation\Application\ClosureProviders;
use Scholaris\Modules\Socle\Organisation\Application\SettingsService;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureStepResult;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;
use Scholaris\Modules\Socle\Organisation\Contracts\TypedPromotionPlanner;
use Scholaris\Modules\Socle\Organisation\Domain\SettingScope;
use Tests\Support\Organisation\ClosureDoubles;
use Tests\Support\Organisation\OrganisationTestCase;

/** D6 (paramètres par école/département, héritage établissement > école > département) et D8 (planificateur de passage typé). */
final class OrgUnitSettingsAndPromotionTest extends OrganisationTestCase
{
    private ClosureDoubles $generic;
    private TypedPromotionPlanner $higher;
    private string $school = '';
    private string $dept = '';

    protected function setUp(): void
    {
        $this->generic = new ClosureDoubles();
        $this->higher = new class () implements TypedPromotionPlanner {
            public int $calls = 0;

            public function establishmentTypes(): array
            {
                return ['higher'];
            }

            public function promote(string $tenantId, string $establishmentId, string $fromYearId, string $toYearId): ClosureStepResult
            {
                ++$this->calls;

                return new ClosureStepResult(ClosureStepResult::DONE, 'Décisions de passage IPES.', ['admitted' => 1]);
            }
        };
        $this->bootOrganisation([
            'organisation.closure.promotion.test' => [$this->generic],
            'organisation.closure.promotion.higher' => [$this->higher],
            'organisation.closure.promotion.people' => [],
        ]);
        $this->seedEstablishment('t1', 'e1', 'Institut', 'higher', 'alice');
        $this->seedEstablishment('t1', 'e2', 'Collège', 'secondary', 'alice');
        $this->seedEstablishment('t2', 'x1', 'Autre client', 'higher', 'bob');
        $this->grantAll('alice');
        $this->grantAll('bob');
        $w = $this->kernel->container()->get(StructureWriter::class);
        $this->school = $w->createOrgUnit('t1', 'e1', 'school', 'École de commerce');
        $this->dept = $w->createOrgUnit('t1', 'e1', 'department', 'Comptabilité', $this->school);
    }

    private function settings(): SettingsService
    {
        return $this->kernel->container()->get(SettingsService::class);
    }

    public function testHeritageEtablissementEcoleDepartement(): void
    {
        $reader = $this->kernel->container()->get(OrgUnitSettingsReader::class);
        $s = $this->settings();
        $s->set(SettingScope::Establishment, 't1', 'e1', 'higher.resit.max_per_unit', 1, 'alice');
        self::assertSame(1, $reader->valueForOrgUnit('t1', 'e1', $this->dept, 'higher.resit.max_per_unit'));

        $s->setForOrgUnit('t1', 'e1', $this->school, 'higher.resit.max_per_unit', 2, 'alice');
        self::assertSame(2, $reader->valueForOrgUnit('t1', 'e1', $this->dept, 'higher.resit.max_per_unit'), 'Le département hérite de son école.');
        $s->setForOrgUnit('t1', 'e1', $this->dept, 'higher.resit.max_per_unit', 3, 'alice');
        self::assertSame(3, $reader->valueForOrgUnit('t1', 'e1', $this->dept, 'higher.resit.max_per_unit'));

        $effective = $s->effective('t1', 'e1', 'higher.resit.max_per_unit', $this->dept);
        self::assertSame([SettingScope::OrgUnit, $this->dept], [$effective?->source, $effective->sourceOrgUnitId]);
        self::assertSame([['org_unit', 2], ['establishment', 1]], array_map(static fn ($e): array => [$e->scope->value, $e->value], $effective->overridden), 'Surcharge visible (RG-ORG-5).');

        self::assertSame(1, $reader->value('t1', 'e1', 'higher.resit.max_per_unit'), 'value() sans unité : inchangé.');
        self::assertSame(1, $reader->valueForOrgUnit('t1', 'e1', null, 'higher.resit.max_per_unit'));
        self::assertSame(1, $this->kernel->container()->get(SettingsReader::class)->value('t1', 'e1', 'higher.resit.max_per_unit'));

        $audit = $this->connections()->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE object_id LIKE 'org_unit:%'")->fetchColumn();
        self::assertSame(2, (int) $audit, 'Surcharges tracées.');
    }

    public function testIsolationDesUnites(): void
    {
        foreach ([fn () => $this->settings()->setForOrgUnit('t2', 'x1', $this->school, 'a.b', 1, 'bob'), fn () => $this->settings()->getForOrgUnit('t1', 'e2', $this->school, 'a.b'), fn () => $this->settings()->effective('t2', 'e1', 'a.b', $this->school)] as $call) {
            try {
                $call();
                self::fail('404 attendu.');
            } catch (HttpException $e) {
                self::assertSame(404, $e->status);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->settings()->set(SettingScope::OrgUnit, 't1', 'e1', 'a.b', 1, 'alice');
    }

    public function testParLApi(): void
    {
        [$put, $body] = $this->api('PUT', '/settings', 'alice', 't1', 'e1', ['scope' => 'org_unit', 'org_unit_id' => $this->school, 'key' => 'higher.signatory', 'value' => 'Directeur EC']);
        self::assertSame(200, $put->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR));
        self::assertSame($this->school, $body['data']['org_unit_id']);

        [, $list] = $this->api('GET', '/settings?filter[scope]=org_unit&filter[org_unit_id]=' . $this->school, 'alice', 't1', 'e1');
        self::assertSame(['higher.signatory'], array_column($list['data'], 'key'));
        [, $effective] = $this->api('GET', '/settings/effective?filter[key]=higher.signatory&filter[org_unit_id]=' . $this->dept, 'alice', 't1', 'e1');
        self::assertSame(['Directeur EC', 'org_unit', $this->school], [$effective['data']['value'], $effective['data']['source'], $effective['data']['source_org_unit_id']]);
        self::assertSame(404, $this->code('GET', '/settings/effective?filter[key]=higher.signatory', 'alice', 't1', 'e1'), 'Établissement seul : non défini.');

        self::assertSame(422, $this->code('PUT', '/settings', 'alice', 't1', 'e1', ['scope' => 'org_unit', 'key' => 'a.b', 'value' => 1]));
        self::assertSame(422, $this->code('PUT', '/settings', 'alice', 't1', 'e1', ['scope' => 'establishment', 'org_unit_id' => $this->school, 'key' => 'a.b', 'value' => 1]));
        self::assertSame(422, $this->code('GET', '/settings?filter[scope]=org_unit', 'alice', 't1', 'e1'));
        self::assertSame(404, $this->code('PUT', '/settings', 'bob', 't2', 'x1', ['scope' => 'org_unit', 'org_unit_id' => $this->school, 'key' => 'a.b', 'value' => 1]), 'Unité d\'un autre client.');
    }

    public function testLePlanificateurTypeRemplaceLeGeneriquePourSonTypeSeulement(): void
    {
        $providers = $this->kernel->container()->get(ClosureProviders::class);

        self::assertSame([$this->higher], $providers->promotersFor('higher'));
        self::assertSame([$this->generic], $providers->promotersFor('secondary'), 'Secondaire : planificateurs génériques, comportement inchangé.');
        self::assertSame([$this->generic], $providers->promotersFor('primary'));
    }
}
