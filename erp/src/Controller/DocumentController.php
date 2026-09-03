<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Upload;
use Scholaris\Support\Validator;

/**
 * Module 37 : gestion electronique des documents.
 *
 * Les fichiers sont ranges sous storage/documents/{etablissement}/, hors de
 * la racine web. Rien n'y est servi directement par le serveur : tout passe
 * par download(), qui verifie la session, l'etablissement et la permission.
 *
 * C'est le point sensible du module. Un dossier de televersement expose sous
 * public/ est la faille la plus banale d'une application de ce genre : il
 * suffit alors de connaitre — ou de deviner — un nom de fichier pour lire le
 * dossier medical ou l'acte de carriere de n'importe qui.
 */
final class DocumentController extends Controller
{
    private const CATEGORIES = [
        'ADMINISTRATIF' => 'Administratif',
        'ELEVE' => 'Dossier eleve',
        'PERSONNEL' => 'Dossier du personnel',
        'FINANCE' => 'Piece comptable',
        'PEDAGOGIE' => 'Pedagogie',
        'JURIDIQUE' => 'Juridique et conventions',
        'AUTRE' => 'Autre',
    ];

    public function index(Request $request): Response
    {
        $search = $request->string('q');
        $category = $request->string('categorie');

        $documents = $this->table('documents')->notDeleted()->orderBy('created_at', 'desc');

        if ($search !== '') {
            $documents->search($search, ['title', 'description', 'original_name']);
        }

        if (isset(self::CATEGORIES[$category])) {
            $documents->where('category', $category);
        }

        $documents = $documents->limit(200)->get();

        $totalBytes = (int) $this->app->db()->scalar(
            'SELECT COALESCE(SUM(size_bytes), 0) FROM documents
             WHERE tenant_id = :tenant AND deleted_at IS NULL',
            ['tenant' => $this->app->tenant()->requireId()]
        );

        return $this->view('documents.index', [
            'documents' => $documents,
            'categories' => self::CATEGORIES,
            'category' => $category,
            'search' => $search,
            'totalBytes' => $totalBytes,
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function store(Request $request): Response
    {
        $validator = (new Validator($request))
            ->required('title', 'intitule')
            ->in('category', 'categorie', array_keys(self::CATEGORIES))
            ->in('visibility', 'visibilite', ['INTERNE', 'RESTREINT'])
            ->optional('description');

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/documents', implode(' ', $validator->errors()));
        }

        $upload = Upload::fromGlobals('file');

        if ($upload === null) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/documents', 'Aucun fichier recu. Verifiez sa taille et reessayez.');
        }

        $rejection = $upload->rejectionReason();

        if ($rejection !== null) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/documents', $rejection);
        }

        $data = $validator->only(['title', 'category', 'visibility', 'description']);
        $storedName = $upload->storedName();
        $now = date('Y-m-d H:i:s');

        $checksum = $upload->moveTo($this->pathFor($storedName));

        $id = $this->table('documents')->insert([
            'title' => $data['title'],
            'category' => $data['category'],
            'visibility' => $data['visibility'],
            'description' => $data['description'] ?? null,
            'original_name' => $upload->originalName(),
            'stored_name' => $storedName,
            'mime_type' => $upload->detectedType(),
            'size_bytes' => $upload->size(),
            'checksum' => $checksum,
            'uploaded_by' => $this->currentUserId(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->trail()->created('DOCUMENT_DEPOSE', 'documents', $id, [
            'title' => $data['title'],
            'original_name' => $upload->originalName(),
        ]);

        return $this->redirectWithSuccess('/documents', 'Document depose.');
    }

    /**
     * Sert un document.
     *
     * findOrFail passe par Table, donc filtre sur l'etablissement : demander
     * l'identifiant d'une piece d'une autre ecole donne un 404, pas le
     * fichier.
     */
    public function download(Request $request): Response
    {
        $document = $this->findOrFail('documents', (string) $request->attribute('id'));

        if ($document['deleted_at'] !== null) {
            throw new HttpException(404);
        }

        $path = $this->pathFor((string) $document['stored_name']);

        if (! is_readable($path)) {
            // La ligne existe mais le fichier a disparu du disque : c'est un
            // incident de stockage, pas une erreur de l'utilisateur, et il
            // doit rester visible dans le journal.
            $this->trail()->recorded('DOCUMENT_INTROUVABLE', 'documents', (string) $document['id']);

            return $this->redirectWithError('/documents', 'Le fichier est introuvable sur le serveur.');
        }

        $this->trail()->recorded('DOCUMENT_CONSULTE', 'documents', (string) $document['id']);

        return Response::download(
            (string) file_get_contents($path),
            (string) $document['original_name'],
            (string) $document['mime_type']
        );
    }

    /**
     * Retire un document.
     *
     * La ligne est marquee supprimee et le fichier reste sur le disque. Une
     * piece justificative effacee par megarde doit pouvoir etre reprise, et
     * la trace de son existence a une valeur propre.
     */
    public function destroy(Request $request): Response
    {
        $document = $this->findOrFail('documents', (string) $request->attribute('id'));

        $this->table('documents')
            ->where('id', (string) $document['id'])
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $this->trail()->deleted('DOCUMENT_RETIRE', 'documents', (string) $document['id'], [
            'title' => $document['title'],
        ]);

        return $this->redirectWithSuccess('/documents', 'Document retire.');
    }

    /** Chemin de stockage, cloisonne par etablissement. */
    private function pathFor(string $storedName): string
    {
        return $this->app->basePath()
            .'/storage/documents/'
            .$this->app->tenant()->requireId()
            .'/'.$storedName;
    }
}
