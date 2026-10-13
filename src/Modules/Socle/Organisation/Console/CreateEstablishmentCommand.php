<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Console;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Organisation\Application\EstablishmentService;
use Scholaris\Modules\Socle\Organisation\Domain\EstablishmentType;
use Throwable;

/**
 * `establishment:create <client> <nom> <nursery|primary|secondary|higher>` : crée un établissement pour un client existant.
 * Le terminal de l'hébergement coupe les arguments aux espaces : le nom peut donc s'étendre sur plusieurs mots,
 * le premier argument est le client, le dernier le type.
 */
final class CreateEstablishmentCommand
{
    private const TRIM = " \t\"'";

    public function __construct(
        private readonly EstablishmentService $establishments,
        private readonly ConnectionFactory $connections,
    ) {
    }

    /** @param list<string> $args arguments après le nom de la commande */
    public function run(array $args): int
    {
        if (count($args) < 3) {
            fwrite(STDERR, "Usage : artisan establishment:create <client> <nom de l'établissement> <nursery|primary|secondary|higher>\n");

            return 2;
        }
        $client = trim($args[0], self::TRIM);
        $typeName = strtolower(trim($args[count($args) - 1], self::TRIM));
        $name = trim(implode(' ', array_slice($args, 1, -1)), self::TRIM);

        $type = EstablishmentType::tryFrom($typeName);
        if ($type === null || $name === '') {
            fwrite(STDERR, "Type inconnu ou nom vide. Types acceptés : nursery, primary, secondary, higher.\n");

            return 2;
        }

        $find = $this->connections->pdo()->prepare('SELECT id, name FROM tenants WHERE id = ? OR name = ?');
        $find->execute([$client, $client]);
        $tenant = $find->fetch();
        if (!is_array($tenant)) {
            fwrite(STDERR, "Client introuvable : « {$client} ». Utilisez son identifiant.\n");

            return 1;
        }

        try {
            $row = $this->establishments->create((string) $tenant['id'], $name, $type);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");

            return 1;
        }

        echo "Établissement créé : {$row['name']} ({$type->value}) pour le client {$tenant['name']}.\n";
        echo "  Identifiant : {$row['id']}\n";

        return 0;
    }
}
