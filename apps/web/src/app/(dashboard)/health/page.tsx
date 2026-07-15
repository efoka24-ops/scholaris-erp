"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { Heart, Plus } from "lucide-react";

export default function HealthPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Santé scolaire</h1>
          <p className="text-sm text-muted-foreground">
            Dossiers médicaux, vaccinations et suivi sanitaire
          </p>
        </div>
        <Button>
          <Plus className="mr-2 h-4 w-4" />
          Nouveau dossier médical
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Dossiers médicaux</CardTitle>
          <CardDescription>
            Fiches de santé, allergies, traitements et vaccinations
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col items-center justify-center py-12 text-center">
            <Heart className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">Suivi médical</h3>
            <p className="text-sm text-muted-foreground mb-4 max-w-md">
              Cette page permettra de consulter les dossiers médicaux des élèves, de suivre les vaccinations,
              d'enregistrer les allergies, traitements en cours et interventions médicales à l'école.
            </p>
            <Button asChild variant="outline">
              <Link href="/students">Voir les élèves</Link>
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
