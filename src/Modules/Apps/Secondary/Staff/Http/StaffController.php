<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Staff\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Staff\Application\PayrollService;
use Scholaris\Modules\Apps\Secondary\Staff\Application\StaffService;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** Personnel du secondaire (profils, heures du mois) et paie simplifiée (statuts éligibles, bulletins, DOC-30). */
final class StaffController extends Controller
{
    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly StaffService $staff,
        private readonly PayrollService $payroll,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function profiles(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $school] = $this->context($request);

        return $this->json(['items' => $this->staff->profiles($principal, $school)]);
    }

    public function saveProfile(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $school] = $this->context($request);

        return $this->json($this->staff->upsert($principal, $school, Input::body($request)));
    }

    public function hours(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $school] = $this->context($request);
        $month = $request->getQueryParams()['month'] ?? null;
        if (!is_string($month) || $month === '') {
            throw HttpException::validation(['month' => ['Paramètre obligatoire (AAAA-MM).']]);
        }

        return $this->json(['items' => $this->staff->hours($principal, $school, $month)]);
    }

    public function payrollRules(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $school] = $this->context($request);

        return $this->json($this->payroll->rules($principal, $school));
    }

    public function savePayrollRules(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $school] = $this->context($request);

        return $this->json($this->payroll->saveRules($principal, $school, Input::body($request)));
    }

    public function payslips(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $school] = $this->context($request);
        $month = $request->getQueryParams()['month'] ?? null;

        return $this->json(['items' => $this->payroll->payslips($principal, $school, is_string($month) && $month !== '' ? $month : null)]);
    }

    public function createPayslip(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $school] = $this->context($request);

        return $this->json($this->payroll->create($principal, $school, Input::body($request)), 201);
    }

    public function showPayslip(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $school] = $this->context($request);

        return $this->json($this->payroll->show($principal, $school, self::id($request)));
    }

    public function validatePayslip(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $school] = $this->context($request);

        return $this->json($this->payroll->validate($principal, $school, self::id($request)));
    }

    public function payPayslip(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $school] = $this->context($request);
        $input = Input::of($request);
        $locale = $input->enum('locale', ['fr', 'en'], false);
        $input->done();

        return $this->json($this->payroll->pay($principal, $school, self::id($request), $locale));
    }
}
