<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Messages\Portal;

use Scholaris\Modules\Apps\PublicSchools\Messages\Application\BroadcastService;
use Scholaris\Modules\Apps\PublicSchools\Messages\Application\MessageClock;
use Scholaris\Modules\Apps\PublicSchools\Messages\Infrastructure\PdoMessageBatches;
use Scholaris\Modules\Apps\PublicSchools\Support\WriteGate;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSection;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;

/** Section « messages » du portail famille : derniers messages de l'école adressés à la classe de l'enfant. */
final class MessagesSection extends FamilySections implements PortalSection
{
    private const LIMIT = 10;

    public function __construct(
        EnrollmentDirectory $enrollments,
        WriteGate $gate,
        SettingsReader $settings,
        MessageClock $clock,
        private readonly PdoMessageBatches $batches,
    ) {
        parent::__construct($enrollments, $gate, $settings, $clock);
    }

    public function key(): string
    {
        return 'public_schools_messages';
    }

    public function portals(): array
    {
        return [self::FAMILY];
    }

    public function build(PortalSectionContext $context): ?array
    {
        $enrollments = $this->activeEnrollments($context);
        if ($enrollments === []) {
            return null;
        }
        $since = $this->since($context->tenantId)->format(DATE_ATOM);
        $messages = [];
        foreach ($enrollments as $enrollment) {
            if ($enrollment->structureId === null) {
                continue;
            }
            foreach ($this->batches->recentForClass($context->tenantId, $enrollment->establishmentId, $enrollment->structureId, $since, self::LIMIT) as $row) {
                $batch = BroadcastService::resource($row);
                $messages[] = [
                    'id' => $batch['id'],
                    'establishment_id' => $enrollment->establishmentId,
                    'template_key' => $batch['template_key'],
                    'text' => is_string($batch['variables']['details'] ?? null) ? $batch['variables']['details'] : null,
                    'sent_at' => $batch['created_at'],
                ];
            }
        }
        usort($messages, static fn (array $left, array $right): int => strcmp($right['sent_at'], $left['sent_at']));

        return ['messages' => array_slice($messages, 0, self::LIMIT)];
    }
}
