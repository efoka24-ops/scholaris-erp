<?php

declare(strict_types=1);

namespace Scholaris\View;

use Scholaris\Tenant\Features;

/**
 * Navigation laterale : rubriques, entrees, icones.
 *
 * Elle vit ici plutot que dans le gabarit pour deux raisons. La premiere est
 * qu'une liste de vingt entrees melee a du HTML se relit mal et se modifie
 * moins bien. La seconde est qu'elle devient testable : verifier qu'une entree
 * porte la bonne permission, ou qu'aucune ne pointe vers une route inexistante,
 * est autrement plus sur que de le constater a l'ecran.
 *
 * Les icones sont des traces SVG poses dans la page : aucune police d'icones a
 * telecharger, ce qui compte sur une connexion lente, et rien qui casse si une
 * ressource exterieure devient injoignable.
 */
final class Navigation
{
    /**
     * Rubriques de l'administration de la plateforme.
     *
     * @return list<array{title: string|null, items: list<array{href: string, label: string, permission: string|null, feature: string|null, icon: string}>}>
     */
    public static function platform(): array
    {
        return [
            ['title' => null, 'items' => [
                self::item('/admin', 'Accueil', 'tenants:read', null, 'home'),
            ]],
            ['title' => 'Etablissements', 'items' => [
                self::item('/admin/etablissements', 'Demandes d ouverture', 'tenants:read', null, 'inbox'),
                self::item('/admin/parc', 'Parc d etablissements', 'tenants:read', null, 'building'),
            ]],
            ['title' => 'Gouvernance', 'items' => [
                self::item('/admin/comptes', 'Comptes', 'tenants:read', null, 'users'),
                self::item('/admin/habilitations', 'Habilitations', 'tenants:read', null, 'shield'),
                self::item('/admin/journal', 'Journal d audit', 'tenants:read', null, 'history'),
            ]],
            ['title' => 'Pilotage', 'items' => [
                self::item('/admin/rapports', 'Rapports', 'tenants:read', null, 'chart'),
                self::item('/admin/courriers', 'Courriers envoyes', 'tenants:read', null, 'mail'),
                self::item('/admin/maintenance', 'Maintenance', 'tenants:update', null, 'settings'),
            ]],
        ];
    }

    /**
     * Rubriques d'un etablissement.
     *
     * Les libelles suivent la terminologie du type : un centre de formation
     * parle d'apprenants et de modules, non d'eleves et de matieres.
     *
     * @return list<array{title: string|null, items: list<array{href: string, label: string, permission: string|null, feature: string|null, icon: string}>}>
     */
    public static function school(Features $features): array
    {
        return [
            ['title' => null, 'items' => [
                self::item('/dashboard', 'Accueil', null, null, 'home'),
            ]],
            ['title' => 'Academique', 'items' => [
                self::item('/classrooms', 'Structure pedagogique', 'classrooms:read', 'structure.classrooms', 'layers'),
                self::item('/students', $features->label('students', 'Eleves').' & inscriptions', 'students:read', null, 'users'),
                self::item('/enrollments', 'Inscriptions', 'enrollments:read', null, 'clipboard'),
                self::item('/grades', 'Notes & calcul', 'grades:read', null, 'edit'),
                self::item('/bulletins', 'Bulletins & diplomes', 'bulletins:read', null, 'award'),
                self::item('/exams', 'Examens officiels', 'exams:read', 'exams.official', 'certificate'),
                self::item('/annees-scolaires', 'Annee scolaire', 'academic-years:read', null, 'calendar'),
            ]],
            ['title' => 'Vie scolaire', 'items' => [
                self::item('/timetable', 'Emplois du temps', 'timetables:read', 'life.timetable', 'calendar'),
                self::item('/attendance', 'Presences', 'attendance:read', 'life.attendance', 'check'),
                self::item('/course-log', 'Cahier de textes', 'course-log:read', 'life.textbook', 'book'),
                self::item('/discipline', 'Discipline', 'discipline:read', 'life.discipline', 'alert'),
                self::item('/health', 'Sante scolaire', 'health:read', 'life.health', 'heart'),
                self::item('/library', 'Bibliotheque', 'library:read', 'life.library', 'book'),
                self::item('/transport', 'Transport', 'transport:read', 'life.transport', 'bus'),
                self::item('/catering', 'Cantine', 'catering:read', 'life.catering', 'meal'),
            ]],
            ['title' => 'Gestion', 'items' => [
                self::item('/finance', 'Gestion financiere', 'finance-dashboard:read', 'finance.fees', 'wallet'),
                self::item('/finance/invoices', 'Factures', 'invoices:read', 'finance.payments', 'receipt'),
                self::item('/hr', 'RH & paie', 'hr:read', 'hr.payroll', 'briefcase'),
                self::item('/patrimoine', 'Patrimoine', 'assets:read', 'life.assets', 'box'),
            ]],
            ['title' => 'Communication', 'items' => [
                self::item('/communication', 'Annonces', 'communications:read', null, 'megaphone'),
                self::item('/messages', 'Messagerie', 'internal-messages:read', null, 'mail'),
            ]],
            ['title' => null, 'items' => [
                self::item('/parametres', 'Parametres', 'tenants:update', null, 'settings'),
            ]],
        ];
    }

    /**
     * @return array{href: string, label: string, permission: string|null, feature: string|null, icon: string}
     */
    private static function item(
        string $href,
        string $label,
        ?string $permission,
        ?string $feature,
        string $icon
    ): array {
        return [
            'href' => $href,
            'label' => $label,
            'permission' => $permission,
            'feature' => $feature,
            'icon' => $icon,
        ];
    }

    /**
     * L'entree correspond-elle a la page ouverte ?
     *
     * La comparaison porte sur le chemin complet ou sur un prefixe suivi d'un
     * segment : sans cela « /finance » s'allumerait aussi sur « /finances-x »,
     * et « /admin » sur toutes les pages de l'administration a la fois.
     */
    public static function isActive(string $href, string $currentPath): bool
    {
        if ($href === $currentPath) {
            return true;
        }

        // Les racines ne s'allument que sur elles-memes, sinon elles restent
        // actives sur toutes leurs sous-pages en meme temps que celles-ci.
        if ($href === '/dashboard' || $href === '/admin') {
            return false;
        }

        return str_starts_with($currentPath, $href.'/');
    }

    /**
     * Libelle de la page ouverte, pour le fil d'Ariane.
     *
     * @param  list<array{title: string|null, items: list<array<string, mixed>>}>  $sections
     */
    public static function currentLabel(array $sections, string $currentPath, string $fallback): string
    {
        $best = null;
        $bestLength = 0;

        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $href = (string) $item['href'];

                if (($href === $currentPath || str_starts_with($currentPath, $href.'/'))
                    && strlen($href) > $bestLength) {
                    $best = (string) $item['label'];
                    $bestLength = strlen($href);
                }
            }
        }

        return $best ?? $fallback;
    }

    /** Initiales, pour la pastille d'identite. */
    public static function initials(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        $initials = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $initials .= mb_strtoupper(mb_substr($part, 0, 1));

            if (mb_strlen($initials) === 2) {
                break;
            }
        }

        return $initials === '' ? '?' : $initials;
    }

    /**
     * Couleur de la pastille, derivee du nom.
     *
     * Stable d'un ecran a l'autre : la meme personne garde sa couleur, ce qui
     * en fait un repere utilisable dans une longue liste.
     */
    public static function colorIndex(string $value): int
    {
        return crc32($value) % 7;
    }

    /** Trace SVG d'une icone, pose dans la page. */
    public static function icon(string $name): string
    {
        $paths = [
            'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
            'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 5.2a3.2 3.2 0 0 1 0 5.6"/><path d="M17.5 14.4A6.5 6.5 0 0 1 21.5 20"/>',
            'layers' => '<path d="M12 3 3 7.5 12 12l9-4.5z"/><path d="m3 12.5 9 4.5 9-4.5"/><path d="m3 17 9 4.5L21 17"/>',
            'clipboard' => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9.5 4V2.8h5V4"/><path d="M9 10h6M9 14h6"/>',
            'edit' => '<path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17z"/><path d="M14.5 6.5 17.5 9.5"/>',
            'award' => '<circle cx="12" cy="9" r="5"/><path d="m8.5 13.5-1.5 7 5-2.5 5 2.5-1.5-7"/>',
            'certificate' => '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M7 20h6"/><path d="M8 9h8M8 12.5h5"/>',
            'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
            'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.5 2.5L16 9.5"/>',
            'alert' => '<path d="M12 3 2.5 20h19z"/><path d="M12 9.5v4.5M12 17h.01"/>',
            'heart' => '<path d="M12 20s-7.5-4.7-7.5-10A4.2 4.2 0 0 1 12 7.5 4.2 4.2 0 0 1 19.5 10c0 5.3-7.5 10-7.5 10z"/>',
            'book' => '<path d="M4 4.5A1.5 1.5 0 0 1 5.5 3H19v18H5.5A1.5 1.5 0 0 1 4 19.5z"/><path d="M8 3v18"/>',
            'bus' => '<rect x="4" y="4" width="16" height="12" rx="2"/><path d="M4 10h16"/><circle cx="8" cy="19" r="1.6"/><circle cx="16" cy="19" r="1.6"/>',
            'meal' => '<path d="M6 3v8a2.5 2.5 0 0 0 5 0V3"/><path d="M8.5 11v10"/><path d="M17 3c-1.5 2-2 4-2 6.5s.7 3.5 2 3.5 2-1 2-3.5S18.5 5 17 3z"/><path d="M17 13v8"/>',
            'wallet' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><circle cx="17" cy="14.5" r="1.2"/>',
            'receipt' => '<path d="M6 3h12v18l-3-1.6-3 1.6-3-1.6L6 21z"/><path d="M9.5 8h5M9.5 12h5"/>',
            'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7"/>',
            'box' => '<path d="M12 3 3 7.5v9L12 21l9-4.5v-9z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/>',
            'megaphone' => '<path d="M4 10v4a1.5 1.5 0 0 0 1.5 1.5H7l8 4.5V5.5L7 10H5.5A1.5 1.5 0 0 0 4 10z"/><path d="M18.5 9a4 4 0 0 1 0 6"/>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.2 5.2l2.1 2.1M16.7 16.7l2.1 2.1M18.8 5.2l-2.1 2.1M7.3 16.7l-2.1 2.1"/>',
            'inbox' => '<path d="M3 13h5l1.5 3h5l1.5-3h5"/><path d="M4.5 5h15l1.5 8v6H3v-6z"/>',
            'building' => '<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M8 7h3M13 7h3M8 11h3M13 11h3M8 15h3M13 15h3"/>',
            'shield' => '<path d="M12 3 4.5 6v6c0 4.5 3.2 7.6 7.5 9 4.3-1.4 7.5-4.5 7.5-9V6z"/><path d="m9 12 2 2 4-4"/>',
            'history' => '<path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1"/><path d="M3 4v5h5"/><path d="M12 8v4.5l3 1.8"/>',
            'chart' => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 17v-5M12.5 17V8M17 17v-7"/>',
            'logout' => '<path d="M14 4h4.5A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5H14"/><path d="M10 16l-4-4 4-4"/><path d="M6 12h9"/>',
            'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        ];

        $path = $paths[$name] ?? $paths['home'];

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"'
            .' stroke-linecap="round" stroke-linejoin="round" width="18" height="18"'
            .' aria-hidden="true" focusable="false">'.$path.'</svg>';
    }
}
