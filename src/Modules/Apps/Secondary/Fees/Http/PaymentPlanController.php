<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Fees\Application\FeesSettings;
use Scholaris\Modules\Apps\Secondary\Fees\Application\PaymentPlans;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Listing;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;

/**
 * Plans de paiement individuels [fees_private] : lecture par les droits des frais, proposition et annulation par l'intendant
 * (`secondary.billing.manage`), validation ou refus par `secondary.fees.validate` (une autre personne que l'auteur).
 */
final class PaymentPlanController extends Controller
{
    public const WRITE = ['secondary.billing.manage'];
    public const DECIDE = ['secondary.fees.validate'];
    private const MAX_AMOUNT = 100000000;

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly PaymentPlans $plans,
        private readonly FeesSettings $settings,
        private readonly Listing $listing,
        private readonly EnrollmentDirectory $enrollments,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, FeesController::READ);

        return $this->page($this->listing->page(
            $request,
            'se_payment_plans',
            $principal->tenantId,
            $establishmentId,
            ['status', 'person_id'],
            ['created_at'],
            'created_at DESC',
            fn (array $row): array => $this->plans->view($row),
        ));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, FeesController::READ);

        return $this->json($this->plans->show($principal->tenantId, $establishmentId, self::id($request)));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $this->access->assertWhole($principal, $perimeter, 'secondary_payment_plan');
        $max = $this->settings->planMaxInstallments($principal->tenantId, $establishmentId);
        $input = Input::of($request);
        $personId = $input->reference('person_id');
        $invoiceId = $input->reference('invoice_id', false, true);
        $reason = $input->string('reason', 255);
        $installments = $input->custom('installments', static function (mixed $value) use ($max): array {
            if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > $max) {
                throw new \InvalidArgumentException(sprintf('Liste de 1 à %d échéances attendue.', $max));
            }
            $clean = [];
            foreach ($value as $item) {
                $day = is_array($item) && is_string($item['due_on'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $item['due_on']) : false;
                $amount = is_array($item) ? ($item['amount'] ?? null) : null;
                if ($day === false || $day->format('Y-m-d') !== $item['due_on'] || !is_int($amount) || $amount < 1 || $amount > self::MAX_AMOUNT) {
                    throw new \InvalidArgumentException('Chaque échéance : {due_on: AAAA-MM-JJ, amount: entier positif en XAF}.');
                }
                $clean[] = ['due_on' => (string) $item['due_on'], 'amount' => $amount];
            }

            return $clean;
        });
        $input->done();
        if (!$this->isEnrolled($principal->tenantId, $establishmentId, (string) $personId)) {
            throw HttpException::validation(['person_id' => ['Élève non inscrit dans cet établissement.']]);
        }
        /** @var list<array{due_on: string, amount: int}> $installments */
        $plan = $this->plans->propose($principal->tenantId, $establishmentId, (string) $personId, $invoiceId, $installments, (string) $reason, $principal->userId);
        $this->access->audit($principal, 'secondary.payment_plan.proposed', 'secondary_payment_plan', (string) $plan['id'], null, ['person_id' => $personId, 'total_amount' => $plan['total_amount']]);

        return $this->json($plan, 201);
    }

    public function approve(ServerRequestInterface $request): ResponseInterface
    {
        return $this->decide($request, true);
    }

    public function refuse(ServerRequestInterface $request): ResponseInterface
    {
        return $this->decide($request, false);
    }

    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, [...self::WRITE, ...self::DECIDE]);
        $this->access->assertWhole($principal, $perimeter, 'secondary_payment_plan');
        $input = Input::of($request);
        $reason = $input->string('reason', 255);
        $input->done();
        $id = self::id($request);
        $plan = $this->plans->cancel($principal->tenantId, $establishmentId, $id, (string) $reason, $principal->userId);
        $this->access->audit($principal, 'secondary.payment_plan.cancelled', 'secondary_payment_plan', $id, null, ['reason' => $reason]);

        return $this->json($plan);
    }

    private function decide(ServerRequestInterface $request, bool $approve): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::DECIDE);
        $this->access->assertWhole($principal, $perimeter, 'secondary_payment_plan');
        $input = Input::of($request);
        $reason = $input->string('reason', 255, $approve === false);
        $input->done();
        $id = self::id($request);
        $plan = $this->plans->decide($principal->tenantId, $establishmentId, $id, $approve, (string) ($reason ?? ''), $principal->userId);
        $this->access->audit($principal, $approve ? 'secondary.payment_plan.approved' : 'secondary.payment_plan.refused', 'secondary_payment_plan', $id, null, ['reason' => $reason]);

        return $this->json($plan);
    }

    private function isEnrolled(string $tenantId, string $establishmentId, string $personId): bool
    {
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                return true;
            }
        }

        return false;
    }
}
