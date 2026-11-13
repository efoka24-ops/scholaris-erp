-- SS6 (secondaire, T807) : valeurs propres d'une application sur une ligne de conseil de classe (distinction refusee pour
-- conduite, alerte pratique, heures d'absence...), JSON fourni par Contracts\CouncilLineProvider. NULL = aucune.
ALTER TABLE council_lines ADD COLUMN line_extras TEXT NULL;
