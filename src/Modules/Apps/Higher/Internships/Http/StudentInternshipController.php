<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Internships\Application\ConventionService;
use Scholaris\Modules\Apps\Higher\Internships\Application\DefenseService;
use Scholaris\Modules\Apps\Higher\Internships\Application\FollowUpService;
use Scholaris\Modules\Apps\Higher\Internships\Application\InternshipAccess;
use Scholaris\Modules\Apps\Higher\Internships\Application\InternshipResources;
use Scholaris\Modules\Apps\Higher\Internships\Domain\ConventionStatus;
use Scholaris\Modules\Apps\Higher\Internships\Domain\DefenseReadiness;
use Scholaris\Modules\Apps\Higher\Internships\Infrastructure\InternshipStore;

/**
 * « Mon stage » (portail étudiant) : la convention en cours de la personne liée au compte, son suivi, l'état des conditions de
 * soutenance et la soutenance programmée ; dépôt du mémoire par l'étudiant lui-même. Jamais le stage d'un autre.
 */
final class StudentInternshipController
{
    public const PERMISSION = ['self.read'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly InternshipAccess $access,
        private readonly InternshipStore $store,
        private readonly ConventionService $conventions,
        private readonly DefenseService $defenses,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = InternshipAccess::principal($request);
        $establishmentId = InternshipAccess::establishmentId($request);
        $internship = $this->current($principal, $establishmentId);
        $id = (string) $internship['id'];
        $followups = $this->store->select(FollowUpService::TABLE, $principal->tenantId, $establishmentId, ['internship_id' => $id], 'occurred_on, created_at');
        $defense = null;
        foreach ($this->store->select(DefenseService::TABLE, $principal->tenantId, $establishmentId, ['internship_id' => $id], 'created_at DESC', 5) as $row) {
            if ($row['status'] !== DefenseService::CANCELLED) {
                $defense = InternshipResources::defense($row, $this->defenses->jury($principal->tenantId, $establishmentId, (string) $row['id']));
                if ($row['status'] !== DefenseService::CLOSED) {
                    $defense['grade'] = null; // la note n'est communiquée qu'avec le PV
                }
                break;
            }
        }
        $checks = $this->conventions->readiness($principal->tenantId, $internship);

        return $this->responder->json(['data' => [
            'internship' => InternshipResources::internship($internship),
            'followups' => array_map(static fn (array $f): array => ['kind' => $f['kind'], 'occurred_on' => $f['occurred_on'], 'summary' => $f['kind'] === 'evaluation' ? null : $f['summary']], $followups),
            'readiness' => ['conditions' => $checks, 'missing' => DefenseReadiness::missing($checks)],
            'defense' => $defense,
        ]]);
    }

    public function thesis(ServerRequestInterface $request): ResponseInterface
    {
        $principal = InternshipAccess::principal($request);
        $establishmentId = InternshipAccess::establishmentId($request);
        $internship = $this->current($principal, $establishmentId);
        $body = Body::of($request);
        $title = (string) $body->string('title', 255);
        $reference = (string) $body->string('file_ref', 255);
        $body->done();

        return $this->responder->json(['data' => InternshipResources::internship($this->conventions->depositThesis($principal, $establishmentId, $internship, $title, $reference))]);
    }

    /** @return array<string, mixed> convention non annulée la plus récente de la personne liée au compte */
    private function current(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, self::PERMISSION);
        $me = $this->access->personOf($principal) ?? throw HttpException::forbidden('Aucune personne liée à ce compte.');
        foreach ($this->store->select(ConventionService::TABLE, $principal->tenantId, $establishmentId, ['person_id' => $me], 'created_at DESC', 20) as $row) {
            if ($row['status'] !== ConventionStatus::CANCELLED) {
                return $row;
            }
        }

        throw HttpException::notFound('Aucun stage enregistré.');
    }
}
