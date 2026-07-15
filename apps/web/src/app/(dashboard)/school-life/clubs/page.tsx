"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { Trophy, Plus } from "lucide-react";

export default function SchoolLifePage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Clubs & Activités</h1>
          <p className="text-sm text-muted-foreground">
            Gestion des clubs scolaires, événements et activités parascolaires
          </p>
        </div>
        <Button>
          <Plus className="mr-2 h-4 w-4" />
          Nouveau club
        </Button>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Clubs scolaires</CardTitle>
            <CardDescription>
              Liste des clubs et inscriptions des élèves
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col items-center justify-center py-8 text-center">
              <Trophy className="h-10 w-10 text-muted-foreground mb-3" />
              <p className="text-sm text-muted-foreground mb-3">
                Créez et gérez les clubs (sport, théâtre, musique, sciences, etc.)
                avec superviseurs et membres.
              </p>
              <Button variant="outline" size="sm">Créer un club</Button>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Événements scolaires</CardTitle>
            <CardDescription>
              Calendrier des événements et activités
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col items-center justify-center py-8 text-center">
              <Trophy className="h-10 w-10 text-muted-foreground mb-3" />
              <p className="text-sm text-muted-foreground mb-3">
                Planifiez les événements (compétitions, sorties, journées thématiques, etc.)
                avec participants et organisateurs.
              </p>
              <Button variant="outline" size="sm">Créer un événement</Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
