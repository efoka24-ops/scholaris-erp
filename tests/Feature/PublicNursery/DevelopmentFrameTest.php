<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Modules\Apps\PublicNursery\Development\Application\FrameService;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Feature\PublicNursery\Support\NurseryApplicationAccess;
use Tests\Support\Organisation\OrganisationTestCase;

final class DevelopmentFrameTest extends OrganisationTestCase
{
    public function testLeReferentielSeVersionneEtGardeLaDerniereVersion(): void
    {
        $this->bootOrganisation();
        $service = $this->kernel->container()->get(FrameService::class);
        $first = $service->save('tenant-dev', [
            'frame_code' => 'FRAME-PS', 'version' => 1, 'subsystem' => 'fr', 'label_fr' => 'Cadre PS', 'label_en' => 'PS frame', 'status' => 'draft', 'validation_ref' => null,
        ]);
        $second = $service->save('tenant-dev', [
            'frame_code' => 'FRAME-PS', 'version' => 2, 'subsystem' => 'fr', 'label_fr' => 'Cadre PS révisé', 'label_en' => 'Revised PS frame', 'status' => 'active', 'validation_ref' => 'VAL-2',
        ]);

        self::assertSame(1, $first['version']);
        self::assertSame(2, $second['version']);
        self::assertSame('FRAME-PS', $service->latest('tenant-dev', 'FRAME-PS')['frame_code']);
        self::assertSame(2, $service->latest('tenant-dev', 'FRAME-PS')['version']);
        self::assertCount(2, $service->all('tenant-dev'));
    }

    public function testLeControleurRefuseLEcritureSansSettingsManageEtAccepteLeR21Virtuel(): void
    {
        $this->bootOrganisation([ApplicationAccess::class => new NurseryApplicationAccess()]);
        $this->seedEstablishment('tenant-dev', 'est-dev', 'École pilote', 'nursery', 'admin', 'school');
        $this->grantAll('admin', 'settings.manage');
        $this->grantAll('school', 'public_nursery.dev.read');

        [$forbidden] = $this->api('POST', '/public-nursery/development/frames/import', 'school', 'tenant-dev', 'est-dev', ['csv' => "frame_code,version,subsystem,label_fr,label_en,status\nFRAME-PS,1,fr,Cadre PS,PS frame,draft"]);
        self::assertSame(403, $forbidden->getStatusCode());

        [$accepted, $json] = $this->api('POST', '/public-nursery/development/frames/import', 'admin', 'tenant-dev', 'est-dev', ['csv' => "frame_code,version,subsystem,label_fr,label_en,status,validation_ref\nFRAME-PS,1,fr,Cadre PS,PS frame,draft,VAL-1\nFRAME-PS,2,fr,Cadre PS révisé,PS frame revised,active,VAL-2"]);
        self::assertSame(201, $accepted->getStatusCode());
        self::assertSame(2, $json['data']['created']);
        self::assertSame(2, $json['data']['valid']);
    }
}
