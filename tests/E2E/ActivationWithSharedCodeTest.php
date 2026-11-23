<?php

declare(strict_types=1);

namespace Tests\E2E;

use Scholaris\Modules\Socle\Identity\Contracts\PlatformAdministrators;
use Tests\E2E\Support\RealStackTestCase;

/**
 * C-116 (défaut C110-P1) : le code d'activation envoyé par SMS à l'acceptation d'une demande d'accès se saisit directement sur
 * /activation (identifiant + code), sans redemander de code ; une nouvelle demande dans la minute est refusée (429), ce qui ne
 * doit pas empêcher d'utiliser le code reçu. Données fictives.
 */
final class ActivationWithSharedCodeTest extends RealStackTestCase
{
    protected function setUp(): void
    {
        $this->bootRealStack();
    }

    public function testLeCodeRecuALAcceptationActiveLeCompteSansNouvelleDemande(): void
    {
        [$submitted] = $this->api('POST', '/public/access-requests', null, [
            'institution_name' => 'Institut Fictif de l\'Activation', 'target_type' => 'higher', 'city' => 'Garoua', 'region' => 'Nord',
            'contact_first_name' => 'Awa', 'contact_last_name' => 'Fictive-Activation', 'contact_email' => 'direction@activation-recette.test',
            'contact_phone' => '+237 699 50 00 05', 'app_key' => 'higher', 'message' => 'Pilote.',
        ]);
        self::assertSame(202, $submitted->getStatusCode(), (string) $submitted->getBody());

        $root = $this->kernel->container()->get(PlatformAdministrators::class)->create('+237699888776', 'Plato', 'Forme');
        $this->clock->advance(max(0, time() - $this->clock->now()) + 1);
        $platform = $this->signIn($root['login'], $root['password']);
        $requestId = (string) $this->ok('GET', '/platform/access-requests?filter[status]=pending', $platform)['data'][0]['id'];
        $accepted = $this->ok('POST', '/platform/access-requests/' . $requestId . '/accept', $platform, ['share_send' => 'sms', 'force_reason' => 'Pilote de la recette.'])['data'];
        self::assertSame('pending_activation', $accepted['administrator']['status']);

        $phone = '+237699500005';
        $code = $this->sms->lastCode($phone);
        self::assertNotNull($code, 'Le code d\'activation est envoyé par SMS à l\'acceptation.');

        // Une nouvelle demande de code dans la minute est refusée : la personne saisit donc le code déjà reçu.
        [$again] = $this->api('POST', '/auth/activation/request', null, ['identifier' => $phone]);
        self::assertSame(429, $again->getStatusCode(), (string) $again->getBody());

        $verified = $this->ok('POST', '/auth/activation/verify', null, ['identifier' => $phone, 'code' => $code]);
        $this->ok('POST', '/auth/activation/password', null, ['ticket' => (string) $verified['ticket'], 'password' => 'Recette-Activation-2026!']);
        self::assertNotSame('', $this->signIn($phone, 'Recette-Activation-2026!'));
    }
}
