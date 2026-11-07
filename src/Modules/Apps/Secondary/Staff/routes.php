<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Staff\Http\StaffController;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/** Personnel (T911, noyau) et paie simplifiée (T912, toutes ses routes gardées par le module payroll_simple). */
return static function (SecondaryRoutes $routes): void {
    $routes->get('/staff/profiles', [StaffController::class, 'profiles']);
    $routes->put('/staff/profiles', [StaffController::class, 'saveProfile']);
    $routes->get('/staff/hours', [StaffController::class, 'hours']);

    $payroll = $routes->module(Modules::PAYROLL_SIMPLE);
    $payroll->get('/staff/payroll/rules', [StaffController::class, 'payrollRules']);
    $payroll->put('/staff/payroll/rules', [StaffController::class, 'savePayrollRules']);
    $payroll->get('/staff/payroll/payslips', [StaffController::class, 'payslips']);
    $payroll->post('/staff/payroll/payslips', [StaffController::class, 'createPayslip']);
    $payroll->get('/staff/payroll/payslips/{id}', [StaffController::class, 'showPayslip']);
    $payroll->post('/staff/payroll/payslips/{id}/validate', [StaffController::class, 'validatePayslip']);
    $payroll->post('/staff/payroll/payslips/{id}/pay', [StaffController::class, 'payPayslip']);
};
