"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Plus, UserCog, KeyRound } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import { useAuth } from "@/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/shared/form";
import { LoadingSpinner } from "@/components/shared/loading-spinner";
import type { RoleSummary, UserAccount, UserAccountStatus } from "@/types/settings";

const STATUS_LABELS: Record<UserAccountStatus, string> = {
  ACTIVE: "Actif",
  INACTIVE: "Inactif",
  SUSPENDED: "Suspendu",
};

const createUserSchema = z.object({
  email: z.string().email("Adresse email invalide"),
  password: z.string().min(8, "8 caractères minimum"),
  firstName: z.string().min(1, "Prénom requis"),
  lastName: z.string().min(1, "Nom requis"),
  phone: z.string().optional(),
  roleIds: z.array(z.string()).optional(),
});
type CreateUserInput = z.infer<typeof createUserSchema>;

export default function UsersSettingsPage() {
  const { hasPermission } = useAuth();
  const [users, setUsers] = useState<UserAccount[]>([]);
  const [roles, setRoles] = useState<RoleSummary[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showCreateForm, setShowCreateForm] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);
  const [infoMessage, setInfoMessage] = useState<string | null>(null);

  const canCreate = hasPermission("users:create");
  const canUpdate = hasPermission("users:update");

  const loadUsers = async () => {
    setIsLoading(true);
    try {
      const { data } = await resourceClient.get("/users", { params: { limit: 100 } });
      setUsers(data.data ?? []);
    } finally {
      setIsLoading(false);
    }
  };

  const loadRoles = async () => {
    try {
      const { data } = await resourceClient.get("/roles", { params: { limit: 100 } });
      setRoles(data.data ?? []);
    } catch {
      setRoles([]);
    }
  };

  useEffect(() => {
    loadUsers();
    loadRoles();
  }, []);

  const form = useForm<CreateUserInput>({
    resolver: zodResolver(createUserSchema),
    defaultValues: { email: "", password: "", firstName: "", lastName: "", phone: "", roleIds: [] },
  });

  async function onCreate(values: CreateUserInput) {
    setServerError(null);
    try {
      await resourceClient.post("/users", values);
      form.reset({ email: "", password: "", firstName: "", lastName: "", phone: "", roleIds: [] });
      setShowCreateForm(false);
      await loadUsers();
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Impossible de créer l'utilisateur.");
    }
  }

  async function onChangeStatus(id: string, status: UserAccountStatus) {
    setServerError(null);
    try {
      await resourceClient.put(`/users/${id}/status`, { status });
      await loadUsers();
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Impossible de modifier le statut de ce compte.");
    }
  }

  async function onResetPassword(id: string) {
    setServerError(null);
    setInfoMessage(null);
    try {
      const { data } = await resourceClient.post(`/users/${id}/reset-password`);
      const temp = data?.data?.temporaryPassword ?? data?.temporaryPassword;
      setInfoMessage(
        temp
          ? `Mot de passe temporaire généré : ${temp} (à communiquer à l'utilisateur, il devra le changer).`
          : "Mot de passe réinitialisé.",
      );
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Impossible de réinitialiser le mot de passe.");
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Gestion des utilisateurs</h1>
          <p className="text-sm text-muted-foreground">Comptes utilisateurs, rôles et permissions</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" asChild>
            <Link href="/settings/roles">
              <UserCog className="mr-2 h-4 w-4" />
              Gérer les rôles
            </Link>
          </Button>
          {canCreate ? (
            <Button onClick={() => setShowCreateForm((v) => !v)}>
              <Plus className="mr-2 h-4 w-4" />
              Nouvel utilisateur
            </Button>
          ) : null}
        </div>
      </div>

      {serverError ? <p className="text-sm font-medium text-destructive">{serverError}</p> : null}
      {infoMessage ? <p className="text-sm font-medium text-primary">{infoMessage}</p> : null}

      {showCreateForm ? (
        <Card>
          <CardHeader>
            <CardTitle>Créer un utilisateur</CardTitle>
          </CardHeader>
          <CardContent>
            <Form {...form}>
              <form onSubmit={form.handleSubmit(onCreate)} className="flex flex-col gap-4" noValidate>
                <div className="grid gap-3 md:grid-cols-2">
                  <FormField
                    control={form.control}
                    name="firstName"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Prénom</FormLabel>
                        <FormControl>
                          <Input {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="lastName"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Nom</FormLabel>
                        <FormControl>
                          <Input {...field} />
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
                        <FormLabel>Email</FormLabel>
                        <FormControl>
                          <Input type="email" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="password"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Mot de passe initial</FormLabel>
                        <FormControl>
                          <Input type="password" {...field} />
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
                          <Input {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </div>

                <FormField
                  control={form.control}
                  name="roleIds"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Rôles</FormLabel>
                      <FormControl>
                        <div className="flex flex-wrap gap-3 rounded-md border border-border p-3">
                          {roles.map((role) => {
                            const checked = (field.value ?? []).includes(role.id);
                            return (
                              <label key={role.id} className="flex items-center gap-2 text-sm">
                                <input
                                  type="checkbox"
                                  className="h-4 w-4"
                                  checked={checked}
                                  onChange={(e) => {
                                    const current = field.value ?? [];
                                    field.onChange(
                                      e.target.checked
                                        ? [...current, role.id]
                                        : current.filter((id) => id !== role.id),
                                    );
                                  }}
                                />
                                {role.name}
                              </label>
                            );
                          })}
                          {roles.length === 0 ? (
                            <span className="text-sm text-muted-foreground">Aucun rôle disponible.</span>
                          ) : null}
                        </div>
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <Button type="submit" disabled={form.formState.isSubmitting} className="w-fit">
                  {form.formState.isSubmitting ? "Création…" : "Créer l'utilisateur"}
                </Button>
              </form>
            </Form>
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>Utilisateurs</CardTitle>
          <CardDescription>Liste des comptes et statuts</CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <LoadingSpinner label="Chargement des utilisateurs…" />
          ) : users.length === 0 ? (
            <p className="text-sm text-muted-foreground">Aucun utilisateur enregistré.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-left text-muted-foreground">
                  <tr>
                    <th className="py-1 font-medium">Nom</th>
                    <th className="py-1 font-medium">Email</th>
                    <th className="py-1 font-medium">Rôles</th>
                    <th className="py-1 font-medium">Statut</th>
                    {canUpdate ? <th className="py-1 font-medium">Actions</th> : null}
                  </tr>
                </thead>
                <tbody>
                  {users.map((user) => (
                    <tr key={user.id} className="border-t border-border">
                      <td className="py-1.5">
                        {user.firstName} {user.lastName}
                      </td>
                      <td className="py-1.5">{user.email}</td>
                      <td className="py-1.5">{user.roles.map((r) => r.name).join(", ") || "—"}</td>
                      <td className="py-1.5">
                        <span
                          className={
                            "rounded-full px-2.5 py-0.5 text-xs font-medium " +
                            (user.status === "ACTIVE"
                              ? "bg-primary/10 text-primary"
                              : "bg-secondary text-secondary-foreground")
                          }
                        >
                          {STATUS_LABELS[user.status]}
                        </span>
                      </td>
                      {canUpdate ? (
                        <td className="flex flex-wrap gap-1 py-1.5">
                          {user.status !== "ACTIVE" ? (
                            <Button size="sm" variant="outline" onClick={() => onChangeStatus(user.id, "ACTIVE")}>
                              Activer
                            </Button>
                          ) : null}
                          {user.status !== "SUSPENDED" ? (
                            <Button size="sm" variant="outline" onClick={() => onChangeStatus(user.id, "SUSPENDED")}>
                              Suspendre
                            </Button>
                          ) : null}
                          {user.status !== "INACTIVE" ? (
                            <Button size="sm" variant="outline" onClick={() => onChangeStatus(user.id, "INACTIVE")}>
                              Désactiver
                            </Button>
                          ) : null}
                          <Button size="sm" variant="ghost" onClick={() => onResetPassword(user.id)}>
                            <KeyRound className="mr-1 h-3.5 w-3.5" />
                            Réinitialiser mdp
                          </Button>
                        </td>
                      ) : null}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
