<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Services\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Services\Contracts\StudentServices;
use Scholaris\Modules\Apps\Secondary\Services\Domain\ServicesDefaults;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeLine;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Offres et abonnements de l'internat [boarding], de la cantine et du transport [canteen_transport] (T972, EX-SEC-63, 64) :
 *  - une offre = une chambre ou un dortoir (internat), une formule (cantine) ou une ligne avec ses arrêts (transport), un montant en XAF
 *    et, pour facturer, le code d'un frais du catalogue Billing (natures canteen, transport, boarding) ;
 *  - un abonnement rattache un élève inscrit (inscription en cours dans l'établissement) à une offre active, dans la limite de la
 *    capacité, un seul abonnement en cours par nature de service ; il se termine à une date avec un motif, rien n'est supprimé ;
 *  - la facture, si elle est demandée, est une facture libre du socle (InvoiceIssuer::forCharges, référence `sec-svc:<abonnement>`,
 *    idempotente) : paiements, reçus, relances et blocages restent ceux du socle.
 */
final class ServiceSubscriptions implements StudentServices
{
    public const OFFERS = 'se_service_offers';
    public const SUBSCRIPTIONS = 'se_service_subscriptions';
    public const ACTIVE = 'active';
    public const ENDED = 'ended';

    public function __construct(
        private readonly Store $store,
        private readonly SecondaryClock $clock,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly InvoiceIssuer $invoices,
    ) {
    }

    /**
     * @param array<string, mixed> $data service_kind, label, stops?, capacity?, fee_code?, amount_xaf
     * @return array<string, mixed>
     */
    public function createOffer(string $tenantId, string $establishmentId, array $data, string $actorId): array
    {
        $stops = $this->stops((string) $data['service_kind'], $data['stops'] ?? null);
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(self::OFFERS, $tenantId, $establishmentId, [
            'id' => $id, 'service_kind' => (string) $data['service_kind'], 'label' => (string) $data['label'],
            'stops' => $stops === null ? null : json_encode($stops, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'capacity' => isset($data['capacity']) ? (int) $data['capacity'] : null, 'fee_code' => isset($data['fee_code']) ? (string) $data['fee_code'] : null,
            'amount_xaf' => (int) $data['amount_xaf'], 'active' => 1, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->offer($tenantId, $establishmentId, $id);
    }

    /**
     * @param list<string> $kinds natures permises par le module de la route
     * @param array<string, mixed> $data champs présents seulement (label, stops, capacity, fee_code, amount_xaf, active)
     * @return array<string, mixed>
     */
    public function updateOffer(string $tenantId, string $establishmentId, string $id, array $kinds, array $data): array
    {
        $row = $this->offerRow($tenantId, $establishmentId, $id, $kinds);
        $values = ['updated_at' => $this->clock->now()];
        foreach (['label', 'fee_code'] as $field) {
            if (array_key_exists($field, $data)) {
                $values[$field] = $data[$field] === null ? null : (string) $data[$field];
            }
        }
        foreach (['capacity', 'amount_xaf'] as $field) {
            if (array_key_exists($field, $data)) {
                $values[$field] = $data[$field] === null ? null : (int) $data[$field];
            }
        }
        if (array_key_exists('active', $data)) {
            $values['active'] = $data['active'] === true ? 1 : 0;
        }
        if (array_key_exists('stops', $data)) {
            $stops = $this->stops((string) $row['service_kind'], $data['stops']);
            $values['stops'] = $stops === null ? null : json_encode($stops, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        if (isset($values['capacity']) && $values['capacity'] < $this->activeCount($tenantId, $establishmentId, $id)) {
            throw HttpException::conflict('La capacité ne peut pas descendre sous le nombre d\'abonnés en cours.');
        }
        $this->store->update(self::OFFERS, $tenantId, $establishmentId, $values, ['id' => $id]);

        return $this->offer($tenantId, $establishmentId, $id);
    }

    /**
     * @param list<string> $kinds
     * @param array<string, mixed> $data offer_id, person_id, starts_on, ends_on?, stop_label?, invoice?
     * @return array<string, mixed>
     */
    public function subscribe(string $tenantId, string $establishmentId, array $kinds, array $data, string $actorId): array
    {
        $offer = $this->offerRow($tenantId, $establishmentId, (string) $data['offer_id'], $kinds);
        if ((int) $offer['active'] !== 1) {
            throw HttpException::conflict('Cette offre est fermée : aucun nouvel abonnement.');
        }
        $personId = (string) $data['person_id'];
        $enrollment = $this->liveEnrollment($tenantId, $establishmentId, $personId)
            ?? throw HttpException::validation(['person_id' => ['Élève sans inscription en cours dans cet établissement.']]);
        $startsOn = (string) $data['starts_on'];
        $endsOn = isset($data['ends_on']) ? (string) $data['ends_on'] : null;
        if ($endsOn !== null && $endsOn < $startsOn) {
            throw HttpException::validation(['ends_on' => ['La fin ne peut pas précéder le début.']]);
        }
        $stop = isset($data['stop_label']) ? (string) $data['stop_label'] : null;
        $stops = self::decodeList($offer['stops'] ?? null);
        if ($stop !== null && !in_array($stop, $stops, true)) {
            throw HttpException::validation(['stop_label' => ['Arrêt inconnu sur cette ligne : ' . implode(', ', $stops) . '.']]);
        }
        $kind = (string) $offer['service_kind'];
        if ($this->store->count(self::SUBSCRIPTIONS, $tenantId, $establishmentId, ['person_id' => $personId, 'service_kind' => $kind, 'status' => self::ACTIVE]) > 0) {
            throw HttpException::conflict('Cet élève a déjà un abonnement en cours pour ce service : terminez-le d\'abord.');
        }
        if ($offer['capacity'] !== null && $this->activeCount($tenantId, $establishmentId, (string) $offer['id']) >= (int) $offer['capacity']) {
            throw HttpException::conflict('Capacité de l\'offre atteinte.');
        }
        $invoice = ($data['invoice'] ?? false) === true;
        if ($invoice && ($offer['fee_code'] === null || (string) $offer['fee_code'] === '')) {
            throw HttpException::validation(['invoice' => ['Renseignez d\'abord le code du frais Billing de cette offre.']]);
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $offer, $kind, $personId, $enrollment, $stop, $startsOn, $endsOn, $invoice, $actorId, $now): void {
            $this->store->insert(self::SUBSCRIPTIONS, $tenantId, $establishmentId, [
                'id' => $id, 'offer_id' => (string) $offer['id'], 'service_kind' => $kind, 'person_id' => $personId, 'enrollment_id' => $enrollment->id,
                'class_id' => $enrollment->structureId, 'academic_year_id' => $enrollment->academicYearId, 'stop_label' => $stop, 'starts_on' => $startsOn,
                'ends_on' => $endsOn, 'status' => self::ACTIVE, 'invoice_id' => null, 'end_reason' => null, 'created_by' => $actorId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($invoice) {
                $summary = $this->invoices->forCharges(new ChargeInvoice(
                    $tenantId,
                    $establishmentId,
                    $actorId,
                    $personId,
                    $enrollment->academicYearId,
                    'sec-svc:' . $id,
                    [new ChargeLine((string) $offer['fee_code'], $startsOn, (int) $offer['amount_xaf'], (string) $offer['label'])],
                    null,
                    $enrollment->structureId,
                ));
                $this->store->update(self::SUBSCRIPTIONS, $tenantId, $establishmentId, ['invoice_id' => $summary->id], ['id' => $id]);
            }
        });

        return $this->subscription($tenantId, $establishmentId, $id);
    }

    /**
     * @param list<string> $kinds
     * @return array<string, mixed>
     */
    public function end(string $tenantId, string $establishmentId, string $id, array $kinds, string $endsOn, string $reason): array
    {
        $row = $this->store->findOrFail(self::SUBSCRIPTIONS, $tenantId, $establishmentId, $id, 'Abonnement introuvable.');
        if (!in_array((string) $row['service_kind'], $kinds, true)) {
            throw HttpException::notFound('Abonnement introuvable.');
        }
        if ($row['status'] !== self::ACTIVE) {
            throw HttpException::conflict('Cet abonnement est déjà terminé.');
        }
        if ($endsOn < (string) $row['starts_on']) {
            throw HttpException::validation(['ends_on' => ['La fin ne peut pas précéder le début.']]);
        }
        $this->store->update(self::SUBSCRIPTIONS, $tenantId, $establishmentId, ['status' => self::ENDED, 'ends_on' => $endsOn, 'end_reason' => $reason, 'updated_at' => $this->clock->now()], ['id' => $id]);

        return $this->subscription($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function offer(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->offerView($this->store->findOrFail(self::OFFERS, $tenantId, $establishmentId, $id, 'Offre introuvable.'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function offerView(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'service_kind' => (string) $row['service_kind'], 'label' => (string) $row['label'],
            'stops' => self::decodeList($row['stops'] ?? null), 'capacity' => $row['capacity'] === null ? null : (int) $row['capacity'],
            'subscribers' => $this->activeCount((string) $row['tenant_id'], (string) $row['establishment_id'], (string) $row['id']),
            'fee_code' => $row['fee_code'], 'amount_xaf' => (int) $row['amount_xaf'], 'active' => (int) $row['active'] === 1,
            'created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @return array<string, mixed> */
    public function subscription(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->subscriptionView($this->store->findOrFail(self::SUBSCRIPTIONS, $tenantId, $establishmentId, $id, 'Abonnement introuvable.'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function subscriptionView(array $row): array
    {
        $tenantId = (string) $row['tenant_id'];
        $person = $this->people->find($tenantId, (string) $row['person_id']);
        $offer = $this->store->find(self::OFFERS, $tenantId, (string) $row['establishment_id'], (string) $row['offer_id']);

        return [
            'id' => (string) $row['id'], 'service_kind' => (string) $row['service_kind'], 'offer_id' => (string) $row['offer_id'],
            'offer_label' => $offer === null ? null : (string) $offer['label'], 'person_id' => (string) $row['person_id'],
            'student_name' => $person === null ? null : $person->fullName(), 'class_id' => $row['class_id'], 'academic_year_id' => (string) $row['academic_year_id'],
            'stop_label' => $row['stop_label'], 'starts_on' => (string) $row['starts_on'], 'ends_on' => $row['ends_on'], 'status' => (string) $row['status'],
            'invoice_id' => $row['invoice_id'], 'end_reason' => $row['end_reason'], 'created_at' => (string) $row['created_at'],
        ];
    }

    /** Abonnements en cours d'un élève (portails) : nature, offre, arrêt, dates ; jamais de montant (le solde est celui du socle). */
    public function currentFor(string $tenantId, string $establishmentId, string $personId): array
    {
        return array_map(
            fn (array $row): array => array_intersect_key($this->subscriptionView($row), array_flip(['id', 'service_kind', 'offer_label', 'stop_label', 'starts_on', 'ends_on'])),
            $this->store->select(self::SUBSCRIPTIONS, $tenantId, $establishmentId, ['person_id' => $personId, 'status' => self::ACTIVE], 'starts_on'),
        );
    }

    /**
     * @param list<string> $kinds
     * @return array<string, mixed>
     */
    private function offerRow(string $tenantId, string $establishmentId, string $id, array $kinds): array
    {
        $row = $this->store->findOrFail(self::OFFERS, $tenantId, $establishmentId, $id, 'Offre introuvable.');
        if (!in_array((string) $row['service_kind'], $kinds, true)) {
            throw HttpException::notFound('Offre introuvable.');
        }

        return $row;
    }

    private function activeCount(string $tenantId, string $establishmentId, string $offerId): int
    {
        return $this->store->count(self::SUBSCRIPTIONS, $tenantId, $establishmentId, ['offer_id' => $offerId, 'status' => self::ACTIVE]);
    }

    private function liveEnrollment(string $tenantId, string $establishmentId, string $personId): ?EnrollmentInfo
    {
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->tenantId === $tenantId && $enrollment->establishmentId === $establishmentId
                && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                return $enrollment;
            }
        }

        return null;
    }

    /** @return list<string>|null arrêts d'une ligne de transport ; null pour les autres natures */
    private function stops(string $kind, mixed $value): ?array
    {
        if ($kind !== ServicesDefaults::TRANSPORT) {
            if ($value !== null && $value !== []) {
                throw HttpException::validation(['stops' => ['Les arrêts ne concernent que le transport.']]);
            }

            return null;
        }
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > ServicesDefaults::MAX_STOPS) {
            throw HttpException::validation(['stops' => [sprintf('Liste de %d arrêts au plus attendue.', ServicesDefaults::MAX_STOPS)]]);
        }
        $clean = [];
        foreach ($value as $stop) {
            if (!is_string($stop) || trim($stop) === '' || mb_strlen(trim($stop)) > 120) {
                throw HttpException::validation(['stops' => ['Chaque arrêt : texte de 1 à 120 caractères.']]);
            }
            $clean[] = trim($stop);
        }

        return array_values(array_unique($clean));
    }

    /** @return list<string> */
    private static function decodeList(mixed $raw): array
    {
        $list = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
