"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { ShieldAlert, Plus } from "lucide-react";

export default function DisciplinePage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Discipline</h1>
          <p className="text-sm text-muted-foreground">
            Gestion des incidents et sanctions disciplinaires
          </p>
        </div>
        <Button>
          <Plus className="mr-2 h-4 w-4" />
          Signaler un incident
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Registre disciplinaire</CardTitle>
          <CardDescription>
            Incidents, sanctions et suivi comportemental des élèves
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col items-center justify-center py-12 text-center">
            <ShieldAlert className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">Gestion disciplinaire</h3>
            <p className="text-sm text-muted-foreground mb-4 max-w-md">
              Cette page permettra d'enregistrer les incidents (retard, insolence, bagarre, etc.),
              d'appliquer des sanctions (avertissement, exclusion, convocation) et de générer
              le dossier disciplinaire de chaque élève.
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
