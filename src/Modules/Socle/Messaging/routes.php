<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Messaging\Http\Controllers\AnnouncementController;
use Scholaris\Modules\Socle\Messaging\Http\Controllers\ConversationController;
use Scholaris\Modules\Socle\Messaging\Http\Controllers\ConversationReportController;
use Scholaris\Modules\Socle\Messaging\Http\Controllers\MessageController;
use Scholaris\Modules\Socle\Messaging\Http\Controllers\SmsUsageController;
use Scholaris\Modules\Socle\Messaging\Http\Controllers\TemplateController;

/** Routes du service S05 Messaging. Toutes exigent un établissement actif (X-Establishment-Id). */
return static function (RouteRegistry $routes): void {
    $routes->add('GET', '/message-templates', [TemplateController::class, 'index']);
    $routes->add('GET', '/message-templates/export', [TemplateController::class, 'export']);
    $routes->add('POST', '/message-templates', [TemplateController::class, 'store']);
    $routes->add('GET', '/message-templates/{id}', [TemplateController::class, 'show']);
    $routes->add('PATCH', '/message-templates/{id}', [TemplateController::class, 'update']);
    $routes->add('DELETE', '/message-templates/{id}', [TemplateController::class, 'destroy']);

    // Routes statiques avant les routes à paramètre (exigence de FastRoute).
    $routes->add('POST', '/messages/audience-preview', [MessageController::class, 'audiencePreview']);
    $routes->add('GET', '/messages', [MessageController::class, 'index']);
    $routes->add('GET', '/messages/export', [MessageController::class, 'export']);
    $routes->add('POST', '/messages', [MessageController::class, 'store']);
    $routes->add('GET', '/messages/{id}', [MessageController::class, 'show']);
    $routes->add('POST', '/messages/{id}/send', [MessageController::class, 'send']);
    $routes->add('GET', '/messages/{id}/deliveries', [MessageController::class, 'deliveries']);
    $routes->add('GET', '/messages/{id}/deliveries/export', [MessageController::class, 'exportDeliveries']);

    $routes->add('GET', '/announcements', [AnnouncementController::class, 'index']);
    $routes->add('GET', '/announcements/export', [AnnouncementController::class, 'export']);
    $routes->add('POST', '/announcements', [AnnouncementController::class, 'store']);
    $routes->add('GET', '/announcements/{id}', [AnnouncementController::class, 'show']);
    $routes->add('PATCH', '/announcements/{id}', [AnnouncementController::class, 'update']);
    $routes->add('DELETE', '/announcements/{id}', [AnnouncementController::class, 'destroy']);

    $routes->add('GET', '/sms-usage', [SmsUsageController::class, 'index']);

    // Messagerie interne modérée (permissions messaging.chat et messaging.moderate).
    $routes->add('GET', '/conversation-contacts', [ConversationController::class, 'contacts']);
    $routes->add('GET', '/conversations', [ConversationController::class, 'index']);
    $routes->add('POST', '/conversations', [ConversationController::class, 'store']);
    $routes->add('GET', '/conversations/{id}', [ConversationController::class, 'show']);
    $routes->add('POST', '/conversations/{id}/archive', [ConversationController::class, 'archive']);
    $routes->add('GET', '/conversations/{id}/messages', [ConversationController::class, 'messages']);
    $routes->add('POST', '/conversations/{id}/messages', [ConversationController::class, 'post']);
    $routes->add('POST', '/conversations/{id}/messages/{messageId}/report', [ConversationController::class, 'report']);
    $routes->add('POST', '/conversations/{id}/messages/{messageId}/hide', [ConversationController::class, 'hide']);
    $routes->add('POST', '/conversations/{id}/messages/{messageId}/restore', [ConversationController::class, 'restore']);
    $routes->add('GET', '/conversation-reports', [ConversationReportController::class, 'index']);
    $routes->add('GET', '/conversation-reports/export', [ConversationReportController::class, 'export']);
    $routes->add('POST', '/conversation-reports/{id}/dismiss', [ConversationReportController::class, 'dismiss']);
};
