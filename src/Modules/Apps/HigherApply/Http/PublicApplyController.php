<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\HigherApply\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\RateLimit\RateLimiter;
use Scholaris\Modules\Apps\Higher\Admissions\Contracts\PublicAdmissions;
use Scholaris\Modules\Apps\Higher\Admissions\Contracts\PublicInstitute;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\ApplicantRegistration;

/**
 * Candidature en ligne, partie publique. Un lien inconnu, désactivé ou d'un client sans l'application Supérieur répond le même 404
 * (RFC 9457) ; la page n'expose que ce qu'un candidat doit voir. L'inscription envoie un code par SMS ou e-mail : elle est limitée
 * par IP (10 par heure) et par institut (300 par jour) pour empêcher qu'on fasse payer des SMS à l'institut, en plus du compteur
 * par identifiant de l'activation (un renvoi par minute, 5 par heure) et de la limite globale de /public/.
 */
final class PublicApplyController
{
    public const LIMIT_PER_IP = 10;
    public const LIMIT_PER_LINK = 300;
    private const NAME = '/^\p{L}[\p{L}\p{M} \'’.\-]*$/u';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PublicAdmissions $admissions,
        private readonly ApplicantRegistration $registration,
        private readonly RateLimiter $limiter,
        private readonly AuditLog $audit,
    ) {
    }

    /** GET /public/admissions/{slug} : institut et campagnes ouvertes. */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $institute = $this->institute($request);

        return $this->responder->json(['data' => [
            'institute' => ['name' => $institute->name],
            'campaigns' => $this->admissions->openCampaigns($institute->tenantId, $institute->establishmentId),
        ]])->withHeader('Cache-Control', 'no-store');
    }

    /** POST /public/admissions/{slug}/register : crée (ou retrouve) le compte du candidat et envoie le code de vérification. */
    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'inconnue');
        $this->hit('higher.apply.register.ip|' . $ip, self::LIMIT_PER_IP, 3600);
        $institute = $this->institute($request);
        $this->hit('higher.apply.register.link|' . $institute->tenantId . '|' . $institute->establishmentId, self::LIMIT_PER_LINK, 86400);

        $data = json_decode((string) $request->getBody(), true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
        }
        $errors = [];
        $text = static function (string $field, int $max) use ($data, &$errors): string {
            $value = $data[$field] ?? null;
            if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max) {
                $errors[$field] = [sprintf('Texte non vide de %d caractères au plus attendu.', $max)];

                return '';
            }

            return trim($value);
        };
        $first = $text('first_name', 80);
        $last = $text('last_name', 80);
        $identifier = $text('identifier', 160);
        foreach (['first_name' => $first, 'last_name' => $last] as $field => $value) {
            if ($value !== '' && preg_match(self::NAME, $value) !== 1) {
                $errors[$field] = ['Caractères non autorisés dans ce nom.'];
            }
        }
        $locale = $data['locale'] ?? 'fr';
        if (!in_array($locale, ['fr', 'en'], true)) {
            $errors['locale'] = ['Langue attendue : fr ou en.'];
        }
        $unknown = array_diff(array_keys($data), ['first_name', 'last_name', 'identifier', 'locale', 'website']);
        foreach ($unknown as $field) {
            $errors[(string) $field] = ['Champ non autorisé.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        // Champ piège invisible : un robot le remplit. Réponse identique à un succès, sans aucun envoi.
        if (is_string($data['website'] ?? null) && trim($data['website']) !== '') {
            return $this->answer(['channel' => str_contains($identifier, '@') ? 'email' : 'sms', 'masked_to' => '•••', 'expires_in' => 300, 'resend_in' => 60]);
        }

        $result = $this->registration->register($institute->tenantId, $institute->establishmentId, $first, $last, $identifier, (string) $locale);
        $this->audit->append($institute->tenantId, null, 'higher.apply.registration_requested', 'establishment', $institute->establishmentId, null, ['channel' => $result['channel']]);

        return $this->answer($result);
    }

    /** @param array<string, mixed> $result */
    private function answer(array $result): ResponseInterface
    {
        return $this->responder->json(['data' => $result])->withHeader('Cache-Control', 'no-store');
    }

    private function institute(ServerRequestInterface $request): PublicInstitute
    {
        $slug = $request->getAttribute('slug');

        return (is_string($slug) ? $this->admissions->resolve($slug) : null) ?? throw HttpException::notFound('Lien de candidature introuvable.');
    }

    private function hit(string $key, int $limit, int $window): void
    {
        $retry = $this->limiter->hit($key, $limit, $window);
        if ($retry > 0) {
            throw HttpException::tooManyRequests($retry);
        }
    }
}
