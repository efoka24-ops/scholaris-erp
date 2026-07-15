"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { FileText, Plus } from "lucide-react";

export default function CommunicationTemplatesPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Templates de communication</h1>
          <p className="text-sm text-muted-foreground">
            Modèles réutilisables pour emails et SMS
          </p>
        </div>
        <Button>
          <Plus className="mr-2 h-4 w-4" />
          Nouveau template
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Bibliothèque de templates</CardTitle>
          <CardDescription>
            Modèles personnalisables avec variables dynamiques
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col items-center justify-center py-12 text-center">
            <FileText className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">Templates de messages</h3>
            <p className="text-sm text-muted-foreground mb-4 max-w-md">
              Cette page permettra de créer et gérer des templates réutilisables pour vos communications
              (convocations, absences, résultats, événements) avec variables dynamiques
              (nom élève, classe, date, etc.).
            </p>
            <Button asChild variant="outline">
              <Link href="/communications">Voir les messages</Link>
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
