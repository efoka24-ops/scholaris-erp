"use client";

import { useEffect, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Settings } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import { useAuth } from "@/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/shared/form";
import { LoadingSpinner } from "@/components/shared/loading-spinner";
import type { Tenant } from "@/types/settings";

const tenantSchema = z.object({
  name: z.string().min(1, "Nom requis"),
  address: z.string().optional(),
  phone: z.string().optional(),
  email: z.union([z.string().email("Email invalide"), z.literal("")]).optional(),
  logoUrl: z.union([z.string().url("URL invalide"), z.literal("")]).optional(),
});
type TenantInput = z.infer<typeof tenantSchema>;

const AVAILABLE_MODULES: { code: string; label: string }[] = [
  { code: "students", label: "Élèves & Admissions" },
  { code: "grades", label: "Notes & Bulletins" },
  { code: "finance", label: "Gestion financière" },
  { code: "communication", label: "Communication multicanal" },
  { code: "timetables", label: "Emplois du temps" },
  { code: "attendance", label: "Présences" },
  { code: "discipline", label: "Discipline" },
  { code: "school-life", label: "Vie scolaire" },
  { code: "library", label: "Bibliothèque" },
  { code: "transport", label: "Transport" },
  { code: "catering", label: "Restauration" },
  { code: "assets", label: "Patrimoine" },
  { code: "hr", label: "Ressources humaines & Paie" },
];

export default function EstablishmentSettingsPage() {
  const { user, hasPermission } = useAuth();
  const [tenant, setTenant] = useState<Tenant | null>(null);
  const [enabledModules, setEnabledModules] = useState<string[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [serverError, setServerError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const canUpdate = hasPermission("tenants:update");

  const form = useForm<TenantInput>({
    resolver: zodResolver(tenantSchema),
    defaultValues: { name: "", address: "", phone: "", email: "", logoUrl: "" },
  });

  useEffect(() => {
    if (!user?.tenantId) return;
    setIsLoading(true);
    Promise.all([
      resourceClient.get(`/tenants/${user.tenantId}`),
      resourceClient.get(`/tenants/${user.tenantId}/modules`),
    ])
      .then(([tenantRes, modulesRes]) => {
        const t: Tenant = tenantRes.data?.data ?? tenantRes.data;
        setTenant(t);
        form.reset({
          name: t.name,
          address: t.address ?? "",
          phone: t.phone ?? "",
          email: t.email ?? "",
          logoUrl: t.logoUrl ?? "",
        });
        setEnabledModules(modulesRes.data?.data ?? modulesRes.data ?? []);
      })
      .finally(() => setIsLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.tenantId]);

  async function onSubmit(values: TenantInput) {
    if (!user?.tenantId) return;
    setServerError(null);
    setSuccessMessage(null);
    try {
      const payload = {
        name: values.name,
        ...(values.address ? { address: values.address } : {}),
        ...(values.phone ? { phone: values.phone } : {}),
        ...(values.email ? { email: values.email } : {}),
        ...(values.logoUrl ? { logoUrl: values.logoUrl } : {}),
      };
      const { data } = await resourceClient.put(`/tenants/${user.tenantId}`, payload);
      setTenant(data?.data ?? data);
      setSuccessMessage("Informations de l'établissement enregistrées.");
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Impossible d'enregistrer les informations.");
    }
  }

  async function onToggleModule(code: string, checked: boolean) {
    if (!user?.tenantId) return;
    const next = checked ? [...enabledModules, code] : enabledModules.filter((m) => m !== code);
    setEnabledModules(next);
    setServerError(null);
    try {
      await resourceClient.put(`/tenants/${user.tenantId}/modules`, { enabledModules: next });
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Impossible de mettre à jour les modules activés.");
      setEnabledModules(enabledModules);
    }
  }

  if (isLoading) {
    return <LoadingSpinner label="Chargement des informations de l'établissement…" />;
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Paramètres de l'établissement</h1>
        <p className="text-sm text-muted-foreground">Configuration générale de votre établissement scolaire</p>
      </div>

      {serverError ? <p className="text-sm font-medium text-destructive">{serverError}</p> : null}
      {successMessage ? <p className="text-sm font-medium text-primary">{successMessage}</p> : null}

      <Card>
        <CardHeader>
          <CardTitle>Informations générales</CardTitle>
          <CardDescription>Nom, adresse, contacts et identifiants officiels</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <FormLabel>Code établissement</FormLabel>
              <Input value={tenant?.code ?? ""} disabled />
            </div>
            <div className="space-y-2">
              <FormLabel>Type</FormLabel>
              <Input value={tenant?.type ?? ""} disabled />
            </div>
          </div>

          <Form {...form}>
            <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
              <div className="grid gap-4 md:grid-cols-2">
                <FormField
                  control={form.control}
                  name="name"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Nom de l'établissement</FormLabel>
                      <FormControl>
                        <Input disabled={!canUpdate} {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="phone"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Téléphone</FormLabel>
                      <FormControl>
                        <Input disabled={!canUpdate} {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="email"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Email de contact</FormLabel>
                      <FormControl>
                        <Input type="email" disabled={!canUpdate} {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="logoUrl"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>URL du logo</FormLabel>
                      <FormControl>
                        <Input disabled={!canUpdate} {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="address"
                  render={({ field }) => (
                    <FormItem className="md:col-span-2">
                      <FormLabel>Adresse</FormLabel>
                      <FormControl>
                        <Input disabled={!canUpdate} {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </div>
              {canUpdate ? (
                <Button type="submit" disabled={form.formState.isSubmitting} className="w-fit">
                  {form.formState.isSubmitting ? "Enregistrement…" : "Enregistrer les modifications"}
                </Button>
              ) : null}
            </form>
          </Form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Modules & fonctionnalités</CardTitle>
          <CardDescription>Activez les modules utilisés par cet établissement</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3">
            {AVAILABLE_MODULES.map((module) => (
              <label key={module.code} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  className="h-4 w-4"
                  checked={enabledModules.includes(module.code)}
                  disabled={!canUpdate}
                  onChange={(e) => onToggleModule(module.code, e.target.checked)}
                />
                {module.label}
              </label>
            ))}
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="flex flex-col items-center justify-center py-8 text-center">
          <Settings className="h-10 w-10 text-muted-foreground mb-3" />
          <p className="text-sm text-muted-foreground max-w-md">
            Le moteur de calcul (types d'évaluation, pondérations, mentions) se configure dans
            « Moteur de calcul », spécifique à cet établissement.
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
