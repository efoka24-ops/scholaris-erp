<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Platform\Application\ClientLifecycle;
use Scholaris\Modules\Socle\Platform\Application\RegularisationService;
use Scholaris\Modules\Socle\Platform\Domain\Regularisation;

/**
 * Régularisation de paiement côté super admin : liste « À régulariser » (et son export), blocage, déblocage, promesse de
 * paiement et niveau de blocage d'un client. Les actions exigent une 2FA récente et un motif.
 */
final class RegularisationAdminController
{
    use ExportsList;

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly RegularisationService $regularisation,
        private readonly ClientLifecycle $clients,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    /** GET /platform/regularisation?filter[bucket]=past_due|regularisation|promise */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.payments.read');
        $items = $this->regularisation->overview($this->bucket($request));
        $count = static fn (string $bucket): int => count(array_filter($items, static fn (array $i): bool => $i['bucket'] === $bucket));

        return $this->responder->json(['data' => $items, 'meta' => [
            'total' => count($items),
            'amount_due_xaf' => array_sum(array_column($items, 'amount_due_xaf')),
            'counts' => [
                RegularisationService::BUCKET_PAST_DUE => $count(RegularisationService::BUCKET_PAST_DUE),
                RegularisationService::BUCKET_REGULARISATION => $count(RegularisationService::BUCKET_REGULARISATION),
                RegularisationService::BUCKET_PROMISE => $count(RegularisationService::BUCKET_PROMISE),
            ],
        ]]);
    }

    /** GET /platform/regularisation/export : CSV de la liste (mêmes filtres). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.payments.read');
        $rows = array_map(static fn (array $i): array => [
            $i['tenant_name'], $i['tenant_id'], $i['label'], $i['bucket'], $i['level'] ?? '', $i['due_on'], $i['days_late'], $i['months'], $i['amount_due_xaf'], $i['promise_until'] ?? '',
        ], $this->regularisation->overview($this->bucket($request)));

        return $this->csvResponse($this->responses, $this->streams, 'a-regulariser.csv', [
            'client', 'identifiant_client', 'application', 'situation', 'niveau', 'echeance', 'jours_de_retard', 'mois', 'montant_du_xaf', 'promesse_jusqu_au',
        ], $rows, $request);
    }

    /** POST /platform/clients/{tenant}/regularisation/lock { reason, level?, app_key? } */
    public function lock(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.applications.manage', true);
        $tenantId = $this->tenantId($request);
        $input = Input::of($request, ['reason', 'level', 'app_key']);
        $reason = $input->longText('reason', 500, true);
        $level = $input->choice('level', Regularisation::LEVELS, false);
        $appKey = $input->text('app_key', 2, 20, false, '/^[a-z][a-z0-9_]+$/');
        $input->validate();

        return $this->responder->json(['data' => $this->regularisation->lock($tenantId, $appKey, $level, (string) $reason, $principal->userId, PlatformGuard::ip($request))]);
    }

    /** POST /platform/clients/{tenant}/regularisation/unlock { reason, until?, app_key? } */
    public function unlock(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.applications.manage', true);
        $tenantId = $this->tenantId($request);
        $input = Input::of($request, ['reason', 'until', 'app_key']);
        $reason = $input->longText('reason', 500, true);
        $until = $input->date('until', false);
        $appKey = $input->text('app_key', 2, 20, false, '/^[a-z][a-z0-9_]+$/');
        $input->validate();

        return $this->responder->json(['data' => $this->regularisation->unlock($tenantId, $appKey, $until, (string) $reason, $principal->userId, PlatformGuard::ip($request))]);
    }

    /** POST /platform/clients/{tenant}/regularisation/delay { until, reason, app_key? } : promesse de paiement. */
    public function delay(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.applications.manage', true);
        $tenantId = $this->tenantId($request);
        $input = Input::of($request, ['until', 'reason', 'app_key']);
        $until = $input->date('until');
        $reason = $input->longText('reason', 500, true);
        $appKey = $input->text('app_key', 2, 20, false, '/^[a-z][a-z0-9_]+$/');
        $input->validate();

        return $this->responder->json(['data' => $this->regularisation->grantDelay($tenantId, $appKey, (string) $until, (string) $reason, $principal->userId, PlatformGuard::ip($request))]);
    }

    /** POST /platform/clients/{tenant}/regularisation/level { level: read_only|locked|null, reason } : niveau choisi pour tout le client. */
    public function level(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.pricing.manage', true);
        $tenantId = $this->tenantId($request);
        $input = Input::of($request, ['level', 'reason']);
        if (!$input->has('level')) {
            $input->fail('level', 'Champ obligatoire (null pour revenir au réglage de la plateforme).');
        }
        $level = $input->raw('level') === null ? null : $input->choice('level', Regularisation::LEVELS);
        $reason = $input->longText('reason', 500, true);
        $input->validate();

        return $this->responder->json(['data' => $this->regularisation->setClientLevel($tenantId, $level, (string) $reason, $principal->userId, PlatformGuard::ip($request))]);
    }

    private function bucket(ServerRequestInterface $request): ?string
    {
        $filters = $request->getQueryParams()['filter'] ?? [];
        $bucket = is_array($filters) ? ($filters['bucket'] ?? null) : null;
        if ($bucket === null || $bucket === '') {
            return null;
        }
        if (!is_string($bucket) || !in_array($bucket, [RegularisationService::BUCKET_PAST_DUE, RegularisationService::BUCKET_REGULARISATION, RegularisationService::BUCKET_PROMISE], true)) {
            throw HttpException::validation(['bucket' => ['Valeur attendue : past_due, regularisation ou promise.']]);
        }

        return $bucket;
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        return $this->clients->tenant(PlatformGuard::param($request, 'tenant'))['id'];
    }
}
