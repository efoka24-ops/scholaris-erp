<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Validator;

/**
 * Module 43 : objectifs et performances.
 *
 * Un objectif porte un indicateur, une cible et une valeur atteinte. La
 * valeur atteinte est relevee a la main plutot que calculee : beaucoup
 * d'indicateurs utiles a un chef d'etablissement portent sur des donnees que
 * l'application ne detient pas — taux de reussite publie par le ministere,
 * nombre de salles rehabilitees, retour d'une inspection.
 *
 * Quand l'indicateur porte sur une donnee que l'application connait, le
 * releve est propose automatiquement : c'est le role de suggest().
 */
final class ObjectiveController extends Controller
{
    private const SCOPES = [
        'ETABLISSEMENT' => 'Etablissement',
        'CLASSE' => 'Classe',
        'MATIERE' => 'Matiere',
        'PERSONNEL' => 'Personnel',
        'FINANCE' => 'Finances',
    ];

    private const STATUSES = ['EN_COURS', 'ATTEINT', 'MANQUE', 'ABANDONNE'];

    public function index(Request $request): Response
    {
        $year = $this->currentAcademicYearId();

        $objectives = $this->app->db()->select(
            'SELECT o.*, u.first_name, u.last_name
             FROM objectives o
             LEFT JOIN users u ON u.id = o.owner_id
             WHERE o.tenant_id = :tenant
               AND (o.academic_year_id IS NULL OR o.academic_year_id = :year)
             ORDER BY o.status, o.due_on',
            ['tenant' => $this->app->tenant()->requireId(), 'year' => $year]
        );

        $summary = ['EN_COURS' => 0, 'ATTEINT' => 0, 'MANQUE' => 0, 'ABANDONNE' => 0];
        $progressSum = 0.0;
        $tracked = 0;

        foreach ($objectives as $objective) {
            $status = (string) $objective['status'];
            $summary[$status] = ($summary[$status] ?? 0) + 1;

            if ($status === 'EN_COURS' || $status === 'ATTEINT') {
                $progressSum += self::progress($objective);
                $tracked++;
            }
        }

        return $this->view('objectives.index', [
            'objectives' => $objectives,
            'summary' => $summary,
            'scopes' => self::SCOPES,
            'statuses' => self::STATUSES,
            // Moyenne d'avancement des objectifs encore vivants : les
            // objectifs abandonnes la fausseraient sans rien apprendre.
            'averageProgress' => $tracked === 0 ? 0.0 : round($progressSum / $tracked, 1),
            'suggestions' => $this->suggest(),
            'owners' => $this->table('users')
                ->select(['id', 'first_name', 'last_name'])
                ->where('status', 'ACTIVE')
                ->orderBy('last_name')
                ->get(),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    /**
     * Avancement d'un objectif, en pourcentage de la cible.
     *
     * Plafonne a 100 : depasser la cible est une bonne nouvelle, mais une
     * barre de progression a 340 % ne se lit plus.
     *
     * @param  array<string, mixed>  $objective
     */
    public static function progress(array $objective): float
    {
        $target = (float) $objective['target_value'];

        if ($target <= 0) {
            return 0.0;
        }

        return min(100.0, round((float) $objective['current_value'] / $target * 100, 1));
    }

    /**
     * Indicateurs que l'application sait deja mesurer.
     *
     * Proposes en appui a la saisie, jamais imposes : le chef
     * d'etablissement reste libre de suivre ce qui compte pour lui.
     *
     * @return array<string, float>
     */
    private function suggest(): array
    {
        $tenant = $this->app->tenant()->requireId();

        $students = (int) $this->app->db()->scalar(
            'SELECT COUNT(*) FROM students
             WHERE tenant_id = :tenant AND deleted_at IS NULL AND status = :status',
            ['tenant' => $tenant, 'status' => 'ACTIVE']
        );

        $invoiced = (float) $this->app->db()->scalar(
            'SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE tenant_id = :tenant',
            ['tenant' => $tenant]
        );

        $collected = (float) $this->app->db()->scalar(
            'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE tenant_id = :tenant',
            ['tenant' => $tenant]
        );

        return [
            'Effectif actif' => (float) $students,
            'Taux de recouvrement' => $invoiced <= 0 ? 0.0 : round($collected / $invoiced * 100, 1),
        ];
    }

    public function store(Request $request): Response
    {
        $ownerIds = array_map(
            static fn (array $u): string => (string) $u['id'],
            $this->table('users')->select(['id'])->where('status', 'ACTIVE')->get()
        );

        $validator = (new Validator($request))
            ->required('label', 'intitule')
            ->in('scope', 'perimetre', array_keys(self::SCOPES))
            ->optional('indicator')
            ->required('unit', 'unite')
            ->decimal('target_value', 'valeur cible')
            ->decimal('current_value', 'valeur atteinte')
            ->date('due_on', 'echeance', false)
            ->in('owner_id', 'responsable', $ownerIds, false);

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/objectifs', implode(' ', $validator->errors()));
        }

        $data = $validator->only([
            'label', 'scope', 'indicator', 'unit',
            'target_value', 'current_value', 'due_on', 'owner_id',
        ]);

        if ((float) $data['target_value'] <= 0) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError(
                '/objectifs',
                'Une cible nulle rend l objectif ininterpretable : indiquez la valeur visee.'
            );
        }

        $now = date('Y-m-d H:i:s');
        $data['academic_year_id'] = $this->currentAcademicYearId();
        $data['status'] = 'EN_COURS';
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $this->table('objectives')->insert($data);

        return $this->redirectWithSuccess('/objectifs', 'Objectif enregistre.');
    }

    /**
     * Releve la valeur atteinte.
     *
     * Le statut bascule seul sur ATTEINT quand la cible est franchie : laisser
     * ce basculement a la main ferait vivre des objectifs atteints depuis des
     * mois dans la colonne « en cours ».
     */
    public function record(Request $request): Response
    {
        $objective = $this->findOrFail('objectives', (string) $request->attribute('id'));

        $validator = (new Validator($request))->decimal('current_value', 'valeur atteinte');

        if ($validator->fails()) {
            return $this->redirectWithError('/objectifs', implode(' ', $validator->errors()));
        }

        $value = (float) $validator->validated()['current_value'];
        $target = (float) $objective['target_value'];
        $status = (string) $objective['status'];

        if ($status === 'EN_COURS' && $target > 0 && $value >= $target) {
            $status = 'ATTEINT';
        }

        // Le retour sous la cible rouvre l'objectif : un indicateur qui
        // redescend n'est plus atteint, et le figer mentirait.
        if ($status === 'ATTEINT' && $target > 0 && $value < $target) {
            $status = 'EN_COURS';
        }

        $this->table('objectives')
            ->where('id', (string) $objective['id'])
            ->update([
                'current_value' => $value,
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->redirectWithSuccess('/objectifs', 'Releve enregistre.');
    }

    public function updateStatus(Request $request): Response
    {
        $objective = $this->findOrFail('objectives', (string) $request->attribute('id'));

        $validator = (new Validator($request))->in('status', 'statut', self::STATUSES);

        if ($validator->fails()) {
            return $this->redirectWithError('/objectifs', implode(' ', $validator->errors()));
        }

        $status = (string) $validator->validated()['status'];

        $this->table('objectives')
            ->where('id', (string) $objective['id'])
            ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);

        $this->trail()->changed(
            'OBJECTIF_STATUT',
            'objectives',
            (string) $objective['id'],
            ['status' => $objective['status']],
            ['status' => $status]
        );

        return $this->redirectWithSuccess('/objectifs', 'Statut de l objectif mis a jour.');
    }
}
