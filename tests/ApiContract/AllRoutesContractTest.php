<?php

declare(strict_types=1);

namespace Tests\ApiContract;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiContract\Support\SharedFixtureTestCase;

/**
 * Recette des API : chaque route déclarée dans le RouteRegistry (et donc dans openapi.d) est appelée sur la pile réelle
 * (authentification, 2FA, rôles et permissions d'Identity) avec de vrais identifiants du client A dans le chemin.
 *
 *  1. sans authentification : 401 au format RFC 9457 avec trace_id, en-têtes de sécurité ;
 *  2. rôle sans permission métier (apprenant) : 403 ou 404, jamais de succès hors libre-service ;
 *  3. client B : jamais de donnée du client A (établissement de A refusé, identifiants de A introuvables) ;
 *  4. listes : pagination par curseur (meta.next_cursor, per_page borné) ;
 *  5. Idempotency-Key exigée sur les POST sensibles et déclarée dans le contrat ;
 *  6. contrat : statut et type de contenu des réponses conformes à openapi.d.
 */
final class AllRoutesContractTest extends SharedFixtureTestCase
{
    /** Routes publiques attendues (sans authentification). */
    private const PUBLIC = [
        'GET /api/v1/health', 'POST /api/v1/webhooks/payments/{provider}', 'GET /api/v1/public/verify/{token}',
        'POST /api/v1/auth/login', 'POST /api/v1/auth/login-authenticator', 'POST /api/v1/auth/activation/request', 'POST /api/v1/auth/activation/verify',
        'POST /api/v1/auth/activation/password', 'POST /api/v1/auth/password/forgot',
        'GET /api/v1/people/{id}/documents/{docId}/download',
        // Ajoutées par le chantier Plateforme en cours (catalogue public, demandes d'accès, webhook de facturation plateforme).
        'GET /api/v1/public/applications', 'POST /api/v1/public/access-requests', 'GET /api/v1/public/access-requests/{token}',
        'POST /api/v1/public/access-requests/{token}/complement', 'POST /api/v1/platform/payments/webhook',
        // Page publique « Candidater » de l'application Supérieur (lien partageable, inscription du candidat).
        'GET /api/v1/public/admissions/{slug}', 'POST /api/v1/public/admissions/{slug}/register',
    ];

    /** Routes qui ferment la session ou réinitialisent la 2FA de l'appelant : exclues des appels authentifiés génériques. */
    private const SESSION = [
        'POST /api/v1/auth/logout', 'POST /api/v1/auth/not-me', 'DELETE /api/v1/me/sessions', 'DELETE /api/v1/me/devices/{id}',
        'POST /api/v1/auth/2fa/setup', 'POST /api/v1/auth/2fa/confirm', 'POST /api/v1/auth/2fa/verify', 'POST /api/v1/auth/2fa/sms', 'POST /api/v1/auth/2fa/recovery',
    ];

    /** Libre-service : routes qu'un apprenant (permissions self.read, documents.request) peut légitimement appeler. */
    private const SELF_SERVICE = [
        'GET /api/v1/me', 'GET /api/v1/me/contexts', 'POST /api/v1/me/context', 'GET /api/v1/me/todo', 'GET /api/v1/me/devices',
        'GET /api/v1/vocabulary', 'GET /api/v1/portal/learner', 'GET /api/v1/document-requests', 'POST /api/v1/document-requests',
        'GET /api/v1/results', 'GET /api/v1/data-requests', 'POST /api/v1/data-requests',
        // Usages élève légitimes : pointage par QR de séance, justificatif, consentements, emploi du temps, calendrier.
        'POST /api/v1/attendance-sessions/{id}/scan', 'POST /api/v1/absence-justifications', 'GET /api/v1/consents', 'POST /api/v1/consents',
        'GET /api/v1/timetable', 'GET /api/v1/me/applications',
        // Paiement en libre-service (D2) : sa référence famille et un paiement mobile money de SES factures, contrôle d'objet
        // strict (tests/Feature/Billing/SelfServicePaymentTest) ; les lectures GET restent soumises au contrôle ci-dessous.
        'POST /api/v1/payment-references', 'POST /api/v1/payments',
    ];

    /** Sous-collections ou référentiels bornés par nature : la pagination par curseur n'y est pas exigée. */
    private const BOUNDED = [
        'GET /api/v1/grades/{id}/events', 'GET /api/v1/privacy/processing-register', 'GET /api/v1/document-types', 'GET /api/v1/me/contexts',
        'GET /api/v1/me/todo', 'GET /api/v1/me/devices', 'GET /api/v1/sms-usage', 'GET /api/v1/vocabulary', 'GET /api/v1/authorizations/expiring',
        'GET /api/v1/settings/effective', 'GET /api/v1/people/{id}/contacts', 'GET /api/v1/people/{id}/identifiers',
        'GET /api/v1/people/{id}/schooling-history', 'GET /api/v1/people/{id}/enrollments', 'GET /api/v1/people/{id}/relations',
        'GET /api/v1/people/{id}/documents', 'GET /api/v1/me/applications', 'GET /api/v1/staff/{id}/qualifications',
        'GET /api/v1/staff/{id}/assignments', 'GET /api/v1/vacation-rates', 'GET /api/v1/leave-calendar',
    ];

    /** @return array<string, array{0: string, 1: string, 2: bool, 3: bool}> */
    public static function routes(): array
    {
        return self::allRoutes();
    }

    private function key(string $method, string $path): string
    {
        return $method . ' ' . $path;
    }

    #[DataProvider('routes')]
    public function testSansAuthentificationLaRouteRepond401AuFormatProblemDetails(string $method, string $path, bool $auth, bool $tenant): void
    {
        $route = $this->key($method, $path);
        if (!$auth) {
            self::assertContains($route, self::PUBLIC, $route . ' est publique sans figurer dans la liste attendue.');
            [$response] = $this->raw($method, $this->concretePath($path), null, null, $method === 'GET' ? null : '{}');
            self::assertNotSame(500, $response->getStatusCode(), $route . ' : erreur serveur');
            $this->assertSecurityHeaders($response, $route);

            return;
        }
        self::assertNotContains($route, self::PUBLIC, $route . ' devrait être publique.');

        [$response] = $this->raw($method, $this->concretePath($path), null, $tenant ? $this->school : null, $method === 'GET' ? null : '{}');

        self::assertSame(401, $response->getStatusCode(), $route);
        $this->assertProblem($response, $route);
        $this->assertSecurityHeaders($response, $route);
    }

    #[DataProvider('routes')]
    public function testUnRoleSansPermissionEstRefuse(string $method, string $path, bool $auth, bool $tenant): void
    {
        $route = $this->key($method, $path);
        if (!$auth || in_array($route, self::SESSION, true)) {
            $this->addToAssertionCount(1);

            return;
        }

        [$response, $json] = $this->raw($method, $this->concretePath($path), $this->tokens['apprenant'], $tenant ? $this->school : null, $method === 'GET' ? null : '{}', ['Idempotency-Key' => 'contrat-apprenant-' . substr(md5($route), 0, 12)]);
        $status = $response->getStatusCode();
        $this->assertSecurityHeaders($response, $route);
        if (in_array($route, self::SELF_SERVICE, true)) {
            self::assertNotSame(500, $status, $route);

            return;
        }

        if ($status >= 200 && $status < 300 && $method === 'GET') {
            // Liste restreinte à son propre périmètre : admise si elle ne contient aucune donnée personnelle ou financière d'autrui.
            preg_match_all('/[0-9A-HJKMNP-TV-Z]{26}/', (string) $response->getBody(), $m);
            $foreign = array_values(array_filter(array_unique($m[0]), fn (string $id): bool => isset($this->sensitiveIds[$id])));
            self::assertSame([], $foreign, $route . ' : un apprenant lit des données d\x27autres personnes');

            return;
        }
        self::assertContains($status, [403, 404], $route . ' renvoie ' . $status . ' à un apprenant : ' . substr((string) $response->getBody(), 0, 300));
        $this->assertProblem($response, $route);
        self::assertArrayNotHasKey('errors', $json, $route . ' : la validation a eu lieu avant le contrôle de droit');
    }

    #[DataProvider('routes')]
    public function testLeClientBNeVoitJamaisDeDonneesDuClientA(string $method, string $path, bool $auth, bool $tenant): void
    {
        $route = $this->key($method, $path);
        if (!$auth || in_array($route, self::SESSION, true)) {
            $this->addToAssertionCount(1);

            return;
        }
        $concrete = $this->concretePath($path);
        $headers = ['Idempotency-Key' => 'contrat-b-' . substr(md5($route), 0, 12)];
        $body = $method === 'GET' ? null : '{}';

        if ($tenant) {
            // L'établissement du client A est refusé au client B, quel que soit le chemin.
            [$foreign] = $this->raw($method, $concrete, $this->tokens['adminB'], $this->school, $body, $headers);
            self::assertContains($foreign->getStatusCode(), [403, 404], $route . ' avec l\'établissement de A');
            $this->assertProblem($foreign, $route);
        }

        // Sur son propre établissement, B ne lit ni ne modifie aucune ressource de A.
        [$own] = $this->raw($method, $concrete, $this->tokens['adminB'], $tenant ? $this->schoolB : null, $body, $headers);
        $this->assertNoTenantALeak($own, $route);
        preg_match_all('/[0-9A-HJKMNP-TV-Z]{26}/', $concrete, $pathIds);
        $targetsA = array_filter($pathIds[0], fn (string $id): bool => isset($this->tenantAIds[$id])) !== [];
        if ($targetsA && $own->getStatusCode() < 300) {
            self::fail($route . ' : le client B obtient ' . $own->getStatusCode() . ' sur une ressource de A (' . $concrete . ')');
        }
        self::assertNotSame(500, $own->getStatusCode(), $route . ' : erreur serveur');
    }

    #[DataProvider('routes')]
    public function testLesListesSontPagineesParCurseur(string $method, string $path, bool $auth, bool $tenant): void
    {
        $route = $this->key($method, $path);
        if ($method !== 'GET' || !$auth || str_ends_with($path, '}') || str_contains($path, 'export') || str_contains($path, 'template') || in_array($route, self::BOUNDED, true)) {
            $this->addToAssertionCount(1);

            return;
        }
        $concrete = $this->concretePath($path);
        $sep = str_contains($concrete, '?') ? '&' : '?';
        $json = null;
        $token = null;
        foreach (['admin', 'teacher', 'cashier', 'parent'] as $who) {
            [$response, $decoded] = $this->raw('GET', $concrete . $sep . 'per_page=1', $this->tokens[$who], $tenant ? $this->school : null);
            if ($response->getStatusCode() === 200) {
                [$json, $token] = [$decoded, $this->tokens[$who]];
                break;
            }
        }
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data']) || !array_is_list($json['data'])) {
            $this->addToAssertionCount(1); // Pas une liste (ressource unique, tableau de bord) ou inaccessible à ces rôles.

            return;
        }

        self::assertLessThanOrEqual(1, count($json['data']), $route . ' : per_page=1 ignoré');
        self::assertArrayHasKey('meta', $json, $route . ' : meta absent');
        self::assertArrayHasKey('next_cursor', (array) $json['meta'], $route . ' : meta.next_cursor absent');
        if (is_string($json['meta']['next_cursor']) && $json['meta']['next_cursor'] !== '') {
            [$next, $page2] = $this->raw('GET', $concrete . $sep . 'per_page=1&cursor=' . rawurlencode($json['meta']['next_cursor']), $token, $tenant ? $this->school : null);
            self::assertSame(200, $next->getStatusCode(), $route . ' : page suivante');
            self::assertNotSame($json['data'][0]['id'] ?? null, $page2['data'][0]['id'] ?? 'fin', $route . ' : la page suivante répète la première');
        }
        [$tooMany] = $this->raw('GET', $concrete . $sep . 'per_page=201', $token, $tenant ? $this->school : null);
        self::assertSame(422, $tooMany->getStatusCode(), $route . ' : per_page au-delà de 200 accepté');
    }

    #[DataProvider('routes')]
    public function testIdempotencyKeyExigeeEtDeclaree(string $method, string $path, bool $auth, bool $tenant): void
    {
        $route = $this->key($method, $path);
        $declared = false;
        foreach ((array) (self::openApi()['paths'][$path][strtolower($method)]['parameters'] ?? []) as $parameter) {
            $declared = $declared || ($parameter['$ref'] ?? '') === '#/components/parameters/IdempotencyKey' || ($parameter['name'] ?? '') === 'Idempotency-Key';
        }
        $sensitive = in_array($route, ['POST /api/v1/payments', 'POST /api/v1/cash/receipts', 'POST /api/v1/documents'], true);
        self::assertSame($sensitive, $declared, $route . ' : déclaration Idempotency-Key dans openapi.d');
        if (!$sensitive) {
            return;
        }
        $token = $route === 'POST /api/v1/cash/receipts' ? $this->tokens['cashier'] : $this->admin;
        [$response] = $this->raw($method, $this->concretePath($path), $token, $this->school, '{}');
        self::assertSame(400, $response->getStatusCode(), $route . ' sans Idempotency-Key');
        $this->assertProblem($response, $route);
        [$bad] = $this->raw($method, $this->concretePath($path), $token, $this->school, '{}', ['Idempotency-Key' => 'court']);
        self::assertSame(400, $bad->getStatusCode(), $route . ' avec une clé invalide');
    }

    #[DataProvider('routes')]
    public function testLesReponsesDeLectureCollentAuContrat(string $method, string $path, bool $auth, bool $tenant): void
    {
        $route = $this->key($method, $path);
        $operation = self::openApi()['paths'][$path][strtolower($method)] ?? null;
        self::assertIsArray($operation, $route . ' absente du contrat');
        $documented = array_map('strval', array_keys((array) ($operation['responses'] ?? [])));
        self::assertNotSame([], $documented, $route . ' : aucune réponse documentée');
        if ($method !== 'GET' || !$auth) {
            return;
        }

        $concrete = $this->concretePath($path);
        foreach (['admin', 'teacher', 'cashier', 'parent'] as $who) {
            [$response] = $this->raw('GET', $concrete, $this->tokens[$who], $tenant ? $this->school : null);
            if ($response->getStatusCode() !== 403) {
                break;
            }
        }
        $status = (string) $response->getStatusCode();
        $implicit = ['401', '403', '429'];
        self::assertTrue(in_array($status, $documented, true) || in_array($status, $implicit, true), $route . ' renvoie ' . $status . ', non documenté (' . implode(', ', $documented) . ') : ' . substr((string) $response->getBody(), 0, 200));
        self::assertNotSame('500', $status, $route);
        $this->assertSecurityHeaders($response, $route);
        if ((int) $status >= 400) {
            $this->assertProblem($response, $route);

            return;
        }

        $content = (array) ($operation['responses'][$status]['content'] ?? []);
        $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        if ($content !== []) {
            self::assertArrayHasKey($type, $content, $route . ' : type ' . $type . ' non déclaré (' . implode(', ', array_keys($content)) . ')');
            if (isset($content['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])) {
                [$xlsx] = $this->raw('GET', $concrete . (str_contains($concrete, '?') ? '&' : '?') . 'format=xlsx', $this->admin, $tenant ? $this->school : null);
                self::assertStringStartsWith('application/vnd.openxmlformats', $xlsx->getHeaderLine('Content-Type'), $route . ' : format=xlsx');
                self::assertStringStartsWith('PK', (string) $xlsx->getBody(), $route . ' : classeur xlsx');
            }
        } elseif ($type === 'application/json') {
            $json = json_decode((string) $response->getBody(), true);
            self::assertIsArray($json, $route);
            self::assertArrayHasKey('data', $json, $route . ' : enveloppe « data » absente');
        }
    }
}
