"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { Calendar, Plus } from "lucide-react";

export default function TimetablesPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Emplois du temps</h1>
          <p className="text-sm text-muted-foreground">
            Gestion et consultation des emplois du temps des classes et enseignants
          </p>
        </div>
        <Button>
          <Plus className="mr-2 h-4 w-4" />
          Nouveau créneau
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Planning hebdomadaire</CardTitle>
          <CardDescription>
            Visualisation et édition des emplois du temps avec détection de conflits
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col items-center justify-center py-12 text-center">
            <Calendar className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">Emplois du temps</h3>
            <p className="text-sm text-muted-foreground mb-4 max-w-md">
              Cette page permettra de créer et gérer les emplois du temps par classe et par enseignant,
              avec détection automatique des conflits de salle et de professeur.
            </p>
            <Button asChild variant="outline">
              <Link href="/academics/classrooms">Voir les classes</Link>
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
