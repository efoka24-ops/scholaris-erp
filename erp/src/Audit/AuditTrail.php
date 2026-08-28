<?php

declare(strict_types=1);

namespace Scholaris\Audit;

use Scholaris\Application;
use Scholaris\Database\Table;
use Throwable;

/**
 * Journal des actes, avec valeur avant et valeur apres.
 *
 * Dans une administration publique, savoir qu'une note a ete modifiee ne sert
 * a rien si l'on ignore ce qu'elle valait. « Note de mathematiques de l'eleve
 * X : 12 vers 14 » se conteste et se verifie ; « note modifiee » ne se conteste
 * pas. Les colonnes existaient depuis l'origine du schema mais n'etaient
 * jamais renseignees.
 *
 * Deux principes.
 *
 * Le journal ne fait jamais echouer l'acte qu'il enregistre. Une base
 * momentanement indisponible ne doit pas empecher un enseignant de saisir ses
 * notes : la trace est importante, la saisie l'est davantage.
 *
 * Seules les differences sont conservees. Enregistrer la ligne entiere a
 * chaque modification rend le journal illisible et masque le seul champ qui a
 * reellement change.
 */
final class AuditTrail
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Enregistre une modification, en ne gardant que ce qui a change.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $watch  champs suivis ; les autres sont ignores
     */
    public function changed(
        string $action,
        string $resource,
        string $resourceId,
        array $before,
        array $after,
        array $watch = []
    ): void {
        $keys = $watch !== [] ? $watch : array_keys($after);
        $oldValues = [];
        $newValues = [];

        foreach ($keys as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            // Comparaison souple : une note relue en base vaut « 14.00 » la ou
            // le formulaire envoie « 14 ». Les signaler comme differentes
            // remplirait le journal de modifications qui n'en sont pas.
            if ($this->same($old, $new)) {
                continue;
            }

            $oldValues[$key] = $old;
            $newValues[$key] = $new;
        }

        if ($newValues === []) {
            return;
        }

        $this->write($action, $resource, $resourceId, $oldValues, $newValues);
    }

    /**
     * Enregistre une creation : il n'y a pas de valeur precedente.
     *
     * @param  array<string, mixed>  $values
     */
    public function created(string $action, string $resource, string $resourceId, array $values = []): void
    {
        $this->write($action, $resource, $resourceId, null, $values === [] ? null : $values);
    }

    /**
     * Enregistre une suppression : c'est l'etat perdu qu'il faut conserver.
     *
     * @param  array<string, mixed>  $values
     */
    public function deleted(string $action, string $resource, string $resourceId, array $values = []): void
    {
        $this->write($action, $resource, $resourceId, $values === [] ? null : $values, null);
    }

    /** Acte sans valeur associee : connexion, consultation, export. */
    public function recorded(string $action, string $resource, string $resourceId = ''): void
    {
        $this->write($action, $resource, $resourceId, null, null);
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function write(
        string $action,
        string $resource,
        string $resourceId,
        ?array $old,
        ?array $new
    ): void {
        try {
            $this->app->tenant()->global(function () use ($action, $resource, $resourceId, $old, $new): void {
                $this->app->db()->execute(
                    'INSERT INTO audit_logs
                        (id, user_id, action, resource, resource_id, old_value, new_value, ip_address, timestamp)
                     VALUES (:id, :user, :action, :resource, :resource_id, :old, :new, :ip, :timestamp)',
                    [
                        'id' => Table::uuid(),
                        'user' => $this->app->auth()->id(),
                        'action' => $action,
                        'resource' => $resource,
                        'resource_id' => substr($resourceId, 0, 255),
                        'old' => $old === null ? null : $this->encode($old),
                        'new' => $new === null ? null : $this->encode($new),
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]
                );
            });
        } catch (Throwable $e) {
            // Le journal ne doit jamais faire echouer l'acte qu'il enregistre :
            // la trace est importante, la saisie l'est davantage.
        }
    }

    /** @param  array<string, mixed>  $values */
    private function encode(array $values): string
    {
        return (string) json_encode($values, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Deux valeurs representent-elles la meme chose ?
     *
     * Les nombres sont compares numeriquement : « 14 », « 14.0 » et « 14.00 »
     * sont la meme note, et les distinguer noierait les vraies modifications.
     */
    private function same(mixed $old, mixed $new): bool
    {
        if ($old === null && $new === null) {
            return true;
        }

        if ($old === null || $new === null) {
            return false;
        }

        if (is_numeric($old) && is_numeric($new)) {
            return abs((float) $old - (float) $new) < 0.0001;
        }

        return (string) $old === (string) $new;
    }
}
