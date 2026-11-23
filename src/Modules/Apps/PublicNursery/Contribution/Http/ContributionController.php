<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Contribution\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicNursery\Capacity\Application\CapacityService;
use Scholaris\Modules\Apps\PublicNursery\Contribution\Application\ContributionService;
use Scholaris\Modules\Apps\PublicNursery\Support\Paging;
use Scholaris\Modules\Apps\PublicNursery\Support\PublicNurseryClock;
use Scholaris\Modules\Apps\PublicNursery\Support\RequestContext;

/**
 * Contribution exigible (encaissement contre reçu, annulation motivée, souches, bilan et répartition par quotas) et effectifs des
 * classes contre le plafond paramétré. Encaissement et liste nominative : direction de l'école (permission dédiée
 * `public_nursery.contribution.manage`, ou `public_schools.school.write` du directeur d'école public, ou `settings.manage`).
 */
final class ContributionController
{
    public const MANAGE = ['public_nursery.contribution.manage', 'public_schools.school.write', 'settings.manage'];
    public const READ = ['public_nursery.contribution.manage', 'public_nursery.contribution.read', 'public_schools.school.write', 'public_schools.school.read', 'settings.manage'];
    public const CAPACITY = ['public_schools.school.read', 'public_schools.school.write', 'public_schools.pupils.read', 'public_nursery.dev.observe', 'settings.manage'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly RequestContext $context,
        private readonly ContributionService $contributions,
        private readonly CapacityService $capacity,
        private readonly PublicNurseryClock $clock,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context->require($request, self::MANAGE);
        $query = $request->getQueryParams();
        $status = isset($query['status']) && in_array($query['status'], ['valid', 'cancelled'], true) ? (string) $query['status'] : null;
        $learner = isset($query['learner_id']) && is_string($query['learner_id']) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $query['learner_id']) === 1 ? $query['learner_id'] : null;

        return $this->responder->json(self::page($request, $this->contributions->stubs($principal->tenantId, $establishmentId, $status, $learner)));
    }

    public function summary(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context->require($request, self::READ);

        return $this->responder->json(['data' => $this->contributions->summary($principal->tenantId, $establishmentId)]);
    }

    public function unpaid(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context->require($request, self::MANAGE);

        return $this->responder->json(self::page($request, $this->contributions->unpaid($principal->tenantId, $establishmentId)));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context->require($request, self::MANAGE);
        $body = RequestContext::body($request);
        RequestContext::onlyKnown($body, ['learner_id', 'payer_name', 'paid_on', 'locale']);
        $errors = [];
        $learner = $body['learner_id'] ?? null;
        if (!is_string($learner) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $learner) !== 1) {
            $errors['learner_id'][] = 'Identifiant de l’enfant requis.';
        }
        $payer = is_string($body['payer_name'] ?? null) ? trim($body['payer_name']) : '';
        if ($payer === '' || mb_strlen($payer) > 160) {
            $errors['payer_name'][] = 'Nom de la personne qui verse (160 caractères au plus) requis.';
        }
        $paidOn = array_key_exists('paid_on', $body) ? RequestContext::date($body['paid_on']) : $this->clock->today();
        if ($paidOn === null) {
            $errors['paid_on'][] = 'Date au format AAAA-MM-JJ attendue.';
        }
        $locale = $body['locale'] ?? 'fr';
        if (!in_array($locale, ['fr', 'en'], true)) {
            $errors['locale'][] = 'Langue : fr ou en.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $this->responder->json(['data' => $this->contributions->collect($principal, $establishmentId, [
            'learner_id' => (string) $learner, 'payer_name' => $payer, 'paid_on' => (string) $paidOn, 'locale' => (string) $locale,
        ], $this->clock->now())], 201);
    }

    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context->require($request, self::MANAGE);
        $body = RequestContext::body($request);
        RequestContext::onlyKnown($body, ['reason']);
        $reason = is_string($body['reason'] ?? null) ? trim($body['reason']) : '';
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw HttpException::validation(['reason' => ['Motif d’annulation requis (255 caractères au plus).']]);
        }
        $id = $request->getAttribute('id');

        return $this->responder->json(['data' => $this->contributions->cancel($principal, $establishmentId, is_string($id) ? $id : '', $reason, $this->clock->now())]);
    }

    /**
     * @param list<array<string, mixed>> $all
     * @return array<string, mixed>
     */
    private static function page(ServerRequestInterface $request, array $all): array
    {
        $paging = Paging::fromRequest($request);

        return $paging->envelope(array_slice($all, $paging->offset, $paging->limit()));
    }

    public function capacity(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId, $scope] = $this->context->require($request, self::CAPACITY);

        return $this->responder->json(['data' => $this->capacity->report($principal->tenantId, $establishmentId, $scope)]);
    }
}
