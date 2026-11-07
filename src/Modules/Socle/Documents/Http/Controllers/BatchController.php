<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Documents\Application\BatchService;
use Scholaris\Modules\Socle\Documents\Application\DocumentService;
use Scholaris\Modules\Socle\Documents\Http\Policies\DocumentPolicy;
use Scholaris\Modules\Socle\Documents\Http\Requests\Validator;
use Scholaris\Modules\Socle\Documents\Http\Resources\Resources;
use Scholaris\Modules\Socle\Documents\Infrastructure\BatchRepository;

/** Génération en lot : création (avec exclusion des impayés), progression, liste des exclus, traitement d'un morceau. */
final class BatchController extends BaseController
{
    private const EXPORT_LIMIT = 5000;

    public function __construct(HttpKit $kit, private readonly BatchRepository $batches, private readonly BatchService $service)
    {
        parent::__construct($kit);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::READ, DocumentPolicy::ISSUE);
        $query = ListQuery::fromRequest($request);

        return $this->page(
            $request,
            $query,
            fn (int $limit, int $offset): array => $this->batches->all($this->tenant(), $this->establishment(), $limit, $offset),
            Resources::batch(...),
        );
    }

    /** Export des lots de génération (mêmes droits que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::READ, DocumentPolicy::ISSUE);
        $rows = $this->batches->all($this->tenant(), $this->establishment(), self::EXPORT_LIMIT, 0);

        return $this->exportFile(
            $request,
            'lots-documents.csv',
            ['Identifiant', 'Type', 'Langue', 'Statut', 'Total', 'Traités', 'Échecs', 'Exclus', 'Créé par', 'Créé le', 'Terminé le'],
            array_map(static function (array $row): array {
                $r = Resources::batch($row);

                return [$r['id'], $r['type_code'], $r['locale'], $r['status'], $r['total'], $r['processed'], $r['failed'], $r['excluded'], $r['created_by'], $r['created_at'], $r['finished_at']];
            }, $rows),
        );
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::ISSUE);
        $v = new Validator(Validator::body($request), ['type', 'locale', 'items']);
        $type = $v->pattern('type', '/^[a-z][a-z0-9_]{1,29}$/', 'Code de type invalide.', true, 30);
        $locale = $v->enum('locale', DocumentService::LOCALES) ?? 'fr';

        $items = [];
        $raw = $v->raw('items');
        if (!is_array($raw) || !array_is_list($raw)) {
            $v->fail('items', 'Une liste de titulaires est attendue.');
        } else {
            foreach ($raw as $index => $item) {
                $sub = is_array($item) ? new Validator($item, ['subject_person_id', 'data']) : null;
                if ($sub === null) {
                    $v->fail('items.' . $index, 'Objet attendu.');
                    continue;
                }
                $subject = $sub->pattern('subject_person_id', '/^[A-Za-z0-9_-]{1,40}$/', 'Identifiant invalide.', true, 40);
                $data = $sub->values('data');
                try {
                    $sub->done();
                } catch (HttpException $e) {
                    foreach ($e->errors as $field => $messages) {
                        $v->fail('items.' . $index . '.' . $field, $messages[0]);
                    }
                    continue;
                }
                $items[] = ['subject_person_id' => (string) $subject, 'data' => $data];
            }
        }
        $v->done();

        $batch = $this->service->create($this->tenant(), $this->establishment(), (string) $type, $locale, $items, $principal->userId);

        return $this->responder->json(['data' => Resources::batch($batch)], 202);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::READ, DocumentPolicy::ISSUE);

        return $this->responder->json(['data' => Resources::batch($this->find($request))]);
    }

    public function excluded(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::READ, DocumentPolicy::ISSUE);
        $batch = $this->find($request);
        $rows = $this->batches->itemsByStatus($this->tenant(), (string) $batch['id'], 'excluded', 2000);

        return $this->responder->json(['data' => array_map(Resources::batchItem(...), $rows), 'meta' => ['count' => count($rows)]]);
    }

    public function process(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::ISSUE);
        $batch = $this->find($request);

        return $this->responder->json(['data' => Resources::batch($this->service->process($this->tenant(), (string) $batch['id']))]);
    }

    /** @return array<string, mixed> */
    private function find(ServerRequestInterface $request): array
    {
        return $this->batches->find($this->tenant(), $this->id($request), $this->establishment()) ?? throw HttpException::notFound('Lot introuvable.');
    }
}
