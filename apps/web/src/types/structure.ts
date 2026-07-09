export interface Cycle {
  id: string;
  code: string;
  name: string;
  order: number;
}

export interface Program {
  id: string;
  code: string;
  name: string;
  cycleId: string;
  departmentId: string | null;
}

export interface Level {
  id: string;
  code: string;
  name: string;
  order: number;
  cycleId: string;
  programId: string | null;
}

export type Section = "FRANCOPHONE" | "ANGLOPHONE";

export interface ClassRoom {
  id: string;
  code: string;
  name: string;
  capacity: number;
  levelId: string;
  mainTeacherId: string | null;
  roomId: string | null;
  section: Section;
}

export type RoomType = "SALLE_CLASSE" | "LABORATOIRE" | "SALLE_INFO" | "AMPHITHEATRE" | "TERRAIN_SPORT";

export interface Room {
  id: string;
  code: string;
  name: string;
  type: RoomType;
  capacity: number | null;
  building: string | null;
  floor: string | null;
  equipment: string[];
  classroomsCount: number;
}

export interface LevelNode extends Level {
  classrooms: ClassRoom[];
}

export interface ProgramNode extends Program {
  levels: LevelNode[];
}

export interface CycleNode extends Cycle {
  programs: ProgramNode[];
  levels: LevelNode[];
}
