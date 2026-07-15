"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { Library, Plus, Search } from "lucide-react";

export default function LibraryPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Bibliothèque</h1>
          <p className="text-sm text-muted-foreground">
            Catalogue de livres, emprunts et retours
          </p>
        </div>
        <Button>
          <Plus className="mr-2 h-4 w-4" />
          Ajouter un livre
        </Button>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Catalogue</CardTitle>
            <CardDescription>
              Recherche et gestion des ouvrages
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col items-center justify-center py-8 text-center">
              <Search className="h-10 w-10 text-muted-foreground mb-3" />
              <p className="text-sm text-muted-foreground mb-3">
                Consultez le catalogue complet avec recherche par titre, auteur, ISBN ou catégorie.
              </p>
              <Button variant="outline" size="sm">Rechercher un livre</Button>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Emprunts en cours</CardTitle>
            <CardDescription>
              Suivi des emprunts et retours
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col items-center justify-center py-8 text-center">
              <Library className="h-10 w-10 text-muted-foreground mb-3" />
              <p className="text-sm text-muted-foreground mb-3">
                Gérez les emprunts et retours avec alertes automatiques pour les retards.
              </p>
              <Button variant="outline" size="sm">Enregistrer un emprunt</Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
