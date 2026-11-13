import { useContextStore } from '@/stores/context';

/** Recharge les tuiles d'établissements (après une création) en conservant l'établissement et le rôle actifs ; s'il n'y en avait aucun et qu'il n'y en a plus qu'un, il devient actif. */
export async function refreshContexts(): Promise<void> {
  const ctx = useContextStore();
  const current = ctx.activeId;
  await ctx.load({ establishment_id: ctx.establishmentId, role: ctx.activeRole });
  if (!ctx.activeId && current === null && ctx.tiles.length > 0 && ctx.tiles[0]) {
    // Aucun établissement n'était actif : on ouvre le premier, c'est celui qu'on vient de créer.
    await ctx.switchTo(ctx.tiles[0].id);
  }
}
