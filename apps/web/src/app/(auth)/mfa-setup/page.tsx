"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { apiClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

/** Chantier 3 : enrôlement MFA obligatoire pour les rôles à privilèges (SUPER_ADMIN, Directeur). */
export default function MfaSetupPage() {
  const router = useRouter();
  const [otpauthUrl, setOtpauthUrl] = useState<string | null>(null);
  const [secret, setSecret] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(false);

  async function startEnrollment() {
    setError(null);
    setIsLoading(true);
    try {
      const { data } = await apiClient.post("/auth/mfa/enable");
      setOtpauthUrl(data.data.otpauthUrl);
      setSecret(data.data.secret);
    } catch (err: any) {
      setError(err.response?.data?.error ?? "Impossible de démarrer l'enrôlement MFA.");
    } finally {
      setIsLoading(false);
    }
  }

  async function onVerify(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setIsLoading(true);
    try {
      await apiClient.post("/auth/mfa/verify", { code });
      router.push("/dashboard");
    } catch (err: any) {
      setError(err.response?.data?.error ?? "Code invalide.");
    } finally {
      setIsLoading(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Sécurisation du compte (MFA obligatoire)</CardTitle>
        <CardDescription>
          Votre rôle exige l&apos;authentification à deux facteurs. Scannez le code avec votre application
          d&apos;authentification (Google Authenticator, Authy...) puis saisissez le code généré.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}
        {!otpauthUrl ? (
          <Button onClick={startEnrollment} disabled={isLoading}>
            {isLoading ? "Génération…" : "Générer mon secret MFA"}
          </Button>
        ) : (
          <>
            <p className="break-all rounded bg-secondary p-2 text-xs">{otpauthUrl}</p>
            <p className="text-sm text-muted-foreground">
              Clé secrète (saisie manuelle si le QR code n&apos;est pas disponible) : <strong>{secret}</strong>
            </p>
            <form onSubmit={onVerify} className="flex flex-col gap-3">
              <Input
                inputMode="numeric"
                maxLength={6}
                placeholder="123456"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                required
              />
              <Button type="submit" disabled={isLoading}>
                {isLoading ? "Vérification…" : "Activer le MFA"}
              </Button>
            </form>
          </>
        )}
      </CardContent>
    </Card>
  );
}
