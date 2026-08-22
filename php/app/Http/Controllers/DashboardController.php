<?php

namespace App\Http\Controllers;

use App\Models\ClassRoom;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tableau de bord de l'etablissement courant.
 *
 * Toutes les requetes passent par le scope global BelongsToTenant : les
 * chiffres affiches sont ceux du seul etablissement de l'utilisateur connecte.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $tenant = $request->user()->tenant;
        $academicYear = $tenant->currentAcademicYear();

        return view('dashboard', [
            'tenant' => $tenant,
            'academicYear' => $academicYear,
            'stats' => $this->stats($academicYear?->id),
        ]);
    }

    /**
     * @return array<string, int|float>
     */
    private function stats(?string $academicYearId): array
    {
        $invoices = Invoice::query()
            ->when($academicYearId, fn ($query) => $query->where('academic_year_id', $academicYearId));

        return [
            'students' => Student::query()->where('status', 'ACTIVE')->count(),
            'classrooms' => ClassRoom::query()->count(),
            'enrollments' => Enrollment::query()
                ->where('status', 'ACTIVE')
                ->when($academicYearId, fn ($query) => $query->where('academic_year_id', $academicYearId))
                ->count(),
            'billed' => (float) (clone $invoices)->sum('total_amount'),
            'collected' => (float) (clone $invoices)->sum('paid_amount'),
            'outstanding' => (float) (clone $invoices)->sum('balance'),
        ];
    }
}
