<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure\Mail;

/**
 * Mise en page HTML commune à tous les e-mails, construite à partir du texte brut (modèles de messages, codes, alertes) :
 * en-tête SCHOLARIS, paragraphes, liens cliquables, bouton pour le premier lien, code mis en évidence, pied de page.
 * Tout le texte est échappé : un modèle ne peut pas injecter de HTML. Tableaux et styles en ligne pour les messageries
 * (Gmail, Outlook) qui ignorent les feuilles de style. Couleurs reprises du front (accent #f26b1d).
 */
final class EmailLayout
{
    private const ACCENT = '#f26b1d';
    private const INK = '#1a1a1a';
    private const MUTED = '#5f5f5a';
    private const BG = '#f4f4f1';
    private const URL = '~https?://[^\s<>"\']+[^\s<>"\'.,;:!?)\]]~u';
    private const CODE = '~\bcode(?:\s+d\'[a-zé]+)?\s*(?:est|:|is)\s*([A-Z0-9][A-Z0-9-]{3,11})\b~iu';

    public static function render(string $subject, string $body, string $senderName = 'SCHOLARIS'): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $body));
        $code = preg_match(self::CODE, $text, $m) === 1 ? $m[1] : null;
        $button = preg_match(self::URL, $text, $u) === 1 ? $u[0] : null;

        $paragraphs = '';
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $block) {
            $paragraphs .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;color:' . self::INK . ';">'
                . nl2br(self::linkify($block), false) . '</p>';
        }

        $codeBlock = $code === null ? '' : '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;"><tr>'
            . '<td style="background:' . self::BG . ';border:2px dashed ' . self::ACCENT . ';border-radius:8px;padding:14px 28px;'
            . 'font-family:Consolas,Menlo,monospace;font-size:30px;font-weight:700;letter-spacing:6px;color:' . self::INK . ';">'
            . self::e($code) . '</td></tr></table>';

        $buttonBlock = $button === null ? '' : '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;"><tr>'
            . '<td style="background:' . self::ACCENT . ';border-radius:6px;">'
            . '<a href="' . self::e($button) . '" style="display:inline-block;padding:13px 26px;font-size:16px;font-weight:700;'
            . 'color:#ffffff;text-decoration:none;">Ouvrir / Open &rarr;</a></td></tr></table>';

        $preheader = mb_substr((string) preg_replace('/\s+/', ' ', $text), 0, 110);
        $sender = self::e($senderName === '' ? 'SCHOLARIS' : $senderName);

        return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . self::e($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . self::BG . ';font-family:Arial,Helvetica,sans-serif;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . self::e($preheader) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . self::BG . ';"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e4e4de;">'
            . '<tr><td style="background:' . self::INK . ';padding:18px 28px;border-bottom:4px solid ' . self::ACCENT . ';">'
            . '<span style="font-size:20px;font-weight:800;letter-spacing:2px;color:#ffffff;">SCHOLARIS</span></td></tr>'
            . '<tr><td style="padding:28px 28px 8px;">'
            . '<h1 style="margin:0 0 20px;font-size:22px;line-height:1.3;color:' . self::INK . ';">' . self::e($subject) . '</h1>'
            . $paragraphs . $codeBlock . $buttonBlock
            . '</td></tr>'
            . '<tr><td style="padding:18px 28px 24px;border-top:1px solid #ecece6;font-size:12px;line-height:1.5;color:' . self::MUTED . ';">'
            . 'Message automatique envoyé par ' . $sender . ' via SCHOLARIS. Merci de ne pas y répondre.<br>'
            . 'Automatic message sent by ' . $sender . ' via SCHOLARIS. Please do not reply.'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    private static function linkify(string $block): string
    {
        $out = '';
        $offset = 0;
        if (preg_match_all(self::URL, $block, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$url, $position]) {
                $out .= self::e(substr($block, $offset, $position - $offset))
                    . '<a href="' . self::e($url) . '" style="color:' . self::ACCENT . ';">' . self::e($url) . '</a>';
                $offset = $position + strlen($url);
            }
        }

        return $out . self::e(substr($block, $offset));
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
