<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Fees\Application\FeeCatalog;
use Scholaris\Modules\Apps\Secondary\Fees\Domain\FeesDefaults;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Catalogue des frais autorisés et demandes d'ajout [fees_public_catalog] (RG-FIN-2, EX-FIN-03). Lecture : droits des frais et
 * bureau de l'APEE ; demande d'ajout : intendant (`secondary.fees.catalog` ou `secondary.billing.manage`) ; tenue du catalogue et
 * décision : administrateur fonctionnel du client (`secondary.profile.manage`), jamais l'auteur de la demande.
 */
final class FeeCatalogController extends Controller
{
    public const READ = [...FeesController::READ, 'secondary.apee.read', 'secondary.profile.manage'];
    public const REQUEST = ['secondary.fees.catalog', 'secondary.billing.manage'];
    public const MANAGE = ['secondary.profile.manage'];
    private const MAX_AMOUNT = 100000000;
    private const KIND = '/^[a-z][a-z0-9_]{1,19}$/';

    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly FeeCatalog $catalog)
    {
        parent::__construct($responder, $access, $store);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, self::READ);

        return $this->page(['data' => $this->catalog->entries($principal->tenantId, $establishmentId), 'meta' => ['next_cursor' => null, 'per_page' => 200, 'confirmation' => FeesDefaults::CONFIRMATION]]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'secondary_fee_catalog');
        $input = Input::of($request);
        $values = [
            'fee_kind' => $this->kind($input),
            'fee_code' => $input->string('fee_code', 40, false, true),
            'label' => $input->string('label', 160),
            'max_amount' => $input->int('max_amount', 0, self::MAX_AMOUNT, false, true),
            'cycle_code' => $input->enum('cycle_code', ['first', 'second'], false),
            'legal_basis' => $input->string('legal_basis', 255),
            'valid_from' => $input->date('valid_from', false),
            'valid_to' => $input->date('valid_to', false, true),
            'client_wide' => $input->bool('client_wide', false),
        ];
        $input->done();
        if (is_string($values['valid_to']) && is_string($values['valid_from']) && $values['valid_to'] < $values['valid_from']) {
            $input->fail('valid_to', 'La fin de validité ne peut précéder le début.');
            $input->done();
        }
        $entry = $this->catalog->add($principal->tenantId, $establishmentId, $values, $principal->userId);
        $this->access->audit($principal, 'secondary.fee_catalog.added', 'secondary_fee_catalog', (string) $entry['id'], null, $entry);

        return $this->json($entry, 201);
    }

    public function retire(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'secondary_fee_catalog');
        $input = Input::of($request);
        $validTo = $input->date('valid_to');
        $reason = $input->string('reason', 255);
        $input->done();
        $id = self::id($request);
        $entry = $this->catalog->retire($principal->tenantId, $establishmentId, $id, (string) $validTo);
        $this->access->audit($principal, 'secondary.fee_catalog.retired', 'secondary_fee_catalog', $id, null, ['valid_to' => $validTo, 'reason' => $reason]);

        return $this->json($entry);
    }

    public function requests(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, self::READ);
        $status = $request->getQueryParams()['status'] ?? null;
        $status = in_array($status, [FeeCatalog::PENDING, FeeCatalog::ACCEPTED, FeeCatalog::REFUSED], true) ? $status : null;

        return $this->page(['data' => $this->catalog->requests($principal->tenantId, $establishmentId, $status), 'meta' => ['next_cursor' => null, 'per_page' => 200]]);
    }

    public function storeRequest(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, self::REQUEST);
        $input = Input::of($request);
        $kind = $this->kind($input);
        $code = $input->string('fee_code', 40, false, true);
        $label = $input->string('label', 160);
        $amount = $input->int('amount', 0, self::MAX_AMOUNT);
        $justification = $input->string('justification', 500);
        $input->done();
        [$fee, $created] = $this->catalog->request($principal->tenantId, $establishmentId, (string) $kind, $code, (string) $label, (int) $amount, $justification, 'manual', $principal->userId);
        if ($created) {
            $this->access->audit($principal, 'secondary.fee_request.created', 'secondary_fee_request', (string) $fee['id'], null, ['fee_kind' => $kind, 'label' => $label, 'amount' => $amount]);
        }

        return $this->json($fee, $created ? 201 : 200);
    }

    public function decide(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'secondary_fee_request');
        $input = Input::of($request);
        $decision = $input->enum('decision', [FeeCatalog::ACCEPTED, FeeCatalog::REFUSED]);
        $reason = $input->string('reason', 255);
        $max = $input->int('max_amount', 0, self::MAX_AMOUNT, false, true);
        $basis = $input->string('legal_basis', 255, false, true);
        $input->done();
        if ($decision === FeeCatalog::ACCEPTED && $basis === null) {
            $input->fail('legal_basis', 'Texte ou procès-verbal de référence obligatoire pour accepter un frais.');
            $input->done();
        }
        $id = self::id($request);
        $fee = $this->catalog->decide($principal->tenantId, $establishmentId, $id, $decision === FeeCatalog::ACCEPTED, (string) $reason, $max, $basis, $principal->userId);
        $this->access->audit($principal, 'secondary.fee_request.' . $decision, 'secondary_fee_request', $id, null, ['reason' => $reason, 'catalog_id' => $fee['catalog_id']]);

        return $this->json($fee);
    }

    private function kind(Input $input): ?string
    {
        return $input->custom('fee_kind', static function (mixed $value): string {
            if (!is_string($value) || preg_match(self::KIND, $value) !== 1) {
                throw new \InvalidArgumentException('Nature de frais : 2 à 20 caractères (a-z, chiffres, « _ »).');
            }

            return $value;
        });
    }
}
