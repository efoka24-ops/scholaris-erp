<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Service\PayrollCalculator;
use Scholaris\Support\Validator;

/**
 * Module 42 : paie.
 *
 * Une periode de paie se cloture, et une fois close plus aucun bulletin n'y
 * bouge. La raison est la meme qu'en comptabilite : un bulletin de salaire
 * est un document remis a une personne et opposable, pas une ligne de
 * tableur. Corriger apres cloture suppose une periode de regularisation.
 *
 * Les cotisations sont figees dans le bulletin au moment du calcul, pas
 * recalculees a l'affichage : un changement de taux CNPS ne doit pas
 * reecrire les bulletins des annees precedentes.
 */
final class PayrollController extends Controller
{
    public function index(Request $request): Response
    {
        $periods = $this->table('payroll_periods')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();

        $current = null;
        $requested = $request->string('periode');

        if ($requested !== '') {
            $current = $this->table('payroll_periods')->find($requested);
        }

        if ($current === null && $periods !== []) {
            $current = $periods[0];
        }

        $payslips = [];
        $totals = ['gross' => 0.0, 'net' => 0.0, 'cnps' => 0.0, 'tax' => 0.0];

        if ($current !== null) {
            $payslips = $this->app->db()->select(
                'SELECT p.*, e.first_name, e.last_name, e.position
                 FROM payslips p
                 INNER JOIN employees e ON e.id = p.employee_id
                 WHERE p.tenant_id = :tenant AND p.period_id = :period
                 ORDER BY e.last_name, e.first_name',
                ['tenant' => $this->app->tenant()->requireId(), 'period' => $current['id']]
            );

            foreach ($payslips as $slip) {
                $totals['gross'] += (float) $slip['gross_amount'];
                $totals['net'] += (float) $slip['net_amount'];
                $totals['cnps'] += (float) $slip['cnps_employee'] + (float) $slip['cnps_employer'];
                $totals['tax'] += (float) $slip['income_tax'];
            }
        }

        return $this->view('payroll.index', [
            'periods' => $periods,
            'current' => $current,
            'payslips' => $payslips,
            'totals' => $totals,
            'employees' => $this->table('employees')
                ->where('status', 'ACTIVE')
                ->orderBy('last_name')
                ->get(),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function storePeriod(Request $request): Response
    {
        $validator = (new Validator($request))
            ->integer('year', 'annee', 2000, 2100)
            ->integer('month', 'mois', 1, 12);

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/paie', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['year', 'month']);
        $year = (int) $data['year'];
        $month = (int) $data['month'];

        $exists = $this->table('payroll_periods')
            ->where('year', (string) $year)
            ->where('month', (string) $month)
            ->exists();

        if ($exists) {
            return $this->redirectWithError('/paie', 'Cette periode de paie existe deja.');
        }

        $now = date('Y-m-d H:i:s');

        $id = $this->table('payroll_periods')->insert([
            'label' => PayrollCalculator::monthName($month).' '.$year,
            'year' => $year,
            'month' => $month,
            'status' => 'OUVERTE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->redirectWithSuccess('/paie?periode='.$id, 'Periode de paie ouverte.');
    }

    /**
     * Genere les bulletins de tous les agents actifs sans bulletin sur la
     * periode.
     *
     * Les agents deja traites sont laisses intacts : relancer la generation
     * apres avoir ajuste un bulletin a la main ne doit pas ecraser l'ajustement.
     */
    public function generate(Request $request): Response
    {
        $period = $this->findOrFail('payroll_periods', (string) $request->attribute('id'));

        if ((string) $period['status'] !== 'OUVERTE') {
            return $this->redirectWithError('/paie?periode='.$period['id'], 'Cette periode est close.');
        }

        $employees = $this->table('employees')->where('status', 'ACTIVE')->get();
        $existing = array_map(
            static fn (array $p): string => (string) $p['employee_id'],
            $this->table('payslips')->select(['employee_id'])->where('period_id', (string) $period['id'])->get()
        );

        $now = date('Y-m-d H:i:s');
        $created = 0;
        $skipped = 0;

        foreach ($employees as $employee) {
            if (in_array((string) $employee['id'], $existing, true)) {
                continue;
            }

            $salary = (float) ($employee['salary'] ?? 0);

            // Un agent sans salaire renseigne n'est pas une erreur bloquante,
            // mais generer un bulletin a zero serait pire que ne rien faire :
            // il serait signe et remis.
            if ($salary <= 0) {
                $skipped++;

                continue;
            }

            $slip = PayrollCalculator::compute($salary);

            $this->app->db()->transaction(function () use ($period, $employee, $salary, $slip, $now): void {
                $payslipId = $this->table('payslips')->insert([
                    'period_id' => $period['id'],
                    'employee_id' => $employee['id'],
                    'base_salary' => $salary,
                    'gross_amount' => $slip['gross'],
                    'cnps_employee' => $slip['cnps_employee'],
                    'cnps_employer' => $slip['cnps_employer'],
                    'income_tax' => $slip['income_tax'],
                    'other_deductions' => 0,
                    'net_amount' => $slip['net'],
                    'status' => 'BROUILLON',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($slip['lines'] as $line) {
                    $this->table('payslip_lines')->insert([
                        'payslip_id' => $payslipId,
                        'label' => $line['label'],
                        'kind' => $line['kind'],
                        'amount' => $line['amount'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });

            $created++;
        }

        $message = $created.' bulletin(s) genere(s).';

        if ($skipped > 0) {
            $message .= ' '.$skipped.' agent(s) ignore(s), faute de salaire de base renseigne.';
        }

        return $this->redirectWithSuccess('/paie?periode='.$period['id'], $message);
    }

    /** Fiche de paie detaillee, imprimable et remise a l'agent. */
    public function show(Request $request): Response
    {
        $payslip = $this->findOrFail('payslips', (string) $request->attribute('id'));

        $employee = $this->table('employees')->find((string) $payslip['employee_id']);
        $period = $this->table('payroll_periods')->find((string) $payslip['period_id']);

        return $this->view('payroll.show', [
            'payslip' => $payslip,
            'employee' => $employee,
            'period' => $period,
            'lines' => $this->table('payslip_lines')
                ->where('payslip_id', (string) $payslip['id'])
                ->get(),
            'tenant' => $this->app->tenant()->current(),
        ]);
    }

    /**
     * Cloture la periode : les bulletins deviennent definitifs.
     *
     * La cloture est refusee si un bulletin est encore au brouillon, sinon on
     * figerait un document que personne n'a relu.
     */
    public function close(Request $request): Response
    {
        $period = $this->findOrFail('payroll_periods', (string) $request->attribute('id'));

        if ((string) $period['status'] !== 'OUVERTE') {
            return $this->redirectWithError('/paie?periode='.$period['id'], 'Cette periode est deja close.');
        }

        $drafts = $this->table('payslips')
            ->where('period_id', (string) $period['id'])
            ->where('status', 'BROUILLON')
            ->count();

        if ($drafts > 0) {
            return $this->redirectWithError(
                '/paie?periode='.$period['id'],
                $drafts.' bulletin(s) encore au brouillon : validez-les avant de clore la periode.'
            );
        }

        $now = date('Y-m-d H:i:s');

        $this->table('payroll_periods')
            ->where('id', (string) $period['id'])
            ->update(['status' => 'CLOSE', 'closed_at' => $now, 'updated_at' => $now]);

        $this->trail()->recorded('PAIE_CLOTUREE', 'payroll_periods', (string) $period['id']);

        return $this->redirectWithSuccess('/paie?periode='.$period['id'], 'Periode de paie close.');
    }

    /** Valide un bulletin : il sort du brouillon et peut etre remis. */
    public function validateSlip(Request $request): Response
    {
        $payslip = $this->findOrFail('payslips', (string) $request->attribute('id'));
        $period = $this->table('payroll_periods')->find((string) $payslip['period_id']);

        if ($period !== null && (string) $period['status'] !== 'OUVERTE') {
            return $this->redirectWithError('/paie', 'La periode est close : ce bulletin ne bouge plus.');
        }

        $now = date('Y-m-d H:i:s');

        $this->table('payslips')
            ->where('id', (string) $payslip['id'])
            ->update(['status' => 'VALIDE', 'paid_on' => date('Y-m-d'), 'updated_at' => $now]);

        return $this->redirectWithSuccess('/paie/bulletins/'.$payslip['id'], 'Bulletin valide.');
    }
}
