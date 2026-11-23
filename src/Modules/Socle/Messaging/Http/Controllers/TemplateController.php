<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Controllers;

use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Messaging\Application\MessagingClock;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateRenderer;
use Scholaris\Modules\Socle\Messaging\Http\Access;
use Scholaris\Modules\Socle\Messaging\Http\Requests\TemplateRequest;
use Scholaris\Modules\Socle\Messaging\Http\Resources\TemplateResource;
use Scholaris\Modules\Socle\Messaging\Infrastructure\Store;
use Scholaris\Modules\Socle\Messaging\Infrastructure\TemplateRepository;

/** Modèles bilingues : ceux de l'établissement (modifiables) et ceux de la plateforme (lecture seule). */
final class TemplateController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly Access $access,
        private readonly TemplateRepository $templates,
        private readonly Store $store,
        private readonly MessagingClock $clock,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeRead($request);
        $query = ListQuery::fromRequest($request, ['template_key', 'locale', 'channel']);
        $rows = $this->templates->visible(
            $this->access->tenantId(),
            $this->access->establishmentId(),
            $query->filters,
            $query->perPage + 1,
            ListQuery::decodeCursor($query->cursor),
        );
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) $rows[count($rows) - 1]['id']);
        }

        return Pages::respond($this->responder, $request, new TemplateResource(), $rows, $next);
    }

    /** Export CSV / Excel des modèles visibles (mêmes filtres et mêmes droits que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeRead($request);
        $rows = Exports::collect($request, ['template_key', 'locale', 'channel'], function (ListQuery $query): array {
            $rows = $this->templates->visible(
                $this->access->tenantId(),
                $this->access->establishmentId(),
                $query->filters,
                $query->perPage + 1,
                ListQuery::decodeCursor($query->cursor),
            );
            $next = null;
            if (count($rows) > $query->perPage) {
                $rows = array_slice($rows, 0, $query->perPage);
                $next = ListQuery::encodeCursor((string) $rows[count($rows) - 1]['id']);
            }

            return [$rows, $next];
        });

        return Exports::respond($request, 'modeles-messages.csv', new TemplateResource(), [
            'id' => 'Identifiant', 'template_key' => 'Clé', 'locale' => 'Langue', 'channel' => 'Canal', 'subject' => 'Objet',
            'body' => 'Corps', 'variables' => 'Variables', 'created_at' => 'Créé le', 'updated_at' => 'Modifié le',
        ], $rows);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeRead($request);

        return $this->responder->json(['data' => (new TemplateResource())->toArray($this->visible($request))]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeTemplates($request);
        $data = (new TemplateRequest())->fromHttp($request);
        $tenantId = $this->access->tenantId();
        $establishmentId = $this->access->establishmentId();
        if ($this->templates->exists($tenantId, $establishmentId, (string) $data['template_key'], (string) $data['locale'], (string) $data['channel'])) {
            throw HttpException::conflict('Un modèle existe déjà pour cette clé, cette langue et ce canal dans cet établissement.');
        }

        $id = (string) new Ulid();
        $now = $this->clock->iso();
        try {
            $this->store->insert('message_templates', [
                'id' => $id,
                'tenant_id' => $tenantId,
                'establishment_id' => $establishmentId,
                'template_key' => $data['template_key'],
                'locale' => $data['locale'],
                'channel' => $data['channel'],
                'subject' => $data['subject'] ?? null,
                'body' => $data['body'],
                'variables' => $this->variablesOf($data),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException) {
            throw HttpException::conflict('Un modèle identique existe déjà dans cet établissement.');
        }

        $row = $this->visible($request, $id);
        $this->access->audit($request, 'message_template.created', 'message_template', $id, null, (new TemplateResource())->toArray($row));

        return $this->responder->json(['data' => (new TemplateResource())->toArray($row)], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeTemplates($request);
        $existing = $this->owned($request);
        $data = (new TemplateRequest(true))->fromHttp($request, true);
        $merged = $data + $existing;

        $this->store->update('message_templates', $this->access->tenantId(), $this->access->establishmentId(), (string) $existing['id'], [
            'subject' => $merged['subject'],
            'body' => $merged['body'],
            'variables' => $this->variablesOf($merged),
            'updated_at' => $this->clock->iso(),
        ]);

        $row = $this->visible($request, (string) $existing['id']);
        $this->access->audit($request, 'message_template.updated', 'message_template', (string) $existing['id'], (new TemplateResource())->toArray($existing), (new TemplateResource())->toArray($row));

        return $this->responder->json(['data' => (new TemplateResource())->toArray($row)]);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeTemplates($request);
        $existing = $this->owned($request);
        $this->store->delete('message_templates', $this->access->tenantId(), $this->access->establishmentId(), (string) $existing['id']);
        $this->access->audit($request, 'message_template.deleted', 'message_template', (string) $existing['id'], (new TemplateResource())->toArray($existing), null);

        return $this->responder->json(['data' => null], 200);
    }

    /** @return array<string, mixed> */
    private function visible(ServerRequestInterface $request, ?string $id = null): array
    {
        return $this->templates->findVisible($this->access->tenantId(), $this->access->establishmentId(), $id ?? (string) $request->getAttribute('id'))
            ?? throw HttpException::notFound('Modèle introuvable.');
    }

    /** @return array<string, mixed> modèle de l'établissement actif (jamais celui de la plateforme) */
    private function owned(ServerRequestInterface $request): array
    {
        $row = $this->visible($request);
        if ($row['tenant_id'] === TemplateRepository::PLATFORM) {
            throw HttpException::forbidden('Les modèles de la plateforme ne se modifient pas : créez un modèle de votre établissement avec la même clé.');
        }

        return $row;
    }

    /** @param array<string, mixed> $data */
    private function variablesOf(array $data): string
    {
        $used = TemplateRenderer::placeholders((string) $data['body'] . ' ' . (string) ($data['subject'] ?? ''));

        return json_encode($used, JSON_THROW_ON_ERROR);
    }
}
