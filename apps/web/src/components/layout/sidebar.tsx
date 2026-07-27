"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  LayoutDashboard,
  Settings,
  Users,
  GraduationCap,
  Network,
  DoorOpen,
  School,
  MessageSquare,
  CalendarDays,
  Calculator,
  ScrollText,
  BookOpen,
  Layers,
  ClipboardList,
  Repeat,
  Wallet,
  Receipt,
  PiggyBank,
  NotebookPen,
  FileText,
  Calendar,
  UserCheck,
  ShieldAlert,
  Heart,
  Trophy,
  Library,
  Bus,
  UtensilsCrossed,
  Building,
  Briefcase,
  ChevronDown,
  ChevronRight,
} from "lucide-react";
import { cn } from "@scholaris/ui";
import { useEffect, useMemo, useState } from "react";
import { useAuth } from "@/hooks/use-auth";
import { resourceClient } from "@/lib/api-client";
import {
  isMenuVisible,
  isOptionalVisible,
  resolveCategory,
  type EstablishmentCategory,
} from "@/lib/establishment-features";

type NavItem = {
  href: string;
  label: string;
  icon: any;
};

type NavSection = {
  label: string;
  items: NavItem[];
};

const NAV_SECTIONS: NavSection[] = [
  {
    label: "Principal",
    items: [{ href: "/dashboard", label: "Tableau de bord", icon: LayoutDashboard }],
  },
  {
    label: "Académique",
    items: [
      { href: "/academics/structure", label: "Structure pédagogique", icon: Network },
      { href: "/academics/classrooms", label: "Classes", icon: School },
      { href: "/academics/rooms", label: "Salles", icon: DoorOpen },
      { href: "/academics/subjects", label: "Matières", icon: BookOpen },
      { href: "/academics/teaching-units", label: "UE & EC", icon: Layers },
      { href: "/academics/assignments", label: "Assignations", icon: ClipboardList },
      { href: "/settings/academic-years", label: "Années académiques", icon: CalendarDays },
    ],
  },
  {
    label: "Élèves",
    items: [
      { href: "/students", label: "Élèves", icon: GraduationCap },
      { href: "/admissions", label: "Admissions", icon: ClipboardList },
      { href: "/enrollments", label: "Inscriptions", icon: Repeat },
    ],
  },
  {
    label: "Notes & Bulletins",
    items: [
      { href: "/grades/entry", label: "Saisie des notes", icon: NotebookPen },
      { href: "/grades/calculations", label: "Calculs", icon: Calculator },
      { href: "/bulletins", label: "Bulletins", icon: FileText },
    ],
  },
  {
    label: "Examens officiels",
    items: [{ href: "/exams", label: "Examens (CEP/BEPC/BAC)", icon: ScrollText }],
  },
  {
    label: "Rapports",
    items: [{ href: "/reports/level", label: "Rapport par niveau", icon: Layers }],
  },
  {
    label: "Finance",
    items: [
      { href: "/finance/dashboard", label: "Tableau de bord", icon: Wallet },
      { href: "/finance/fee-structures", label: "Grilles tarifaires", icon: PiggyBank },
      { href: "/finance/invoices", label: "Factures", icon: Receipt },
      { href: "/finance/payments", label: "Paiements", icon: Wallet },
    ],
  },
  {
    label: "Vie Scolaire",
    items: [
      { href: "/timetables", label: "Emplois du temps", icon: Calendar },
      { href: "/attendance", label: "Présences", icon: UserCheck },
      { href: "/discipline", label: "Discipline", icon: ShieldAlert },
      { href: "/health", label: "Santé scolaire", icon: Heart },
      { href: "/school-life/clubs", label: "Clubs & Activités", icon: Trophy },
      { href: "/library", label: "Bibliothèque", icon: Library },
      { href: "/transport", label: "Transport", icon: Bus },
      { href: "/catering", label: "Cantine & Internat", icon: UtensilsCrossed },
    ],
  },
  {
    label: "Gestion",
    items: [
      { href: "/assets", label: "Patrimoine", icon: Building },
      { href: "/hr", label: "RH & Paie", icon: Briefcase },
    ],
  },
  {
    label: "Communication",
    items: [
      { href: "/communications", label: "Messages", icon: MessageSquare },
      { href: "/communications/templates", label: "Templates", icon: FileText },
    ],
  },
  {
    label: "Configuration",
    items: [
      { href: "/settings/profile", label: "Mon profil", icon: UserCheck },
      { href: "/settings/establishments", label: "Établissements", icon: Building },
      { href: "/settings/establishment-requests", label: "Demandes d'établissement", icon: ClipboardList },
      { href: "/settings/establishment", label: "Mon établissement", icon: Settings },
      { href: "/settings/users", label: "Utilisateurs", icon: Users },
      { href: "/settings/roles", label: "Rôles & Permissions", icon: Users },
      { href: "/settings/calculation-engine", label: "Moteur de calcul", icon: Calculator },
      { href: "/settings/bulletin-groups", label: "Groupes de matières", icon: Layers },
      { href: "/settings/modules", label: "Modules & fonctionnalités", icon: Settings },
      { href: "/settings/audit-logs", label: "Journal d'audit", icon: ScrollText },
    ],
  },
];

export function Sidebar() {
  const pathname = usePathname();
  const { user, hasPermission } = useAuth();
  const [category, setCategory] = useState<EstablishmentCategory | null>(null);
  const [enabledModules, setEnabledModules] = useState<string[] | null>(null);
  const isSuperAdmin = hasPermission("tenants:create");

  // Récupère le type + les modules activés de l'établissement pour filtrer les menus.
  useEffect(() => {
    if (!user?.tenantId) return;
    let cancelled = false;
    resourceClient
      .get<{ type?: string; configJson?: { establishmentCategory?: string } }>(`/tenants/${user.tenantId}`)
      .then((r) => {
        if (!cancelled) setCategory(resolveCategory(r.data.type, r.data.configJson?.establishmentCategory));
      })
      .catch(() => {
        if (!cancelled) setCategory(null);
      });
    resourceClient
      .get<string[]>(`/tenants/${user.tenantId}/modules`)
      .then((r) => {
        if (!cancelled) setEnabledModules(Array.isArray(r.data) ? r.data : []);
      })
      .catch(() => {
        if (!cancelled) setEnabledModules([]);
      });
    return () => {
      cancelled = true;
    };
  }, [user?.tenantId]);

  // Sections filtrées selon le type d'établissement + modules optionnels activés.
  const sections = useMemo(() => {
    const cat = category ?? "COLLEGE";
    return NAV_SECTIONS.map((section) => ({
      ...section,
      items: section.items.filter(
        (item) =>
          isMenuVisible(item.href, cat, isSuperAdmin) &&
          (isSuperAdmin || isOptionalVisible(item.href, enabledModules)),
      ),
    })).filter((section) => section.items.length > 0);
  }, [category, enabledModules, isSuperAdmin]);

  const [expandedSections, setExpandedSections] = useState<string[]>([
    "Principal",
    "Académique",
    "Élèves",
  ]);

  const toggleSection = (label: string) => {
    setExpandedSections((prev) =>
      prev.includes(label) ? prev.filter((s) => s !== label) : [...prev, label],
    );
  };

  return (
    <aside className="hidden w-64 shrink-0 border-r border-border bg-background md:flex md:flex-col">
      <div className="flex h-16 items-center border-b border-border px-6">
        <span className="text-lg font-semibold text-primary">SCHOLARIS</span>
      </div>
      <nav className="flex flex-1 flex-col gap-2 overflow-y-auto p-3">
        {sections.map((section) => {
          const isExpanded = expandedSections.includes(section.label);
          return (
            <div key={section.label}>
              <button
                onClick={() => toggleSection(section.label)}
                className="flex w-full items-center justify-between rounded-md px-3 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground hover:bg-secondary"
              >
                <span>{section.label}</span>
                {isExpanded ? (
                  <ChevronDown className="h-3 w-3" />
                ) : (
                  <ChevronRight className="h-3 w-3" />
                )}
              </button>
              {isExpanded && (
                <div className="mt-1 space-y-1">
                  {section.items.map(({ href, label, icon: Icon }) => {
                    const isActive = pathname === href || pathname.startsWith(`${href}/`);
                    return (
                      <Link
                        key={href}
                        href={href}
                        className={cn(
                          "flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground",
                          isActive && "bg-secondary text-foreground",
                        )}
                      >
                        <Icon className="h-4 w-4" />
                        {label}
                      </Link>
                    );
                  })}
                </div>
              )}
            </div>
          );
        })}
      </nav>
    </aside>
  );
}
