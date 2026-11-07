<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Nyholm\Psr7\ServerRequest;
use Scholaris\Modules\Socle\Identity\Contracts\PlatformAdministrators;

/**
 * Construction d'un IPES client sur la pile réelle : demande d'accès publique `higher`, acceptation par l'administrateur de
 * plateforme (2FA récente), activation du premier administrateur, comptes et rôles réels (RoleMatrix), paramétrage académique,
 * maquette S1 publiée, admission, inscription et compte de scolarité. Tous les noms et numéros sont FICTIFS.
 *
 * Contexte d'un institut (`$ctx`) : tenant, establishment, admin (jeton), year, org[...], tokens[rôle], people[rôle], etc.
 */
trait BuildsIpes
{
    /**
     * Maquette L1 S1 de l'exemple du CDC (Marie N.) : UE => [type, [EC => [crédits, intitulé]]] (PHP 8.1 : pas de constante de trait).
     *
     * @return array<string, array{0: string, 1: array<string, array{0: int, 1: string}>}>
     */
    protected static function marieS1(): array
    {
        return [
            'UE1' => ['fundamental', ['CG' => [4, 'Comptabilité générale'], 'TDC' => [2, 'TD de comptabilité']]],
            'UE2' => ['fundamental', ['MF' => [6, 'Mathématiques financières']]],
            'UE3' => ['fundamental', ['MIC' => [3, 'Microéconomie'], 'MAC' => [3, 'Macroéconomie']]],
            'UE4' => ['transversal', ['DC' => [3, 'Droit civil'], 'IA' => [3, 'Institutions administratives']]],
            'UE5' => ['transversal', ['ANG' => [3, 'Anglais'], 'BUR' => [3, 'Bureautique']]],
        ];
    }

    /** @var array{login: string, password: string}|null */
    private ?array $root = null;
    private ?string $platformToken = null;
    private int $ipSeq = 10;

    /** Première année de l'année académique en cours (septembre à juillet). */
    protected static function startYear(): int
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Douala'));

        return (int) $today->format('n') >= 8 ? (int) $today->format('Y') : (int) $today->format('Y') - 1;
    }

    protected static function day(string $modifier): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Douala')))->modify($modifier)->format('Y-m-d');
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>}
     */
    protected function publicCall(string $method, string $path, ?array $body, string $ip): array
    {
        $headers = ['Accept-Language' => 'fr', 'User-Agent' => 'Recette E2E IPES'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->send(new ServerRequest($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR), '1.1', ['REMOTE_ADDR' => $ip]));
    }

    /** Administrateur de plateforme (outil console réel), connecté avec 2FA ; 2FA re-vérifiée pour rester « récente ». */
    protected function platform(): string
    {
        if ($this->root === null) {
            $created = $this->kernel->container()->get(PlatformAdministrators::class)->create('+237699000999', 'Recette', 'Plateforme');
            $this->root = ['login' => $created['login'], 'password' => $created['password']];
            $this->clock->advance(max(0, time() - $this->clock->now()) + 1);
            $this->platformToken = $this->signIn($this->root['login'], $this->root['password']);
        } else {
            $this->clock->advance(31);
            $this->ok('POST', '/auth/2fa/verify', $this->platformToken, ['code' => $this->totp($this->root['login'])]);
        }

        return (string) $this->platformToken;
    }

    /**
     * Parcours d'accès complet d'un institut ; renvoie le contexte (tenant, établissement, administrateur connecté).
     *
     * @param array{name: string, city: string, region: string, address: string, first: string, last: string, email: string, phone: string, normalized: string} $institute
     * @return array<string, mixed>
     */
    protected function onboard(array $institute): array
    {
        $ip = '198.51.100.' . (++$this->ipSeq);
        [$catalog, $catalogJson] = $this->publicCall('GET', '/public/applications', null, $ip);
        self::assertSame(200, $catalog->getStatusCode());
        self::assertContains('higher', array_column($catalogJson['data'], 'key'));

        [$submitted, $submittedJson] = $this->publicCall('POST', '/public/access-requests', [
            'institution_name' => $institute['name'], 'target_type' => 'higher', 'city' => $institute['city'], 'region' => $institute['region'],
            'contact_first_name' => $institute['first'], 'contact_last_name' => $institute['last'], 'contact_email' => $institute['email'],
            'contact_phone' => $institute['phone'], 'contact_position' => 'Directeur', 'app_key' => 'higher',
            'message' => 'Adresse : ' . $institute['address'] . '. Rentrée académique ' . self::startYear() . '.',
        ], $ip);
        self::assertSame(202, $submitted->getStatusCode(), (string) $submitted->getBody());
        $tracking = (string) $submittedJson['data']['tracking_token'];

        $platform = $this->platform();
        $pending = $this->ok('GET', '/platform/access-requests?filter[status]=pending', $platform)['data'];
        $mine = array_values(array_filter($pending, static fn (array $r): bool => ($r['institution_name'] ?? null) === $institute['name']));
        self::assertCount(1, $mine, (string) json_encode($pending));
        $accepted = $this->ok('POST', '/platform/access-requests/' . $mine[0]['id'] . '/accept', $platform, [])['data'];
        self::assertSame('accepted', $accepted['status']);
        self::assertSame('trial', $accepted['entitlement']['state']);
        self::assertSame('accepted', $this->publicCall('GET', '/public/access-requests/' . $tracking, null, $ip)[1]['data']['status']);

        $password = 'Recette-Ipes-' . substr(md5($institute['name']), 0, 8) . '!';
        $this->activate($institute['normalized'], $password);
        $admin = $this->signIn($institute['normalized'], $password);
        $apps = $this->ok('GET', '/me/applications', $admin)['data'];
        $higher = array_values(array_filter($apps, static fn (array $a): bool => ($a['key'] ?? $a['app_key'] ?? null) === 'higher'));
        self::assertCount(1, $higher, (string) json_encode($apps));
        self::assertSame('trial', $higher[0]['state'] ?? null, (string) json_encode($higher[0]));

        $me = $this->ok('GET', '/me', $admin);

        return [
            'tenant' => (string) $accepted['tenant_id'], 'establishment' => (string) $accepted['establishment_id'], 'admin' => $admin,
            'admin_person' => (string) ($me['data']['person_id'] ?? $me['person_id'] ?? ''), 'name' => $institute['name'],
        ];
    }

    /**
     * Appel d'un acteur de l'institut (en-tête X-Establishment-Id).
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function as(array $ctx, string $who, string $method, string $path, ?array $body = null, ?int $expected = null, array $headers = []): array
    {
        $token = $who === 'admin' ? $ctx['admin'] : $ctx['tokens'][$who];

        return $this->ok($method, $path, $token, $body, $ctx['establishment'], $headers, $expected);
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function try(array $ctx, string $who, string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $token = $who === 'admin' ? $ctx['admin'] : $ctx['tokens'][$who];
        [$response, $json] = $this->api($method, $path, $token, $body, $ctx['establishment'], $headers);

        return [$response->getStatusCode(), $json];
    }

    /**
     * Affectation de rôle par l'API d'administration du client (vrais rôles, vrai périmètre).
     *
     * @param array<string, mixed> $ctx
     */
    protected function assignRole(array $ctx, string $person, string $role, string $scopeType, ?string $scopeId = null): void
    {
        $body = ['person_id' => $person, 'role' => $role, 'scope_type' => $scopeType, 'scope_id' => $scopeId ?? $ctx['establishment']];
        if ($scopeType !== 'establishment') {
            $body['establishment_id'] = $ctx['establishment'];
        }
        $this->ok('POST', '/role-assignments', $ctx['admin'], $body, $ctx['establishment'], [], 201);
    }

    /**
     * Compte réel activé ; rôle initial à l'établissement si $role est donné, puis connexion (2FA si rôle sensible).
     *
     * @param array<string, mixed> $ctx
     * @param list<array{0: string, 1: string, 2?: string}> $roles [rôle, périmètre, identifiant de périmètre]
     */
    protected function actor(array &$ctx, string $key, string $first, string $last, array $roles): void
    {
        $phone = $this->nextPhone();
        $created = $this->ok('POST', '/users', $ctx['admin'], ['first_name' => $first, 'last_name' => $last, 'phone' => $phone], $ctx['establishment'], [], 201);
        $person = (string) $created['data']['person_id'];
        foreach ($roles as $role) {
            $this->assignRole($ctx, $person, $role[0], $role[1], $role[2] ?? null);
        }
        $password = 'Recette-Solide-' . substr(md5($phone), 0, 8) . '!';
        $this->activate($phone, $password);
        $ctx['people'][$key] = $person;
        $ctx['logins'][$key] = [$phone, $password];
        $ctx['tokens'][$key] = $this->signIn($phone, $password);
    }

    /**
     * Reconnexion d'un acteur (après ajout de rôles, ou pour une 2FA récente).
     *
     * @param array<string, mixed> $ctx
     */
    protected function relogin(array &$ctx, string $key): void
    {
        [$login, $password] = $ctx['logins'][$key];
        $ctx['tokens'][$key] = $this->signIn($login, $password);
    }

    /**
     * Année, école, département, filière, niveaux, groupe, autorisation MINESUP, tutelle (LMD), règlement.
     *
     * @param array<string, mixed> $ctx
     */
    protected function buildAcademic(array &$ctx, string $regime, string $schoolCode, string $programCode, string $programName): void
    {
        $y = self::startYear();
        $ctx['regime'] = $regime;
        $ctx['org_code'] = $programCode;
        $ctx['year'] = (string) $this->as($ctx, 'admin', 'POST', '/academic-years', ['label' => $y . '-' . ($y + 1), 'starts_on' => $y . '-09-01', 'ends_on' => ($y + 1) . '-07-31'], 201)['data']['id'];

        // L'administrateur du client se donne les rôles d'administrateur fonctionnel (R20) et de directeur académique (R03).
        $this->assignRole($ctx, $ctx['admin_person'], 'higher_admin_fonct', 'establishment');
        $this->assignRole($ctx, $ctx['admin_person'], 'higher_dir_academique', 'establishment');

        $school = (string) $this->as($ctx, 'admin', 'POST', '/higher/schools', ['code' => $schoolCode, 'name' => 'École ' . $schoolCode])['data']['id'];
        $department = (string) $this->as($ctx, 'admin', 'POST', '/higher/departments', ['school_id' => $school, 'code' => 'D' . $programCode, 'name' => 'Département ' . $programName])['data']['id'];
        $program = (string) $this->as($ctx, 'admin', 'POST', '/higher/programs', [
            'department_id' => $department, 'code' => $programCode, 'name' => $programName, 'regime' => $regime,
            'teaching_language' => 'fr', 'study_mode' => 'day', 'duration_years' => $regime === 'lmd' ? 3 : 2,
        ])['data']['id'];
        $prefix = $regime === 'lmd' ? 'L' : 'BTS';
        $l1 = (string) $this->as($ctx, 'admin', 'POST', '/higher/levels', ['program_id' => $program, 'ordinal' => 1, 'code' => $prefix . '1', 'name' => $prefix . ' 1'])['data']['id'];
        $l2 = (string) $this->as($ctx, 'admin', 'POST', '/higher/levels', ['program_id' => $program, 'ordinal' => 2, 'code' => $prefix . '2', 'name' => $prefix . ' 2'])['data']['id'];
        $g1 = (string) $this->as($ctx, 'admin', 'POST', '/higher/groups', ['level_id' => $l1, 'code' => 'G1', 'name' => 'Groupe 1', 'capacity' => 60])['data']['id'];
        $ctx['org'] = ['school' => $school, 'department' => $department, 'program' => $program, 'l1' => $l1, 'l2' => $l2, 'g1' => $g1];

        $authorization = $this->as($ctx, 'admin', 'POST', '/higher/authorizations', [
            'program_id' => $program, 'decree_number' => 'ARR-' . ($y - 1) . '-0' . $programCode, 'decree_date' => ($y - 1) . '-07-15', 'authority' => 'MINESUP',
            'valid_from' => ($y - 1) . '-09-01', 'valid_to' => ($y + 3) . '-08-31', 'level_ids' => null,
        ])['data'];
        self::assertSame('valid', $authorization['status']);
        if ($regime === 'lmd') {
            $this->as($ctx, 'admin', 'POST', '/higher/tutelages', [
                'program_id' => $program, 'academic_year_id' => $ctx['year'], 'university_name' => 'Université de Ngaoundéré',
                'convention_reference' => 'CONV-UN-' . $y, 'convention_ends_on' => null, 'approved_programme_ref' => 'UN/FSEG/LIC-' . $programCode,
                'contacts' => [['name' => 'Pr. Fictif Doyen', 'role' => 'Doyen', 'email' => 'doyen@universite.example', 'phone' => '+237600000001']],
            ]);
        }
        $regulation = $this->as($ctx, 'admin', 'POST', '/higher/regulations', ['school_id' => $school, 'regime' => $regime, 'academic_year_id' => $ctx['year']])['data'];
        self::assertSame('draft', $regulation['status']);
        $ctx['regulation'] = (string) $regulation['id'];
    }

    /**
     * Acteurs réels de l'institut (RoleMatrix) : directeur d'école R04, chef de département R05, scolarité R07, deux comptables
     * R15, caissier R14, enseignant R09 (sur la filière).
     *
     * @param array<string, mixed> $ctx
     */
    protected function staff(array &$ctx): void
    {
        $o = $ctx['org'];
        $this->actor($ctx, 'dir', 'Adamou', 'Fictif-Directeur', [['higher_dir_ecole', 'org_unit', $o['school']]]);
        $this->actor($ctx, 'chef', 'Bello', 'Fictif-Chef', [['higher_chef_dept', 'org_unit', $o['department']]]);
        $this->actor($ctx, 'scol', 'Hadja', 'Fictive-Scolarite', [['higher_scolarite', 'org_unit', $o['school']]]);
        $this->actor($ctx, 'compta', 'Oumarou', 'Fictif-Comptable', [['comptable', 'establishment']]);
        $this->actor($ctx, 'compta2', 'Aissatou', 'Fictive-Comptable', [['comptable', 'establishment']]);
        $this->actor($ctx, 'caisse', 'Ibrahim', 'Fictif-Caissier', [['caissier', 'establishment']]);
        $this->actor($ctx, 'prof', 'Moussa', 'Fictif-Enseignant', [['enseignant', 'structure', $o['program']]]);
        $this->actor($ctx, 'prof2', 'Ramatou', 'Fictive-Enseignante', [['enseignant', 'structure', $o['program']]]);
    }

    /**
     * Maquette L1 S1 (30 crédits) saisie par le directeur d'école, publiée par le directeur académique.
     *
     * @param array<string, mixed> $ctx
     */
    protected function curriculum(array &$ctx): void
    {
        $draft = $this->as($ctx, 'dir', 'POST', '/higher/curricula', [
            'program_id' => $ctx['org']['program'], 'level_id' => $ctx['org']['l1'], 'academic_year_id' => $ctx['year'],
            'approved_programme_ref' => $ctx['regime'] === 'lmd' ? 'UN/FSEG/LIC-' . $ctx['org_code'] : null, 'semester_ordinals' => [1],
        ])['data'];
        $id = (string) $draft['id'];
        $units = $elements = [];
        foreach (self::marieS1() as $code => [$type, $ecs]) {
            $credits = array_sum(array_map(static fn (array $ec): int => $ec[0], $ecs));
            $units[$code] = (string) $this->as($ctx, 'dir', 'POST', '/higher/curricula/' . $id . '/units', [
                'semester_ordinal' => 1, 'code' => $code, 'title' => 'Unité ' . $code, 'unit_type' => $type, 'credits' => $credits, 'coefficient' => $credits,
            ] + ($ctx['regime'] === 'bts' ? ['national_exam_paper' => 'E' . substr($code, 2)] : []))['data']['id'];
            foreach ($ecs as $ecCode => [$ecCredits, $title]) {
                $elements[$ecCode] = (string) $this->as($ctx, 'dir', 'POST', '/higher/curriculum-units/' . $units[$code] . '/elements', [
                    'code' => $ecCode, 'title' => $title, 'credits' => $ecCredits, 'coefficient' => $ecCredits,
                    'cm_hours' => 10 * $ecCredits, 'td_hours' => 5 * $ecCredits, 'tp_hours' => 0, 'personal_hours' => 10 * $ecCredits,
                ])['data']['id'];
            }
        }
        $published = $this->as($ctx, 'admin', 'POST', '/higher/curricula/' . $id . '/publish')['data'];
        self::assertSame('published', $published['status']);
        $ctx['curriculum'] = ['id' => $id, 'units' => $units, 'elements' => $elements];
    }

    /**
     * Catalogue des frais du socle (codes stables de l'IPES), créé par le comptable.
     *
     * @param array<string, mixed> $ctx
     */
    protected function feeCatalog(array $ctx): void
    {
        foreach ([
            ['registration', 'hi.inscription', 'Frais d\'inscription', 50000],
            ['tuition', 'hi.pension', 'Pension', 450000],
            ['application_fee', 'hi.dossier', 'Frais de dossier', 15000],
        ] as [$kind, $code, $name, $amount]) {
            $this->as($ctx, 'compta', 'POST', '/fee-items', ['kind' => $kind, 'code' => $code, 'name' => $name, 'amount_xaf' => $amount], 201);
        }
    }

    /**
     * Campagne ouverte, candidature au guichet complète. Renvoie la candidature.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    protected function application(array &$ctx, string $first, string $last): array
    {
        if (!isset($ctx['campaign'])) {
            $ctx['campaign'] = (string) $this->as($ctx, 'scol', 'POST', '/higher/admissions/campaigns', [
                'level_id' => $ctx['org']['l1'], 'academic_year_id' => $ctx['year'], 'opens_on' => self::day('-30 days'), 'closes_on' => self::day('+30 days'),
                'seats' => 50, 'application_fee_xaf' => 15000, 'selection_mode' => 'file',
            ])['data']['id'];
            $this->as($ctx, 'scol', 'POST', '/higher/admissions/campaigns/' . $ctx['campaign'] . '/open');
        }
        $application = $this->as($ctx, 'scol', 'POST', '/higher/admissions/applications', [
            'campaign_id' => $ctx['campaign'], 'diploma' => 'bac', 'diploma_series' => 'D', 'diploma_year' => self::startYear(), 'diploma_mention' => 'assez_bien',
            'identity' => ['first_name' => $first, 'last_name' => $last, 'phone' => $this->nextPhone()],
        ])['data'];
        $id = (string) $application['id'];
        foreach ($application['documents'] as $document) {
            $this->as($ctx, 'scol', 'POST', '/higher/admissions/applications/' . $id . '/documents', ['requirement_code' => $document['requirement_code'], 'document_ref' => 'scan-' . $document['requirement_code']]);
        }
        $this->as($ctx, 'scol', 'POST', '/higher/admissions/applications/' . $id . '/submit');

        return $this->as($ctx, 'scol', 'POST', '/higher/admissions/applications/' . $id . '/fee-payment', ['channel' => 'orange_money', 'payer_phone' => '+237699000555', 'external_reference' => 'OM-DOSSIER-' . $id])['data'];
    }

    /**
     * Vérification des pièces bloquantes par la scolarité puis décision « admis » du directeur d'école.
     *
     * @param array<string, mixed> $ctx
     */
    protected function admit(array $ctx, string $applicationId): void
    {
        $application = $this->as($ctx, 'scol', 'GET', '/higher/admissions/applications/' . $applicationId)['data'];
        foreach ($application['documents'] as $document) {
            if ($document['blocking'] && $document['status'] === 'uploaded') {
                $this->as($ctx, 'scol', 'POST', '/higher/admissions/applications/' . $applicationId . '/documents/' . $document['id'] . '/verify');
            }
        }
        $this->as($ctx, 'dir', 'POST', '/higher/admissions/applications/' . $applicationId . '/decision', ['decision' => 'admitted', 'reason' => 'Dossier conforme.']);
    }
}
