import Link from "next/link";
import { Button } from "@/components/ui/button";

const TENANT_CODE = process.env.NEXT_PUBLIC_TENANT_CODE ?? "DEMO";

/**
 * En-tête + pied de page communs au site public (vitrine "/" et
 * "/inscription") : partagé entre `app/page.tsx` (racine, hors route group)
 * et `app/(public)/layout.tsx` pour éviter la duplication de markup.
 */
export function PublicShell({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen flex-col bg-background">
      <header className="flex items-center justify-between border-b border-border px-4 py-3 sm:px-8">
        <Link href="/" className="flex items-center gap-2 font-semibold">
          <span className="flex h-8 w-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
            S
          </span>
          <span>SCHOLARIS — {TENANT_CODE}</span>
        </Link>
        <Button asChild variant="outline" size="sm">
          <Link href="/login">Se connecter</Link>
        </Button>
      </header>
      <main className="flex flex-1 flex-col">{children}</main>
      <footer className="border-t border-border px-4 py-4 text-center text-xs text-muted-foreground sm:px-8">
        © {new Date().getFullYear()} SCHOLARIS ERP — Plateforme de gestion scolaire
      </footer>
    </div>
  );
}
