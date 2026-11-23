<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;

/**
 * Cycle de vie d'une année scolaire. L'année est propre à un établissement : la clôturer n'affecte jamais
 * les autres établissements du même client (le dépôt est borné au client et à l'établissement indiqués).
 */
final class AcademicYearService
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';

    public function __construct(
        private readonly Repositories $repositories,
        private readonly OrgClock $clock,
    ) {
    }

    /**
     * @return array<string, mixed> l'année clôturée
     * @throws HttpException 404 si l'année n'est pas celle de cet établissement, 409 si elle est déjà clôturée
     */
    public function close(string $tenantId, string $establishmentId, string $yearId, string $actorId): array
    {
        $years = $this->repositories->explicit('academic_years', $tenantId, $establishmentId);
        $year = $years->findOrFail($yearId);
        if ($year['status'] === self::CLOSED) {
            throw HttpException::conflict('Cette année scolaire est déjà clôturée.');
        }

        $now = $this->clock->now();
        $years->update($yearId, ['status' => self::CLOSED, 'closed_at' => $now, 'closed_by' => $actorId, 'updated_at' => $now]);

        return $years->findOrFail($yearId);
    }
}
