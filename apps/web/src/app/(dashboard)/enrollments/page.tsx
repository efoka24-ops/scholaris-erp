"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { GraduationCap, Plus } from "lucide-react";

export default function EnrollmentsPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Inscriptions</h1>
          <p className="text-sm text-muted-foreground">
            Gestion des inscriptions des élèves par année académique et classe
          </p>
        </div>
        <Button asChild>
          <Link href="/students">
            <Plus className="mr-2 h-4 w-4" />
            Nouvelle inscription
          </Link>
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Liste des inscriptions</CardTitle>
          <CardDescription>
            Filtrez et consultez les inscriptions par année, classe ou statut
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col items-center justify-center py-12 text-center">
            <GraduationCap className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">Page en cours de développement</h3>
            <p className="text-sm text-muted-foreground mb-4 max-w-md">
              Cette page affichera la liste complète des inscriptions avec filtres par année académique,
              classe, statut (actif, suspendu, annulé) et type (nouveau, redoublant, transfert).
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
