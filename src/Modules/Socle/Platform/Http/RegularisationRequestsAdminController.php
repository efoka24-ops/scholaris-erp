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
use Scholaris\Modules\Socle\Platform\Application\PlatformSettings;
use Scholaris\Modules\Socle\Platform\Application\RegularisationRequests;

/**
 * Vérification des paiements déclarés par les établissements (super admin) : liste filtrée, export CSV, confirmation (montant
 * réellement reçu, référence constatée) et rejet motivé. Confirmer et rejeter exigent une double authentification récente.
 */
final class RegularisationRequestsAdminController
{
    use ExportsList;

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly RegularisationRequests $requests,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    /** GET /platform/regularisation/declarations?filter[state]=&filter[channel]=&filter[tenant_id]= */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.payments.read');
        $overview = $this->requests->overview(...$this->filters($request));

        return $this->responder->json(['data' => $overview['items'], 'meta' => ['total' => count($overview['items']), 'counts' => $overview['counts']]]);
    }

    /** GET /platform/regularisation/declarations/export (mêmes filtres). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.payments.read');
        $rows = array_map(static fn (array $r): array => [
            $r['tenant_name'], $r['tenant_id'], $r['label'], $r['state'], $r['kind'], $r['channel'], $r['payer_phone_masked'], $r['reference'],
            $r['declared_amount_xaf'], $r['balance_xaf'], $r['confirmed_amount_xaf'] ?? '', $r['declared_at'], $r['expires_on'], $r['verified_via'] ?? '', $r['decision_reason'] ?? '',
        ], $this->requests->overview(...$this->filters($request))['items']);

        return $this->csvResponse($this->responses, $this->streams, 'paiements-declares.csv', [
            'client', 'identifiant_client', 'application', 'etat', 'type', 'moyen', 'numero_payeur', 'reference',
            'montant_declare_xaf', 'solde_xaf', 'montant_recu_xaf', 'declare_le', 'expire_le', 'verifie_par', 'motif',
        ], $rows, $request);
    }

    /** POST /platform/regularisation/declarations/{id}/confirm { received_amount_xaf, reference?, note?, promise_until? } */
    public function confirm(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.payments.manage', true);
        $input = Input::of($request, ['received_amount_xaf', 'reference', 'note', 'promise_until']);
        $received = $input->integer('received_amount_xaf', 1, 100000000);
        $reference = $input->text('reference', 3, 120, false);
        $note = $input->longText('note', 500);
        $promise = $input->date('promise_until', false);
        $input->validate();

        return $this->responder->json(['data' => $this->requests->confirm(
            PlatformGuard::param($request, 'id'),
            $received,
            $reference,
            $note,
            $promise,
            RegularisationRequests::VIA_MANUAL,
            $principal->userId,
            PlatformGuard::ip($request),
        )]);
    }

    /** POST /platform/regularisation/declarations/{id}/reject { reason } */
    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.payments.manage', true);
        $input = Input::of($request, ['reason']);
        $reason = $input->longText('reason', 500, true);
        $input->validate();

        return $this->responder->json(['data' => $this->requests->reject(PlatformGuard::param($request, 'id'), (string) $reason, $principal->userId, PlatformGuard::ip($request))]);
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} état, moyen, client */
    private function filters(ServerRequestInterface $request): array
    {
        $filters = $request->getQueryParams()['filter'] ?? [];
        $filters = is_array($filters) ? $filters : [];
        $pick = static function (string $name, ?array $allowed) use ($filters): ?string {
            $value = $filters[$name] ?? null;
            if ($value === null || $value === '') {
                return null;
            }
            if (!is_string($value) || ($allowed !== null && !in_array($value, $allowed, true)) || ($allowed === null && preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $value) !== 1)) {
                throw HttpException::validation(['filter[' . $name . ']' => ['Valeur invalide.']]);
            }

            return $value;
        };

        return [$pick('state', RegularisationRequests::STATES), $pick('channel', PlatformSettings::PAYMENT_CHANNELS), $pick('tenant_id', null)];
    }
}
