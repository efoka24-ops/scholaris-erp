<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Service\CalculationRules;

/**
 * Parametres de l'etablissement : activation des fonctionnalites optionnelles.
 *
 * Seules les fonctionnalites marquees configurables pour le type
 * d'etablissement sont proposees. Celles qui n'existent pas pour ce type ne
 * sont ni listees ni activables : un directeur de primaire ne doit pas pouvoir
 * s'ouvrir le baccalaureat, meme volontairement.
 */
final class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $this->app->tenant()->global(fn () => $this->app->db()->selectOne(
            'SELECT * FROM tenants WHERE id = :id',
            ['id' => $this->app->tenant()->requireId()]
        ));

        $features = $this->app->features();

        return $this->view('settings.index', [
            'tenant' => $tenant,
            'rules' => CalculationRules::forTenant($tenant),
            'features' => $features,
            'optional' => $features->optional(),
            'alwaysOn' => $features->alwaysOn(),
            'types' => $features->types(),
        ]);
    }

    public function save(Request $request): Response
    {
        $tenantId = $this->app->tenant()->requireId();
        $features = $this->app->features();
        $optional = $features->optional();

        $submitted = $request->all()['features'] ?? [];
        $submitted = is_array($submitted) ? $submitted : [];

        // Seules les cles reellement configurables sont retenues : un champ
        // ajoute a la main dans le formulaire ne doit rien ouvrir.
        $values = [];

        foreach (array_keys($optional) as $key) {
            $values[$key] = isset($submitted[$key]);
        }

        $tenant = $this->app->tenant()->global(fn () => $this->app->db()->selectOne(
            'SELECT type, config_json FROM tenants WHERE id = :id',
            ['id' => $tenantId]
        ));

        $config = [];

        if (is_string($tenant['config_json'] ?? null) && $tenant['config_json'] !== '') {
            $decoded = json_decode((string) $tenant['config_json'], true);
            $config = is_array($decoded) ? $decoded : [];
        }

        $config['features'] = $values;
        $config['calculation'] = $this->calculationRules($request, (string) ($tenant['type'] ?? ''));

        $this->app->tenant()->global(function () use ($tenantId, $config): void {
            $this->app->db()->execute(
                'UPDATE tenants SET config_json = :config, updated_at = :updated_at WHERE id = :id',
                [
                    'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'id' => $tenantId,
                ]
            );
        });

        // Le cache de fonctionnalites porte l'ancienne configuration.
        $this->app->resetFeatures();

        return $this->redirectWithSuccess('/parametres', 'Parametres enregistres.');
    }

    /**
     * Regles de calcul soumises par le formulaire.
     *
     * Les valeurs passent par CalculationRules, qui ecarte celles qui n'ont
     * pas de sens — un bareme a zero, un seuil de reussite au-dessus du
     * bareme, des mentions en desordre. Une saisie fautive retombe alors sur
     * la valeur par defaut : elle ne doit jamais produire des bulletins faux,
     * seulement des bulletins ordinaires.
     *
     * @return array<string, mixed>
     */
    private function calculationRules(Request $request, string $type): array
    {
        $mentions = [];
        $submitted = $request->all()['mention'] ?? [];

        if (is_array($submitted)) {
            foreach ($submitted as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $label = trim((string) ($entry['label'] ?? ''));
                $threshold = $entry['threshold'] ?? null;

                // Une ligne laissee vide est une ligne supprimee, pas une
                // erreur : c'est ainsi qu'on retire une mention.
                if ($label === '' || ! is_numeric($threshold)) {
                    continue;
                }

                $mentions[] = ['threshold' => (float) $threshold, 'label' => $label];
            }
        }

        return CalculationRules::fromArray([
            'scale' => $request->string('scale'),
            'pass_mark' => $request->string('pass_mark'),
            'rounding' => $request->string('rounding'),
            'unjustified_absence' => $request->string('unjustified_absence'),
            'fail_label' => $request->string('fail_label'),
            'mentions' => $mentions,
        ], $type)->toArray();
    }
}
