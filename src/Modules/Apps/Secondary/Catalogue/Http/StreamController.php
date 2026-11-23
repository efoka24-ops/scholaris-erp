<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Catalogue\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Apps\Secondary\Catalogue\Application\StreamCatalogue;
use Scholaris\Modules\Apps\Secondary\Catalogue\Infrastructure\PdoStreamCatalogue;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Séries et spécialités vues par un établissement (noyau : paramétrage). Lecture : `secondary.catalogue.read` (ou les droits
 * de paramétrage) ; ajout, masquage et renommage pour l'établissement : `secondary.settings.manage` ou
 * `secondary.profile.manage` sur tout l'établissement. Permission contrôlée AVANT la validation du corps.
 */
final class StreamController extends Controller
{
    public const READ = ['secondary.catalogue.read', 'secondary.settings.manage', 'secondary.profile.manage'];
    public const WRITE = ['secondary.settings.manage', 'secondary.profile.manage'];

    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly StreamCatalogue $catalogue)
    {
        parent::__construct($responder, $access, $store);
    }

    /** Liste paginée ; `scope=all` montre aussi les cycles et enseignements hors profil, `include_hidden=1` les lignes masquées. */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, self::READ);
        $query = ListQuery::fromRequest($request, PdoStreamCatalogue::FILTERS, PdoStreamCatalogue::SORTS);
        $params = $request->getQueryParams();
        $offset = self::offset($query);
        $rows = $this->catalogue->establishmentPage(
            $principal->tenantId,
            $establishmentId,
            $query->filters,
            $query->sorts,
            ($params['scope'] ?? 'profile') !== 'all',
            in_array($params['include_hidden'] ?? '0', ['1', 'true'], true),
            $query->perPage + 1,
            $offset,
        );

        return $this->page(self::paginate($rows, $query, $offset));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, self::READ);

        return $this->json($this->catalogue->establishmentShow($principal->tenantId, $establishmentId, self::id($request)));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $this->access->assertWhole($principal, $perimeter, 'secondary_stream');
        $values = StreamInput::creation($request);
        $stream = $this->catalogue->addForEstablishment($principal->tenantId, $establishmentId, $values, $principal->userId);
        $this->access->audit($principal, 'secondary.stream.created', 'secondary_stream', (string) $stream['id'], null, ['code' => $stream['code'], 'cycle_code' => $stream['cycle_code']]);

        return $this->json($stream, 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $this->access->assertWhole($principal, $perimeter, 'secondary_stream');
        $id = self::id($request);
        $values = StreamInput::change($request, false);
        $stream = $this->catalogue->changeForEstablishment($principal->tenantId, $establishmentId, $id, $values, $principal->userId);
        $this->access->audit($principal, 'secondary.stream.changed', 'secondary_stream', $id, null, ['label_fr' => $stream['label_fr'], 'hidden' => $stream['hidden']]);

        return $this->json($stream);
    }

    public static function offset(ListQuery $query): int
    {
        $decoded = ListQuery::decodeCursor($query->cursor);

        return $decoded !== null && ctype_digit($decoded) ? (int) $decoded : 0;
    }

    /**
     * @param list<array<string, mixed>> $rows une ligne de plus que la page (détection de la page suivante)
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public static function paginate(array $rows, ListQuery $query, int $offset): array
    {
        $next = null;
        if (count($rows) > $query->perPage) {
            array_pop($rows);
            $next = ListQuery::encodeCursor((string) ($offset + $query->perPage));
        }

        return ['data' => $rows, 'meta' => ['next_cursor' => $next, 'per_page' => $query->perPage]];
    }
}
