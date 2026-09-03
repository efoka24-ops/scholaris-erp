<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Validator;

/**
 * Module 24 : stocks et magasin.
 *
 * Le solde affiche est une colonne tenue a jour, pas une somme recalculee :
 * une ecole qui compte quelques milliers de mouvements par an ne peut pas
 * refaire l'addition a chaque affichage sur une connexion lente. En
 * contrepartie, toute variation passe obligatoirement par un mouvement
 * enregistre, dans la meme transaction que la mise a jour du solde. Le
 * journal reste donc la source de verite : si le solde derive, il est
 * recalculable.
 */
final class StockController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('q');
        $items = $this->table('stock_items')->orderBy('name');

        if ($search !== '') {
            $items->search($search, ['name', 'code', 'category']);
        }

        $items = $items->limit(200)->get();

        // Les articles sous le seuil sont remontes a part : c'est la seule
        // information du module qui appelle une action immediate.
        $alerts = array_values(array_filter(
            $items,
            static fn (array $i): bool => (float) $i['quantity'] <= (float) $i['min_quantity']
        ));

        $movements = $this->app->db()->select(
            'SELECT m.*, i.name, i.code, i.unit
             FROM stock_movements m
             INNER JOIN stock_items i ON i.id = m.item_id
             WHERE m.tenant_id = :tenant
             ORDER BY m.moved_on DESC, m.created_at DESC
             LIMIT 60',
            ['tenant' => $this->app->tenant()->requireId()]
        );

        $value = 0.0;

        foreach ($items as $item) {
            $value += (float) $item['quantity'] * (float) $item['unit_cost'];
        }

        return $this->view('stock.index', [
            'items' => $items,
            'alerts' => $alerts,
            'movements' => $movements,
            'totalValue' => $value,
            'search' => $search,
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function storeItem(Request $request): Response
    {
        $validator = (new Validator($request))
            ->required('code', 'code article')
            ->required('name', 'designation')
            ->optional('category')
            ->optional('location')
            ->required('unit', 'unite')
            ->decimal('min_quantity', 'seuil d alerte')
            ->decimal('unit_cost', 'cout unitaire');

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/stocks', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['code', 'name', 'category', 'location', 'unit', 'min_quantity', 'unit_cost']);

        if ($this->table('stock_items')->where('code', (string) $data['code'])->exists()) {
            return $this->redirectWithError('/stocks', 'Ce code article est deja utilise.');
        }

        // Un article nait a zero : la quantite initiale se saisit comme une
        // entree, pour qu'elle apparaisse dans le journal des mouvements.
        $data['quantity'] = 0;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];

        $this->table('stock_items')->insert($data);

        return $this->redirectWithSuccess('/stocks', 'Article cree. Enregistrez une entree pour constituer le stock.');
    }

    /**
     * Enregistre une entree, une sortie ou un ajustement d'inventaire.
     *
     * Le solde ne peut pas devenir negatif : un stock negatif est toujours une
     * erreur de saisie, jamais un etat reel, et l'accepter reviendrait a
     * masquer le probleme au lieu de le signaler.
     */
    public function move(Request $request): Response
    {
        $item = $this->findOrFail('stock_items', (string) $request->attribute('id'));

        $validator = (new Validator($request))
            ->in('direction', 'sens du mouvement', ['ENTREE', 'SORTIE', 'AJUSTEMENT'])
            ->decimal('quantity', 'quantite', 0.01)
            ->optional('reason')
            ->optional('reference');

        if ($validator->fails()) {
            return $this->redirectWithError('/stocks', implode(' ', $validator->errors()));
        }

        $data = $validator->validated();
        $direction = (string) $data['direction'];
        $quantity = (float) $data['quantity'];
        $current = (float) $item['quantity'];

        // L'ajustement pose le stock a la valeur comptee, il ne l'additionne
        // pas : c'est le geste de l'inventaire physique.
        $balance = match ($direction) {
            'ENTREE' => $current + $quantity,
            'SORTIE' => $current - $quantity,
            default => $quantity,
        };

        if ($balance < 0) {
            return $this->redirectWithError(
                '/stocks',
                sprintf(
                    'Sortie impossible : le stock de %s est de %s %s.',
                    (string) $item['name'],
                    rtrim(rtrim(number_format($current, 2, ',', ' '), '0'), ','),
                    (string) $item['unit']
                )
            );
        }

        $now = date('Y-m-d H:i:s');

        $this->app->db()->transaction(function () use ($item, $data, $direction, $quantity, $balance, $now): void {
            $this->table('stock_movements')->insert([
                'item_id' => $item['id'],
                'direction' => $direction,
                'quantity' => $quantity,
                'balance_after' => $balance,
                'reason' => $data['reason'] ?? null,
                'reference' => $data['reference'] ?? null,
                'moved_on' => date('Y-m-d'),
                'created_by' => $this->currentUserId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->table('stock_items')
                ->where('id', (string) $item['id'])
                ->update(['quantity' => $balance, 'updated_at' => $now]);
        });

        return $this->redirectWithSuccess('/stocks', 'Mouvement enregistre.');
    }

    /** Fiche d'un article : etat courant et historique complet. */
    public function show(Request $request): Response
    {
        $item = $this->findOrFail('stock_items', (string) $request->attribute('id'));

        return $this->view('stock.show', [
            'item' => $item,
            'movements' => $this->table('stock_movements')
                ->where('item_id', (string) $item['id'])
                ->orderBy('moved_on', 'desc')
                ->limit(200)
                ->get(),
        ]);
    }
}
