"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { UserCheck } from "lucide-react";

export default function AttendancePage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Présences et absences</h1>
          <p className="text-sm text-muted-foreground">
            Suivi quotidien des présences, absences et retards
          </p>
        </div>
        <Button>Appel de classe</Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Registre des présences</CardTitle>
          <CardDescription>
            Enregistrement et justification des absences
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col items-center justify-center py-12 text-center">
            <UserCheck className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">Gestion des présences</h3>
            <p className="text-sm text-muted-foreground mb-4 max-w-md">
              Cette page permettra de faire l'appel quotidien par classe, de marquer les présences,
              absences, retards et excuses, avec calcul automatique du taux de présence.
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
