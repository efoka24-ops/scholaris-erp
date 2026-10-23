<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Messaging\Contracts\TerritorialAnnouncer;
use Scholaris\Modules\Socle\Messaging\Infrastructure\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

final class TerritorialAnnouncerService implements TerritorialAnnouncer
{
    public function __construct(
        private readonly Store $store,
        private readonly TerritoryDirectory $territories,
        private readonly MessagingClock $clock,
    ) {
    }

    public function announce(string $tenantId, string $territoryId, string $title, string $body, string $actorId, ?string $expiresAt = null): array
    {
        $title = trim($title);
        $body = trim($body);
        $errors = [];
        if ($title === '' || mb_strlen($title) > 160) {
            $errors['title'] = ['Titre requis (160 caractères max).'];
        }
        if ($body === '') {
            $errors['body'] = ['Texte requis.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $now = $this->clock->iso();
        $establishments = $this->territories->establishmentsIn($tenantId, $territoryId, $now);
        foreach ($establishments as $establishmentId) {
            $this->store->insert('announcements', [
                'id' => (string) new Ulid(),
                'tenant_id' => $tenantId,
                'establishment_id' => $establishmentId,
                'created_by' => $actorId,
                'title' => $title,
                'body' => $body,
                'published_at' => $now,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
                'origin_territory_id' => $territoryId,
            ]);
        }

        return $establishments;
    }
}
