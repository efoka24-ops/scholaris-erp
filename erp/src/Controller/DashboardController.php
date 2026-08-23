<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;

/**
 * Tableau de bord de l'etablissement courant.
 *
 * Tous les comptages passent par Table, donc portent le filtre d'etablissement :
 * les chiffres affiches sont ceux du seul etablissement de l'utilisateur.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $academicYearId = $this->currentAcademicYearId();

        return $this->view('dashboard.index', [
            'academicYear' => $academicYearId !== null
                ? $this->table('academic_years')->find($academicYearId)
                : null,
            'stats' => $this->stats($academicYearId),
            'recentStudents' => $this->table('students')
                ->notDeleted()
                ->where('status', 'ACTIVE')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(),
        ]);
    }

    /**
     * @return array<string, float|int>
     */
    private function stats(?string $academicYearId): array
    {
        $invoices = fn () => $academicYearId === null
            ? $this->table('invoices')->notDeleted()
            : $this->table('invoices')->notDeleted()->where('academic_year_id', $academicYearId);

        $enrollments = $this->table('enrollments')->notDeleted()->where('status', 'ACTIVE');

        if ($academicYearId !== null) {
            $enrollments->where('academic_year_id', $academicYearId);
        }

        return [
            'students' => $this->table('students')->notDeleted()->where('status', 'ACTIVE')->count(),
            'classrooms' => $this->table('classrooms')->count(),
            'enrollments' => $enrollments->count(),
            'billed' => $invoices()->sum('total_amount'),
            'collected' => $invoices()->sum('paid_amount'),
            'outstanding' => $invoices()->sum('balance'),
        ];
    }
}
