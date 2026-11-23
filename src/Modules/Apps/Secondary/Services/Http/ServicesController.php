<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Services\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Services\Application\ServiceSubscriptions;
use Scholaris\Modules\Apps\Secondary\Services\Domain\ServicesDefaults;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Listing;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Offres et abonnements de la cantine et du transport [canteen_transport] et de l'internat [boarding] (T972). Une route par module
 * (garde de module de SecondaryRoutes) : le module d'une route borne les natures de service qu'elle voit ; une offre d'une autre
 * nature est introuvable (404). Lecture : responsable des services, intendant, chef d'établissement, promoteur ; écriture :
 * responsable des services (`secondary.services.manage`), offres aussi par le chef d'établissement.
 */
final class ServicesController extends Controller
{
    public const READ = ['secondary.services.manage', 'secondary.billing.manage', 'secondary.settings.manage', 'secondary.finance.read'];
    public const WRITE = ['secondary.services.manage'];
    public const OFFERS_WRITE = ['secondary.services.manage', 'secondary.settings.manage'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly ServiceSubscriptions $services,
        private readonly Listing $listing,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function canteenOffers(ServerRequestInterface $request): ResponseInterface
    {
        return $this->offers($request, Modules::CANTEEN_TRANSPORT);
    }

    public function canteenCreateOffer(ServerRequestInterface $request): ResponseInterface
    {
        return $this->createOffer($request, Modules::CANTEEN_TRANSPORT);
    }

    public function canteenUpdateOffer(ServerRequestInterface $request): ResponseInterface
    {
        return $this->updateOffer($request, Modules::CANTEEN_TRANSPORT);
    }

    public function canteenSubscriptions(ServerRequestInterface $request): ResponseInterface
    {
        return $this->subscriptions($request, Modules::CANTEEN_TRANSPORT);
    }

    public function canteenSubscribe(ServerRequestInterface $request): ResponseInterface
    {
        return $this->subscribe($request, Modules::CANTEEN_TRANSPORT);
    }

    public function canteenEnd(ServerRequestInterface $request): ResponseInterface
    {
        return $this->end($request, Modules::CANTEEN_TRANSPORT);
    }

    public function boardingOffers(ServerRequestInterface $request): ResponseInterface
    {
        return $this->offers($request, Modules::BOARDING);
    }

    public function boardingCreateOffer(ServerRequestInterface $request): ResponseInterface
    {
        return $this->createOffer($request, Modules::BOARDING);
    }

    public function boardingUpdateOffer(ServerRequestInterface $request): ResponseInterface
    {
        return $this->updateOffer($request, Modules::BOARDING);
    }

    public function boardingSubscriptions(ServerRequestInterface $request): ResponseInterface
    {
        return $this->subscriptions($request, Modules::BOARDING);
    }

    public function boardingSubscribe(ServerRequestInterface $request): ResponseInterface
    {
        return $this->subscribe($request, Modules::BOARDING);
    }

    public function boardingEnd(ServerRequestInterface $request): ResponseInterface
    {
        return $this->end($request, Modules::BOARDING);
    }

    private function offers(ServerRequestInterface $request, string $module): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, self::READ);
        $kinds = ServicesDefaults::KINDS_BY_MODULE[$module];

        return $this->page($this->listing->page(
            $request,
            ServiceSubscriptions::OFFERS,
            $principal->tenantId,
            $establishmentId,
            ['service_kind', 'active'],
            ['label', 'created_at'],
            'label',
            fn (array $row): array => $this->services->offerView($row),
            [],
            self::kindScope($kinds),
        ));
    }

    private function createOffer(ServerRequestInterface $request, string $module): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::OFFERS_WRITE);
        $this->access->assertWhole($principal, $perimeter, 'service_offer');
        $input = Input::of($request);
        $input->enum('service_kind', ServicesDefaults::KINDS_BY_MODULE[$module]);
        $input->string('label');
        $input->custom('stops', static fn (mixed $v): mixed => $v, false);
        $input->int('capacity', 1, ServicesDefaults::MAX_CAPACITY, false, true);
        $input->string('fee_code', 40, false, true);
        $input->int('amount_xaf', 0, ServicesDefaults::MAX_AMOUNT_XAF);
        $data = $input->done();
        $offer = $this->services->createOffer($principal->tenantId, $establishmentId, $data, $principal->userId);
        $this->access->audit($principal, 'secondary.service.offer.created', 'service_offer', (string) $offer['id'], null, ['service_kind' => $offer['service_kind'], 'amount_xaf' => $offer['amount_xaf']]);

        return $this->json($offer, 201);
    }

    private function updateOffer(ServerRequestInterface $request, string $module): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::OFFERS_WRITE);
        $this->access->assertWhole($principal, $perimeter, 'service_offer');
        $input = Input::of($request, true);
        $input->string('label');
        $input->custom('stops', static fn (mixed $v): mixed => $v);
        $input->int('capacity', 1, ServicesDefaults::MAX_CAPACITY, true, true);
        $input->string('fee_code', 40, true, true);
        $input->int('amount_xaf', 0, ServicesDefaults::MAX_AMOUNT_XAF);
        $input->bool('active');
        $data = $input->done();
        $id = self::id($request);
        $offer = $this->services->updateOffer($principal->tenantId, $establishmentId, $id, ServicesDefaults::KINDS_BY_MODULE[$module], $data);
        $this->access->audit($principal, 'secondary.service.offer.updated', 'service_offer', $id, null, $data);

        return $this->json($offer);
    }

    private function subscriptions(ServerRequestInterface $request, string $module): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, self::READ);

        return $this->page($this->listing->page(
            $request,
            ServiceSubscriptions::SUBSCRIPTIONS,
            $principal->tenantId,
            $establishmentId,
            ['service_kind', 'status', 'person_id', 'offer_id', 'class_id'],
            ['starts_on', 'created_at'],
            'created_at DESC',
            fn (array $row): array => $this->services->subscriptionView($row),
            [],
            self::kindScope(ServicesDefaults::KINDS_BY_MODULE[$module]),
        ));
    }

    private function subscribe(ServerRequestInterface $request, string $module): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $this->access->assertWhole($principal, $perimeter, 'service_subscription');
        $input = Input::of($request);
        $input->reference('offer_id');
        $input->reference('person_id');
        $input->date('starts_on');
        $input->date('ends_on', false, true);
        $input->string('stop_label', 120, false, true);
        $input->bool('invoice', false);
        $data = $input->done();
        $subscription = $this->services->subscribe($principal->tenantId, $establishmentId, ServicesDefaults::KINDS_BY_MODULE[$module], $data, $principal->userId);
        $this->access->audit($principal, 'secondary.service.subscribed', 'service_subscription', (string) $subscription['id'], null, ['person_id' => $subscription['person_id'], 'offer_id' => $subscription['offer_id'], 'invoice_id' => $subscription['invoice_id']]);

        return $this->json($subscription, 201);
    }

    private function end(ServerRequestInterface $request, string $module): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $this->access->assertWhole($principal, $perimeter, 'service_subscription');
        $input = Input::of($request);
        $endsOn = $input->date('ends_on');
        $reason = $input->string('reason', 255);
        $input->done();
        $id = self::id($request);
        $subscription = $this->services->end($principal->tenantId, $establishmentId, $id, ServicesDefaults::KINDS_BY_MODULE[$module], (string) $endsOn, (string) $reason);
        $this->access->audit($principal, 'secondary.service.ended', 'service_subscription', $id, null, ['ends_on' => $endsOn, 'reason' => $reason]);

        return $this->json($subscription);
    }

    /**
     * @param list<string> $kinds
     * @return array{0: string, 1: list<string>}
     */
    private static function kindScope(array $kinds): array
    {
        return ['service_kind IN (' . implode(', ', array_fill(0, count($kinds), '?')) . ')', $kinds];
    }
}
