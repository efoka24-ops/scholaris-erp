"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { FileText, Download } from "lucide-react";

export default function BulletinsPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Bulletins scolaires</h1>
          <p className="text-sm text-muted-foreground">
            Génération, visualisation et envoi des bulletins de notes
          </p>
        </div>
        <Button>
          <Download className="mr-2 h-4 w-4" />
          Générer bulletins
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Liste des bulletins</CardTitle>
          <CardDescription>
            Consultez et téléchargez les bulletins par période et par classe
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col items-center justify-center py-12 text-center">
            <FileText className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">Génération de bulletins</h3>
            <p className="text-sm text-muted-foreground mb-4 max-w-md">
              Cette page permettra de générer les bulletins en PDF, de les prévisualiser,
              de les télécharger individuellement ou en batch, et de les envoyer par email
              aux parents.
            </p>
            <Button asChild variant="outline">
              <Link href="/grades/results">Voir les résultats</Link>
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
