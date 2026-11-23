<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Application;

use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\NurserySettings;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\People\Contracts\MediaRef;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;

/**
 * Durée de conservation des photos du cahier de vie (MQ-07) : `nursery.lifebook.retention_days` jours après le jour de l'activité.
 * À échéance, le fichier est supprimé du stockage privé (MediaStore) et la ligne passe à `purged` (sans étiquetage d'enfant) : la trace
 * reste, la photo non. Sans durée paramétrée, rien n'est purgé (jamais de durée inventée). Chaque purge est journalisée.
 */
final class MediaRetention
{
    public function __construct(
        private readonly Store $store,
        private readonly MediaStore $media,
        private readonly SectionService $sections,
        private readonly NurserySettings $settings,
        private readonly NurseryClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return list<array{0: string, 1: string}> */
    public function establishments(): array
    {
        return $this->sections->establishments();
    }

    /**
     * @param string|null $today jour de référence (AAAA-MM-JJ), aujourd'hui par défaut
     * @return array{purged: int, retention_days: ?int, before: ?string}
     */
    public function purge(string $tenantId, string $establishmentId, ?string $today = null): array
    {
        $days = $this->settings->int($tenantId, $establishmentId, NurserySettings::LIFEBOOK_RETENTION_DAYS);
        if ($days === null) {
            return ['purged' => 0, 'retention_days' => null, 'before' => null];
        }
        $reference = new \DateTimeImmutable($today ?? $this->clock->today());
        $before = $reference->modify('-' . $days . ' days')->format('Y-m-d');
        $purged = 0;
        foreach ($this->store->select(LifeBookService::ENTRIES, $tenantId, $establishmentId, [], 'activity_on, id', 10000, 'activity_on < ?', [$before]) as $entry) {
            foreach ($this->store->select(LifeBookService::MEDIA, $tenantId, $establishmentId, ['entry_id' => (string) $entry['id']], 'id', 500, "status <> 'purged'") as $row) {
                $ref = MediaRef::tryFrom((string) $row['media_ref']);
                if ($ref !== null) {
                    $this->media->delete($tenantId, $ref);
                }
                $this->store->delete(LifeBookService::MEDIA_CHILDREN, $tenantId, $establishmentId, ['media_id' => (string) $row['id']]);
                $this->store->update(LifeBookService::MEDIA, $tenantId, $establishmentId, ['status' => 'purged', 'purged_at' => $this->clock->now()], ['id' => (string) $row['id']]);
                $this->audit->append($tenantId, 'system', 'nursery.lifebook.media_purged', 'lifebook_media', (string) $row['id'], ['status' => (string) $row['status']], ['status' => 'purged', 'before' => $before]);
                ++$purged;
            }
        }

        return ['purged' => $purged, 'retention_days' => $days, 'before' => $before];
    }
}
