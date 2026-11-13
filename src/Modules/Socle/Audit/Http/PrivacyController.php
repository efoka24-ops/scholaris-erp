<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Modules\Socle\Audit\Application\AuditLogReader;
use Scholaris\Modules\Socle\Audit\Application\DataRequestService;
use Scholaris\Modules\Socle\Audit\Application\PrivacySettings;
use Scholaris\Modules\Socle\Audit\Application\ProcessingRegister;
use Scholaris\Modules\Socle\Audit\Application\TenantExporter;

/** Conformité : demandes des personnes concernées, paramètres, registre des traitements, export complet du client. */
final class PrivacyController
{
    use ExportsList;

    private const EXPORT_LIMIT = 5000;

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly TenantContext $context,
        private readonly PrivacyPolicy $policy,
        private readonly DataRequestService $requests,
        private readonly PrivacySettings $settings,
        private readonly ProcessingRegister $register,
        private readonly TenantExporter $exporter,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly AuditLogReader $auditLog,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, PrivacyPolicy::READ, PrivacyPolicy::MANAGE);
        $query = ListQuery::fromRequest($request, ['status', 'kind']);
        $offset = 0;
        if ($query->cursor !== null) {
            $decoded = ListQuery::decodeCursor($query->cursor);
            if ($decoded === null || preg_match('/^\d{1,9}$/', $decoded) !== 1) {
                throw HttpException::badRequest('Curseur invalide ou périmé.');
            }
            $offset = (int) $decoded;
        }
        $rows = $this->requests->list($this->tenant(), $query->filters['status'] ?? null, $query->filters['kind'] ?? null, $query->perPage + 1, $offset);
        $more = count($rows) > $query->perPage;
        $rows = array_slice($rows, 0, $query->perPage);
        $next = $more ? ListQuery::encodeCursor((string) ($offset + $query->perPage)) : null;
        $links = ['next' => null];
        if ($next !== null) {
            $params = $request->getQueryParams();
            $params['cursor'] = $next;
            $links['next'] = $request->getUri()->getPath() . '?' . http_build_query($params);
        }

        return $this->responder->json(['data' => array_map($this->present(...), $rows), 'links' => $links, 'meta' => ['next_cursor' => $next]]);
    }

    /** Export CSV / Excel des demandes de personnes concernées (mêmes filtres et mêmes droits que la liste, plafonné). */
    public function exportRequests(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, PrivacyPolicy::READ, PrivacyPolicy::MANAGE);
        $filters = ListQuery::fromRequest($request, ['status', 'kind'])->filters;
        $rows = [];
        $offset = 0;
        do {
            $chunk = $this->requests->list($this->tenant(), $filters['status'] ?? null, $filters['kind'] ?? null, ListQuery::MAX_PER_PAGE, $offset);
            array_push($rows, ...$chunk);
            $offset += ListQuery::MAX_PER_PAGE;
        } while (count($chunk) === ListQuery::MAX_PER_PAGE && count($rows) < self::EXPORT_LIMIT);

        return $this->csvResponse(
            $this->responses,
            $this->streams,
            'demandes-personnes-concernees.csv',
            ['Identifiant', 'Nature', 'Personne', 'Détails', 'Statut', 'Reçue le', 'Échéance', 'Démarrée le', 'Traitée le', 'Motif de refus', 'Résolution', 'En retard', 'Créée par', 'Décidée par'],
            array_map(function (array $row): array {
                $r = $this->present($row);

                return [$r['id'], $r['kind'], $r['subject_person_id'], $r['details'], $r['status'], $r['received_at'], $r['due_at'], $r['started_at'], $r['processed_at'], $r['refusal_reason'], $r['resolution'], $r['overdue'], $r['created_by'], $r['decided_by']];
            }, array_slice($rows, 0, self::EXPORT_LIMIT)),
            $request,
        );
    }

    /**
     * Export CSV / Excel du journal d'audit du client : réservé à la permission d'export de la conformité, sans
     * états avant / après, adresse IP, appareil ni empreintes. Filtres : actor_id, action, object_type, from, to.
     */
    public function exportAuditLog(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, PrivacyPolicy::EXPORT);
        $filters = ListQuery::fromRequest($request, ['actor_id', 'action', 'object_type', 'from', 'to'])->filters;
        foreach (['from', 'to'] as $bound) {
            if (isset($filters[$bound]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$bound]) !== 1) {
                throw HttpException::validation(['filter[' . $bound . ']' => ['La date doit être au format AAAA-MM-JJ.']]);
            }
        }
        $rows = [];
        $after = 0;
        do {
            $chunk = $this->auditLog->page($this->tenant(), $filters, $after, ListQuery::MAX_PER_PAGE);
            array_push($rows, ...$chunk);
            $after = $chunk === [] ? $after : (int) $chunk[count($chunk) - 1]['seq'];
        } while (count($chunk) === ListQuery::MAX_PER_PAGE && count($rows) < self::EXPORT_LIMIT);

        return $this->csvResponse(
            $this->responses,
            $this->streams,
            'journal-audit.csv',
            ['Rang', 'Date', 'Auteur', 'Action', 'Type d\'objet', 'Objet'],
            array_map(static fn (array $r): array => [$r['seq'], $r['occurred_at'], $r['actor_id'], $r['action'], $r['object_type'], $r['object_id']], array_slice($rows, 0, self::EXPORT_LIMIT)),
            $request,
        );
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, PrivacyPolicy::MANAGE);
        $body = new Body($request, ['kind', 'subject_person_id', 'details']);
        $kind = $body->enum('kind', DataRequestService::KINDS, true);
        $subject = $body->pattern('subject_person_id', '/^[A-Za-z0-9_-]{1,60}$/', 'Identifiant de personne invalide.', true);
        $details = $body->string('details', 2000);
        $body->done();

        $row = $this->requests->create($this->tenant(), (string) $kind, (string) $subject, $details, $principal->userId);

        return $this->responder->json(['data' => $this->present($row)], 201);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, PrivacyPolicy::READ, PrivacyPolicy::MANAGE);

        return $this->responder->json(['data' => $this->present($this->requests->get($this->tenant(), $this->id($request)))]);
    }

    public function start(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, PrivacyPolicy::MANAGE);

        return $this->responder->json(['data' => $this->present($this->requests->start($this->tenant(), $this->id($request), $principal->userId))]);
    }

    public function refuse(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, PrivacyPolicy::MANAGE);
        $body = new Body($request, ['reason']);
        $reason = $body->string('reason', 1000, true);
        $body->done();

        return $this->responder->json(['data' => $this->present($this->requests->refuse($this->tenant(), $this->id($request), (string) $reason, $principal->userId))]);
    }

    public function approval(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, PrivacyPolicy::MANAGE);
        $body = new Body($request, ['decision']);
        $decision = $body->enum('decision', ['approve', 'reject'], true);
        $body->done();

        return $this->responder->json(['data' => $this->present(
            $this->requests->decideErasure($this->tenant(), $this->id($request), $decision === 'approve', $principal->userId),
        )]);
    }

    public function complete(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, PrivacyPolicy::MANAGE);
        $body = new Body($request, ['resolution'], true);
        $resolution = $body->string('resolution', 2000);
        $body->done();

        return $this->responder->json(['data' => $this->present($this->requests->complete($this->tenant(), $this->id($request), $resolution, $principal->userId))]);
    }

    public function data(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, PrivacyPolicy::MANAGE);

        return $this->responder->json(['data' => $this->requests->subjectData($this->tenant(), $this->id($request), $principal->userId)])
            ->withHeader('Cache-Control', 'no-store');
    }

    public function showSettings(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, PrivacyPolicy::READ, PrivacyPolicy::MANAGE);

        return $this->responder->json(['data' => $this->settings->all($this->tenant())]);
    }

    public function updateSettings(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, PrivacyPolicy::MANAGE);
        $data = Body::decode($request);

        return $this->responder->json(['data' => $this->settings->update($this->tenant(), $data, $principal->userId)]);
    }

    public function processingRegister(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, PrivacyPolicy::READ, PrivacyPolicy::MANAGE);

        return $this->responder->json(['data' => $this->register->all()]);
    }

    public function tenantExport(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, PrivacyPolicy::EXPORT);
        $bytes = $this->exporter->exportToString($this->tenant(), $principal->userId);

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="export-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $this->tenant()) . '.zip"')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream($bytes));
    }

    private function authorize(ServerRequestInterface $request, string ...$anyOf): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $this->policy->authorize($principal, ...$anyOf);

        return $principal;
    }

    private function tenant(): string
    {
        return $this->context->tenantId();
    }

    private function id(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute('id');
    }

    /**
     * Liste blanche des champs exposés.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $out = [];
        foreach (['id', 'kind', 'subject_person_id', 'details', 'status', 'received_at', 'due_at', 'started_at', 'processed_at', 'refusal_reason', 'resolution', 'approval_id', 'created_by', 'decided_by'] as $field) {
            $out[$field] = $row[$field] ?? null;
        }
        $out['overdue'] = $this->requests->isOverdue($row);

        return $out;
    }
}
