import { computed, ref } from 'vue';
import { listStaff, type StaffMember } from '@/api/staff';
import { useAuthStore } from '@/stores/auth';

/** Fiches visibles par la personne connectée (un enseignant sans droit de lecture ne reçoit que la sienne). */
export function useStaffDirectory() {
  const auth = useAuthStore();
  const staff = ref<StaffMember[]>([]);
  const error = ref<unknown>(null);

  async function load(): Promise<void> {
    try {
      staff.value = await listStaff();
    } catch (e) {
      error.value = e;
    }
  }
  const nameOf = (id: string | null | undefined): string => {
    const s = staff.value.find((m) => m.id === id);
    return s?.full_name || [s?.first_name, s?.last_name].filter(Boolean).join(' ') || id || '—';
  };
  const ownIds = computed(() => staff.value.filter((s) => s.person_id === auth.me?.person_id).map((s) => s.id));
  const isOwn = (staffId: string): boolean => ownIds.value.includes(staffId);
  return { staff, error, load, nameOf, ownIds, isOwn };
}
