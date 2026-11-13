<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use ReflectionClass;
use ReflectionMethod;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupInfo;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;
use Scholaris\Modules\Socle\Attendance\Infrastructure\PdoAuthorizedPickupRegistry;
use Tests\Support\Attendance\AttendanceTestCase;

/** PM1 (T700) : contrat des personnes autorisées à récupérer un enfant, au-dessus de `authorized_pickups`. Données SYN-* uniquement. */
final class AuthorizedPickupRegistryTest extends AttendanceTestCase
{
    private const NEXT_DAY = '2026-10-09';

    protected function setUp(): void
    {
        $this->bootAttendance();
    }

    private function registry(): AuthorizedPickupRegistry
    {
        return $this->container()->get(AuthorizedPickupRegistry::class);
    }

    /**
     * Crée une autorisation par la route existante.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function grantByApi(array $body, string $learner = 's1'): array
    {
        [$response, $json] = $this->api('POST', '/authorized-pickups', 'surveillant', $body + ['learner_id' => $learner, 'relationship' => 'tante']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    /**
     * @param list<AuthorizedPickupInfo> $infos
     * @return list<string>
     */
    private static function names(array $infos): array
    {
        $names = array_map(static fn (AuthorizedPickupInfo $info): string => $info->name, $infos);
        sort($names);

        return $names;
    }

    /** @return array{permanent: array<string, mixed>, today: array<string, mixed>, other: array<string, mixed>, revoked: array<string, mixed>} */
    private function seed(): array
    {
        $permanent = $this->grantByApi(['pickup_name' => 'SYN-Tante Rose', 'grant_type' => 'permanent', 'phone' => '+237670000111', 'photo_document_ref' => 'ref-photo-1']);
        $today = $this->grantByApi(['pickup_name' => 'SYN-Oncle Paul', 'grant_type' => 'day', 'valid_on' => self::DAY]);
        $other = $this->grantByApi(['pickup_name' => 'SYN-Cousin Luc', 'grant_type' => 'day', 'valid_on' => self::NEXT_DAY]);
        $revoked = $this->grantByApi(['pickup_name' => 'SYN-Ancien Voisin', 'grant_type' => 'permanent']);
        [$response] = $this->api('POST', '/authorized-pickups/' . $revoked['id'] . '/revoke', 'surveillant');
        self::assertSame(200, $response->getStatusCode());

        return compact('permanent', 'today', 'other', 'revoked');
    }

    public function testLeContratEstLieAImplementationPdo(): void
    {
        self::assertInstanceOf(PdoAuthorizedPickupRegistry::class, $this->registry());
    }

    public function testValidOnRetientLePermanentEtLeJourSeulementPasLeRevoque(): void
    {
        $this->seed();

        $today = $this->registry()->validOn('t1', 'e1', ['s1'], self::DAY);
        $tomorrow = $this->registry()->validOn('t1', 'e1', ['s1'], self::NEXT_DAY);
        $later = $this->registry()->validOn('t1', 'e1', ['s1'], '2026-10-20');

        self::assertSame(['SYN-Oncle Paul', 'SYN-Tante Rose'], self::names($today), 'permanent + du jour ; ni le jour suivant ni le révoqué');
        self::assertSame(['SYN-Cousin Luc', 'SYN-Tante Rose'], self::names($tomorrow));
        self::assertSame(['SYN-Tante Rose'], self::names($later), 'seule la permanente survit, l\'autorisation d\'un autre jour est expirée');
    }

    public function testValidOnRendLesChampsDuContrat(): void
    {
        $seed = $this->seed();

        $infos = $this->registry()->validOn('t1', 'e1', ['s1'], self::DAY);
        $rose = array_values(array_filter($infos, static fn (AuthorizedPickupInfo $i): bool => $i->name === 'SYN-Tante Rose'))[0];
        $paul = array_values(array_filter($infos, static fn (AuthorizedPickupInfo $i): bool => $i->name === 'SYN-Oncle Paul'))[0];

        self::assertSame([$seed['permanent']['id'], 's1', 'tante', '+237670000111', 'ref-photo-1', 'permanent', null, 'active'], [$rose->id, $rose->learnerId, $rose->relationship, $rose->phone, $rose->photoRef, $rose->grantType, $rose->validOn, $rose->status]);
        self::assertSame(['day', self::DAY, null, null], [$paul->grantType, $paul->validOn, $paul->phone, $paul->photoRef]);
    }

    public function testValidOnFiltreParEnfantEtAccepteUneListeVide(): void
    {
        $this->seed();
        $this->grantByApi(['pickup_name' => 'SYN-Grand-mère', 'grant_type' => 'permanent'], 's2');

        self::assertSame([], $this->registry()->validOn('t1', 'e1', [], self::DAY));
        self::assertSame(['SYN-Grand-mère'], self::names($this->registry()->validOn('t1', 'e1', ['s2', 's3'], self::DAY)));
        self::assertCount(3, $this->registry()->validOn('t1', 'e1', ['s1', 's2', 's3'], self::DAY));
        self::assertSame([], $this->registry()->validOn('t1', 'e1', ['inconnu'], self::DAY));
    }

    public function testFindDesigneUneAutorisationParSonIdentifiant(): void
    {
        $seed = $this->seed();

        $info = $this->registry()->find('t1', 'e1', $seed['permanent']['id']);
        $revoked = $this->registry()->find('t1', 'e1', $seed['revoked']['id']);
        $other = $this->registry()->find('t1', 'e1', $seed['other']['id']);

        self::assertNotNull($info);
        self::assertSame('SYN-Tante Rose', $info->name);
        self::assertTrue($info->isValidOn(self::DAY));
        self::assertNotNull($revoked);
        self::assertSame('revoked', $revoked->status);
        self::assertFalse($revoked->isValidOn(self::DAY), 'une autorisation révoquée n\'est jamais valable');
        self::assertNotNull($other);
        self::assertFalse($other->isValidOn(self::DAY), 'une autorisation d\'un autre jour n\'est pas valable');
        self::assertTrue($other->isValidOn(self::NEXT_DAY));
        self::assertNull($this->registry()->find('t1', 'e1', 'inconnue'));
    }

    public function testAucuneMethodeDuContratNeDesigneUneAutorisationParUnNomLibre(): void
    {
        $methods = array_map(static fn (ReflectionMethod $m): string => $m->getName(), (new ReflectionClass(AuthorizedPickupRegistry::class))->getMethods());
        sort($methods);
        self::assertSame(['find', 'grant', 'revoke', 'validOn'], $methods);

        foreach (['validOn', 'find', 'revoke'] as $name) {
            foreach ((new ReflectionMethod(AuthorizedPickupRegistry::class, $name))->getParameters() as $parameter) {
                self::assertDoesNotMatchRegularExpression('/name|pickup_?name|label/i', $parameter->getName(), $name . ' ne doit pas viser une personne par un nom');
            }
        }
    }

    public function testIsolationParClientEtParEtablissement(): void
    {
        $seed = $this->seed();

        self::assertSame([], $this->registry()->validOn('t2', 'e1', ['s1'], self::DAY), 'autre client');
        self::assertSame([], $this->registry()->validOn('t1', 'e2', ['s1'], self::DAY), 'autre établissement');
        self::assertNull($this->registry()->find('t2', 'e1', $seed['permanent']['id']));
        self::assertNull($this->registry()->find('t1', 'e2', $seed['permanent']['id']));

        try {
            $this->registry()->revoke('t2', 'e1', $seed['permanent']['id'], 'intrus');
            self::fail('Un autre client ne peut pas révoquer.');
        } catch (HttpException $error) {
            self::assertSame(404, $error->status);
        }
        self::assertSame('active', $this->rows("SELECT status FROM authorized_pickups WHERE id = '" . $seed['permanent']['id'] . "'")[0]['status']);

        try {
            $this->registry()->grant('t2', 'e1', 's1', ['name' => 'SYN-Intrus', 'relationship' => 'ami', 'grant_type' => 'permanent'], 'intrus');
            self::fail('Un autre client ne peut pas créer d\'autorisation pour cet enfant.');
        } catch (HttpException $error) {
            self::assertSame(404, $error->status);
        }
        self::assertCount(4, $this->rows('SELECT id FROM authorized_pickups'));
    }

    public function testGrantCreeUneAutorisationVisibleParLesRoutesExistantes(): void
    {
        $permanent = $this->registry()->grant('t1', 'e1', 's1', ['name' => 'SYN-Marraine', 'relationship' => 'marraine', 'phone' => '+237670000222', 'photo_ref' => 'ref-photo-9', 'grant_type' => 'permanent'], 'u-director');
        $day = $this->registry()->grant('t1', 'e1', 's1', ['name' => 'SYN-Voisine', 'relationship' => 'voisine', 'grant_type' => 'day', 'valid_on' => self::DAY], 'u-director');

        $info = $this->registry()->find('t1', 'e1', $permanent);
        self::assertNotNull($info);
        self::assertSame(['SYN-Marraine', 'marraine', '+237670000222', 'ref-photo-9', 'permanent', null, 'active'], [$info->name, $info->relationship, $info->phone, $info->photoRef, $info->grantType, $info->validOn, $info->status]);
        self::assertSame('u-director', $this->rows("SELECT granted_by FROM authorized_pickups WHERE id = '" . $permanent . "'")[0]['granted_by']);
        self::assertSame(['SYN-Marraine', 'SYN-Voisine'], self::names($this->registry()->validOn('t1', 'e1', ['s1'], self::DAY)));

        [$list, $json] = $this->api('GET', '/authorized-pickups?filter[learner_id]=s1', 'surveillant');
        self::assertSame(200, $list->getStatusCode());
        self::assertEqualsCanonicalizing([$permanent, $day], array_column($json['data'], 'id'));
    }

    public function testGrantAppliqueLesMemesValidationsQueLaRoute(): void
    {
        $cases = [
            'enfant inconnu' => [['name' => 'SYN-X', 'relationship' => 'ami', 'grant_type' => 'permanent'], 'inconnu', 404],
            'jour sans date' => [['name' => 'SYN-X', 'relationship' => 'ami', 'grant_type' => 'day'], 's1', 422],
            'permanente datée' => [['name' => 'SYN-X', 'relationship' => 'ami', 'grant_type' => 'permanent', 'valid_on' => self::DAY], 's1', 422],
            'type inconnu' => [['name' => 'SYN-X', 'relationship' => 'ami', 'grant_type' => 'toujours'], 's1', 422],
            'sans nom' => [['relationship' => 'ami', 'grant_type' => 'permanent'], 's1', 422],
            'nom trop long' => [['name' => str_repeat('n', 161), 'relationship' => 'ami', 'grant_type' => 'permanent'], 's1', 422],
            'date invalide' => [['name' => 'SYN-X', 'relationship' => 'ami', 'grant_type' => 'day', 'valid_on' => '2026-02-31'], 's1', 422],
        ];

        foreach ($cases as $label => [$data, $learner, $status]) {
            try {
                $this->registry()->grant('t1', 'e1', $learner, $data, 'u-director');
                self::fail($label . ' : refus attendu');
            } catch (HttpException $error) {
                self::assertSame($status, $error->status, $label);
            }
        }
        self::assertSame([], $this->rows('SELECT id FROM authorized_pickups'));
    }

    public function testRevokeRetireLAutorisationEtResteIdempotent(): void
    {
        $seed = $this->seed();

        $this->registry()->revoke('t1', 'e1', $seed['permanent']['id'], 'u-director');
        $this->registry()->revoke('t1', 'e1', $seed['permanent']['id'], 'u-director');

        self::assertSame(['SYN-Oncle Paul'], self::names($this->registry()->validOn('t1', 'e1', ['s1'], self::DAY)));
        [, $json] = $this->api('GET', '/authorized-pickups?filter[status]=revoked', 'surveillant');
        self::assertCount(2, $json['data']);
        try {
            $this->registry()->revoke('t1', 'e1', 'inconnue', 'u-director');
            self::fail('Une autorisation inconnue est introuvable.');
        } catch (HttpException $error) {
            self::assertSame(404, $error->status);
        }
    }

    public function testLesRoutesExistantesRestentInchangees(): void
    {
        $created = $this->grantByApi(['pickup_name' => 'SYN-Oncle Paul', 'grant_type' => 'day', 'valid_on' => self::DAY]);
        self::assertSame(['s1', 'SYN-Oncle Paul', 'active', 'surveillant'], [$created['learner_id'], $created['pickup_name'], $created['status'], $created['granted_by']]);
        self::assertArrayNotHasKey('tenant_id', $created);

        [$denied] = $this->api('POST', '/authorized-pickups', 'teacher', ['learner_id' => 's1', 'pickup_name' => 'X', 'relationship' => 'ami', 'grant_type' => 'permanent']);
        [$list] = $this->api('GET', '/authorized-pickups', 'reader');
        [$revoked, $json] = $this->api('POST', '/authorized-pickups/' . $created['id'] . '/revoke', 'surveillant');

        self::assertSame([403, 200, 200], [$denied->getStatusCode(), $list->getStatusCode(), $revoked->getStatusCode()]);
        self::assertSame('revoked', $json['data']['status']);
        self::assertSame([], $this->registry()->validOn('t1', 'e1', ['s1'], self::DAY));
    }
}
