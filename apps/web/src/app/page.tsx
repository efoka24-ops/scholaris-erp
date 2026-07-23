"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { apiClient } from "@/lib/api-client";
import { PublicShell } from "@/components/public/public-shell";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

// Porte d'entrée du site : un visiteur non connecté voit la vitrine publique
// (présentation + pré-inscription), pas un mur de connexion. `/login` reste
// accessible via le bouton "Se connecter" du header.

const TENANT_CODE = process.env.NEXT_PUBLIC_TENANT_CODE ?? "DEMO";

interface PublicTenant {
  code: string;
  name: string;
  type: string;
  address: string | null;
  logoUrl: string | null;
}

const CYCLES = [
  { name: "Maternelle", description: "Éveil et préparation à la vie scolaire" },
  { name: "Primaire", description: "SIL au CM2 — apprentissages fondamentaux" },
  { name: "Secondaire 1er cycle", description: "6ème à la 3ème" },
  { name: "Secondaire 2nd cycle", description: "2nde à la Terminale, toutes séries" },
];

export default function RootPage() {
  const [tenant, setTenant] = useState<PublicTenant | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    apiClient
      .get(`/public/tenants/${encodeURIComponent(TENANT_CODE)}`)
      .then((response) => {
        if (!cancelled) setTenant(response.data.data as PublicTenant);
      })
      .catch(() => {
        if (!cancelled) setTenant(null);
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const establishmentName = tenant?.name ?? (isLoading ? "…" : "Notre établissement");

  return (
    <PublicShell>
      <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-12 px-4 py-10 sm:px-8">
        <section className="flex flex-col items-start gap-4 text-left">
          <h1 className="text-3xl font-bold sm:text-4xl">Bienvenue à {establishmentName}</h1>
          <p className="max-w-2xl text-muted-foreground">
            Un établissement engagé pour la réussite de chaque élève, de la maternelle au second cycle du
            secondaire. Déposez une demande de pré-inscription en quelques minutes : notre équipe vous
            recontactera pour finaliser le dossier.
          </p>
          {tenant?.address ? <p className="text-sm text-muted-foreground">{tenant.address}</p> : null}
          <div className="mt-2 flex flex-wrap gap-3">
            <Button asChild size="lg">
              <Link href="/inscription">Faire une pré-inscription</Link>
            </Button>
            <Button asChild variant="outline" size="lg">
              <Link href="/creer-etablissement">Créer un établissement</Link>
            </Button>
            <Button asChild variant="outline" size="lg">
              <Link href="/login">Se connecter</Link>
            </Button>
          </div>
          <p className="text-xs text-muted-foreground">
            Directeur d&apos;école ? « Créer un établissement » vous permet de demander l&apos;ouverture de votre
            espace ; après validation, vous recevrez vos identifiants par email.
          </p>
        </section>

        <section>
          <h2 className="mb-4 text-xl font-semibold">Nos cycles</h2>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {CYCLES.map((cycle) => (
              <Card key={cycle.name}>
                <CardHeader>
                  <CardTitle className="text-base">{cycle.name}</CardTitle>
                  <CardDescription>{cycle.description}</CardDescription>
                </CardHeader>
              </Card>
            ))}
          </div>
        </section>

        <section>
          <Card>
            <CardContent className="flex flex-col items-start gap-3 py-6">
              <h2 className="text-lg font-semibold">Comment se déroule l'admission ?</h2>
              <ol className="list-inside list-decimal text-sm text-muted-foreground">
                <li>Vous déposez une pré-inscription en ligne pour votre enfant.</li>
                <li>Notre secrétariat étudie le dossier et vous contacte par téléphone.</li>
                <li>Vous complétez le dossier physique et finalisez l'inscription à l'établissement.</li>
              </ol>
              <Button asChild className="mt-2">
                <Link href="/inscription">Commencer maintenant</Link>
              </Button>
            </CardContent>
          </Card>
        </section>
      </div>
    </PublicShell>
  );
}
