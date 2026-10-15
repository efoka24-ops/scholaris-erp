<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Platform\Application\AccessRequestService;
use Scholaris\Modules\Socle\Platform\Application\Catalog;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;

/**
 * Routes publiques (sans compte) : catalogue des applications proposées, demande d'accès et suivi par jeton.
 * Protections : limitation de débit par IP et par e-mail, champ piège `website`, validation stricte, réponse identique
 * que l'adresse soit connue ou non.
 */
final class PublicAccessController
{
    private const FIELDS = [
        'institution_name', 'target_type', 'city', 'region', 'country', 'contact_first_name', 'contact_last_name',
        'contact_email', 'contact_phone', 'contact_position', 'ministerial_authorization', 'app_key', 'message', 'locale', 'website',
    ];
    private const NAME = '/^\p{L}[\p{L}\p{M} \'’.\-]*$/u';
    private const ACKNOWLEDGEMENT = 'Demande enregistrée. Si les informations sont valides, vous recevrez un message de confirmation.';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly AccessRequestService $requests,
        private readonly Catalog $catalog,
    ) {
    }

    /** GET /public/applications : applications disponibles ou annoncées (jamais les retirées). */
    public function applications(ServerRequestInterface $request): ResponseInterface
    {
        $items = [];
        foreach ($this->catalog->all() as $app) {
            if ($app['status'] === ApplicationManifest::RETIRED) {
                continue;
            }
            $items[] = [
                'key' => $app['key'], 'label' => $app['label'], 'target' => $app['target'], 'status' => $app['status'], 'version' => $app['version'],
                'monthly_price_xaf' => $app['monthly_price_xaf'], 'trial_days' => $app['trial_days'],
            ];
        }

        return $this->responder->json(['data' => $items]);
    }

    /** POST /public/access-requests */
    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $ip = PlatformGuard::ip($request);
        $this->requests->throttleSubmission($ip);

        $input = Input::of($request, self::FIELDS);
        if (is_string($input->raw('website')) && trim($input->raw('website')) !== '') {
            return $this->accepted($this->requests->decoy());
        }
        $data = [
            'institution_name' => $input->text('institution_name', 3, 160),
            'target_type' => $input->choice('target_type', ApplicationManifest::TARGETS),
            'city' => $input->text('city', 2, 80),
            'region' => $input->text('region', 2, 80),
            'country' => $input->text('country', 2, 2, false, '/^[A-Z]{2}$/') ?? 'CM',
            'contact_first_name' => $input->text('contact_first_name', 1, 80, true, self::NAME),
            'contact_last_name' => $input->text('contact_last_name', 1, 80, true, self::NAME),
            'contact_email' => $input->email('contact_email'),
            'contact_phone' => $input->phone('contact_phone', false),
            'contact_position' => $input->text('contact_position', 2, 80, false),
            'ministerial_authorization' => $input->text('ministerial_authorization', 2, 120, false, '/^[\p{L}\p{N} \/.\-_°]+$/u'),
            'app_key' => $input->text('app_key', 2, 20, true, '/^[a-z][a-z0-9_]+$/'),
            'message' => $input->longText('message', 2000),
            'locale' => $input->choice('locale', ['fr', 'en'], false) ?? ($request->getAttribute('locale') === 'en' ? 'en' : 'fr'),
        ];
        $input->validate();

        return $this->accepted($this->requests->submit($data, $ip));
    }

    /** GET /public/access-requests/{token} */
    public function track(ServerRequestInterface $request): ResponseInterface
    {
        $this->requests->throttleTracking(PlatformGuard::ip($request));

        return $this->responder->json(['data' => $this->requests->track(PlatformGuard::param($request, 'token'))]);
    }

    /** POST /public/access-requests/{token}/complement */
    public function complement(ServerRequestInterface $request): ResponseInterface
    {
        $ip = PlatformGuard::ip($request);
        $this->requests->throttleTracking($ip);
        $input = Input::of($request, ['message']);
        $message = $input->longText('message', 2000, true);
        $input->validate();

        return $this->responder->json(['data' => $this->requests->complement(PlatformGuard::param($request, 'token'), (string) $message, $ip)]);
    }

    /** @param array<string, string> $result */
    private function accepted(array $result): ResponseInterface
    {
        return $this->responder->json(['data' => $result + ['message' => self::ACKNOWLEDGEMENT]], 202);
    }
}
