<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Catalogue\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Catalogue\Application\StreamCatalogue;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Cycles;
use Scholaris\Modules\Apps\Secondary\Support\Input;

/** Contrôle des corps JSON du catalogue (ajout d'une ligne, changement de libellé ou de masquage) : 422 avec toutes les erreurs. */
final class StreamInput
{
    /** Code tel que publié : lettres, chiffres, espace, « - », « / », « . » (ex. « BP CF », « MF/CM », « CMA-MVPL »). */
    private const CODE = '/^[A-Z0-9](?:[A-Z0-9 \/.-]{0,18}[A-Z0-9])?$/';

    private function __construct()
    {
    }

    /** @return array<string, mixed> */
    public static function creation(ServerRequestInterface $request): array
    {
        $input = Input::of($request);
        $input->custom('code', static function (mixed $value): string {
            $code = is_string($value) ? strtoupper(trim((string) preg_replace('/\s+/', ' ', $value))) : '';
            if (preg_match(self::CODE, $code) !== 1) {
                throw new \InvalidArgumentException('Code de 1 à 20 caractères (lettres, chiffres, espace, « - », « / », « . »).');
            }

            return $code;
        });
        $input->enum('cycle_code', Cycles::ALL);
        $teaching = $input->enum('teaching_kind', Cycles::TEACHING_KINDS);
        $track = $input->enum('track_code', StreamCatalogue::TRACKS, false);
        $input->enum('section_code', Cycles::SECTIONS, false);
        $input->string('field_label', 120, false, true);
        $input->string('label_fr', 160);
        $input->string('label_en', 160, false, true);
        $input->string('source_ref', 255);
        if ($teaching === Cycles::TEACHING_GENERAL && $track !== null && $track !== 'GEN') {
            $input->fail('track_code', 'L\'enseignement général utilise la filière GEN.');
        }
        if ($teaching === Cycles::TEACHING_TECHNICAL && !in_array($track, ['STT', 'IND'], true)) {
            $input->fail('track_code', 'Filière obligatoire pour l\'enseignement technique : STT ou IND.');
        }

        return $input->done();
    }

    /** @return array<string, mixed> */
    public static function change(ServerRequestInterface $request, bool $platform): array
    {
        $input = Input::of($request);
        $input->string('label_fr', 160, false, !$platform);
        $input->string('label_en', 160, false, true);
        $input->bool('hidden', false);
        $input->string('reason', 255);
        if ($platform) {
            $input->enum('confirmation', StreamCatalogue::CONFIRMATIONS, false);
        }
        $values = $input->done();
        if (!array_key_exists('label_fr', $values) && !array_key_exists('label_en', $values) && !array_key_exists('hidden', $values) && !array_key_exists('confirmation', $values)) {
            throw HttpException::validation(['hidden' => ['Indiquez au moins un changement : libellé ou masquage.']]);
        }

        return $values;
    }
}
