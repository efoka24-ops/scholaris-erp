<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Billing\Application\AccountService;
use Scholaris\Modules\Apps\Higher\Billing\Application\BillingAccess;
use Scholaris\Modules\Apps\Higher\Billing\Application\FeeGridService;
use Scholaris\Modules\Apps\Higher\Billing\Application\FinancialHoldPolicy;
use Scholaris\Modules\Apps\Higher\Billing\Application\ScholarshipService;
use Scholaris\Modules\Apps\Higher\Billing\Domain\HigherFees;
use Scholaris\Modules\Apps\Higher\Billing\Domain\ScholarshipRules;

/** Routes /higher/billing : grilles, comptes de scolarité, paiements, bourses, échéanciers négociés, tiers payeurs, blocages. */
final class BillingController
{
    private const YEAR = '/^[A-Za-z0-9_-]{1,40}$/';

    public function __construct(
        private readonly FeeGridService $grids,
        private readonly AccountService $accounts,
        private readonly ScholarshipService $scholarships,
        private readonly FinancialHoldPolicy $holds,
        private readonly BillingAccess $access,
        private readonly JsonResponder $responder,
    ) {
    }

    public function feeKinds(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->perimeter(Input::principal($request), Input::establishment($request), BillingAccess::READ, BillingAccess::MANAGE, BillingAccess::CASH);

        return $this->responder->json(['data' => HigherFees::describe()]);
    }

    public function grids(ServerRequestInterface $request): ResponseInterface
    {
        $filters = ['academic_year_id' => Input::query($request, 'academic_year_id'), 'school_id' => Input::query($request, 'school_id'), 'status' => Input::query($request, 'status')];

        return $this->responder->json(['data' => $this->grids->index(Input::principal($request), Input::establishment($request), $filters)]);
    }

    public function createGrid(ServerRequestInterface $request): ResponseInterface
    {
        $in = new Input($request, ['academic_year_id', 'school_id', 'program_id', 'level_id', 'study_mode', 'label', 'fees', 'terms', 'start_year'], true);
        $data = [
            'academic_year_id' => (string) $in->pattern('academic_year_id', self::YEAR, 'Identifiant d\'année attendu.', true),
            'school_id' => (string) $in->id('school_id', true),
            'program_id' => $in->id('program_id'),
            'level_id' => $in->id('level_id'),
            'study_mode' => $in->enum('study_mode', FeeGridService::MODES),
            'label' => (string) $in->text('label', 160, true),
            'fees' => $in->raw('fees'),
            'terms' => $in->raw('terms'),
            'start_year' => $in->int('start_year', 2000, 2100),
        ];
        $in->done();

        return $this->responder->json(['data' => $this->grids->create(Input::principal($request), Input::establishment($request), $data)], 201);
    }

    public function showGrid(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->grids->show(Input::principal($request), Input::establishment($request), Input::route($request, 'id'))]);
    }

    public function archiveGrid(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->grids->archive(Input::principal($request), Input::establishment($request), Input::route($request, 'id'))]);
    }

    public function accounts(ServerRequestInterface $request): ResponseInterface
    {
        $filters = ['academic_year_id' => Input::query($request, 'academic_year_id'), 'school_id' => Input::query($request, 'school_id'), 'person_id' => Input::query($request, 'person_id')];

        return $this->responder->json(['data' => $this->accounts->index(Input::principal($request), Input::establishment($request), $filters)]);
    }

    public function openAccount(ServerRequestInterface $request): ResponseInterface
    {
        $in = new Input($request, ['person_id', 'academic_year_id', 'school_id', 'program_id', 'level_id', 'study_mode', 'enrolment_ref', 'plan_id', 'sponsor_id'], true);
        $data = [
            'person_id' => (string) $in->id('person_id', true),
            'academic_year_id' => (string) $in->pattern('academic_year_id', self::YEAR, 'Identifiant d\'année attendu.', true),
            'school_id' => (string) $in->id('school_id', true),
            'program_id' => $in->id('program_id'),
            'level_id' => $in->id('level_id'),
            'study_mode' => $in->enum('study_mode', FeeGridService::MODES),
            'enrolment_ref' => $in->id('enrolment_ref'),
            'plan_id' => $in->id('plan_id'),
            'sponsor_id' => $in->id('sponsor_id'),
        ];
        $in->done();

        return $this->responder->json(['data' => $this->accounts->open(Input::principal($request), Input::establishment($request), $data)], 201);
    }

    public function showAccount(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->accounts->show(Input::principal($request), Input::establishment($request), Input::route($request, 'id'))]);
    }

    public function pay(ServerRequestInterface $request): ResponseInterface
    {
        $in = new Input($request, ['amount_xaf', 'channel', 'payer_phone', 'external_reference'], true);
        $key = trim($request->getHeaderLine('Idempotency-Key'));
        if ($key === '') {
            throw HttpException::badRequest('L\'en-tête Idempotency-Key est obligatoire.');
        }
        $data = [
            'amount_xaf' => (int) $in->int('amount_xaf', 1, 100000000, true),
            'channel' => (string) $in->enum('channel', AccountService::CHANNELS, true),
            'idempotency_key' => $key,
            'payer_phone' => $in->pattern('payer_phone', '/^\+?[0-9]{8,15}$/', 'Numéro de téléphone attendu.'),
            'external_reference' => $in->pattern('external_reference', '/^[A-Za-z0-9_\-:.]{4,100}$/', 'Référence de l\'opérateur invalide.'),
        ];
        $in->done();

        return $this->responder->json(['data' => $this->accounts->pay(Input::principal($request), Input::establishment($request), Input::route($request, 'id'), $data)], 201);
    }

    public function scholarships(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->scholarships->forAccount(Input::principal($request), Input::establishment($request), Input::route($request, 'id'))]);
    }

    public function requestScholarship(ServerRequestInterface $request): ResponseInterface
    {
        $in = new Input($request, ['scholarship_kind', 'calc_mode', 'rate_value', 'applies_to', 'reason'], true);
        $data = [
            'scholarship_kind' => (string) $in->enum('scholarship_kind', ScholarshipRules::KINDS, true),
            'calc_mode' => $in->enum('calc_mode', ['percent', 'fixed']),
            'rate_value' => $in->int('rate_value', 0, 100000000),
            'applies_to' => $in->enum('applies_to', ['tuition', 'registration', 'other', 'all', ...HigherFees::SOCLE_KINDS]),
            'reason' => (string) $in->text('reason', 400, true),
        ];
        $in->done();

        return $this->responder->json(['data' => $this->scholarships->request(Input::principal($request), Input::establishment($request), Input::route($request, 'id'), $data)], 201);
    }

    public function approveScholarship(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->scholarships->decide(Input::principal($request), Input::establishment($request), Input::route($request, 'id'), true)]);
    }

    public function rejectScholarship(ServerRequestInterface $request): ResponseInterface
    {
        $in = new Input($request, ['note'], true);
        $note = $in->text('note', 400);
        $in->done();

        return $this->responder->json(['data' => $this->scholarships->decide(Input::principal($request), Input::establishment($request), Input::route($request, 'id'), false, $note)]);
    }

    public function requestPlan(ServerRequestInterface $request): ResponseInterface
    {
        $in = new Input($request, ['person_id', 'academic_year_id', 'school_id', 'program_id', 'terms', 'reason'], true);
        $data = [
            'person_id' => (string) $in->id('person_id', true),
            'academic_year_id' => (string) $in->pattern('academic_year_id', self::YEAR, 'Identifiant d\'année attendu.', true),
            'school_id' => (string) $in->id('school_id', true),
            'program_id' => $in->id('program_id'),
            'terms' => $in->raw('terms'),
            'reason' => (string) $in->text('reason', 500, true),
        ];
        $in->done();

        return $this->responder->json(['data' => $this->scholarships->requestPlan(Input::principal($request), Input::establishment($request), $data)], 201);
    }

    public function approvePlan(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->scholarships->decidePlan(Input::principal($request), Input::establishment($request), Input::route($request, 'id'), true)]);
    }

    public function rejectPlan(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->scholarships->decidePlan(Input::principal($request), Input::establishment($request), Input::route($request, 'id'), false)]);
    }

    public function sponsors(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->scholarships->sponsors(Input::principal($request), Input::establishment($request))]);
    }

    public function createSponsor(ServerRequestInterface $request): ResponseInterface
    {
        $in = new Input($request, ['sponsor_kind', 'name', 'reference', 'contact'], true);
        $data = [
            'sponsor_kind' => (string) $in->enum('sponsor_kind', ['person', 'organisation'], true),
            'name' => (string) $in->text('name', 160, true),
            'reference' => $in->text('reference', 60),
            'contact' => $in->text('contact', 160),
        ];
        $in->done();

        return $this->responder->json(['data' => $this->scholarships->createSponsor(Input::principal($request), Input::establishment($request), $data)], 201);
    }

    public function sponsorStatement(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->scholarships->sponsorStatement(Input::principal($request), Input::establishment($request), Input::route($request, 'id'))]);
    }

    public function holds(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $this->access->perimeter($principal, $establishmentId, BillingAccess::READ, BillingAccess::MANAGE);
        $personId = Input::route($request, 'personId');

        return $this->responder->json(['data' => [
            'person_id' => $personId,
            'balance_xaf' => $this->holds->balanceXaf($principal->tenantId, $establishmentId, $personId),
            'blocks' => $this->holds->summary($principal->tenantId, $establishmentId, $personId),
        ]]);
    }
}
