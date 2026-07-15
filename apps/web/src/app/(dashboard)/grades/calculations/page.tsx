"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { Calculator } from "lucide-react";

export default function GradesCalculationsPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Calculs et moyennes</h1>
          <p className="text-sm text-muted-foreground">
            Calculer les moyennes, classements et résultats par période
          </p>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Moteur de calcul</CardTitle>
          <CardDescription>
            Calcul automatique des moyennes, classements et décisions du conseil
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col items-center justify-center py-12 text-center">
            <Calculator className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">Calculs automatisés</h3>
            <p className="text-sm text-muted-foreground mb-4 max-w-md">
              Cette page permettra de lancer les calculs de moyennes par période,
              de générer les classements et d'appliquer les règles de délibération
              (matières éliminatoires, seuils de validation, mentions).
            </p>
            <Button asChild variant="outline">
              <Link href="/settings/calculation-engine">Voir le moteur de calcul</Link>
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
