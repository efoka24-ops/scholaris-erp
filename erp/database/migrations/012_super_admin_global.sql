-- Le Super Admin n'appartient a aucun etablissement.
--
-- Il administre la plateforme entiere : il instruit les demandes de creation,
-- et voit tous les etablissements. A ne pas confondre avec l'administrateur
-- d'une ecole — souvent son directeur — qui appartient a son etablissement et
-- n'en voit aucun autre.
--
-- Rattache par erreur a une ecole, le compte de plateforme subit le filtrage
-- par etablissement applique a toutes les requetes : il cesse alors de voir
-- les demandes des autres, c'est-a-dire l'essentiel de son travail. Cette
-- migration repare les installations concernees ; elle est sans effet sur
-- celles qui sont deja correctes.

UPDATE users
SET tenant_id = NULL
WHERE tenant_id IS NOT NULL
  AND id IN (
      SELECT user_id FROM user_roles
      WHERE role_id IN (SELECT id FROM roles WHERE name = 'SUPER_ADMIN')
  );
