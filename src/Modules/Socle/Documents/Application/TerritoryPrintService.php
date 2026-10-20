<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Documents\Domain\LightPdfProfile;
use Scholaris\Modules\Socle\Documents\Infrastructure\DocumentRepository;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

/**
 * Lot d'impression multi-établissements (PP15, « impression à l'inspection ») : les documents valides d'un type, émis par les
 * écoles d'un territoire, réunis dans un seul PDF (profil léger par défaut), une page par document. Aucun document n'est émis
 * ni modifié. Il faut la permission `documents.read` dans CHACUN des établissements du territoire (rôle territorial ou de
 * niveau client) ; l'impression est journalisée.
 */
final class TerritoryPrintService
{
    public const MAX_DOCUMENTS = 500;
    public const READ = 'documents.read';

    public function __construct(
        private readonly TerritoryDirectory $territories,
        private readonly PermissionChecker $permissions,
        private readonly DocumentRepository $documents,
        private readonly DocumentService $service,
        private readonly AuditLog $audit,
        private readonly DocumentClock $clock,
    ) {
    }

    /** @return array{pdf: string, count: int} */
    public function print(Principal $principal, string $territoryId, string $typeCode, ?string $issuedFrom, string $profile = LightPdfProfile::LIGHT): array
    {
        $tenantId = $principal->tenantId;
        $at = $this->clock->now();
        if ($this->territories->find($tenantId, $territoryId, $at) === null) {
            throw HttpException::notFound('Territoire introuvable.');
        }
        $establishments = $this->territories->establishmentsIn($tenantId, $territoryId, $at);
        foreach ($establishments as $establishmentId) {
            if (!$this->permissions->can($principal, self::READ, $establishmentId)) {
                throw HttpException::forbidden('Ce territoire est hors de votre périmètre.');
            }
        }
        if ($establishments === []) {
            throw HttpException::forbidden('Ce territoire est hors de votre périmètre.');
        }
        $rows = $this->documents->validForPrint($tenantId, $establishments, $typeCode, $issuedFrom, self::MAX_DOCUMENTS + 1);
        if ($rows === []) {
            throw HttpException::notFound('Aucun document valide à imprimer pour ce territoire.');
        }
        if (count($rows) > self::MAX_DOCUMENTS) {
            throw HttpException::validation(['issued_from' => ['Plus de ' . self::MAX_DOCUMENTS . ' documents : restreindre par date d\'émission.']]);
        }

        $pages = array_map(static fn (array $row): string => self::body((string) $row['content']), $rows);
        $html = '<html><head><meta charset="utf-8"></head><body>' . implode('<div class="page-break"></div>', $pages) . '</body></html>';
        $pdf = $this->service->pdf($html, $profile);
        $this->audit->append($tenantId, $principal->userId, 'document.territory_printed', 'territory', $territoryId, null, [
            'type' => $typeCode, 'count' => count($rows), 'establishments' => count(array_unique(array_column($rows, 'establishment_id'))), 'profile' => $profile,
        ]);

        return ['pdf' => $pdf, 'count' => count($rows)];
    }

    /** Contenu du corps d'un document émis (le document entier s'il n'a pas de balise body). */
    private static function body(string $html): string
    {
        return preg_match('#<body\b[^>]*>(.*)</body>#is', $html, $m) === 1 ? $m[1] : $html;
    }
}
