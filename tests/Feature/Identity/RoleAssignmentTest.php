<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Tests\Support\Identity\IdentityTestCase;

/** Affectations de rôle : validité dans le temps, délégation temporaire, suppression, permissions de l'Organisation. */
final class RoleAssignmentTest extends IdentityTestCase
{
    /** @var array{user_id: string, person_id: string} */
    private array $director;
    private string $token;

    protected function setUp(): void
    {
        $this->boot();
        $this->directory->add('t1', 'e1', 'Collège Beta');
        $this->directory->add('t1', 'e2', 'Ecole Alpha', 'primary');
        $this->director = $this->createUser('t1', 'Diane', '+237691000050', [['directeur', 'establishment', 'e1']]);
        $token = (string) $this->json($this->login('+237691000050'))['token'];
        $setup = $this->json($this->call('POST', '/api/v1/auth/2fa/setup', $this->auth($token)));
        $this->call('POST', '/api/v1/auth/2fa/confirm', $this->auth($token), ['code' => $this->totp((string) $setup['secret'])]);
        $this->token = $token;
    }

    private function can(array $user, string $permission, ?string $establishment): bool
    {
        return $this->kernel->container()->get(PermissionChecker::class)->can(new Principal($user['user_id'], 't1'), $permission, $establishment);
    }

    public function testAffectationFuturePuisExpireeNeDonnePasDeDroit(): void
    {
        $user = $this->createUser('t1', 'Eve', '+237691000051');
        $this->assignRole('t1', $user['person_id'], 'comptable', 'establishment', 'e1', endsAt: $this->kernel->container()->get(\Scholaris\Modules\Socle\Identity\Application\Moment::class)->in(3600), startsAt: $this->kernel->container()->get(\Scholaris\Modules\Socle\Identity\Application\Moment::class)->in(600));

        self::assertFalse($this->can($user, 'finance.read', 'e1'), 'Pas encore commencée.');
        $this->clock->advance(700);
        self::assertTrue($this->can($user, 'finance.read', 'e1'));
        $this->clock->advance(3600);
        self::assertFalse($this->can($user, 'finance.read', 'e1'), 'Terminée.');
    }

    public function testCreationListeEtSuppressionParLeDirecteur(): void
    {
        $user = $this->createUser('t1', 'Eve', '+237691000052');

        $created = $this->call('POST', '/api/v1/role-assignments', $this->auth($this->token, 'e1'), [
            'person_id' => $user['person_id'], 'role' => 'comptable', 'scope_type' => 'establishment', 'scope_id' => 'e1',
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $id = (string) $this->json($created)['data']['id'];
        self::assertTrue($this->can($user, 'finance.manage', 'e1'));

        $list = $this->json($this->call('GET', '/api/v1/role-assignments?filter[person_id]=' . $user['person_id'], $this->auth($this->token, 'e1')));
        self::assertCount(1, $list['data']);
        self::assertSame('comptable', $list['data'][0]['role']);
        self::assertArrayHasKey('next_cursor', $list['meta']);
        self::assertSame(422, $this->call('GET', '/api/v1/role-assignments?filter[inconnu]=x', $this->auth($this->token, 'e1'))->getStatusCode());

        self::assertSame(204, $this->call('DELETE', '/api/v1/role-assignments/' . $id, $this->auth($this->token, 'e1'))->getStatusCode());
        self::assertFalse($this->can($user, 'finance.manage', 'e1'));
        self::assertSame(404, $this->call('DELETE', '/api/v1/role-assignments/' . $id, $this->auth($this->token, 'e1'))->getStatusCode());
    }

    public function testValidationDesDonnees(): void
    {
        $user = $this->createUser('t1', 'Eve', '+237691000053');
        $post = fn (array $body): int => $this->call('POST', '/api/v1/role-assignments', $this->auth($this->token, 'e1'), $body)->getStatusCode();

        self::assertSame(422, $post(['person_id' => $user['person_id'], 'role' => 'inconnu', 'scope_type' => 'establishment', 'scope_id' => 'e1']));
        self::assertSame(422, $post(['person_id' => 'personne-absente', 'role' => 'comptable', 'scope_type' => 'establishment', 'scope_id' => 'e1']));
        self::assertSame(422, $post(['person_id' => $user['person_id'], 'role' => 'comptable', 'scope_type' => 'tenant']));
        self::assertSame(422, $post(['person_id' => $user['person_id'], 'role' => 'comptable', 'scope_type' => 'establishment', 'scope_id' => 'e1', 'starts_at' => '2026-10-10T10:00:00+01:00', 'ends_at' => '2026-10-09T10:00:00+01:00']));
        self::assertSame(422, $post(['person_id' => $user['person_id'], 'role' => 'comptable', 'scope_type' => 'establishment', 'scope_id' => 'e-inexistant']));
        self::assertSame(422, $post(['role' => 'comptable']));
    }

    public function testRoleDeClasseSeRattacheALEtablissement(): void
    {
        $user = $this->createUser('t1', 'Eve', '+237691000054');

        $created = $this->call('POST', '/api/v1/role-assignments', $this->auth($this->token, 'e1'), [
            'person_id' => $user['person_id'], 'role' => 'enseignant', 'scope_type' => 'class', 'scope_id' => 'classe-6e-a', 'establishment_id' => 'e1',
        ]);

        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertTrue($this->can($user, 'grades.enter', 'e1'));
        self::assertFalse($this->can($user, 'grades.enter', 'e2'));
    }

    public function testDelegationTemporaire(): void
    {
        $deputy = $this->createUser('t1', 'Sous', '+237691000055');
        $ends = '2026-10-20T10:00:00+01:00';
        $moment = $this->kernel->container()->get(\Scholaris\Modules\Socle\Identity\Application\Moment::class);
        $ends = $moment->in(5 * 86400);

        $delegate = $this->call('POST', '/api/v1/role-assignments/delegations', $this->auth($this->token, 'e1'), [
            'person_id' => $deputy['person_id'], 'role' => 'directeur', 'scope_type' => 'establishment', 'scope_id' => 'e1', 'ends_at' => $ends,
        ]);

        self::assertSame(201, $delegate->getStatusCode(), (string) $delegate->getBody());
        self::assertSame($this->director['person_id'], $this->json($delegate)['data']['delegated_by']);
        self::assertTrue($this->can($deputy, 'organisation.manage', 'e1'));
        $this->clock->advance(6 * 86400);
        self::assertFalse($this->can($deputy, 'organisation.manage', 'e1'), 'La délégation prend fin à sa date.');
    }

    public function testDelegationExigeUneFinUnRoleDetenuEtNeSeRedelegueePas(): void
    {
        $deputy = $this->createUser('t1', 'Sous', '+237691000056');
        $moment = $this->kernel->container()->get(\Scholaris\Modules\Socle\Identity\Application\Moment::class);
        $delegate = fn (array $extra): int => $this->call('POST', '/api/v1/role-assignments/delegations', $this->auth($this->token, 'e1'), $extra + [
            'person_id' => $deputy['person_id'], 'role' => 'directeur', 'scope_type' => 'establishment', 'scope_id' => 'e1',
        ])->getStatusCode();

        self::assertSame(422, $delegate([]), 'Sans date de fin.');
        self::assertSame(422, $delegate(['ends_at' => $moment->in(200 * 86400)]), 'Au-delà de 90 jours.');
        self::assertSame(403, $delegate(['role' => 'comptable', 'ends_at' => $moment->in(86400)]), 'Rôle non détenu.');
        self::assertSame(422, $delegate(['person_id' => $this->director['person_id'], 'ends_at' => $moment->in(86400)]), 'À soi-même.');
        self::assertSame(201, $delegate(['ends_at' => $moment->in(86400)]));
    }

    /** SS13 : un compte externe du secondaire (R21) s'attribue avec une fin d'au plus 30 jours, puis ne donne plus aucun droit. */
    public function testRoleExterneADureeLimiteePuisIgnoreUneFoisEchu(): void
    {
        $inspector = $this->createUser('t1', 'Ines', '+237691000059');
        $moment = $this->kernel->container()->get(\Scholaris\Modules\Socle\Identity\Application\Moment::class);
        $post = fn (array $extra): \Psr\Http\Message\ResponseInterface => $this->call('POST', '/api/v1/role-assignments', $this->auth($this->token, 'e1'), $extra + [
            'person_id' => $inspector['person_id'], 'role' => 'sec_inspecteur', 'scope_type' => 'establishment', 'scope_id' => 'e1',
        ]);

        self::assertSame(422, $post([])->getStatusCode(), 'Sans date de fin.');
        self::assertSame(422, $post(['ends_at' => $moment->in(31 * 86400)])->getStatusCode(), 'Au-delà de 30 jours.');
        self::assertSame(201, $post(['ends_at' => $moment->in(29 * 86400)])->getStatusCode());
        self::assertSame(201, $this->call('POST', '/api/v1/role-assignments', $this->auth($this->token, 'e1'), [
            'person_id' => $inspector['person_id'], 'role' => 'sec_censeur', 'scope_type' => 'establishment', 'scope_id' => 'e1',
        ])->getStatusCode(), 'Un rôle interne reste sans limite.');

        self::assertTrue($this->can($inspector, 'secondary.inspection.read', 'e1'));
        $this->clock->advance(30 * 86400);
        self::assertFalse($this->can($inspector, 'secondary.inspection.read', 'e1'), 'Affectation échue : ignorée.');
        self::assertTrue($this->can($inspector, 'secondary.grades.validate', 'e1'));
    }

    public function testLeSuperAdminSeulAttribueSuperAdminEtSettingsPlatform(): void
    {
        $root = $this->createUser('t1', 'Root', '+237691000057', [['super_admin', 'tenant', null]]);
        $group = $this->createUser('t1', 'Zoé', '+237691000058', [['direction_groupe', 'tenant', null]]);
        $newbie = $this->createUser('t1', 'Neo', '+237691000059');

        self::assertTrue($this->can($root, 'settings.platform', null));
        self::assertTrue($this->can($root, 'organisation.manage', 'e2'));
        self::assertFalse($this->can($group, 'settings.platform', null), 'Réservé au super administrateur.');
        self::assertTrue($this->can($group, 'settings.manage', null));
        self::assertTrue($this->can($group, 'organisation.read', 'e2'));

        $groupToken = (string) $this->json($this->login('+237691000058'))['token'];
        $setup = $this->json($this->call('POST', '/api/v1/auth/2fa/setup', $this->auth($groupToken)));
        $this->call('POST', '/api/v1/auth/2fa/confirm', $this->auth($groupToken), ['code' => $this->totp((string) $setup['secret'])]);
        $asGroup = $this->call('POST', '/api/v1/role-assignments', $this->auth($groupToken, 'e1'), ['person_id' => $newbie['person_id'], 'role' => 'super_admin', 'scope_type' => 'tenant']);
        $asGroupOk = $this->call('POST', '/api/v1/role-assignments', $this->auth($groupToken, 'e1'), ['person_id' => $newbie['person_id'], 'role' => 'direction_groupe', 'scope_type' => 'tenant']);

        self::assertSame(403, $asGroup->getStatusCode());
        self::assertSame(201, $asGroupOk->getStatusCode(), (string) $asGroupOk->getBody());
    }
}
