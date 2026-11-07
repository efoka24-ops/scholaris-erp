<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;
use Scholaris\Modules\Apps\PublicSchools\Messages\Application\AnnouncementService;
use Scholaris\Modules\Apps\PublicSchools\Messages\Application\BroadcastService;
use Scholaris\Modules\Apps\PublicSchools\Messages\Application\MessageClock;
use Scholaris\Modules\Apps\PublicSchools\Messages\Application\PublicMessagesService;
use Scholaris\Modules\Apps\PublicSchools\Messages\Http\MessageController;
use Scholaris\Modules\Apps\PublicSchools\Messages\Infrastructure\PdoMessageBatches;
use Scholaris\Modules\Apps\PublicSchools\Messages\Portal\AbsencesSection;
use Scholaris\Modules\Apps\PublicSchools\Messages\Portal\MessagesSection;

return [
    MessageClock::class => autowire(),
    PdoMessageBatches::class => autowire(),
    PublicMessages::class => autowire(PublicMessagesService::class),
    PublicMessagesService::class => autowire(),
    BroadcastService::class => autowire(),
    AnnouncementService::class => autowire(),
    MessageController::class => autowire(),
    AbsencesSection::class => autowire(),
    MessagesSection::class => autowire(),
    // Portail famille (T641) : une seule clé de liste pour tout PublicSchools ; le sous-module Apee y ajoutera les reçus (EX-APE).
    'portals.sections.public_schools' => [AbsencesSection::class, MessagesSection::class],
];
