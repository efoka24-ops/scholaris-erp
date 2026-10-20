<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Documents\Application\DocumentClock;
use Scholaris\Modules\Socle\Documents\Application\DocumentService;
use Scholaris\Modules\Socle\Documents\Http\Policies\DocumentPolicy;
use Scholaris\Modules\Socle\Documents\Http\Requests\Validator;
use Scholaris\Modules\Socle\Documents\Http\Resources\Resources;
use Scholaris\Modules\Socle\Documents\Infrastructure\DocumentRepository;

/** Registre et émission : liste filtrée, export CSV, émission, détail, aperçu PDF, duplicata, révocation. */
final class DocumentController extends BaseController
{
    private const FILTERS = ['view', 'status', 'type', 'subject_person_id', 'issued_via', 'number'];
    private const SORTS = ['issued_at', 'number', 'type'];
    private const VIEWS = ['all', 'bulletins', 'certificates', 'revoked'];
    private const EXPORT_LIMIT = 5000;

    public function __construct(
        HttpKit $kit,
        private readonly DocumentService $documents,
        private readonly DocumentRepository $repository,
        private readonly DocumentClock $clock,
    ) {
        parent::__construct($kit);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::READ);
        $query = ListQuery::fromRequest($request, self::FILTERS, self::SORTS);
        $this->assertView($query->filters);

        return $this->page(
            $request,
            $query,
            fn (int $limit, int $offset): array => $this->repository->search($this->tenant(), $this->establishment(), $query->filters, $this->sorts($query), $limit, $offset),
            fn (array $row): array => Resources::document($row, $this->clock->verificationBase()),
        );
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::READ);
        $query = ListQuery::fromRequest($request, self::FILTERS, self::SORTS);
        $this->assertView($query->filters);
        $rows = $this->repository->search($this->tenant(), $this->establishment(), $query->filters, $this->sorts($query), self::EXPORT_LIMIT, 0);

        return $this->exportFile(
            $request,
            'registre-documents.csv',
            ['Numéro', 'Type', 'Titulaire', 'Émis par', 'Origine', 'Date', 'Statut', 'Version du modèle'],
            array_map(static fn (array $row): array => [
                $row['number'], $row['type_code'], $row['holder_name'], $row['issued_by'], $row['issued_via'], $row['issued_at'], $row['status'], $row['template_version'],
            ], $rows),
        );
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::ISSUE);
        $v = new Validator(Validator::body($request), ['type', 'subject_person_id', 'locale', 'data']);
        $type = $v->pattern('type', '/^[a-z][a-z0-9_]{1,29}$/', 'Code de type invalide.', true, 30);
        $subject = $v->pattern('subject_person_id', '/^[A-Za-z0-9_-]{1,40}$/', 'Identifiant invalide.', true, 40);
        $locale = $v->enum('locale', DocumentService::LOCALES) ?? 'fr';
        $data = $v->values('data');
        $v->done();

        $issued = $this->documents->issue($this->tenant(), $this->establishment(), (string) $type, (string) $subject, $data, $principal->userId, $locale);
        $row = $this->repository->find($this->tenant(), $issued->id, $this->establishment()) ?? [];

        return $this->responder->json(['data' => Resources::document($row, $this->clock->verificationBase())], 201);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::READ);

        return $this->responder->json(['data' => Resources::document($this->find($request), $this->clock->verificationBase())]);
    }

    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::READ);
        $profile = $request->getQueryParams()['profile'] ?? \Scholaris\Modules\Socle\Documents\Domain\LightPdfProfile::STANDARD;
        if (!is_string($profile) || !in_array($profile, \Scholaris\Modules\Socle\Documents\Domain\LightPdfProfile::PROFILES, true)) {
            throw \Scholaris\Core\Http\HttpException::validation(['profile' => ['Valeur attendue : standard ou light.']]);
        }
        $document = $this->find($request);
        $pdf = $this->documents->pdf($this->documents->content($this->tenant(), $this->establishment(), (string) $document['id']), $profile);

        return $this->binary($pdf, 'application/pdf', (string) $document['number'] . '.pdf');
    }

    public function duplicate(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::ISSUE);
        $document = $this->find($request);
        $html = $this->documents->duplicate($this->tenant(), $this->establishment(), (string) $document['id'], $principal->userId);

        return $this->binary($this->documents->pdf($html), 'application/pdf', 'duplicata-' . (string) $document['number'] . '.pdf');
    }

    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::REVOKE);
        $v = new Validator(Validator::body($request), ['reason', 'replaced_by']);
        $reason = $v->string('reason', 500, true);
        $replacedBy = $v->pattern('replaced_by', '/^[A-Za-z0-9_-]{1,40}$/', 'Identifiant invalide.', false, 40);
        $v->done();

        $document = $this->find($request);
        $this->documents->revoke($this->tenant(), (string) $document['id'], (string) $reason, $principal->userId, $replacedBy);

        return $this->responder->json(['data' => Resources::document($this->find($request), $this->clock->verificationBase())]);
    }

    /** @return array<string, mixed> */
    private function find(ServerRequestInterface $request): array
    {
        return $this->repository->find($this->tenant(), $this->id($request), $this->establishment())
            ?? throw HttpException::notFound('Document introuvable.');
    }

    /** @param array<string, string> $filters */
    private function assertView(array $filters): void
    {
        if (isset($filters['view']) && !in_array($filters['view'], self::VIEWS, true)) {
            throw HttpException::validation(['filter[view]' => ['Valeurs possibles : ' . implode(', ', self::VIEWS) . '.']], 'Paramètres de liste invalides.');
        }
    }

    /** @return list<array{0: string, 1: 'asc'|'desc'}> */
    private function sorts(ListQuery $query): array
    {
        return $query->sorts !== [] ? $query->sorts : [['issued_at', 'desc']];
    }
}
