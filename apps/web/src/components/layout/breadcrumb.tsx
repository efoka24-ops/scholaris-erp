"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { ChevronRight } from "lucide-react";

const LABELS: Record<string, string> = {
  dashboard: "Tableau de bord",
  settings: "Paramètres",
  users: "Utilisateurs",
  establishment: "Établissement",
  students: "Élèves",
};

export function Breadcrumb() {
  const pathname = usePathname();
  const segments = pathname.split("/").filter(Boolean);

  return (
    <nav aria-label="Fil d'Ariane" className="flex items-center gap-1.5 text-sm text-muted-foreground">
      <Link href="/dashboard" className="hover:text-foreground">
        Accueil
      </Link>
      {segments.map((segment, index) => {
        const href = `/${segments.slice(0, index + 1).join("/")}`;
        const isLast = index === segments.length - 1;
        return (
          <span key={href} className="flex items-center gap-1.5">
            <ChevronRight className="h-3.5 w-3.5" />
            {isLast ? (
              <span className="font-medium text-foreground">{LABELS[segment] ?? segment}</span>
            ) : (
              <Link href={href} className="hover:text-foreground">
                {LABELS[segment] ?? segment}
              </Link>
            )}
          </span>
        );
      })}
    </nav>
  );
}
