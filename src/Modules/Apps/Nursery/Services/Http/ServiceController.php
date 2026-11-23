<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Services\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Services\Application\ServiceAttendance;
use Scholaris\Modules\Apps\Nursery\Services\Application\ServiceBilling;
use Scholaris\Modules\Apps\Nursery\Services\Application\ServiceCatalog;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;

/** Services garderie, cantine et transport : offres, abonnements, facturation par cycle, présence du jour et vue du parent. */
final class ServiceController extends Controller
{
    private const MANAGE = ['nursery.service.manage'];
    private const ATTEND = ['nursery.service.attend', 'nursery.service.manage'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        private readonly ServiceCatalog $catalog,
        private readonly ServiceBilling $billing,
        private readonly ServiceAttendance $attendance,
        private readonly NurseryClock $clock,
        private readonly ChildDirectory $children,
    ) {
        parent::__construct($responder, $access);
    }

    public function plans(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, self::ATTEND);
        $kind = self::query($request, 'kind');
        if ($kind !== null && !in_array($kind, ServiceCatalog::KINDS, true)) {
            throw HttpException::validation(['kind' => ['Valeurs possibles : ' . implode(', ', ServiceCatalog::KINDS) . '.']]);
        }

        return $this->page(Paging::fromRequest($request)->slice($this->catalog->plans($principal->tenantId, $establishmentId, $kind)));
    }

    public function createPlan(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'service_plan');
        $input = Input::of($request);
        $input->enum('kind', ServiceCatalog::KINDS);
        $input->string('label', 160);
        $input->reference('fee_item_id');
        $input->enum('billing_cycle', ServiceCatalog::CYCLES);

        return $this->json($this->catalog->createPlan($principal, $establishmentId, $input->done()), 201);
    }

    public function updatePlan(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'service_plan');
        $input = Input::of($request, true);
        $input->string('label', 160, false);
        $input->reference('fee_item_id', false);
        $input->bool('active', false);

        return $this->json($this->catalog->updatePlan($principal, $establishmentId, self::id($request), $input->done()));
    }

    public function subscriptions(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::ATTEND);
        $status = self::query($request, 'status');

        return $this->page($this->catalog->page($perimeter, Paging::fromRequest($request), self::query($request, 'plan_id'), self::query($request, 'learner_id'), $status));
    }

    public function subscribe(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $input = Input::of($request);
        $input->reference('learner_id');
        $input->reference('plan_id');
        $input->date('starts_on', false);

        return $this->json($this->catalog->subscribe($principal, $perimeter, $input->done()), 201);
    }

    public function end(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $input = Input::of($request);
        $input->date('ends_on', false);

        return $this->json($this->catalog->end($principal, $perimeter, self::id($request), (string) ($input->done()['ends_on'] ?? $this->clock->today())));
    }

    public function invoice(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $input = Input::of($request);
        $input->date('on', false);

        return $this->json($this->billing->invoice($principal, $perimeter, self::id($request), (string) ($input->done()['on'] ?? $this->clock->today())), 201);
    }

    public function invoiceAll(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $input = Input::of($request);
        $input->reference('plan_id', false);
        $input->date('on', false);
        $data = $input->done();

        return $this->json($this->billing->invoiceAll($principal, $perimeter, isset($data['plan_id']) ? (string) $data['plan_id'] : null, (string) ($data['on'] ?? $this->clock->today())));
    }

    public function invoices(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $subscription = $this->catalog->subscription($principal->tenantId, $establishmentId, self::id($request));
        $this->children->assertInPerimeter($perimeter, (string) $subscription['learner_id']);

        return $this->page(Paging::fromRequest($request)->slice($this->billing->forSubscription($principal->tenantId, $establishmentId, self::id($request))));
    }

    public function roster(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::ATTEND);
        $planId = self::query($request, 'plan_id') ?? throw HttpException::validation(['plan_id' => ['Offre de service requise.']]);
        $day = $this->day($request);

        return $this->json($this->attendance->roster($perimeter, $planId, $day));
    }

    public function record(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::ATTEND);
        $input = Input::of($request);
        $input->reference('plan_id');
        $input->date('date', false);
        $input->custom('marks', static function (mixed $value): array {
            if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 300) {
                throw new \InvalidArgumentException('Liste de 1 à 300 présences attendue.');
            }
            $out = [];
            foreach ($value as $mark) {
                if (!is_array($mark) || !is_string($mark['learner_id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $mark['learner_id']) !== 1 || !is_bool($mark['present'] ?? null)) {
                    throw new \InvalidArgumentException('Chaque présence porte learner_id et present (booléen).');
                }
                $out[] = ['learner_id' => $mark['learner_id'], 'present' => $mark['present']];
            }

            return $out;
        });
        $data = $input->done();
        /** @var list<array{learner_id: string, present: bool}> $marks */
        $marks = $data['marks'];

        return $this->json($this->attendance->record($principal, $perimeter, (string) $data['plan_id'], (string) ($data['date'] ?? $this->clock->today()), $marks));
    }

    public function portal(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $learnerId = self::query($request, 'learner_id') ?? throw HttpException::validation(['learner_id' => ['Identifiant de l’enfant requis.']]);
        $this->access->ownChild($principal, $establishmentId, $learnerId);

        return $this->page(Paging::fromRequest($request)->slice($this->catalog->forLearner($principal->tenantId, $establishmentId, $learnerId)));
    }

    private function day(ServerRequestInterface $request): string
    {
        $value = $request->getQueryParams()['date'] ?? null;
        if ($value === null) {
            return $this->clock->today();
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw HttpException::validation(['date' => ['Date au format AAAA-MM-JJ attendue.']]);
        }

        return $value;
    }

    private static function query(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $value) === 1 ? $value : null;
    }
}
