<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Support;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Nursery\Policies\ScopeResolver;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Contrôle d'accès de la Maternelle privée, dans l'ordre : établissement de type `nursery` du client (sinon 404), permission
 * `nursery.*` (refus par défaut, 403), puis périmètre de sections (RG-MAT-7). Plusieurs permissions possibles pour une même
 * opération : leurs périmètres s'additionnent. Hors périmètre : 404 en lecture, 403 journalisé en écriture. Le parent n'a pas de
 * permission `nursery.*` : `guardian()` vérifie qu'il est garant de l'enfant (RG-MAT-4).
 */
final class Access
{
    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly EstablishmentDirectory $establishments,
        private readonly AuditLog $audit,
        private readonly UserPersonLink $links,
        private readonly PersonDirectory $people,
    ) {
    }

    public static function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }

        return $principal;
    }

    public static function establishmentId(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('establishment_id');
        if (!is_string($id) || $id === '') {
            throw HttpException::badRequest('En-tête X-Establishment-Id manquant.');
        }

        return $id;
    }

    /** 404 si l'établissement n'est pas une maternelle de ce client. */
    public function assertNursery(Principal $principal, string $establishmentId): void
    {
        $establishment = $this->establishments->find($principal->tenantId, $establishmentId);
        if ($establishment === null || $establishment->type !== 'nursery') {
            throw HttpException::notFound('Cette route ne concerne que les établissements de type maternelle.');
        }
    }

    /**
     * Périmètre de l’utilisateur pour ces permissions (vide si aucune), sans refuser : sert aux champs sensibles facultatifs.
     *
     * @param list<string> $permissions
     */
    public function perimeter(Principal $principal, string $establishmentId, array $permissions): Perimeter
    {
        $union = new Perimeter($principal->tenantId, $establishmentId, false);
        foreach ($permissions as $permission) {
            $union = $union->union($this->resolver->resolve($principal, $permission, $establishmentId));
        }

        return $union;
    }

    /**
     * Périmètre non vide exigé (sinon 403) : point d'entrée des lectures et des écritures du personnel.
     *
     * @param list<string> $permissions
     */
    public function require(Principal $principal, string $establishmentId, array $permissions): Perimeter
    {
        $this->assertNursery($principal, $establishmentId);
        $union = $this->perimeter($principal, $establishmentId, $permissions);
        if ($union->isEmpty()) {
            throw HttpException::forbidden('Permission requise : ' . implode(' ou ', $permissions) . '.');
        }

        return $union;
    }

    /** Lecture d'une ressource rattachée à une section : 404 hors périmètre. */
    public function assertReadable(Perimeter $perimeter, ?string $sectionId): void
    {
        if (!$perimeter->coversSection($sectionId)) {
            throw HttpException::notFound();
        }
    }

    /** Écriture sur une section : 403 journalisé hors périmètre. */
    public function assertWritable(Principal $principal, Perimeter $perimeter, ?string $sectionId, string $objectType, string $objectId): void
    {
        if ($perimeter->coversSection($sectionId)) {
            return;
        }
        $this->audit->append($principal->tenantId, $principal->userId, 'nursery.scope.denied', $objectType, $objectId, null, ['section_id' => $sectionId]);

        throw HttpException::forbidden('Cette section est hors de votre périmètre.');
    }

    /** L'opération exige tout l'établissement (paramétrage, restrictions de garde…). */
    public function assertWhole(Principal $principal, Perimeter $perimeter, string $objectType): void
    {
        if ($perimeter->wholeEstablishment) {
            return;
        }
        $this->audit->append($principal->tenantId, $principal->userId, 'nursery.scope.denied', $objectType, $perimeter->establishmentId, null, ['scope' => 'establishment']);

        throw HttpException::forbidden('Cette opération exige une affectation sur tout l’établissement.');
    }

    /**
     * Parent ou tuteur : les enfants dont la personne liée au compte est garante (tous établissements du client). Compte sans
     * personne liée : 403.
     *
     * @return list<string> identifiants des enfants
     */
    public function guardian(Principal $principal, string $establishmentId): array
    {
        $this->assertNursery($principal, $establishmentId);
        $personId = $this->links->personIdForUser($principal->tenantId, $principal->userId);
        if ($personId === null) {
            throw HttpException::forbidden('Compte non rattaché à une personne.');
        }
        $ids = [];
        foreach ($this->people->learnersOf($principal->tenantId, $personId) as $learner) {
            $ids[] = $learner->id;
        }
        if ($ids === []) {
            throw HttpException::forbidden('Aucun enfant rattaché à ce compte.');
        }

        return $ids;
    }

    /** Un enfant du portail famille : 404 s'il n'est pas un des enfants du parent. */
    public function ownChild(Principal $principal, string $establishmentId, string $learnerId): void
    {
        if (!in_array($learnerId, $this->guardian($principal, $establishmentId), true)) {
            throw HttpException::notFound('Cet enfant ne relève pas de votre portail famille.');
        }
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function audit(Principal $principal, string $action, string $objectType, string $objectId, ?array $before = null, ?array $after = null): void
    {
        $this->audit->append($principal->tenantId, $principal->userId, $action, $objectType, $objectId, $before, $after);
    }
}
