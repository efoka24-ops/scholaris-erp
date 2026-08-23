<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Application;
use Scholaris\Database\Table;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Offline\OperationLog;

/**
 * Saisie differee en zone sans reseau.
 *
 * La promesse faite a l'enseignant est double, et les deux moities comptent
 * autant : ce qu'il saisit hors-ligne finit par arriver, et n'arrive qu'une
 * fois. Un appel envoye deux fois au retour de la connexion creerait des
 * absences fantomes ; une note envoyee deux fois fausserait la moyenne.
 *
 * En bordure de couverture, une requete atteint regulierement le serveur sans
 * que la reponse revienne. Le client, croyant avoir echoue, reessaie. C'est ce
 * cas precis que ces tests couvrent.
 */
final class OfflineSyncTest extends TestCase
{
    /** Compteur d'executions de la route de test. */
    private int $executions = 0;

    /**
     * Route d'essai qui compte ses executions.
     *
     * Les ecrans reels (appel, notes) ont chacun leur logique ; ce qui est
     * verifie ici est le garde-fou transverse, en amont d'eux tous.
     */
    private function registerCountingRoute(): void
    {
        $this->executions = 0;

        $this->app->router()->guest(
            'POST',
            '/essai-hors-ligne',
            function (Request $request, Application $app): Response {
                $this->executions++;

                return Response::redirect('/attendance');
            }
        );
    }

    public function testUneOperationRejoueeNEstAppliqueeQuUneFois(): void
    {
        $this->registerCountingRoute();

        $token = '11111111-2222-4333-8444-555555555555';

        $first = $this->request('POST', '/essai-hors-ligne', ['_op' => $token]);
        $second = $this->request('POST', '/essai-hors-ligne', ['_op' => $token]);

        $this->assertSame(1, $this->executions, 'Le meme jeton ne doit declencher qu une seule ecriture');

        // Le second appel doit tout de meme aboutir : du point de vue de
        // l'appareil, l'operation est passee. Lui repondre en erreur le
        // pousserait a reessayer sans fin.
        $this->assertSame(302, $second->status(), 'Le rejeu recoit la meme redirection');
        $this->assertSame(
            $first->header('Location'),
            $second->header('Location'),
            'Et la meme destination qu a la premiere tentative'
        );
    }

    public function testDeuxSaisiesDistinctesSontToutesLesDeuxAppliquees(): void
    {
        $this->registerCountingRoute();

        $this->request('POST', '/essai-hors-ligne', ['_op' => '11111111-2222-4333-8444-555555555555']);
        $this->request('POST', '/essai-hors-ligne', ['_op' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']);

        $this->assertSame(2, $this->executions, 'Deux jetons distincts sont deux operations distinctes');
    }

    public function testUneSaisieSansJetonResteUneSaisieOrdinaire(): void
    {
        // Le formulaire envoye en ligne par un navigateur sans JavaScript n'a
        // pas de jeton : il doit fonctionner comme avant.
        $this->registerCountingRoute();

        $this->request('POST', '/essai-hors-ligne');
        $this->request('POST', '/essai-hors-ligne');

        $this->assertSame(2, $this->executions, 'Sans jeton, chaque envoi est une operation a part entiere');
    }

    public function testUnEchecResteRejouable(): void
    {
        // Journaliser une operation qui a echoue rendrait la perte definitive :
        // l'appareil renverrait le meme jeton, et le serveur repondrait « deja
        // fait » alors que rien n'a ete enregistre.
        $attempts = 0;

        $this->app->router()->guest(
            'POST',
            '/essai-echec',
            function (Request $request, Application $app) use (&$attempts): Response {
                $attempts++;

                return Response::html('Indisponible', 503);
            }
        );

        $token = '99999999-8888-4777-8666-555555555555';

        $this->request('POST', '/essai-echec', ['_op' => $token]);
        $this->request('POST', '/essai-echec', ['_op' => $token]);

        $this->assertSame(2, $attempts, 'Une operation qui a echoue doit pouvoir etre rejouee');
        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM sync_operations'),
            'Et ne doit pas figurer au journal'
        );
    }

    public function testUnJetonMalFormeEstIgnore(): void
    {
        // Sans controle de forme, n'importe qui pourrait remplir la table en
        // envoyant des jetons arbitraires. Un jeton non conforme est traite
        // comme absent : l'operation s'execute, mais rien n'est journalise.
        $this->registerCountingRoute();

        $this->request('POST', '/essai-hors-ligne', ['_op' => str_repeat('x', 200)]);

        $this->assertSame(1, $this->executions, 'L operation reste executee');
        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM sync_operations'),
            'Mais un jeton non conforme n entre pas au journal'
        );

        $this->assertTrue(
            ! OperationLog::isWellFormed('pas-un-uuid'),
            'La forme attendue est celle d un identifiant genere par le client'
        );
    }

    public function testLeJournalOublieLesOperationsAnciennes(): void
    {
        // Un rejeu plus d'un mois apres la saisie n'est pas plausible ; garder
        // la trace indefiniment ferait grossir la table sans fin.
        $log = new OperationLog($this->db);

        $this->db->execute(
            'INSERT INTO sync_operations (token, tenant_id, user_id, path, redirect_to, applied_at)
             VALUES (:token, :tenant, :user, :path, :redirect, :applied_at)',
            [
                'token' => '12345678-1234-4234-8234-123456789012',
                'tenant' => $this->tenantA,
                'user' => null,
                'path' => '/attendance',
                'redirect' => '/attendance',
                'applied_at' => date('Y-m-d H:i:s', time() - 90 * 86400),
            ]
        );

        $log->prune();

        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM sync_operations'),
            'Les operations trop anciennes sont oubliees'
        );
    }

    public function testLeJournalRetientLEtablissementEtLAuteur(): void
    {
        // Necessaire pour instruire une contestation : qui a saisi quoi, et
        // depuis quel etablissement.
        $userId = $this->createUser($this->tenantA, 'prof@a.cm');
        $this->giveRole($userId, 'ENSEIGNANT', ['attendance:create']);
        $this->actingAs($userId);

        $this->app->router()->post(
            '/essai-trace',
            static fn (Request $request, Application $app): Response => Response::redirect('/attendance'),
            'attendance:create'
        );

        $token = 'abcdefab-cdef-4abc-8def-abcdefabcdef';
        $this->request('POST', '/essai-trace', ['_op' => $token]);

        $row = $this->db->selectOne('SELECT * FROM sync_operations WHERE token = :token', ['token' => $token]);

        $this->assertTrue($row !== null, 'L operation est journalisee');
        $this->assertSame($this->tenantA, (string) $row['tenant_id'], 'Avec son etablissement');
        $this->assertSame($userId, (string) $row['user_id'], 'Et son auteur');
    }

    /** Le hors-ligne ne doit pas s appliquer aux ecritures qui engagent de l argent. */
    public function testLaFileNAcceptePasLesPaiements(): void
    {
        $script = (string) file_get_contents($this->basePath().'/public/assets/offline.js');

        $this->assertTrue(
            ! str_contains($script, '/finance') && ! str_contains($script, '/paiement'),
            'Un paiement doit etre confirme par le serveur, jamais mis en file'
        );

        $this->assertTrue(
            str_contains($script, "/^\\/attendance/"),
            'L appel, lui, doit pouvoir se faire sans reseau'
        );
    }

    public function testLaPageDeRepliEstPrechargeeParLeServiceWorker(): void
    {
        // Sans elle en cache, un appareil hors-ligne tombant sur une page
        // jamais visitee n'afficherait rien du tout.
        $sw = (string) file_get_contents($this->basePath().'/public/sw.js');

        $this->assertTrue(str_contains($sw, "'/hors-ligne'"), 'La page de repli est prechargee');
        $this->assertTrue(str_contains($sw, "'/assets/app.css'"), 'La feuille de style aussi');
        $this->assertTrue(
            str_contains($sw, "url.pathname === '/login'"),
            'La page de connexion ne doit jamais etre mise en cache'
        );
    }
}
