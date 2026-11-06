// Lot E2-A (front) : aides de l'emploi du temps et du cahier de textes, entrées de menu, textes FR/EN complets.
import { describe, expect, it } from 'vitest';
import { byDay, formatRate, newClientUuid, type Entry } from '@/api/secondaryTimetable';
import { MENU, visibleEntries } from '@/views/secondary/menu';
import { secondaryTimetableFr } from '@/i18n/secondary-timetable-fr';
import { secondaryTimetableEn } from '@/i18n/secondary-timetable-en';

const entry = (id: string, weekday: number, start: string): Entry => ({
  id, academic_year_id: 'y', slot_id: 's', assignment_id: 'a', class_id: 'c', group_code: null, subject_ref: 'm', subject_label: 'Maths',
  teacher_person_id: 'p', room_id: null, weekday, start_time: start, end_time: start, valid_from: '2026-09-07', valid_to: null, origin: 'manual',
});

describe('emploi du temps et cahier de textes du secondaire', () => {
  it('groupe les créneaux par jour, lundi d’abord, dans l’ordre des heures', () => {
    const days = byDay([entry('3', 3, '10:00'), entry('1', 1, '09:00'), entry('2', 1, '07:30')]);
    expect(days.map((d) => d.weekday)).toEqual([1, 3]);
    expect(days[0]?.entries.map((e) => e.id)).toEqual(['2', '1']);
  });

  it('affiche le taux de couverture en pourcentage, ou un tiret sans progression', () => {
    expect(formatRate(0.425)).toContain('42,5');
    expect(formatRate(null)).toBe('—');
  });

  it('crée un identifiant de séance hors ligne accepté par le serveur', () => {
    const uuid = newClientUuid();
    expect(uuid).toMatch(/^[A-Za-z0-9-]{8,40}$/);
    expect(newClientUuid()).not.toBe(uuid);
  });

  it('montre les entrées au censeur et à l’enseignant, pas au parent', () => {
    const names = (perms: string[]) => visibleEntries(null, (...p) => p.some((x) => perms.includes(x)), () => true, MENU).map((e) => e.name);
    expect(names(['secondary.timetable.manage'])).toContain('sec-timetable');
    expect(names(['secondary.lesson.log'])).toEqual(expect.arrayContaining(['sec-timetable', 'sec-syllabus']));
    expect(names(['secondary.inspection.read'])).toContain('sec-syllabus');
    expect(names(['secondary.family.read'])).not.toContain('sec-syllabus');
  });

  it('a chaque texte en français et en anglais', () => {
    expect(Object.keys(secondaryTimetableEn).sort()).toEqual(Object.keys(secondaryTimetableFr).sort());
  });
});
