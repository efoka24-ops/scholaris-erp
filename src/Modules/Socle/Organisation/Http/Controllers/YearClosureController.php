<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Organisation\Application\YearClosureService;
use Scholaris\Modules\Socle\Organisation\Http\Policies\OrganisationPolicy;
use Scholaris\Modules\Socle\Organisation\Http\RequestContext;

/**
 * Clôture d'une année scolaire de l'établissement actif : contrôle préalable, demande, approbation (2FA récente) et refus.
 * Toutes ces routes exigent organisation.manage.
 */
final class YearClosureController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly RequestContext $context,
        private readonly OrganisationPolicy $policy,
        private readonly YearClosureService $closures,
    ) {
    }

    /** GET /academic-years/{id}/closure-check */
    public function check(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request);

        return $this->responder->json(['data' => $this->closures->check($this->context->tenantId(), $this->context->establishmentId(), $this->id($request))]);
    }

    /** POST /academic-years/{id}/close : dépose la demande de clôture (202), ou renvoie celle en cours (200). */
    public function close(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $nextYear = $this->nextYear($request);
        [$closure, $created] = $this->closures->request($this->context->tenantId(), $this->context->establishmentId(), $this->id($request), $nextYear, $principal->userId);
        if ($created) {
            $this->context->audit($request, 'academic_year.closure_requested', 'academic_year', $this->id($request), null, ['closure_id' => $closure['id'], 'next_academic_year_id' => $closure['next_academic_year_id']]);
        }

        return $this->responder->json(['data' => $closure], $created ? 202 : 200);
    }

    /** POST /academic-years/{id}/close/approve */
    public function approve(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $id = $this->id($request);
        $before = $this->closures->current($this->context->tenantId(), $this->context->establishmentId(), $id);
        $closure = $this->closures->approve($this->context->tenantId(), $this->context->establishmentId(), $id, $principal->userId);
        if (($before['status'] ?? null) !== 'closed') {
            $this->context->audit($request, 'academic_year.closed', 'academic_year', $id, $before, $closure);
        }

        return $this->responder->json(['data' => $closure]);
    }

    /** POST /academic-years/{id}/close/reject */
    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $id = $this->id($request);
        $closure = $this->closures->reject($this->context->tenantId(), $this->context->establishmentId(), $id, $principal->userId);
        $this->context->audit($request, 'academic_year.closure_rejected', 'academic_year', $id, null, $closure);

        return $this->responder->json(['data' => $closure]);
    }

    private function authorize(ServerRequestInterface $request): \Scholaris\Core\Security\Principal
    {
        $principal = $this->context->principal($request);
        $this->context->activeEstablishment();
        $this->policy->authorizeManage($principal, $this->context->establishmentId());

        return $principal;
    }

    private function id(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute('id');
    }

    private function nextYear(ServerRequestInterface $request): ?string
    {
        $raw = (string) $request->getBody();
        if (trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
        }
        $unknown = array_diff(array_keys($data), ['next_academic_year_id']);
        if ($unknown !== []) {
            throw HttpException::validation(array_fill_keys(array_map('strval', $unknown), ['Champ non autorisé.']));
        }
        $next = $data['next_academic_year_id'] ?? null;
        if ($next !== null && (!is_string($next) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $next) !== 1)) {
            throw HttpException::validation(['next_academic_year_id' => ['Identifiant invalide.']]);
        }

        return $next;
    }
}
