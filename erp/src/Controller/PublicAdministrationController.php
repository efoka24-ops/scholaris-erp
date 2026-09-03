<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Validator;

/**
 * Module 40 : administration publique.
 *
 * Actes de carriere du personnel (mutation, promotion, avancement,
 * nomination, sanction, retraite) et notes de service.
 *
 * Un acte suit deux etats : PROJET, tant qu'il se prepare, puis SIGNE, une
 * fois pris. Un acte signe ne se modifie plus — il se rapporte par un autre
 * acte. C'est la regle de toute administration : un arrete qui changerait
 * apres signature ne vaudrait rien devant un recours.
 */
final class PublicAdministrationController extends Controller
{
    private const KINDS = [
        'MUTATION' => 'Mutation',
        'PROMOTION' => 'Promotion',
        'AVANCEMENT' => 'Avancement d echelon',
        'NOMINATION' => 'Nomination',
        'AFFECTATION' => 'Affectation',
        'SANCTION' => 'Sanction disciplinaire',
        'CONGE' => 'Mise en conge',
        'RETRAITE' => 'Admission a la retraite',
    ];

    private const AUDIENCES = [
        'TOUS' => 'Tout le personnel',
        'ENSEIGNANTS' => 'Personnel enseignant',
        'ADMINISTRATIF' => 'Personnel administratif',
        'DIRECTION' => 'Equipe de direction',
    ];

    public function index(Request $request): Response
    {
        $decisions = $this->app->db()->select(
            'SELECT d.*, e.first_name, e.last_name, e.position
             FROM staff_decisions d
             INNER JOIN employees e ON e.id = d.employee_id
             WHERE d.tenant_id = :tenant
             ORDER BY d.decided_on DESC
             LIMIT 150',
            ['tenant' => $this->app->tenant()->requireId()]
        );

        return $this->view('publicadmin.index', [
            'decisions' => $decisions,
            'notes' => $this->table('service_notes')->orderBy('created_at', 'desc')->limit(100)->get(),
            'employees' => $this->table('employees')
                ->where('status', 'ACTIVE')
                ->orderBy('last_name')
                ->get(),
            'kinds' => self::KINDS,
            'audiences' => self::AUDIENCES,
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function storeDecision(Request $request): Response
    {
        $employeeIds = array_map(
            static fn (array $e): string => (string) $e['id'],
            $this->table('employees')->select(['id'])->get()
        );

        if ($employeeIds === []) {
            return $this->redirectWithError(
                '/administration',
                'Aucun agent enregistre : creez le personnel avant de prendre un acte.'
            );
        }

        $validator = (new Validator($request))
            ->in('employee_id', 'agent', $employeeIds)
            ->in('kind', 'nature de l acte', array_keys(self::KINDS))
            ->required('subject', 'objet')
            ->optional('content')
            ->optional('signed_by')
            ->date('decided_on', 'date de la decision')
            ->date('effective_on', 'date d effet', false);

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/administration', implode(' ', $validator->errors()));
        }

        $data = $validator->only([
            'employee_id', 'kind', 'subject', 'content',
            'signed_by', 'decided_on', 'effective_on',
        ]);

        $now = date('Y-m-d H:i:s');

        $id = $this->table('staff_decisions')->insert([
            'employee_id' => $data['employee_id'],
            'kind' => $data['kind'],
            'reference' => $this->nextReference('staff_decisions', 'DEC'),
            'subject' => $data['subject'],
            'content' => $data['content'] ?? null,
            'decided_on' => $data['decided_on'],
            'effective_on' => $data['effective_on'] ?? null,
            'signed_by' => $data['signed_by'] ?? null,
            'status' => 'PROJET',
            'created_by' => $this->currentUserId(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->trail()->created('ACTE_PREPARE', 'staff_decisions', $id, [
            'kind' => $data['kind'],
            'subject' => $data['subject'],
        ]);

        return $this->redirectWithSuccess('/administration', 'Acte prepare. Il reste modifiable tant qu il n est pas signe.');
    }

    /** Signe un acte : il devient definitif. */
    public function signDecision(Request $request): Response
    {
        $decision = $this->findOrFail('staff_decisions', (string) $request->attribute('id'));

        if ((string) $decision['status'] === 'SIGNE') {
            return $this->redirectWithError('/administration', 'Cet acte est deja signe.');
        }

        if (trim((string) ($decision['signed_by'] ?? '')) === '') {
            return $this->redirectWithError(
                '/administration',
                'Renseignez l autorite signataire avant de signer l acte.'
            );
        }

        $now = date('Y-m-d H:i:s');

        $this->table('staff_decisions')
            ->where('id', (string) $decision['id'])
            ->update(['status' => 'SIGNE', 'updated_at' => $now]);

        $this->trail()->recorded('ACTE_SIGNE', 'staff_decisions', (string) $decision['id']);

        return $this->redirectWithSuccess('/administration', 'Acte signe.');
    }

    public function showDecision(Request $request): Response
    {
        $decision = $this->findOrFail('staff_decisions', (string) $request->attribute('id'));

        return $this->view('publicadmin.decision', [
            'decision' => $decision,
            'employee' => $this->table('employees')->find((string) $decision['employee_id']),
            'kinds' => self::KINDS,
            'tenant' => $this->app->tenant()->current(),
        ]);
    }

    public function storeNote(Request $request): Response
    {
        $validator = (new Validator($request))
            ->required('title', 'titre')
            ->required('content', 'contenu')
            ->in('audience', 'destinataires', array_keys(self::AUDIENCES));

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/administration', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['title', 'content', 'audience']);
        $now = date('Y-m-d H:i:s');

        $this->table('service_notes')->insert([
            'reference' => $this->nextReference('service_notes', 'NS'),
            'title' => $data['title'],
            'content' => $data['content'],
            'audience' => $data['audience'],
            'status' => 'PROJET',
            'created_by' => $this->currentUserId(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->redirectWithSuccess('/administration', 'Note de service enregistree.');
    }

    /** Publie une note : elle est datee du jour et devient opposable. */
    public function publishNote(Request $request): Response
    {
        $note = $this->findOrFail('service_notes', (string) $request->attribute('id'));

        if ((string) $note['status'] === 'PUBLIEE') {
            return $this->redirectWithError('/administration', 'Cette note est deja publiee.');
        }

        $now = date('Y-m-d H:i:s');

        $this->table('service_notes')
            ->where('id', (string) $note['id'])
            ->update([
                'status' => 'PUBLIEE',
                'published_on' => date('Y-m-d'),
                'updated_at' => $now,
            ]);

        $this->trail()->recorded('NOTE_PUBLIEE', 'service_notes', (string) $note['id']);

        return $this->redirectWithSuccess('/administration', 'Note de service publiee.');
    }

    /**
     * Numero d'acte, continu par annee civile et par nature.
     *
     * Comme en comptabilite, on repart du plus grand numero existant : un acte
     * supprime ne doit pas faire reutiliser un numero deja cite ailleurs.
     */
    private function nextReference(string $table, string $prefix): string
    {
        // Le nom de table entre dans le SQL par concatenation : aucun
        // parametre lie ne peut porter un nom de table. Il est donc restreint
        // a une liste fermee, pour que la methode reste sure meme si un appel
        // futur lui passait autre chose qu'une constante.
        if (! in_array($table, ['staff_decisions', 'service_notes'], true)) {
            throw new \InvalidArgumentException('Table de numerotation inconnue : '.$table);
        }

        $stem = $prefix.'/'.date('Y').'/';

        $last = $this->app->db()->scalar(
            'SELECT MAX(reference) FROM '.$table.'
             WHERE tenant_id = :tenant AND reference LIKE :prefix',
            ['tenant' => $this->app->tenant()->requireId(), 'prefix' => $stem.'%']
        );

        $sequence = $last === null ? 0 : (int) substr((string) $last, strlen($stem));

        return $stem.str_pad((string) ($sequence + 1), 4, '0', STR_PAD_LEFT);
    }
}
