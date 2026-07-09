import type { Metadata } from "next";
import { AuthProvider } from "@/lib/auth-context";
import { TenantProvider } from "@/lib/tenant-context";
import "./globals.css";

export const metadata: Metadata = {
  title: "SCHOLARIS ERP",
  description: "ERP national de gestion des établissements scolaires et universitaires",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="fr">
      <body>
        <AuthProvider>
          <TenantProvider>{children}</TenantProvider>
        </AuthProvider>
      </body>
    </html>
  );
}
