<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Modules\Apps\PublicNursery\PublicNurseryApplication;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;
use Tests\Support\AppTestCase;

final class ApplicationManifestTest extends AppTestCase
{
    public function testLeManifesteEtLeVocabulaireSontExposes(): void
    {
        $definitions = require dirname(__DIR__, 3) . '/src/Modules/Apps/PublicNursery/definitions.php';

        self::assertInstanceOf(ApplicationManifest::class, $definitions['platform.applications.public_nursery'] ?? null);
        self::assertSame('public_nursery', $definitions['platform.applications.public_nursery']->key);
        self::assertSame('nursery', $definitions['platform.applications.public_nursery']->target);
        self::assertSame('Maternelle publique', PublicNurseryApplication::descriptor()->label);
        self::assertSame('enfant', PublicNurseryApplication::descriptor()->vocabulary['learner']);
        self::assertSame('section', PublicNurseryApplication::descriptor()->vocabulary['grouping']);
    }
}
