"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { UtensilsCrossed, Plus } from "lucide-react";

export default function CateringPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Cantine & Internat</h1>
          <p className="text-sm text-muted-foreground">
            Gestion des menus, abonnements cantine et chambres d'internat
          </p>
        </div>
        <Button>
          <Plus className="mr-2 h-4 w-4" />
          Nouveau menu
        </Button>
      </div>

      <div className="grid gap-6 md:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle>Menus</CardTitle>
            <CardDescription>Planification hebdomadaire</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col items-center justify-center py-8 text-center">
              <UtensilsCrossed className="h-10 w-10 text-muted-foreground mb-3" />
              <p className="text-sm text-muted-foreground mb-3">
                Créez les menus de la semaine avec entrée, plat et dessert.
              </p>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Abonnements cantine</CardTitle>
            <CardDescription>Élèves inscrits</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col items-center justify-center py-8 text-center">
              <UtensilsCrossed className="h-10 w-10 text-muted-foreground mb-3" />
              <p className="text-sm text-muted-foreground mb-3">
                Gérez les abonnements journaliers ou hebdomadaires.
              </p>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Dortoirs</CardTitle>
            <CardDescription>Chambres et lits</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col items-center justify-center py-8 text-center">
              <UtensilsCrossed className="h-10 w-10 text-muted-foreground mb-3" />
              <p className="text-sm text-muted-foreground mb-3">
                Attribuez les chambres d'internat aux internes.
              </p>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
