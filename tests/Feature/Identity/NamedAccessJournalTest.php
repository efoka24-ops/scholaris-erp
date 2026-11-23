<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use InvalidArgumentException;
use Scholaris\Modules\Socle\Identity\Contracts\NamedAccessJournal;
use Tests\Support\Identity\IdentityTestCase;

/** T607 (PP3) : la consultation nominative d'un inspecteur est visible du directeur de l'école ; la finalité est obligatoire. */
final class NamedAccessJournalTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        $this->boot();
        $this->directory->add('t1', 'e1', 'École A1', 'primary');
        $this->directory->add('t1', 'e2', 'École A2', 'primary');
    }

    private function directorToken(string $phone, string $establishment): string
    {
        $this->createUser('t1', 'Directeur', $phone, [['directeur', 'establishment', $establishment]]);
        $token = (string) $this->json($this->login($phone))['token'];
        $setup = $this->json($this->call('POST', '/api/v1/auth/2fa/setup', $this->auth($token)));
        $this->call('POST', '/api/v1/auth/2fa/confirm', $this->auth($token), ['code' => $this->totp((string) $setup['secret'])]);

        return $token;
    }

    public function testLaConsultationDUnInspecteurEstVisibleDuDirecteurDeLEcoleSeulement(): void
    {
        $inspector = $this->createUser('t1', 'Inspecteur', '+237691000070');
        $journal = $this->kernel->container()->get(NamedAccessJournal::class);
        $journal->record('t1', 'e1', (string) $inspector['user_id'], 'learner', 'P-001', 'Vérification du registre');
        $journal->record('t1', 'e1', (string) $inspector['user_id'], 'learner', 'P-002', 'Vérification du registre');
        $journal->record('t1', 'e2', (string) $inspector['user_id'], 'learner', 'P-900', 'Enquête');

        $token = $this->directorToken('+237691000071', 'e1');
        $page = $this->call('GET', '/api/v1/audit/named-access?per_page=1', $this->auth($token, 'e1'));
        self::assertSame(200, $page->getStatusCode(), (string) $page->getBody());
        $json = $this->json($page);
        self::assertCount(1, $json['data']);
        self::assertSame('P-002', $json['data'][0]['record_id']);
        self::assertSame((string) $inspector['user_id'], $json['data'][0]['actor_id']);
        self::assertSame('Vérification du registre', $json['data'][0]['purpose']);
        self::assertIsString($json['meta']['next_cursor']);

        $rest = $this->json($this->call('GET', '/api/v1/audit/named-access?per_page=5&cursor=' . $json['meta']['next_cursor'], $this->auth($token, 'e1')));
        self::assertSame(['P-001'], array_column($rest['data'], 'record_id'));
        self::assertNull($rest['meta']['next_cursor']);

        self::assertSame(403, $this->call('GET', '/api/v1/audit/named-access', $this->auth($token, 'e2'))->getStatusCode());
        $teacher = $this->createUser('t1', 'Enseignant', '+237691000072', [['enseignant', 'establishment', 'e1']]);
        self::assertSame(403, $this->call('GET', '/api/v1/audit/named-access', $this->auth($this->token('+237691000072'), 'e1'))->getStatusCode(), (string) $teacher['user_id']);
    }

    public function testLaFinaliteEstObligatoire(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->kernel->container()->get(NamedAccessJournal::class)->record('t1', 'e1', 'actor', 'learner', 'P-001', '   ');
    }
}
