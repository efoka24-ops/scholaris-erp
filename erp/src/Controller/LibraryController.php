<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Validator;

/**
 * Module 14 : bibliotheque.
 *
 * Le nombre d'exemplaires disponibles est tenu a jour a chaque emprunt et
 * chaque retour, dans la meme transaction : sans cela, deux emprunts
 * simultanes du dernier exemplaire passeraient tous les deux.
 */
final class LibraryController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('q');

        $books = $this->table('library_books')->orderBy('title');

        if ($search !== '') {
            $books->search($search, ['title', 'author', 'isbn', 'category']);
        }

        $borrows = $this->app->db()->select(
            'SELECT b.*, k.title, s.matricule, s.first_name, s.last_name
             FROM library_borrows b
             INNER JOIN library_books k ON k.id = b.book_id
             INNER JOIN students s ON s.id = b.student_id
             WHERE b.tenant_id = :tenant AND b.return_date IS NULL
             ORDER BY b.due_date',
            ['tenant' => $this->app->tenant()->requireId()]
        );

        $today = date('Y-m-d');

        return $this->view('library.index', [
            'books' => $books->limit(100)->get(),
            'borrows' => $borrows,
            'overdue' => count(array_filter(
                $borrows,
                static fn (array $b): bool => (string) $b['due_date'] < $today
            )),
            'search' => $search,
            'students' => $this->table('students')
                ->select(['id', 'matricule', 'first_name', 'last_name'])
                ->notDeleted()
                ->where('status', 'ACTIVE')
                ->orderBy('last_name')
                ->get(),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function storeBook(Request $request): Response
    {
        $validator = (new Validator($request))
            ->required('title', 'titre')
            ->optional('author')
            ->optional('isbn')
            ->optional('category')
            ->integer('quantity', 'nombre d exemplaires', 1, 999);

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/library', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['title', 'author', 'isbn', 'category', 'quantity']);
        $data['available'] = $data['quantity'];
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];

        $this->table('library_books')->insert($data);

        return $this->redirectWithSuccess('/library', 'Ouvrage ajoute au catalogue.');
    }

    public function borrow(Request $request): Response
    {
        $book = $this->findOrFail('library_books', (string) $request->attribute('id'));

        if ((int) $book['available'] < 1) {
            return $this->redirectWithError('/library', 'Aucun exemplaire disponible.');
        }

        $studentIds = array_map(
            static fn (array $s): string => (string) $s['id'],
            $this->table('students')->select(['id'])->notDeleted()->get()
        );

        $validator = (new Validator($request))
            ->in('student_id', 'eleve', $studentIds)
            ->integer('days', 'duree', 1, 90);

        if ($validator->fails()) {
            return $this->redirectWithError('/library', implode(' ', $validator->errors()));
        }

        $data = $validator->validated();
        $now = date('Y-m-d H:i:s');

        $this->app->db()->transaction(function () use ($book, $data, $now): void {
            $this->table('library_borrows')->insert([
                'book_id' => $book['id'],
                'student_id' => $data['student_id'],
                'borrow_date' => date('Y-m-d'),
                'due_date' => date('Y-m-d', strtotime('+'.(int) $data['days'].' days')),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Decrement dans la meme transaction que l'emprunt : deux emprunts
            // simultanes du dernier exemplaire ne peuvent pas passer tous deux.
            $this->table('library_books')
                ->where('id', (string) $book['id'])
                ->update(['available' => (int) $book['available'] - 1, 'updated_at' => $now]);
        });

        return $this->redirectWithSuccess('/library', 'Emprunt enregistre.');
    }

    public function returnBook(Request $request): Response
    {
        $borrow = $this->findOrFail('library_borrows', (string) $request->attribute('id'));

        if ($borrow['return_date'] !== null) {
            return $this->redirectWithError('/library', 'Cet ouvrage a deja ete rendu.');
        }

        $now = date('Y-m-d H:i:s');

        $this->app->db()->transaction(function () use ($borrow, $now): void {
            $this->table('library_borrows')
                ->where('id', (string) $borrow['id'])
                ->update(['return_date' => date('Y-m-d'), 'updated_at' => $now]);

            $book = $this->table('library_books')->find((string) $borrow['book_id']);

            if ($book !== null) {
                // Le disponible ne depasse jamais le stock, meme si un retour
                // etait enregistre deux fois.
                $available = min((int) $book['available'] + 1, (int) $book['quantity']);

                $this->table('library_books')
                    ->where('id', (string) $book['id'])
                    ->update(['available' => $available, 'updated_at' => $now]);
            }
        });

        return $this->redirectWithSuccess('/library', 'Retour enregistre.');
    }
}
