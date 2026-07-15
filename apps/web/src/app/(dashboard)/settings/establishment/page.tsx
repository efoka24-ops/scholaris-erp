"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Settings } from "lucide-react";

export default function EstablishmentSettingsPage() {
  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Paramètres de l'établissement</h1>
        <p className="text-sm text-muted-foreground">
          Configuration générale de votre établissement scolaire
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Informations générales</CardTitle>
          <CardDescription>
            Nom, adresse, contacts et identifiants officiels
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="name">Nom de l'établissement</Label>
              <Input id="name" placeholder="Lycée XYZ" disabled />
            </div>
            <div className="space-y-2">
              <Label htmlFor="code">Code établissement</Label>
              <Input id="code" placeholder="DEMO" disabled />
            </div>
            <div className="space-y-2">
              <Label htmlFor="type">Type</Label>
              <Input id="type" placeholder="Secondaire" disabled />
            </div>
            <div className="space-y-2">
              <Label htmlFor="status">Statut</Label>
              <Input id="status" placeholder="Public" disabled />
            </div>
          </div>
          <div className="flex flex-col items-center justify-center py-8 text-center border-t mt-6">
            <Settings className="h-10 w-10 text-muted-foreground mb-3" />
            <p className="text-sm text-muted-foreground mb-3 max-w-md">
              Cette page permettra de modifier les informations de l'établissement,
              de configurer le logo, les coordonnées, les horaires et les paramètres généraux.
            </p>
            <Button variant="outline" disabled>Modifier les paramètres</Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
