<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Database\SchoolFactory;
use Scholaris\Database\Table;
use Scholaris\Http\Request;
use Scholaris\Http\Response;

/**
 * Annee scolaire et periodes de saisie.
 *
 * Tout le reste en depend : sans annee active, aucune inscription ; sans
 * periode ouverte, aucune note ni aucun appel. Un etablissement qui n'a pas
 * cet ecran ne peut rien faire du tout, et rien ne le lui explique.
 *
 * Une seule annee est active a la fois, et une seule periode ouverte a la
 * saisie. C'est la regle de gestion des etablissements camerounais, et elle
 * evite qu'une note se retrouve rattachee a la mauvaise sequence.
 */
final class AcademicYearController extends Controller
{
    /** Decoupage standard : six sequences groupees en trois trimestres. */
    private const SEQUENCES = 6;

    public function index(Request $request): Response
    {
        $years = $this->table('academic_years')->orderBy('start_date', 'desc')->get();
        $periods = [];

        foreach ($years as $year) {
            $periods[(string) $year['id']] = $this->app->db()->select(
                'SELECT * FROM periods WHERE academic_year_id = :year ORDER BY number',
                ['year' => $year['id']]
            );
        }

        return $this->view('academic-years.index', [
            'years' => $years,
            'periods' => $periods,
        ]);
    }

    public function store(Request $request): Response
    {
        $startYear = (int) $request->string('start_year');

        if ($startYear < 2000 || $startYear > 2100) {
            return $this->redirectWithError('/annees-scolaires', 'Annee de debut invalide.');
        }

        $label = $startYear.'-'.($startYear + 1);

        $existing = $this->table('academic_years')->where('label', $label)->first();

        if ($existing !== null) {
            return $this->redirectWithError('/annees-scolaires', 'L annee '.$label.' existe deja.');
        }

        $tenantId = $this->app->tenant()->requireId();
        $now = date('Y-m-d H:i:s');

        $this->app->db()->transaction(function () use ($tenantId, $startYear, $now): void {
            // Une nouvelle annee devient l'annee courante : deux annees actives
            // rendraient ambigu le rattachement de toute inscription.
            $this->table('academic_years')
                ->where('status', 'ACTIVE')
                ->update(['status' => 'CLOSED']);

            $this->createYear($tenantId, $startYear, $now);
        });

        return $this->redirectWithSuccess('/annees-scolaires', 'Annee '.$label.' creee et activee.');
    }

    public function activate(Request $request): Response
    {
        $year = $this->findOrFail('academic_years', (string) $request->attribute('id'));

        $this->app->db()->transaction(function () use ($year): void {
            $this->table('academic_years')
                ->where('status', 'ACTIVE')
                ->update(['status' => 'CLOSED']);

            $this->table('academic_years')
                ->where('id', (string) $year['id'])
                ->update(['status' => 'ACTIVE']);
        });

        return $this->redirectWithSuccess('/annees-scolaires', 'Annee '.$year['label'].' activee.');
    }

    public function openPeriod(Request $request): Response
    {
        $periodId = (string) $request->attribute('id');
        $period = $this->periodOfThisTenant($periodId);

        if ($period === null) {
            return $this->redirectWithError('/annees-scolaires', 'Periode introuvable.');
        }

        $this->app->db()->transaction(function () use ($period, $periodId): void {
            // Une seule periode ouverte a la fois : sinon un enseignant peut
            // saisir dans la sequence precedente sans s'en apercevoir.
            $this->app->db()->execute(
                "UPDATE periods SET grading_status = 'CLOSED'
                 WHERE academic_year_id = :year AND grading_status = 'OPEN'",
                ['year' => $period['academic_year_id']]
            );

            $this->app->db()->execute(
                "UPDATE periods SET grading_status = 'OPEN' WHERE id = :id",
                ['id' => $periodId]
            );
        });

        return $this->redirectWithSuccess(
            '/annees-scolaires',
            'Sequence '.$period['number'].' ouverte a la saisie.'
        );
    }

    public function closePeriod(Request $request): Response
    {
        $periodId = (string) $request->attribute('id');
        $period = $this->periodOfThisTenant($periodId);

        if ($period === null) {
            return $this->redirectWithError('/annees-scolaires', 'Periode introuvable.');
        }

        $this->app->db()->execute(
            "UPDATE periods SET grading_status = 'CLOSED' WHERE id = :id",
            ['id' => $periodId]
        );

        return $this->redirectWithSuccess(
            '/annees-scolaires',
            'Sequence '.$period['number'].' fermee a la saisie.'
        );
    }

    /**
     * Une periode appartient a un etablissement par son annee academique.
     *
     * La table periods ne porte pas de tenant_id : la verification passe donc
     * par la jointure, sans quoi l'identifiant d'une periode d'un autre
     * etablissement suffirait a la manipuler.
     *
     * @return array<string, mixed>|null
     */
    private function periodOfThisTenant(string $periodId): ?array
    {
        $period = $this->app->db()->selectOne(
            'SELECT p.* FROM periods p
             JOIN academic_years y ON y.id = p.academic_year_id
             WHERE p.id = :id AND y.tenant_id = :tenant',
            ['id' => $periodId, 'tenant' => $this->app->tenant()->requireId()]
        );

        return $period;
    }

    private function createYear(string $tenantId, int $startYear, string $now): void
    {
        $start = new \DateTimeImmutable($startYear.'-09-01');
        $yearId = Table::uuid();

        $this->app->db()->execute(
            'INSERT INTO academic_years (id, tenant_id, label, start_date, end_date, status, created_at)
             VALUES (:id, :tenant, :label, :start, :end, :status, :created_at)',
            [
                'id' => $yearId,
                'tenant' => $tenantId,
                'label' => $startYear.'-'.($startYear + 1),
                'start' => $start->format('Y-m-d'),
                'end' => $start->modify('+10 months')->format('Y-m-d'),
                'status' => 'ACTIVE',
                'created_at' => $now,
            ]
        );

        for ($number = 1; $number <= self::SEQUENCES; $number++) {
            $this->app->db()->execute(
                'INSERT INTO periods (id, academic_year_id, type, number, start_date, end_date, grading_status)
                 VALUES (:id, :year, :type, :number, :start, :end, :status)',
                [
                    'id' => Table::uuid(),
                    'year' => $yearId,
                    'type' => 'SEQUENCE',
                    'number' => $number,
                    'start' => $start->modify('+'.(($number - 1) * 6).' weeks')->format('Y-m-d'),
                    'end' => $start->modify('+'.($number * 6).' weeks')->format('Y-m-d'),
                    // La premiere sequence est ouverte : une annee creee sans
                    // periode de saisie ne servirait a rien.
                    'status' => $number === 1 ? 'OPEN' : 'CLOSED',
                ]
            );
        }
    }
}
