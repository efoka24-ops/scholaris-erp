"use client";

import { useAuth } from "@/hooks/use-auth";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

// Widgets génériques pour la Phase 0 : les KPI réels (effectifs, recouvrement,
// avancement des notes...) arrivent module par module — voir §1.5 et suivants.
const PLACEHOLDER_WIDGETS = [
  { title: "Établissement", description: "Configuration et statut du tenant courant" },
  { title: "Utilisateurs actifs", description: "Comptes créés sur cet établissement" },
  { title: "Année académique", description: "Période en cours et statut de saisie" },
];

export default function DashboardPage() {
  const { user, isLoading } = useAuth();

  if (isLoading) {
    return <LoadingSpinner label="Chargement du tableau de bord…" />;
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Tableau de bord</h1>
        <p className="text-sm text-muted-foreground">
          Connecté en tant que {user?.email} — {user?.permissions.length ?? 0} permission(s) résolue(s)
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {PLACEHOLDER_WIDGETS.map((widget) => (
          <Card key={widget.title}>
            <CardHeader>
              <CardTitle>{widget.title}</CardTitle>
              <CardDescription>{widget.description}</CardDescription>
            </CardHeader>
            <CardContent className="text-sm text-muted-foreground">À implémenter avec le module correspondant.</CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}
