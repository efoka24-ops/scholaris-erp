<?php

declare(strict_types=1);

namespace Scholaris\Notification;

use Scholaris\Database\Connection;

/**
 * Modeles de communication systeme (tenant_id NULL), editables par le Super
 * Admin depuis /communication.
 *
 * Chaque courrier de la plateforme a un texte code en dur, qui sert de repli :
 * tant qu'aucun modele systeme n'est enregistre pour son code, c'est ce texte
 * qui part. Des qu'un modele existe, il prend le relais, variables remplacees
 * par simple str_replace sur des marqueurs {{comme_ceci}}. Rien ne casse si la
 * table est absente ou vide : c'est le sens meme du repli.
 */
final class SystemTemplates
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{0: string, 1: string} [sujet, corps]
     */
    public function render(string $code, array $variables, string $fallbackSubject, string $fallbackBody): array
    {
        $subject = $fallbackSubject;
        $body = $fallbackBody;

        try {
            $row = $this->db->selectOne(
                'SELECT subject_fr, body_fr FROM communication_templates
                 WHERE tenant_id IS NULL AND code = :code LIMIT 1',
                ['code' => $code]
            );
        } catch (\Throwable $e) {
            // Table absente (migration pas encore jouee) : le repli suffit,
            // l'envoi ne doit jamais en dependre.
            $row = null;
        }

        if ($row !== null && is_string($row['body_fr'] ?? null) && $row['body_fr'] !== '') {
            $subject = is_string($row['subject_fr'] ?? null) && $row['subject_fr'] !== '' ? $row['subject_fr'] : $fallbackSubject;
            $body = $row['body_fr'];
        }

        foreach ($variables as $key => $value) {
            $subject = str_replace('{{'.$key.'}}', $value, $subject);
            $body = str_replace('{{'.$key.'}}', $value, $body);
        }

        return [$subject, $body];
    }
}
