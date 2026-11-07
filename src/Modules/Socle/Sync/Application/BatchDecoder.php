<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Application;

use JsonException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Sync\Infrastructure\ContainerHandlerRegistry;

/**
 * Décompresse (gzip) et valide le corps d'un lot : `{"items": [{"kind", "client_uuid", "payload"}]}`. Toute erreur est une
 * HttpException : 413 (trop volumineux, y compris bombe de décompression), 400 (gzip ou JSON illisible), 422 (forme). Aucun
 * champ inconnu n'est toléré : client, établissement et statuts ne viennent jamais du corps.
 */
final class BatchDecoder
{
    private const CLIENT_UUID = '/^[A-Za-z0-9_\-]{8,64}$/D';
    private const CHUNK = 1024;
    private const MAX_DEPTH = 16;

    /**
     * @return array{hash: string, items: list<array{kind: string, client_uuid: string, payload: array<string, mixed>}>}
     */
    public function decode(string $compressed, int $maxItems, int $maxUncompressedBytes): array
    {
        $json = $this->inflate($compressed, $maxUncompressedBytes);
        try {
            $data = json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw HttpException::badRequest('Le corps du lot n\'est pas un JSON valide.');
        }
        if (!is_array($data) || !str_starts_with(ltrim($json), '{')) {
            throw HttpException::badRequest('Le corps du lot doit être un objet JSON.');
        }

        return ['hash' => hash('sha256', $json), 'items' => $this->items($data, $maxItems)];
    }

    private function inflate(string $compressed, int $maxBytes): string
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            throw new \RuntimeException('Extension zlib indisponible.');
        }
        $out = '';
        foreach (str_split($compressed, self::CHUNK) as $chunk) {
            $part = @inflate_add($context, $chunk, ZLIB_NO_FLUSH);
            if ($part === false) {
                throw HttpException::badRequest('Le corps du lot n\'est pas un flux gzip valide.');
            }
            $out .= $part;
            if (strlen($out) > $maxBytes) {
                throw self::tooLarge('Le lot décompressé dépasse ' . $maxBytes . ' octets.');
            }
        }
        if (inflate_get_status($context) !== ZLIB_STREAM_END) {
            throw HttpException::badRequest('Le flux gzip du lot est incomplet.');
        }

        return $out;
    }

    /**
     * @param array<mixed> $data
     * @return list<array{kind: string, client_uuid: string, payload: array<string, mixed>}>
     */
    private function items(array $data, int $maxItems): array
    {
        $errors = [];
        foreach (array_keys($data) as $name) {
            if ($name !== 'items') {
                $errors[(string) $name] = ['Champ non autorisé.'];
            }
        }
        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === [] || !array_is_list($items)) {
            $errors['items'] = ['Le lot doit contenir une liste d\'éléments non vide.'];
        } elseif (count($items) > $maxItems) {
            $errors['items'] = ['Un lot contient au plus ' . $maxItems . ' éléments.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $clean = [];
        /** @var list<mixed> $items */
        foreach ($items as $index => $item) {
            $clean[] = $this->item($item, $index, $errors);
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        /** @var list<array{kind: string, client_uuid: string, payload: array<string, mixed>}> $clean */
        return $clean;
    }

    /**
     * @param array<string, list<string>> $errors
     * @return array{kind: string, client_uuid: string, payload: array<string, mixed>}|array{}
     */
    private function item(mixed $item, int $index, array &$errors): array
    {
        $prefix = 'items.' . $index;
        if (!is_array($item) || ($item !== [] && array_is_list($item))) {
            $errors[$prefix] = ['Chaque élément doit être un objet {kind, client_uuid, payload}.'];

            return [];
        }
        foreach (array_keys($item) as $name) {
            if (!in_array($name, ['kind', 'client_uuid', 'payload'], true)) {
                $errors[$prefix . '.' . $name] = ['Champ non autorisé.'];
            }
        }
        $kind = $item['kind'] ?? null;
        if (!is_string($kind) || $kind === '' || strlen($kind) > ContainerHandlerRegistry::KIND_MAX_LENGTH || preg_match(ContainerHandlerRegistry::KIND_FORMAT, $kind) !== 1) {
            $errors[$prefix . '.kind'] = ['Type invalide : minuscules, chiffres, « _ » et « . » (60 caractères au plus).'];
        }
        $uuid = $item['client_uuid'] ?? null;
        if (!is_string($uuid) || preg_match(self::CLIENT_UUID, $uuid) !== 1) {
            $errors[$prefix . '.client_uuid'] = ['Identifiant client invalide : 8 à 64 caractères (lettres, chiffres, « - » et « _ »).'];
        }
        $payload = $item['payload'] ?? null;
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            $errors[$prefix . '.payload'] = [array_key_exists('payload', $item) ? 'La charge utile doit être un objet.' : 'Ce champ est obligatoire.'];
        }
        if (!is_string($kind) || !is_string($uuid) || !is_array($payload)) {
            return [];
        }

        /** @var array<string, mixed> $payload */
        return ['kind' => $kind, 'client_uuid' => $uuid, 'payload' => $payload];
    }

    public static function tooLarge(string $detail): HttpException
    {
        return new HttpException(413, 'Corps trop volumineux', $detail, 'https://api.scholaris.cm/errors/payload-too-large');
    }
}
