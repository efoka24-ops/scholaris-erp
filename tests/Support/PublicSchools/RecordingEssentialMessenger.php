<?php

declare(strict_types=1);

namespace Tests\Support\PublicSchools;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\EssentialMessenger;
use Tests\Support\Attendance\RecordingMessenger;

/** Doublure d'EssentialMessenger (D-BUL) : enregistre la clé du modèle envoyé comme message de service essentiel, puis délègue à la doublure de Messenger. */
final class RecordingEssentialMessenger implements EssentialMessenger
{
    /** @var list<string> modèles envoyés comme messages de service essentiels */
    public array $templates = [];

    public function __construct(private readonly RecordingMessenger $messenger)
    {
    }

    public function sendEssential(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables = [], array $channels = []): DeliveryResult
    {
        $this->templates[] = $templateKey;

        return $this->messenger->send($tenantId, $establishmentId, $personId, $templateKey, $variables, $channels);
    }
}
