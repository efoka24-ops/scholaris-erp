<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Feature\PublicNursery\Support\NurseryApplicationAccess;
use Tests\Support\Organisation\OrganisationTestCase;

final class NeedReportTest extends OrganisationTestCase
{
    public function testLesBesoinsSontVisiblesEtValidationnesPourLePersonnelAutorise(): void
    {
        $this->bootOrganisation([ApplicationAccess::class => new NurseryApplicationAccess()]);
        $this->seedEstablishment('tenant-dev', 'est-dev', 'École pilote', 'nursery', 'teacher');
        $this->grantAll('teacher', 'public_nursery.need.report');

        [$createdResponse, $created] = $this->api('POST', '/public-nursery/development/needs', 'teacher', 'tenant-dev', 'est-dev', [
            'learner_id' => 'learner-1',
            'need_area' => 'language',
            'summary' => 'Besoin d’accompagnement langage',
            'status' => 'open',
            'orientation_note' => 'Suivi orthophonie proposé',
        ]);
        self::assertSame(201, $createdResponse->getStatusCode());
        self::assertSame('language', $created['data']['need_area']);

        [$listResponse, $list] = $this->api('GET', '/public-nursery/development/needs', 'teacher', 'tenant-dev', 'est-dev');
        self::assertSame(200, $listResponse->getStatusCode());
        self::assertCount(1, $list['data']);
    }

    public function testUnBesoinInvalideEstRefuse(): void
    {
        $this->bootOrganisation([ApplicationAccess::class => new NurseryApplicationAccess()]);
        $this->seedEstablishment('tenant-dev', 'est-dev', 'École pilote', 'nursery', 'teacher');
        $this->grantAll('teacher', 'public_nursery.need.report');

        [$response] = $this->api('POST', '/public-nursery/development/needs', 'teacher', 'tenant-dev', 'est-dev', [
            'learner_id' => 'learner-1',
            'need_area' => 'medical',
            'summary' => 'Besoin impossible',
            'status' => 'open',
        ]);

        self::assertSame(422, $response->getStatusCode());
    }
}
