"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { Users, Plus, UserCog } from "lucide-react";

export default function UsersSettingsPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Gestion des utilisateurs</h1>
          <p className="text-sm text-muted-foreground">
            Comptes utilisateurs, rôles et permissions
          </p>
        </div>
        <Button>
          <Plus className="mr-2 h-4 w-4" />
          Nouvel utilisateur
        </Button>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Utilisateurs</CardTitle>
            <CardDescription>
              Liste des comptes et statuts
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col items-center justify-center py-8 text-center">
              <Users className="h-10 w-10 text-muted-foreground mb-3" />
              <p className="text-sm text-muted-foreground mb-3">
                Gérez les comptes utilisateurs (admin, directeur, enseignants, personnel)
                avec activation, suspension et réinitialisation de mots de passe.
              </p>
              <Button variant="outline" size="sm">Voir les utilisateurs</Button>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Rôles & Permissions</CardTitle>
            <CardDescription>
              Configuration RBAC
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col items-center justify-center py-8 text-center">
              <UserCog className="h-10 w-10 text-muted-foreground mb-3" />
              <p className="text-sm text-muted-foreground mb-3">
                Créez des rôles personnalisés et assignez des permissions granulaires
                (lecture, écriture, suppression) par module.
              </p>
              <Button variant="outline" size="sm">Gérer les rôles</Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
