-- Correction de l'appel par le directeur de l'école publique (RG-PRE-1, T634) : l'enseignant corrige son appel le jour même
-- (il en est l'auteur) ; le directeur corrige l'appel d'un autre et, après le jour concerné, avec un motif obligatoire.
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'attendance.correct');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'attendance.correct_late');
