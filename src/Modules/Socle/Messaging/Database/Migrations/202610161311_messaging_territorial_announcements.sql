-- Messaging (PP12) : une annonce territoriale est publiée dans chaque établissement du territoire ; l'origine est conservée.
ALTER TABLE announcements ADD COLUMN origin_territory_id VARCHAR(40) NULL;
