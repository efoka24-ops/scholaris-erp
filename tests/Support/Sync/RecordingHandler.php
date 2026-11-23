<?php

declare(strict_types=1);

namespace Tests\Support\Sync;

use RuntimeException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Sync\Contracts\BatchContext;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItem;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItemHandler;
use Scholaris\Modules\Socle\Sync\Contracts\ItemResult;

/**
 * Gestionnaire de test (kind `test.record`) : un « registre » clé => valeur versionné, avec contrôle de version optimiste.
 * Charge utile : `key`, `value`, `base_version` (0 par défaut). Si la version du serveur diffère de `base_version`, c'est un
 * conflit et la valeur du serveur n'est PAS écrasée. Drapeaux : `reject`, `forbid` (HttpException 403), `boom` (exception brute).
 */
final class RecordingHandler implements BatchItemHandler
{
    /** @var array<string, array{value: mixed, version: int}> */
    public array $store = [];

    /** @var list<array{item: BatchItem, context: BatchContext}> */
    public array $calls = [];

    public function handle(BatchItem $item, BatchContext $context): ItemResult
    {
        $this->calls[] = ['item' => $item, 'context' => $context];
        $payload = $item->payload;

        if (($payload['boom'] ?? false) === true) {
            throw new RuntimeException('détail interne secret SYN-NOM');
        }
        if (($payload['forbid'] ?? false) === true) {
            throw HttpException::forbidden('Droit insuffisant pour cet élément.');
        }
        if (($payload['reject'] ?? false) === true) {
            return ItemResult::rejected('Élément invalide.', 422, ['value' => ['Valeur interdite.']]);
        }

        $key = (string) ($payload['key'] ?? '');
        $current = $this->store[$key] ?? ['value' => null, 'version' => 0];
        if ($current['version'] !== (int) ($payload['base_version'] ?? 0)) {
            return ItemResult::conflict('Version du serveur plus récente.', ['key' => $key, 'version' => $current['version'], 'value' => $current['value']]);
        }
        $this->store[$key] = ['value' => $payload['value'] ?? null, 'version' => $current['version'] + 1];

        return ItemResult::applied(['key' => $key, 'version' => $current['version'] + 1]);
    }
}
