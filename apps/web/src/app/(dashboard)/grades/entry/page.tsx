"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { NotebookPen, School } from "lucide-react";

export default function GradesEntryPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Saisie des notes</h1>
          <p className="text-sm text-muted-foreground">
            Saisir les notes par classe, matière et période
          </p>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Sélection classe / matière / période</CardTitle>
          <CardDescription>
            Choisissez la classe, la matière et la période pour commencer la saisie
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col items-center justify-center py-12 text-center">
            <NotebookPen className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">Saisie des notes</h3>
            <p className="text-sm text-muted-foreground mb-4 max-w-md">
              Cette page permettra de saisir les notes individuellement ou en batch pour une classe,
              avec verrouillage par évaluation et calcul automatique des moyennes.
            </p>
            <div className="flex gap-2">
              <Button asChild variant="outline">
                <Link href="/academics/classrooms">
                  <School className="mr-2 h-4 w-4" />
                  Voir les classes
                </Link>
              </Button>
              <Button asChild variant="outline">
                <Link href="/grades/progress">Avancement saisie</Link>
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
