<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Pupils;

use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;

/** Avec la passerelle réelle de PublicSchools/Messages (modèle NOT-01 absent en test) l'inscription réussit : un échec d'envoi n'est qu'un avertissement. */
final class PupilRealMessagingTest extends PublicSchoolsTestCase
{
    public function testInscriptionAvecLaPasserelleReelleDeMessages(): void
    {
        $this->bootPublic(false);
        $this->seedSchool('t1', 'e1', 'SYN-E1');
        $this->director('dir1', 't1', 'e1', 'public_schools.pupils.write');
        $class = $this->createClass('t1', 'e1', 'SIL A SYN');

        self::assertTrue($this->kernel->container()->has(PublicMessages::class));
        [$response, $json] = $this->api('POST', '/public/pupils', 'dir1', 't1', 'e1', [
            'last_name' => 'SYN-Mbarga', 'first_name' => 'Awa', 'birth_date' => '2020-03-14', 'sex' => 'F', 'birth_act_ref' => 'SYN-ACTE-001', 'class_id' => $class,
            'guardians' => [['kind' => 'parent', 'identity' => ['first_name' => 'Jean', 'last_name' => 'SYN-Mbarga', 'phone' => '677000111']]],
        ]);

        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));
        self::assertNotContains('birth_act_missing', $json['data']['warnings']);
    }
}
