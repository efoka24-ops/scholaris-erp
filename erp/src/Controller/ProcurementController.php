<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Validator;

/**
 * Module 23 : achats et fournisseurs.
 *
 * Le circuit d'une commande est volontairement contraint : DEMANDE, puis
 * APPROUVEE ou REFUSEE, puis RECUE. Chaque passage est date et nominatif.
 *
 * La separation entre celui qui demande et celui qui approuve est le seul
 * controle serieux contre l'engagement de depenses non autorisees, et c'est
 * pourquoi l'approbation exige une permission distincte de la creation.
 */
final class ProcurementController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('statut');
        $orders = $this->table('purchase_orders')->orderBy('ordered_on', 'desc');

        if (in_array($status, ['DEMANDE', 'APPROUVEE', 'REFUSEE', 'RECUE'], true)) {
            $orders->where('status', $status);
        }

        $orders = $orders->limit(100)->get();
        $suppliers = $this->table('suppliers')->orderBy('name')->get();

        // Index des fournisseurs : evite une requete par ligne a l'affichage.
        $supplierNames = [];

        foreach ($suppliers as $supplier) {
            $supplierNames[(string) $supplier['id']] = (string) $supplier['name'];
        }

        return $this->view('procurement.index', [
            'orders' => $orders,
            'suppliers' => $suppliers,
            'supplierNames' => $supplierNames,
            'items' => $this->table('stock_items')->orderBy('name')->get(),
            'status' => $status,
            'counts' => $this->counts(),
            'pendingAmount' => $this->pendingAmount(),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $rows = $this->app->db()->select(
            'SELECT status, COUNT(*) AS total FROM purchase_orders
             WHERE tenant_id = :tenant GROUP BY status',
            ['tenant' => $this->app->tenant()->requireId()]
        );

        $counts = ['DEMANDE' => 0, 'APPROUVEE' => 0, 'REFUSEE' => 0, 'RECUE' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /** Montant engage mais pas encore recu : ce qui pese sur la tresorerie. */
    private function pendingAmount(): float
    {
        return (float) $this->app->db()->scalar(
            'SELECT COALESCE(SUM(total_amount), 0) FROM purchase_orders
             WHERE tenant_id = :tenant AND status = :status',
            ['tenant' => $this->app->tenant()->requireId(), 'status' => 'APPROUVEE']
        );
    }

    public function storeSupplier(Request $request): Response
    {
        $validator = (new Validator($request))
            ->required('name', 'raison sociale')
            ->optional('contact_name')
            ->optional('phone')
            ->optional('address')
            ->optional('tax_number')
            ->email('email', 'adresse electronique');

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/achats', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['name', 'contact_name', 'phone', 'email', 'address', 'tax_number']);
        $data['status'] = 'ACTIVE';
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];

        $this->table('suppliers')->insert($data);

        return $this->redirectWithSuccess('/achats', 'Fournisseur enregistre.');
    }

    /**
     * Cree une demande d'achat.
     *
     * Le total est calcule ici a partir des lignes, jamais repris du
     * formulaire : un montant envoye par le navigateur ne doit pas pouvoir
     * differer de la somme des articles commandes.
     */
    public function storeOrder(Request $request): Response
    {
        $supplierIds = array_map(
            static fn (array $s): string => (string) $s['id'],
            $this->table('suppliers')->select(['id'])->get()
        );

        $validator = (new Validator($request))
            ->required('subject', 'objet de la commande')
            ->date('ordered_on', 'date')
            ->date('expected_on', 'date de livraison souhaitee', false)
            ->in('supplier_id', 'fournisseur', $supplierIds, false);

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/achats', implode(' ', $validator->errors()));
        }

        $lines = $this->readLines($request);

        if ($lines === []) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/achats', 'Une commande demande au moins un article.');
        }

        $total = 0.0;

        foreach ($lines as $line) {
            $total += $line['quantity'] * $line['unit_price'];
        }

        $data = $validator->only(['subject', 'ordered_on', 'expected_on', 'supplier_id']);
        $now = date('Y-m-d H:i:s');

        $this->app->db()->transaction(function () use ($data, $lines, $total, $now): void {
            $orderId = $this->table('purchase_orders')->insert([
                'reference' => $this->nextReference(),
                'supplier_id' => $data['supplier_id'] ?? null,
                'subject' => $data['subject'],
                'status' => 'DEMANDE',
                'ordered_on' => $data['ordered_on'],
                'expected_on' => $data['expected_on'] ?? null,
                'total_amount' => $total,
                'requested_by' => $this->currentUserId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($lines as $line) {
                $this->table('purchase_order_lines')->insert([
                    'order_id' => $orderId,
                    'item_id' => $line['item_id'],
                    'label' => $line['label'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return $this->redirectWithSuccess('/achats', 'Demande d achat enregistree.');
    }

    /**
     * @return list<array{item_id: ?string, label: string, quantity: float, unit_price: float}>
     */
    private function readLines(Request $request): array
    {
        $raw = $request->all()['lines'] ?? null;
        $known = array_map(
            static fn (array $i): string => (string) $i['id'],
            $this->table('stock_items')->select(['id'])->get()
        );

        if (! is_array($raw)) {
            return [];
        }

        $lines = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $quantity = (float) str_replace([' ', ','], ['', '.'], (string) ($row['quantity'] ?? '0'));
            $price = (float) str_replace([' ', ','], ['', '.'], (string) ($row['unit_price'] ?? '0'));
            $itemId = (string) ($row['item_id'] ?? '');

            if ($label === '' || $quantity <= 0) {
                continue;
            }

            $lines[] = [
                // Un article inconnu est traite comme une ligne libre plutot
                // que comme une erreur : on commande souvent hors catalogue.
                'item_id' => in_array($itemId, $known, true) ? $itemId : null,
                'label' => mb_substr($label, 0, 255),
                'quantity' => $quantity,
                'unit_price' => max(0.0, $price),
            ];
        }

        return $lines;
    }

    public function show(Request $request): Response
    {
        $order = $this->findOrFail('purchase_orders', (string) $request->attribute('id'));

        $supplier = $order['supplier_id'] === null
            ? null
            : $this->table('suppliers')->find((string) $order['supplier_id']);

        return $this->view('procurement.show', [
            'order' => $order,
            'supplier' => $supplier,
            'lines' => $this->table('purchase_order_lines')
                ->where('order_id', (string) $order['id'])
                ->get(),
        ]);
    }

    /** Approuve ou refuse une demande. */
    public function decide(Request $request): Response
    {
        $order = $this->findOrFail('purchase_orders', (string) $request->attribute('id'));

        if ((string) $order['status'] !== 'DEMANDE') {
            return $this->redirectWithError('/achats', 'Cette commande a deja ete traitee.');
        }

        $validator = (new Validator($request))
            ->in('decision', 'decision', ['APPROUVEE', 'REFUSEE'])
            ->optional('decision_note');

        if ($validator->fails()) {
            return $this->redirectWithError('/achats', implode(' ', $validator->errors()));
        }

        $decision = (string) $validator->validated()['decision'];
        $note = (string) ($validator->validated()['decision_note'] ?? '');

        // Un refus sans motif est ininterpretable trois mois plus tard, quand
        // il faut expliquer pourquoi la depense n'a pas ete engagee.
        if ($decision === 'REFUSEE' && trim($note) === '') {
            return $this->redirectWithError('/achats', 'Un refus doit etre motive.');
        }

        $now = date('Y-m-d H:i:s');

        $this->table('purchase_orders')
            ->where('id', (string) $order['id'])
            ->update([
                'status' => $decision,
                'decision_note' => $note === '' ? null : $note,
                'approved_by' => $this->currentUserId(),
                'approved_at' => $now,
                'updated_at' => $now,
            ]);

        $this->trail()->changed(
            $decision === 'APPROUVEE' ? 'ACHAT_APPROUVE' : 'ACHAT_REFUSE',
            'purchase_orders',
            (string) $order['id'],
            ['status' => $order['status']],
            ['status' => $decision]
        );

        return $this->redirectWithSuccess(
            '/achats/'.$order['id'],
            $decision === 'APPROUVEE' ? 'Commande approuvee.' : 'Commande refusee.'
        );
    }

    /**
     * Enregistre la reception : les articles rattaches au catalogue entrent
     * automatiquement en stock.
     *
     * C'est le point ou les modules 23 et 24 se rejoignent. Le faire a la main
     * garantirait qu'un magasinier presse l'oublie, et le stock cesserait de
     * refleter la realite des le premier oubli.
     */
    public function receive(Request $request): Response
    {
        $order = $this->findOrFail('purchase_orders', (string) $request->attribute('id'));

        if ((string) $order['status'] !== 'APPROUVEE') {
            return $this->redirectWithError(
                '/achats/'.$order['id'],
                'Seule une commande approuvee peut etre receptionnee.'
            );
        }

        $lines = $this->table('purchase_order_lines')
            ->where('order_id', (string) $order['id'])
            ->get();

        $now = date('Y-m-d H:i:s');
        $entered = 0;

        $this->app->db()->transaction(function () use ($order, $lines, $now, &$entered): void {
            foreach ($lines as $line) {
                if ($line['item_id'] === null) {
                    continue;
                }

                $item = $this->table('stock_items')->find((string) $line['item_id']);

                if ($item === null) {
                    continue;
                }

                $balance = (float) $item['quantity'] + (float) $line['quantity'];

                $this->table('stock_movements')->insert([
                    'item_id' => $item['id'],
                    'direction' => 'ENTREE',
                    'quantity' => $line['quantity'],
                    'balance_after' => $balance,
                    'reason' => 'Reception commande '.$order['reference'],
                    'reference' => $order['reference'],
                    'moved_on' => date('Y-m-d'),
                    'created_by' => $this->currentUserId(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->table('stock_items')
                    ->where('id', (string) $item['id'])
                    ->update([
                        'quantity' => $balance,
                        // Le cout unitaire suit le dernier prix paye : sans
                        // cela, la valorisation du stock reste figee au prix
                        // de la premiere commande.
                        'unit_cost' => $line['unit_price'],
                        'updated_at' => $now,
                    ]);

                $entered++;
            }

            $this->table('purchase_orders')
                ->where('id', (string) $order['id'])
                ->update(['status' => 'RECUE', 'received_at' => $now, 'updated_at' => $now]);
        });

        return $this->redirectWithSuccess(
            '/achats/'.$order['id'],
            $entered === 0
                ? 'Reception enregistree. Aucun article du catalogue n etait rattache : le stock est inchange.'
                : 'Reception enregistree. '.$entered.' article(s) entres en stock.'
        );
    }

    /** Numero de commande, continu par annee civile. */
    private function nextReference(): string
    {
        $prefix = 'BC'.date('Y');

        $last = $this->app->db()->scalar(
            'SELECT MAX(reference) FROM purchase_orders
             WHERE tenant_id = :tenant AND reference LIKE :prefix',
            ['tenant' => $this->app->tenant()->requireId(), 'prefix' => $prefix.'%']
        );

        $sequence = $last === null ? 0 : (int) substr((string) $last, strlen($prefix));

        return $prefix.str_pad((string) ($sequence + 1), 4, '0', STR_PAD_LEFT);
    }
}
