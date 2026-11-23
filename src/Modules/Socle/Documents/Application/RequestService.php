<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Application;

use RuntimeException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Documents\Infrastructure\RequestRepository;
use Scholaris\Modules\Socle\Documents\Infrastructure\TypeRepository;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/** Demande en ligne d'un document par l'apprenant ou le parent : crée une demande en attente, traitée ensuite par le secrétariat. */
final class RequestService
{
    public function __construct(
        private readonly RequestRepository $requests,
        private readonly TypeRepository $types,
        private readonly PersonDirectory $people,
        private readonly DocumentService $documents,
        private readonly AuditLog $audit,
        private readonly DocumentClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function create(string $tenantId, string $establishmentId, string $typeCode, string $subjectPersonId, string $requestedBy, ?string $note): array
    {
        $this->types->find($tenantId, $establishmentId, $typeCode)
            ?? throw HttpException::validation(['type' => ['Type de document inconnu : ' . $typeCode . '.']]);
        $this->people->find($tenantId, $subjectPersonId)
            ?? throw HttpException::validation(['subject_person_id' => ['Personne introuvable dans ce client.']]);

        $id = (string) new Ulid();
        $this->requests->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'type_code' => $typeCode,
            'subject_person_id' => $subjectPersonId,
            'requested_by' => $requestedBy,
            'note' => $note,
            'status' => 'pending',
            'created_at' => $this->clock->now(),
        ]);
        $this->audit->append($tenantId, $requestedBy, 'document_request.created', 'document_request', $id, null, ['type' => $typeCode, 'subject_person_id' => $subjectPersonId]);

        return $this->requests->find($tenantId, $establishmentId, $id) ?? [];
    }

    /**
     * @param array<string, scalar|null> $data
     * @return array<string, mixed> demande traitée
     */
    public function fulfill(string $tenantId, string $establishmentId, string $id, array $data, string $by, ?string $locale): array
    {
        $request = $this->pending($tenantId, $establishmentId, $id);
        $person = $this->people->find($tenantId, (string) $request['subject_person_id']);
        $language = $locale ?? ($person !== null && in_array($person->locale, DocumentService::LOCALES, true) ? $person->locale : 'fr');

        $this->documents->issueVia(
            $tenantId,
            $establishmentId,
            (string) $request['type_code'],
            (string) $request['subject_person_id'],
            $data,
            $by,
            $language,
            'request',
            function (string $documentId) use ($tenantId, $id, $by): void {
                if ($this->requests->decide($tenantId, $id, 'fulfilled', $by, $this->clock->now(), $documentId) === 0) {
                    throw new RuntimeException('Demande déjà traitée.');
                }
            },
        );

        return $this->requests->find($tenantId, $establishmentId, $id) ?? [];
    }

    /** @return array<string, mixed> */
    public function reject(string $tenantId, string $establishmentId, string $id, string $by): array
    {
        $this->pending($tenantId, $establishmentId, $id);
        $this->requests->decide($tenantId, $id, 'rejected', $by, $this->clock->now(), null);
        $this->audit->append($tenantId, $by, 'document_request.rejected', 'document_request', $id, null, null);

        return $this->requests->find($tenantId, $establishmentId, $id) ?? [];
    }

    /** @return array<string, mixed> */
    private function pending(string $tenantId, string $establishmentId, string $id): array
    {
        $request = $this->requests->find($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Demande introuvable.');
        if ($request['status'] !== 'pending') {
            throw HttpException::conflict('Cette demande a déjà été traitée.');
        }

        return $request;
    }
}
