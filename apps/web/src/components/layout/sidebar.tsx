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
} from "lucide-react";
import { cn } from "@scholaris/ui";

const NAV_ITEMS = [
  { href: "/dashboard", label: "Tableau de bord", icon: LayoutDashboard },
  { href: "/academics/structure", label: "Structure pédagogique", icon: Network },
  { href: "/academics/classrooms", label: "Classes", icon: School },
  { href: "/academics/rooms", label: "Salles", icon: DoorOpen },
  { href: "/academics/subjects", label: "Matières", icon: BookOpen },
  { href: "/academics/teaching-units", label: "UE & EC", icon: Layers },
  { href: "/academics/assignments", label: "Assignations", icon: ClipboardList },
  { href: "/students", label: "Élèves", icon: GraduationCap },
  { href: "/communications", label: "Communication", icon: MessageSquare },
  { href: "/settings/users", label: "Utilisateurs", icon: Users },
  { href: "/settings/academic-years", label: "Années académiques", icon: CalendarDays },
  { href: "/settings/calculation-engine", label: "Moteur de calcul", icon: Calculator },
  { href: "/settings/audit-logs", label: "Journal d'audit", icon: ScrollText },
  { href: "/settings/establishment", label: "Établissement", icon: Settings },
];

export function Sidebar() {
  const pathname = usePathname();

  return (
    <aside className="hidden w-64 shrink-0 border-r border-border bg-background md:flex md:flex-col">
      <div className="flex h-16 items-center border-b border-border px-6">
        <span className="text-lg font-semibold text-primary">SCHOLARIS</span>
      </div>
      <nav className="flex flex-1 flex-col gap-1 p-3">
        {NAV_ITEMS.map(({ href, label, icon: Icon }) => {
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
      </nav>
    </aside>
  );
}
