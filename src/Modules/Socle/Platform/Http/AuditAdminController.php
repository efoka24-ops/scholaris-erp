<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Audit\Contracts\AuditTrail;
use Scholaris\Modules\Socle\Platform\Application\PlatformAudit;

/**
 * Journal d'audit de la plateforme (client technique `platform` uniquement : jamais le journal d'un client),
 * le plus récent d'abord. Filtres : action (préfixe), actor_id, object_type, object_id, from, to (AAAA-MM-JJ).
 */
final class AuditAdminController
{
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly AuditTrail $trail,
    ) {
    }

    /** GET /platform/audit */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.audit.read');
        $query = ListQuery::fromRequest($request, ['action', 'actor_id', 'object_type', 'object_id', 'from', 'to']);
        $filters = $query->filters;
        foreach (['from', 'to'] as $date) {
            if (isset($filters[$date]) && preg_match(self::DATE, $filters[$date]) !== 1) {
                throw HttpException::validation(['filter[' . $date . ']' => ['Date attendue au format AAAA-MM-JJ.']]);
            }
        }
        $before = ListQuery::decodeCursor($query->cursor);
        $beforeSeq = $before !== null && ctype_digit($before) ? (int) $before : null;
        $rows = $this->trail->recent(PlatformAudit::PLATFORM_TENANT, $filters, $beforeSeq, $query->perPage + 1);
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) $rows[$query->perPage - 1]['seq']);
        }
        $items = array_map(static fn (array $row): array => ['id' => (string) $row['seq']] + $row, $rows);

        return $this->responder->json(['data' => $items, 'meta' => ['next_cursor' => $next]]);
    }
}
