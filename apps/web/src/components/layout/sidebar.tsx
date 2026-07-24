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
import { useState } from "react";

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
      { href: "/settings/establishments", label: "Établissements", icon: Building },
      { href: "/settings/establishment-requests", label: "Demandes d'établissement", icon: ClipboardList },
      { href: "/settings/establishment", label: "Mon établissement", icon: Settings },
      { href: "/settings/users", label: "Utilisateurs", icon: Users },
      { href: "/settings/roles", label: "Rôles & Permissions", icon: Users },
      { href: "/settings/calculation-engine", label: "Moteur de calcul", icon: Calculator },
      { href: "/settings/bulletin-groups", label: "Groupes de matières", icon: Layers },
      { href: "/settings/audit-logs", label: "Journal d'audit", icon: ScrollText },
    ],
  },
];

export function Sidebar() {
  const pathname = usePathname();
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
        {NAV_SECTIONS.map((section) => {
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
