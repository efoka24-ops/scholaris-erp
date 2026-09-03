<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Validator;

/**
 * Module 8 : communication multicanal.
 *
 * Deux voies distinctes : les messages sortants (email, SMS, WhatsApp, push),
 * mis en file et remis a un prestataire, et la messagerie interne, qui reste
 * dans l'application.
 *
 * Aucun prestataire n'est branche a ce stade : les messages sortants sont
 * enregistres en attente. C'est volontaire — mieux vaut une file visible
 * qu'un envoi silencieusement perdu.
 */
final class CommunicationController extends Controller
{
    private const CHANNELS = ['EMAIL', 'SMS', 'WHATSAPP', 'PUSH', 'INTERNAL'];

    public function index(Request $request): Response
    {
        $messages = $this->app->db()->select(
            'SELECT m.*, u.first_name, u.last_name, u.email
             FROM communication_messages m
             INNER JOIN users u ON u.id = m.recipient_user_id
             WHERE m.tenant_id = :tenant
             ORDER BY m.created_at DESC
             LIMIT 50',
            ['tenant' => $this->app->tenant()->requireId()]
        );

        $isSuperAdmin = $this->app->rbac()->isSuperAdmin();

        return $this->view('communication.index', [
            'templates' => $this->table('communication_templates')->orderBy('name')->get(),
            // Les modeles systeme (tenant_id NULL) servent de repli a tous les
            // etablissements : seul le Super Admin doit pouvoir les consulter
            // et les modifier ici, jamais un administrateur d'ecole.
            'systemTemplates' => $isSuperAdmin
                ? $this->app->db()->select(
                    'SELECT * FROM communication_templates WHERE tenant_id IS NULL ORDER BY name'
                )
                : [],
            'isSuperAdmin' => $isSuperAdmin,
            'messages' => $messages,
            'pending' => count(array_filter($messages, static fn (array $m): bool => $m['status'] === 'PENDING')),
            'channels' => self::CHANNELS,
            'recipients' => $this->table('users')
                ->select(['id', 'first_name', 'last_name', 'email'])
                ->notDeleted()
                ->where('status', 'ACTIVE')
                ->orderBy('last_name')
                ->get(),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function storeTemplate(Request $request): Response
    {
        $validator = (new Validator($request))
            ->required('code', 'code')
            ->required('name', 'nom')
            ->in('channel', 'canal', self::CHANNELS)
            ->required('body_fr', 'message en francais')
            ->optional('subject_fr')
            ->optional('subject_en')
            ->optional('body_en');

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/communication', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['code', 'name', 'channel', 'body_fr', 'subject_fr', 'subject_en', 'body_en']);

        if ($this->table('communication_templates')->where('code', (string) $data['code'])->exists()) {
            return $this->redirectWithError('/communication', 'Ce code de modele est deja utilise.');
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];

        $this->table('communication_templates')->insert($data);

        return $this->redirectWithSuccess('/communication', 'Modele enregistre.');
    }

    /**
     * Cree ou met a jour un modele systeme (tenant_id NULL), reserve au Super
     * Admin. C'est ce modele que SystemTemplates::render() sert de repli a
     * tous les etablissements tant qu'aucun modele local ne le remplace.
     */
    public function storeSystemTemplate(Request $request): Response
    {
        if (! $this->app->rbac()->isSuperAdmin()) {
            return $this->redirectWithError('/communication', 'Reserve au Super Admin.');
        }

        $validator = (new Validator($request))
            ->required('code', 'code')
            ->required('name', 'nom')
            ->in('channel', 'canal', self::CHANNELS)
            ->required('body_fr', 'message en francais')
            ->optional('subject_fr')
            ->optional('subject_en')
            ->optional('body_en');

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/communication', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['code', 'name', 'channel', 'body_fr', 'subject_fr', 'subject_en', 'body_en']);
        $now = date('Y-m-d H:i:s');

        $existing = $this->app->db()->selectOne(
            'SELECT id FROM communication_templates WHERE tenant_id IS NULL AND code = :code',
            ['code' => $data['code']]
        );

        if ($existing !== null) {
            $this->app->db()->execute(
                'UPDATE communication_templates
                 SET name = :name, channel = :channel, subject_fr = :subject_fr, subject_en = :subject_en,
                     body_fr = :body_fr, body_en = :body_en, updated_at = :updated_at
                 WHERE id = :id',
                [
                    'name' => $data['name'],
                    'channel' => $data['channel'],
                    'subject_fr' => $data['subject_fr'],
                    'subject_en' => $data['subject_en'],
                    'body_fr' => $data['body_fr'],
                    'body_en' => $data['body_en'],
                    'updated_at' => $now,
                    'id' => $existing['id'],
                ]
            );

            return $this->redirectWithSuccess('/communication', 'Modele systeme mis a jour.');
        }

        $this->app->db()->execute(
            'INSERT INTO communication_templates
                (id, tenant_id, code, name, channel, subject_fr, subject_en, body_fr, body_en, created_at, updated_at)
             VALUES (:id, NULL, :code, :name, :channel, :subject_fr, :subject_en, :body_fr, :body_en, :created_at, :updated_at)',
            [
                'id' => \Scholaris\Database\Table::uuid(),
                'code' => $data['code'],
                'name' => $data['name'],
                'channel' => $data['channel'],
                'subject_fr' => $data['subject_fr'],
                'subject_en' => $data['subject_en'],
                'body_fr' => $data['body_fr'],
                'body_en' => $data['body_en'],
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $this->redirectWithSuccess('/communication', 'Modele systeme cree.');
    }

    /**
     * Met un message en file d'envoi.
     *
     * Le statut reste PENDING tant qu'aucun prestataire n'a confirme la remise :
     * marquer "envoye" sans preuve donnerait une fausse assurance a
     * l'etablissement.
     */
    public function send(Request $request): Response
    {
        $recipientIds = array_map(
            static fn (array $u): string => (string) $u['id'],
            $this->table('users')->select(['id'])->notDeleted()->get()
        );

        $validator = (new Validator($request))
            ->in('recipient_user_id', 'destinataire', $recipientIds)
            ->in('channel', 'canal', self::CHANNELS)
            ->required('body', 'message')
            ->optional('subject');

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/communication', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['recipient_user_id', 'channel', 'body', 'subject']);

        // Un message interne est remis immediatement : il ne depend d'aucun
        // prestataire externe.
        if ($data['channel'] === 'INTERNAL') {
            $this->table('internal_messages')->insert([
                'sender_user_id' => $this->currentUserId(),
                'recipient_user_id' => $data['recipient_user_id'],
                'body' => $data['body'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return $this->redirectWithSuccess('/communication', 'Message interne remis.');
        }

        $data['status'] = 'PENDING';
        $data['created_at'] = date('Y-m-d H:i:s');

        $this->table('communication_messages')->insert($data);

        return $this->redirectWithSuccess(
            '/communication',
            'Message mis en file. Il partira des qu un prestataire d envoi sera configure.'
        );
    }

    /**
     * Messagerie interne de l'utilisateur connecte.
     */
    public function inbox(Request $request): Response
    {
        $userId = $this->currentUserId();

        $received = $this->app->db()->select(
            'SELECT m.*, u.first_name, u.last_name
             FROM internal_messages m
             INNER JOIN users u ON u.id = m.sender_user_id
             WHERE m.tenant_id = :tenant AND m.recipient_user_id = :user
             ORDER BY m.created_at DESC LIMIT 50',
            ['tenant' => $this->app->tenant()->requireId(), 'user' => $userId]
        );

        // La consultation vaut lecture : marquer lu ici evite un bouton de plus.
        $this->table('internal_messages')
            ->where('recipient_user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => date('Y-m-d H:i:s')]);

        return $this->view('communication.inbox', [
            'received' => $received,
            'sent' => $this->app->db()->select(
                'SELECT m.*, u.first_name, u.last_name
                 FROM internal_messages m
                 INNER JOIN users u ON u.id = m.recipient_user_id
                 WHERE m.tenant_id = :tenant AND m.sender_user_id = :user
                 ORDER BY m.created_at DESC LIMIT 50',
                ['tenant' => $this->app->tenant()->requireId(), 'user' => $userId]
            ),
        ]);
    }
}
