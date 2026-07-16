import { PublicShell } from "@/components/public/public-shell";

/**
 * Layout du site public (pages sans authentification, ex: "/inscription"),
 * séparé du dashboard authentifié : pas de sidebar/topbar, juste un en-tête
 * minimal (voir PublicShell, partagé avec la page vitrine "/" qui vit hors
 * de ce route group puisqu'elle occupe la racine "/").
 *
 * Limitation MVP : un seul établissement public à la fois, identifié par
 * NEXT_PUBLIC_TENANT_CODE (défaut "DEMO"). Une vraie solution multi-tenant
 * publique nécessiterait un sous-domaine ou un slug d'URL par établissement
 * (ex: etablissement.scholaris.cm ou /e/[slug]) — hors scope de ce MVP.
 */
export default function PublicLayout({ children }: { children: React.ReactNode }) {
  return <PublicShell>{children}</PublicShell>;
}
