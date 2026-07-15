"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { Building, Plus } from "lucide-react";

export default function AssetsPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Patrimoine</h1>
          <p className="text-sm text-muted-foreground">
            Inventaire et maintenance du patrimoine de l'établissement
          </p>
        </div>
        <Button>
          <Plus className="mr-2 h-4 w-4" />
          Ajouter un bien
        </Button>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Inventaire</CardTitle>
            <CardDescription>
              Mobilier, équipements, bâtiments et véhicules
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col items-center justify-center py-8 text-center">
              <Building className="h-10 w-10 text-muted-foreground mb-3" />
              <p className="text-sm text-muted-foreground mb-3">
                Gérez l'inventaire complet avec catégories (mobilier, équipement, bâtiment, véhicule),
                valeurs d'acquisition et états (actif, endommagé, hors service).
              </p>
              <Button variant="outline" size="sm">Voir l'inventaire</Button>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Maintenance</CardTitle>
            <CardDescription>
              Historique et planification des réparations
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col items-center justify-center py-8 text-center">
              <Building className="h-10 w-10 text-muted-foreground mb-3" />
              <p className="text-sm text-muted-foreground mb-3">
                Enregistrez les interventions de maintenance avec dates, descriptions,
                coûts et techniciens responsables.
              </p>
              <Button variant="outline" size="sm">Planifier maintenance</Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
