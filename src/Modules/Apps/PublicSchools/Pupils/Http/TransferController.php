<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\TransferService;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/** Transferts entre écoles (API-03) : demande (origine), accord ou refus (accueil), exeat DOC-03 (origine), dossier utile (accueil). */
final class TransferController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PupilAccess $access,
        private readonly TransferService $transfers,
        private readonly AuditLog $audit,
    ) {
    }

    public function request(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->manage($principal, 'public_schools.pupils.write', $establishmentId);
        $body = ApiRequest::body($request);
        $personId = $body['person_id'] ?? null;
        $target = $body['to_establishment_id'] ?? null;
        if (!is_string($personId) || $personId === '' || !is_string($target) || $target === '') {
            throw HttpException::validation(['body' => ['person_id et to_establishment_id sont requis.']]);
        }
        $id = self::guard(fn (): string => $this->transfers->request($principal->tenantId, $personId, $establishmentId, $target, $principal->userId));
        $this->record($request, 'public_schools.transfer.requested', $id, ['person_id' => $personId, 'from' => $establishmentId, 'to' => $target]);

        return $this->responder->json(['data' => $this->visible($principal->tenantId, $id, $establishmentId)], 201);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $read = $this->access->read($principal, $establishmentId);
        if (!$read->wholeSchool) {
            throw HttpException::notFound();
        }
        $direction = $request->getQueryParams()['direction'] ?? null;
        if ($direction !== null && $direction !== 'incoming' && $direction !== 'outgoing') {
            throw HttpException::validation(['direction' => ['Valeur attendue : incoming ou outgoing.']]);
        }

        return $this->responder->json(['data' => $this->transfers->listFor($principal->tenantId, $establishmentId, $direction)]);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $read = $this->access->read($principal, $establishmentId);
        if (!$read->wholeSchool) {
            throw HttpException::notFound();
        }

        return $this->responder->json(['data' => $this->visible($principal->tenantId, ApiRequest::attribute($request, 'id'), $establishmentId)]);
    }

    public function accept(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $id = ApiRequest::attribute($request, 'id');
        $transfer = $this->visible($principal->tenantId, $id, $establishmentId);
        $this->access->manage($principal, 'public_schools.pupils.write', $establishmentId);
        $this->assertHost($transfer, $establishmentId);
        $body = ApiRequest::body($request);
        $arrival = $body['arrival_on'] ?? null;
        $class = $body['class_id'] ?? null;
        if (!is_string($arrival) || ($class !== null && !is_string($class))) {
            throw HttpException::validation(['arrival_on' => ['Date d\'accueil AAAA-MM-JJ requise ; class_id texte.']]);
        }
        $result = self::guard(fn (): array => $this->transfers->acceptInto($principal->tenantId, $id, $arrival, $class, $principal->userId));
        $this->record($request, 'public_schools.transfer.accepted', $id, ['arrival_on' => $arrival, 'class_id' => $class]);

        return $this->responder->json(['data' => $this->visible($principal->tenantId, $id, $establishmentId) + ['exeat_issued' => $result['exeat_document_id'] !== null]]);
    }

    public function complete(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $id = ApiRequest::attribute($request, 'id');
        $transfer = $this->visible($principal->tenantId, $id, $establishmentId);
        $this->access->manage($principal, 'public_schools.pupils.write', $establishmentId);
        $this->assertHost($transfer, $establishmentId);
        $class = ApiRequest::body($request)['class_id'] ?? null;
        if (!is_string($class) || $class === '') {
            throw HttpException::validation(['class_id' => ['Classe d\'accueil requise.']]);
        }
        self::guard(function () use ($principal, $id, $class): bool {
            $this->transfers->complete($principal->tenantId, $id, $class, $principal->userId);

            return true;
        });
        $this->record($request, 'public_schools.transfer.completed', $id, ['class_id' => $class]);

        return $this->responder->json(['data' => $this->visible($principal->tenantId, $id, $establishmentId)]);
    }

    public function refuse(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $id = ApiRequest::attribute($request, 'id');
        $transfer = $this->visible($principal->tenantId, $id, $establishmentId);
        $this->access->manage($principal, 'public_schools.pupils.write', $establishmentId);
        $this->assertHost($transfer, $establishmentId);
        $reason = ApiRequest::body($request)['reason'] ?? null;
        if (!is_string($reason)) {
            throw HttpException::validation(['reason' => ['Motif de refus requis.']]);
        }
        self::guard(function () use ($principal, $id, $reason): bool {
            $this->transfers->refuse($principal->tenantId, $id, $reason, $principal->userId);

            return true;
        });
        $this->record($request, 'public_schools.transfer.refused', $id, ['reason' => trim($reason)]);

        return $this->responder->json(['data' => $this->visible($principal->tenantId, $id, $establishmentId)]);
    }

    /** DOC-03 : délivre (ou redélivre, si le modèle manquait) le certificat de radiation depuis l'école d'origine. */
    public function exeat(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $id = ApiRequest::attribute($request, 'id');
        $transfer = $this->visible($principal->tenantId, $id, $establishmentId);
        $this->access->manage($principal, 'public_schools.pupils.write', $establishmentId);
        if ($transfer['from_establishment_id'] !== $establishmentId) {
            throw HttpException::forbidden('Le certificat de radiation est délivré par l\'école d\'origine.');
        }
        $document = self::guard(fn (): ?string => $this->transfers->issueExeat($principal->tenantId, $id, $principal->userId));
        if ($document === null) {
            throw HttpException::conflict('Le modèle du certificat de radiation n\'est pas encore disponible ; réessayez après sa mise en service.');
        }

        return $this->responder->json(['data' => ['transfer_id' => $id, 'exeat_document_id' => $document]], 201);
    }

    /** RG-ELV-5 : l'école d'accueil ne reçoit que le dossier utile (identité, parcours, dernier bulletin), après l'accord. */
    public function dossier(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $id = ApiRequest::attribute($request, 'id');
        $transfer = $this->visible($principal->tenantId, $id, $establishmentId);
        $read = $this->access->read($principal, $establishmentId);
        if (!$read->wholeSchool) {
            throw HttpException::notFound();
        }
        $this->assertHost($transfer, $establishmentId);

        return $this->responder->json(['data' => self::guard(fn (): array => $this->transfers->dossier($principal->tenantId, $id))]);
    }

    /**
     * Transfert vu de l'école : seules l'origine et l'accueil le connaissent (404 sinon).
     *
     * @return array<string, mixed>
     */
    private function visible(string $tenantId, string $id, string $establishmentId): array
    {
        $transfer = null;
        foreach ($this->transfers->listFor($tenantId, $establishmentId, null) as $row) {
            if ($row['id'] === $id) {
                $transfer = $row;
            }
        }

        return $transfer ?? throw HttpException::notFound('Transfert introuvable.');
    }

    /** @param array<string, mixed> $transfer */
    private function assertHost(array $transfer, string $establishmentId): void
    {
        if ($transfer['to_establishment_id'] !== $establishmentId) {
            throw HttpException::forbidden('Seule l\'école d\'accueil peut effectuer cette opération.');
        }
    }

    /**
     * @template T
     * @param callable(): T $action
     * @return T
     */
    private static function guard(callable $action): mixed
    {
        try {
            return $action();
        } catch (\InvalidArgumentException $refusal) {
            throw HttpException::validation(['transfer' => [$refusal->getMessage()]]);
        }
    }

    /** @param array<string, mixed> $after */
    private function record(ServerRequestInterface $request, string $action, string $id, array $after): void
    {
        $principal = ApiRequest::principal($request);
        $this->audit->append($principal->tenantId, $principal->userId, $action, 'transfer', $id, null, $after, ApiRequest::ip($request), ApiRequest::device($request));
    }
}
