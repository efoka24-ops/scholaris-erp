"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { Wallet, Plus } from "lucide-react";

export default function PaymentsPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Paiements</h1>
          <p className="text-sm text-muted-foreground">
            Enregistrement et suivi des paiements des frais de scolarité
          </p>
        </div>
        <Button asChild>
          <Link href="/finance/payments/new">
            <Plus className="mr-2 h-4 w-4" />
            Enregistrer un paiement
          </Link>
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Liste des paiements</CardTitle>
          <CardDescription>
            Historique des paiements avec méthodes et reçus
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col items-center justify-center py-12 text-center">
            <Wallet className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">Gestion des paiements</h3>
            <p className="text-sm text-muted-foreground mb-4 max-w-md">
              Cette page affichera l'historique complet des paiements avec filtres par élève,
              date, méthode de paiement (espèces, chèque, mobile money, virement) et génération
              automatique de reçus.
            </p>
            <div className="flex gap-2">
              <Button asChild variant="outline">
                <Link href="/finance/invoices">Voir les factures</Link>
              </Button>
              <Button asChild variant="outline">
                <Link href="/finance/dashboard">Tableau de bord</Link>
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
