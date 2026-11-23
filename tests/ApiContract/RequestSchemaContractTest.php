<?php

declare(strict_types=1);

namespace Tests\ApiContract;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiContract\Support\SharedFixtureTestCase;

/**
 * Conformité aux schémas d'openapi.d quand ils existent. Les contrats ne décrivent aucun schéma de RÉPONSE (seul Problem,
 * vérifié partout par assertProblem) ; ils décrivent des schémas de CORPS de requête. Pour chacun :
 *  - corps vide : 422 avec une erreur sur chaque champ déclaré `required` ;
 *  - `additionalProperties: false` : un champ inconnu est refusé (422) et non ignoré silencieusement.
 * Appelé par l'administrateur du client A (routes /platform/** : hors périmètre, le client n'y a pas accès).
 */
final class RequestSchemaContractTest extends SharedFixtureTestCase
{
    /** @return array<string, array{0: string, 1: string, 2: bool, 3: bool, 4: list<string>, 5: bool}> */
    public static function schemaRoutes(): array
    {
        $rows = [];
        $spec = self::openApi();
        foreach (self::allRoutes() as $key => [$method, $path, $auth, $tenant]) {
            $schema = $spec['paths'][$path][strtolower($method)]['requestBody']['content']['application/json']['schema'] ?? null;
            if (!is_array($schema) || str_starts_with($path, '/api/v1/platform/') || str_contains($path, '/auth/') || str_contains($path, 'webhook')) {
                continue;
            }
            $rows[$key] = [$method, $path, $auth, $tenant, array_values(array_map('strval', (array) ($schema['required'] ?? []))), ($schema['additionalProperties'] ?? true) === false];
        }

        return $rows;
    }

    /** @param list<string> $required */
    #[DataProvider('schemaRoutes')]
    public function testLaValidationSuitLeSchemaDuContrat(string $method, string $path, bool $auth, bool $tenant, array $required, bool $closed): void
    {
        $route = $method . ' ' . $path;
        $token = $auth ? ($route === 'POST /api/v1/cash/receipts' ? $this->tokens['cashier'] : $this->admin) : null;
        $headers = ['Idempotency-Key' => 'schema-' . substr(md5($route . microtime()), 0, 16)];
        $concrete = $this->concretePath($path);

        if ($required !== []) {
            [$response, $json] = $this->raw($method, $concrete, $token, $tenant ? $this->school : null, '{}', $headers);
            $status = $response->getStatusCode();
            self::assertNotSame(500, $status, $route);
            if (in_array($status, [403, 404, 409], true)) {
                $this->addToAssertionCount(1); // Ressource absente du jeu ou état incompatible : schéma non vérifiable ici.
            } else {
                self::assertSame(422, $status, $route . ' : corps vide accepté (' . $status . ') alors que le contrat exige ' . implode(', ', $required) . ' : ' . substr((string) $response->getBody(), 0, 200));
                $missing = array_values(array_diff($required, array_keys((array) ($json['errors'] ?? []))));
                self::assertSame([], $missing, $route . ' : champs obligatoires du contrat non signalés par la validation');
            }
        }

        if ($closed) {
            $body = json_encode(['champ_inconnu_recette' => 'x'], JSON_THROW_ON_ERROR);
            [$unknown, $json] = $this->raw($method, $concrete, $token, $tenant ? $this->school : null, $body, ['Idempotency-Key' => $headers['Idempotency-Key'] . 'u']);
            if (in_array($unknown->getStatusCode(), [403, 404, 409], true)) {
                $this->addToAssertionCount(1);

                return;
            }
            self::assertSame(422, $unknown->getStatusCode(), $route . ' : champ inconnu non refusé (additionalProperties: false)');
            if ($required === []) {
                self::assertArrayHasKey('champ_inconnu_recette', (array) ($json['errors'] ?? []), $route . ' : erreur non rattachée au champ inconnu');
            }
        }
        if ($required === [] && !$closed) {
            $this->addToAssertionCount(1);
        }
    }
}
