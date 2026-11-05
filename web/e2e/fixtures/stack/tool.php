<?php

declare(strict_types=1);

/**
 * Outil d'installation de la pile de test (équivalent des commandes artisan, sans .env) : réponses en JSON sur la sortie.
 *   php tool.php migrate
 *   php tool.php platform-admin <téléphone> <prénom> <nom>
 *   php tool.php client <application> <type> <téléphone> <prénom> <nom> <établissement> [ville] [région]
 *   php tool.php sql <requête>              (lecture ou réglage ponctuel de la base de test)
 */

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Modules\Socle\Identity\Contracts\PlatformAdministrators;
use Scholaris\Modules\Socle\Platform\Application\AdministratorAccounts;
use Scholaris\Modules\Socle\Platform\Application\ClientLifecycle;
use Scholaris\Modules\Socle\Platform\Application\EntitlementService;

/** @var Scholaris\Core\Kernel $kernel */
$kernel = require __DIR__ . '/boot.php';
$c = $kernel->container();
$args = array_slice($argv, 1);
$out = static function (mixed $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
};

switch ($args[0] ?? '') {
    case 'migrate':
        $c->get(Migrator::class)->migrate();
        $out(['ok' => true]);
        break;
    case 'platform-admin':
        $out($c->get(PlatformAdministrators::class)->create($args[1], $args[2], $args[3]));
        break;
    case 'client':
        [, $app, $type, $phone, $first, $last, $name] = $args;
        $client = $c->get(ClientLifecycle::class)->create([
            'name' => $name, 'contact_name' => $first . ' ' . $last, 'contact_email' => null, 'contact_phone' => $phone,
            'city' => $args[7] ?? 'Garoua', 'region' => $args[8] ?? 'Nord', 'locale' => 'fr', 'establishment_type' => $type,
        ], 'console', null);
        $tenant = (string) $client['id'];
        $entitlement = $c->get(EntitlementService::class)->open($tenant, $app, EntitlementService::SOURCE_PLATFORM, 'console');
        $admin = $c->get(AdministratorAccounts::class)->invite($tenant, $name, [
            'first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'email' => null, 'locale' => 'fr',
        ], 'console', null);
        $out(['tenant_id' => $tenant, 'establishment_id' => $client['establishments'][0]['id'] ?? null, 'entitlement' => $entitlement, 'admin' => $admin]);
        break;
    case 'sql':
        $pdo = $c->get(ConnectionFactory::class)->pdo();
        $stmt = $pdo->prepare($args[1]);
        $stmt->execute(array_slice($args, 2));
        $out($stmt->columnCount() > 0 ? $stmt->fetchAll(PDO::FETCH_ASSOC) : ['changed' => $stmt->rowCount()]);
        break;
    default:
        fwrite(STDERR, "Commande inconnue.\n");
        exit(2);
}
