<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Documents\Application\DocumentService;
use Scholaris\Modules\Socle\Documents\Application\RequestService;
use Scholaris\Modules\Socle\Documents\Http\Policies\DocumentPolicy;
use Scholaris\Modules\Socle\Documents\Http\Requests\Validator;
use Scholaris\Modules\Socle\Documents\Http\Resources\Resources;
use Scholaris\Modules\Socle\Documents\Infrastructure\RequestRepository;

/** Demandes en ligne de documents : l'apprenant ou le parent demande, le secrétariat traite ou refuse. */
final class RequestController extends BaseController
{
    private const EXPORT_LIMIT = 5000;

    public function __construct(HttpKit $kit, private readonly RequestRepository $requests, private readonly RequestService $service)
    {
        parent::__construct($kit);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::READ, DocumentPolicy::ISSUE);
        $query = ListQuery::fromRequest($request, ['status']);
        $status = $query->filters['status'] ?? null;

        return $this->page(
            $request,
            $query,
            fn (int $limit, int $offset): array => $this->requests->all($this->tenant(), $this->establishment(), $status, $limit, $offset),
            Resources::request(...),
        );
    }

    /** Export des demandes de documents (mêmes filtres et droits que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::READ, DocumentPolicy::ISSUE);
        $query = ListQuery::fromRequest($request, ['status']);
        $rows = $this->requests->all($this->tenant(), $this->establishment(), $query->filters['status'] ?? null, self::EXPORT_LIMIT, 0);

        return $this->exportFile(
            $request,
            'demandes-documents.csv',
            ['Identifiant', 'Type', 'Titulaire', 'Demandé par', 'Note', 'Statut', 'Document', 'Décidé par', 'Décidé le', 'Créée le'],
            array_map(static function (array $row): array {
                $r = Resources::request($row);

                return [$r['id'], $r['type_code'], $r['subject_person_id'], $r['requested_by'], $r['note'], $r['status'], $r['document_id'], $r['decided_by'], $r['decided_at'], $r['created_at']];
            }, $rows),
        );
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::REQUEST, DocumentPolicy::ISSUE);
        $v = new Validator(Validator::body($request), ['type', 'subject_person_id', 'note']);
        $type = $v->pattern('type', '/^[a-z][a-z0-9_]{1,29}$/', 'Code de type invalide.', true, 30);
        $subject = $v->pattern('subject_person_id', '/^[A-Za-z0-9_-]{1,40}$/', 'Identifiant invalide.', true, 40);
        $note = $v->string('note', 500);
        $v->done();

        $row = $this->service->create($this->tenant(), $this->establishment(), (string) $type, (string) $subject, $principal->userId, $note);

        return $this->responder->json(['data' => Resources::request($row)], 201);
    }

    public function fulfill(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::ISSUE);
        $v = new Validator(Validator::body($request, true), ['data', 'locale']);
        $data = $v->values('data');
        $locale = $v->enum('locale', DocumentService::LOCALES);
        $v->done();

        $row = $this->service->fulfill($this->tenant(), $this->establishment(), $this->id($request), $data, $principal->userId, $locale);

        return $this->responder->json(['data' => Resources::request($row)]);
    }

    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::ISSUE);

        return $this->responder->json(['data' => Resources::request(
            $this->service->reject($this->tenant(), $this->establishment(), $this->id($request), $principal->userId),
        )]);
    }
}
