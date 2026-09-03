<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Service\ChartOfAccounts;
use Scholaris\Support\Validator;

/**
 * Module 22 : comptabilite generale (plan SYSCOHADA).
 *
 * Deux regles portent tout le module et ne sont jamais contournables :
 *
 *  - une ecriture ne s'enregistre que si elle est equilibree, debit egal
 *    credit. Une comptabilite desequilibree n'est pas une comptabilite
 *    imparfaite, c'est un document sans valeur ;
 *  - une ecriture validee ne se modifie plus. Corriger une erreur passe par
 *    une contre-passation, qui laisse les deux traces. C'est ce que reclame
 *    un controle, et c'est ce qui distingue ce module d'un tableur.
 */
final class AccountingController extends Controller
{
    /** Journaux normalises : achats, ventes, banque, caisse, operations diverses. */
    private const JOURNALS = ['AC', 'VE', 'BQ', 'CA', 'OD'];

    public function index(Request $request): Response
    {
        $tenant = $this->app->tenant()->requireId();
        $year = $this->currentAcademicYearId();

        // La balance se lit par compte : c'est la vue dont part tout controle,
        // avant meme le detail des ecritures.
        $balance = $this->app->db()->select(
            'SELECT a.id, a.code, a.name, a.account_class,
                    COALESCE(SUM(l.debit), 0) AS debit,
                    COALESCE(SUM(l.credit), 0) AS credit
             FROM ledger_accounts a
             LEFT JOIN ledger_lines l ON l.account_id = a.id AND l.tenant_id = a.tenant_id
             LEFT JOIN ledger_entries e ON e.id = l.entry_id AND e.posted = 1
             WHERE a.tenant_id = :tenant
             GROUP BY a.id, a.code, a.name, a.account_class
             ORDER BY a.code',
            ['tenant' => $tenant]
        );

        $entries = $this->app->db()->select(
            'SELECT e.*,
                    COALESCE((SELECT SUM(debit) FROM ledger_lines WHERE entry_id = e.id), 0) AS total
             FROM ledger_entries e
             WHERE e.tenant_id = :tenant
             ORDER BY e.entry_date DESC, e.reference DESC
             LIMIT 50',
            ['tenant' => $tenant]
        );

        return $this->view('accounting.index', [
            'balance' => $balance,
            'entries' => $entries,
            'accounts' => $this->table('ledger_accounts')->orderBy('code')->get(),
            'journals' => self::JOURNALS,
            'result' => $this->result($tenant),
            'budget' => $this->budgetComparison($tenant, $year),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    /**
     * Compte de resultat simplifie : classe 6 en charges, classe 7 en produits.
     *
     * @return array{charges: float, produits: float, solde: float}
     */
    private function result(string $tenant): array
    {
        $sums = $this->app->db()->select(
            'SELECT a.account_class,
                    COALESCE(SUM(l.debit), 0) AS debit,
                    COALESCE(SUM(l.credit), 0) AS credit
             FROM ledger_lines l
             INNER JOIN ledger_accounts a ON a.id = l.account_id
             INNER JOIN ledger_entries e ON e.id = l.entry_id
             WHERE l.tenant_id = :tenant AND e.posted = 1 AND a.account_class IN (6, 7)
             GROUP BY a.account_class',
            ['tenant' => $tenant]
        );

        $charges = 0.0;
        $produits = 0.0;

        foreach ($sums as $row) {
            if ((int) $row['account_class'] === 6) {
                // Une charge s'accroit au debit ; un avoir fournisseur la reduit.
                $charges = (float) $row['debit'] - (float) $row['credit'];
            } else {
                $produits = (float) $row['credit'] - (float) $row['debit'];
            }
        }

        return [
            'charges' => $charges,
            'produits' => $produits,
            'solde' => $produits - $charges,
        ];
    }

    /**
     * Budget prevu face au realise, ligne a ligne.
     *
     * @return list<array<string, mixed>>
     */
    private function budgetComparison(string $tenant, ?string $year): array
    {
        return $this->app->db()->select(
            'SELECT b.id, b.label, b.direction, b.planned_amount, a.code, a.name,
                    COALESCE((
                        SELECT SUM(CASE WHEN b.direction = :charge THEN l.debit - l.credit
                                        ELSE l.credit - l.debit END)
                        FROM ledger_lines l
                        INNER JOIN ledger_entries e ON e.id = l.entry_id
                        WHERE l.account_id = b.account_id AND l.tenant_id = b.tenant_id AND e.posted = 1
                    ), 0) AS actual_amount
             FROM budget_lines b
             LEFT JOIN ledger_accounts a ON a.id = b.account_id
             WHERE b.tenant_id = :tenant
               AND (b.academic_year_id IS NULL OR b.academic_year_id = :year)
             ORDER BY b.direction, b.label',
            ['tenant' => $tenant, 'year' => $year, 'charge' => 'CHARGE']
        );
    }

    public function storeAccount(Request $request): Response
    {
        $validator = (new Validator($request))
            ->required('code', 'numero de compte')
            ->required('name', 'intitule')
            ->integer('account_class', 'classe', 1, 9)
            ->in('nature', 'nature', ['BILAN', 'GESTION']);

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/comptabilite', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['code', 'name', 'account_class', 'nature']);

        if ($this->table('ledger_accounts')->where('code', (string) $data['code'])->exists()) {
            return $this->redirectWithError('/comptabilite', 'Ce numero de compte existe deja.');
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];

        $id = $this->table('ledger_accounts')->insert($data);
        $this->trail()->created('COMPTE_CREE', 'ledger_accounts', $id, $data);

        return $this->redirectWithSuccess('/comptabilite', 'Compte ajoute au plan comptable.');
    }

    /** Installe le plan comptable SYSCOHADA de base, si le plan est vide. */
    public function seedChart(Request $request): Response
    {
        if ($this->table('ledger_accounts')->exists()) {
            return $this->redirectWithError(
                '/comptabilite',
                'Le plan comptable contient deja des comptes : il n a pas ete remplace.'
            );
        }

        $now = date('Y-m-d H:i:s');
        $count = 0;

        foreach (ChartOfAccounts::syscohada() as $account) {
            $this->table('ledger_accounts')->insert([
                'code' => $account['code'],
                'name' => $account['name'],
                'account_class' => $account['class'],
                'nature' => $account['nature'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $count++;
        }

        return $this->redirectWithSuccess(
            '/comptabilite',
            $count.' comptes SYSCOHADA installes.'
        );
    }

    /**
     * Enregistre une ecriture au brouillard.
     *
     * L'equilibre est verifie avant toute insertion : une ecriture partielle
     * en base serait pire qu'un refus, parce qu'elle fausserait la balance
     * sans qu'on sache d'ou vient l'ecart.
     */
    public function storeEntry(Request $request): Response
    {
        $accounts = array_map(
            static fn (array $a): string => (string) $a['id'],
            $this->table('ledger_accounts')->select(['id'])->get()
        );

        if ($accounts === []) {
            return $this->redirectWithError(
                '/comptabilite',
                'Installez d abord le plan comptable.'
            );
        }

        $validator = (new Validator($request))
            ->required('label', 'libelle')
            ->date('entry_date', 'date')
            ->in('journal', 'journal', self::JOURNALS);

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/comptabilite', implode(' ', $validator->errors()));
        }

        $lines = $this->readLines($request, $accounts);

        if ($lines === []) {
            return $this->redirectWithError(
                '/comptabilite',
                'Une ecriture demande au moins deux lignes renseignees.'
            );
        }

        $debit = array_sum(array_column($lines, 'debit'));
        $credit = array_sum(array_column($lines, 'credit'));

        // Tolerance au centime : les montants sont saisis en francs CFA, mais
        // la comparaison porte sur des flottants.
        if (abs($debit - $credit) > 0.005) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError(
                '/comptabilite',
                sprintf(
                    'Ecriture desequilibree : %s au debit contre %s au credit.',
                    number_format($debit, 0, ',', ' '),
                    number_format($credit, 0, ',', ' ')
                )
            );
        }

        if ($debit <= 0) {
            return $this->redirectWithError('/comptabilite', 'Une ecriture a montant nul n a pas de sens.');
        }

        $data = $validator->only(['label', 'entry_date', 'journal']);
        $now = date('Y-m-d H:i:s');

        $this->app->db()->transaction(function () use ($data, $lines, $now): void {
            $entryId = $this->table('ledger_entries')->insert([
                'reference' => $this->nextReference((string) $data['journal']),
                'journal' => $data['journal'],
                'entry_date' => $data['entry_date'],
                'label' => $data['label'],
                'academic_year_id' => $this->currentAcademicYearId(),
                'posted' => 0,
                'created_by' => $this->currentUserId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($lines as $line) {
                $this->table('ledger_lines')->insert([
                    'entry_id' => $entryId,
                    'account_id' => $line['account_id'],
                    'label' => $line['label'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return $this->redirectWithSuccess('/comptabilite', 'Ecriture enregistree au brouillard.');
    }

    /**
     * Lit les lignes du formulaire, en ignorant celles qui sont vides.
     *
     * @param  list<string>  $accounts
     * @return list<array{account_id: string, label: string, debit: float, credit: float}>
     */
    private function readLines(Request $request, array $accounts): array
    {
        // Les lignes arrivent en tableau (lines[0][debit]...), ce que input()
        // ne sait pas rendre : il ne renvoie que des chaines.
        $raw = $request->all()['lines'] ?? null;
        $lines = [];

        if (! is_array($raw)) {
            return [];
        }

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $accountId = (string) ($row['account_id'] ?? '');
            $debit = (float) str_replace([' ', ','], ['', '.'], (string) ($row['debit'] ?? '0'));
            $credit = (float) str_replace([' ', ','], ['', '.'], (string) ($row['credit'] ?? '0'));

            // Une ligne sans compte ou sans montant est un reliquat du
            // formulaire, pas une erreur de saisie : on l'ignore en silence.
            if ($accountId === '' || ! in_array($accountId, $accounts, true)) {
                continue;
            }

            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            $lines[] = [
                'account_id' => $accountId,
                'label' => mb_substr(trim((string) ($row['label'] ?? '')), 0, 255),
                // Une meme ligne ne peut pas etre a la fois au debit et au
                // credit : le debit l'emporte, le credit est remis a zero.
                'debit' => $debit > 0 ? $debit : 0.0,
                'credit' => $debit > 0 ? 0.0 : $credit,
            ];
        }

        return count($lines) >= 2 ? $lines : [];
    }

    /**
     * Valide une ecriture : elle devient definitive.
     *
     * L'equilibre est re-verifie ici, et non seulement a la saisie : entre les
     * deux, une ligne a pu etre supprimee par un autre chemin.
     */
    public function postEntry(Request $request): Response
    {
        $entry = $this->findOrFail('ledger_entries', (string) $request->attribute('id'));

        if ((int) $entry['posted'] === 1) {
            return $this->redirectWithError('/comptabilite', 'Cette ecriture est deja validee.');
        }

        $totals = $this->app->db()->selectOne(
            'SELECT COALESCE(SUM(debit), 0) AS debit, COALESCE(SUM(credit), 0) AS credit
             FROM ledger_lines WHERE tenant_id = :tenant AND entry_id = :entry',
            ['tenant' => $this->app->tenant()->requireId(), 'entry' => $entry['id']]
        );

        if (abs((float) ($totals['debit'] ?? 0) - (float) ($totals['credit'] ?? 0)) > 0.005) {
            return $this->redirectWithError(
                '/comptabilite',
                'Ecriture desequilibree : elle ne peut pas etre validee.'
            );
        }

        $now = date('Y-m-d H:i:s');

        $this->table('ledger_entries')
            ->where('id', (string) $entry['id'])
            ->update(['posted' => 1, 'posted_at' => $now, 'updated_at' => $now]);

        $this->trail()->recorded('ECRITURE_VALIDEE', 'ledger_entries', (string) $entry['id']);

        return $this->redirectWithSuccess('/comptabilite', 'Ecriture validee. Elle est desormais definitive.');
    }

    /**
     * Contre-passe une ecriture validee.
     *
     * On ne supprime pas : on ajoute l'ecriture inverse. Les deux restent
     * lisibles, et la correction est elle-meme datee et tracee.
     */
    public function reverseEntry(Request $request): Response
    {
        $entry = $this->findOrFail('ledger_entries', (string) $request->attribute('id'));

        if ((int) $entry['posted'] !== 1) {
            return $this->redirectWithError(
                '/comptabilite',
                'Une ecriture au brouillard se corrige directement, sans contre-passation.'
            );
        }

        $lines = $this->table('ledger_lines')->where('entry_id', (string) $entry['id'])->get();

        if ($lines === []) {
            throw new HttpException(404);
        }

        $now = date('Y-m-d H:i:s');

        $this->app->db()->transaction(function () use ($entry, $lines, $now): void {
            $reversalId = $this->table('ledger_entries')->insert([
                'reference' => $this->nextReference((string) $entry['journal']),
                'journal' => $entry['journal'],
                'entry_date' => date('Y-m-d'),
                'label' => 'Contre-passation de '.$entry['reference'].' : '.$entry['label'],
                'academic_year_id' => $entry['academic_year_id'],
                'posted' => 1,
                'posted_at' => $now,
                'source' => 'CONTREPASSATION',
                'source_id' => $entry['id'],
                'created_by' => $this->currentUserId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($lines as $line) {
                // Debit et credit echanges : la somme des deux ecritures est nulle.
                $this->table('ledger_lines')->insert([
                    'entry_id' => $reversalId,
                    'account_id' => $line['account_id'],
                    'label' => $line['label'],
                    'debit' => $line['credit'],
                    'credit' => $line['debit'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return $this->redirectWithSuccess('/comptabilite', 'Contre-passation enregistree.');
    }

    public function show(Request $request): Response
    {
        $entry = $this->findOrFail('ledger_entries', (string) $request->attribute('id'));

        $lines = $this->app->db()->select(
            'SELECT l.*, a.code, a.name
             FROM ledger_lines l
             INNER JOIN ledger_accounts a ON a.id = l.account_id
             WHERE l.tenant_id = :tenant AND l.entry_id = :entry
             ORDER BY l.debit DESC',
            ['tenant' => $this->app->tenant()->requireId(), 'entry' => $entry['id']]
        );

        return $this->view('accounting.show', ['entry' => $entry, 'lines' => $lines]);
    }

    public function storeBudget(Request $request): Response
    {
        $accounts = array_map(
            static fn (array $a): string => (string) $a['id'],
            $this->table('ledger_accounts')->select(['id'])->get()
        );

        $validator = (new Validator($request))
            ->required('label', 'intitule')
            ->in('direction', 'sens', ['CHARGE', 'PRODUIT'])
            ->decimal('planned_amount', 'montant prevu')
            ->in('account_id', 'compte', $accounts, false);

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/comptabilite', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['label', 'direction', 'planned_amount', 'account_id']);
        $data['academic_year_id'] = $this->currentAcademicYearId();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];

        $this->table('budget_lines')->insert($data);

        return $this->redirectWithSuccess('/comptabilite', 'Ligne budgetaire ajoutee.');
    }

    /**
     * Numero d'ecriture, continu par journal et par exercice.
     *
     * Le maximum existant est relu a chaque fois plutot que compte : une
     * ecriture supprimee ne doit pas faire reutiliser un numero deja cite.
     */
    private function nextReference(string $journal): string
    {
        $prefix = $journal.date('Y');

        $last = $this->app->db()->scalar(
            'SELECT MAX(reference) FROM ledger_entries
             WHERE tenant_id = :tenant AND reference LIKE :prefix',
            ['tenant' => $this->app->tenant()->requireId(), 'prefix' => $prefix.'%']
        );

        $sequence = $last === null ? 0 : (int) substr((string) $last, strlen($prefix));

        return $prefix.str_pad((string) ($sequence + 1), 5, '0', STR_PAD_LEFT);
    }
}
