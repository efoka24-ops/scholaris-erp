<?php

declare(strict_types=1);

namespace Tests\E2E;

use Nyholm\Psr7\ServerRequest;
use Tests\E2E\Support\RealStackTestCase;

/**
 * Enregistre, depuis la pile réelle, les réponses des écrans publics de la plateforme (catalogue, demande d'accès acceptée,
 * demande invalide 422, suivi par jeton) dans web/e2e/fixtures/platform.json, pour le faux backend Playwright.
 */
final class PlatformUiFixturesRecorderTest extends RealStackTestCase
{
    protected function setUp(): void
    {
        $this->bootRealStack(['app' => ['url' => 'https://app.scholaris.test']]);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, content_type: string, body: mixed}
     */
    private function call(string $method, string $path, ?array $body = null, string $ip = '198.51.100.30'): array
    {
        $headers = ['Accept-Language' => 'fr'] + ($body === null ? [] : ['Content-Type' => 'application/json']);
        [$response] = $this->send(new ServerRequest($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR), '1.1', ['REMOTE_ADDR' => $ip]));

        return ['status' => $response->getStatusCode(), 'content_type' => $response->getHeaderLine('Content-Type'), 'body' => json_decode((string) $response->getBody(), true)];
    }

    public function testEnregistreLesReponsesDesEcransPublicsDeLaPlateforme(): void
    {
        $valid = [
            'institution_name' => 'Collège Bilingue de Recette', 'target_type' => 'secondary', 'city' => 'Douala', 'region' => 'Littoral',
            'contact_first_name' => 'Awa', 'contact_last_name' => 'Ngo Bassa', 'contact_email' => 'direction@college-recette.test',
            'contact_phone' => '+237690112233', 'app_key' => 'secondary', 'establishment_subtype' => 'college_prive', 'locale' => 'fr',
        ];
        $calls = [];
        $calls['public.applications'] = $this->call('GET', '/public/applications');
        $calls['public.request.invalid'] = $this->call('POST', '/public/access-requests', ['contact_email' => 'pas-une-adresse'] + $valid);
        $calls['public.request.accepted'] = $this->call('POST', '/public/access-requests', $valid);
        $token = (string) ($calls['public.request.accepted']['body']['data']['tracking_token'] ?? '');
        $calls['public.track.pending'] = $this->call('GET', '/public/access-requests/' . $token);
        $calls['public.track.unknown'] = $this->call('GET', '/public/access-requests/' . str_repeat('a', 48));

        self::assertSame(200, $calls['public.applications']['status']);
        self::assertSame(422, $calls['public.request.invalid']['status']);
        self::assertSame(202, $calls['public.request.accepted']['status']);
        self::assertSame(200, $calls['public.track.pending']['status']);
        self::assertSame(404, $calls['public.track.unknown']['status']);

        $file = dirname(__DIR__, 2) . '/web/e2e/fixtures/platform.json';
        file_put_contents($file, json_encode(['recorded_from' => 'tests/E2E/PlatformUiFixturesRecorderTest.php', 'tracking_token' => $token, 'calls' => $calls], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        self::assertFileExists($file);
    }
}
