# Plateforme multi-clients et catalogue d'applications (exigence du 2026-10-09)

Source : demande de l'utilisateur. L'application IPES (et plus tard les applications maternelle, primaire, secondaire) sera utilisée par **un ou plusieurs établissements clients**, retenus par nous ou qui demandent l'accès à la plateforme. Le **tableau de bord général** de la plateforme présente les applications disponibles ; elles sont développées en fonction de la cible.

## Constat sur le socle actuel
- `tenants` (clients) existe ; `establishments` porte un `type` (`nursery`, `primary`, `secondary`, `higher`) et `modules_enabled` (modules du socle seulement : people, billing, messaging…).
- `admin:create` crée un super administrateur **de portée client** : il n'existe aucun administrateur de **plateforme** au-dessus des clients.
- Il n'existe ni catalogue d'applications, ni droit d'usage d'une application par client, ni demande d'accès, ni intégration d'un nouveau client.

## À construire (module socle « Platform », nouveau)
1. **Catalogue d'applications** (données de plateforme, hors client) : `higher` (IPES), `secondary`, `primary`, `nursery` ; libellé, cible (type d'établissement), statut (disponible, bientôt, retiré), version, modules du socle requis.
2. **Droits d'usage** : quelle application est ouverte à quel client (et, si besoin, à quel établissement) ; date d'ouverture, état (actif, suspendu), source (contrat, essai). L'activation d'une application active les modules du socle qu'elle requiert.
3. **Demande d'accès** : un établissement (inscrit ou invité) demande l'usage d'une application : formulaire (nom de l'institution, type, pays/région, contact, autorisation ministérielle le cas échéant, application demandée) → file de traitement → décision par l'administrateur de plateforme (accepter, refuser avec motif, demander un complément) → à l'acceptation : création du client (ou rattachement), ouverture de l'application, invitation du premier administrateur du client. Notifications par Messaging, journal d'audit, approbation à deux personnes pour l'ouverture.
4. **Administrateur de plateforme** : rôle global (hors client), distinct du super administrateur de client ; commande `platform:admin-create`. Il voit tous les clients, demandes, applications et usages, sans accès aux données métier d'un client sauf procédure journalisée.
5. **Tableau de bord général** : (a) pour l'administrateur de plateforme : clients, demandes en attente, applications et leur adoption, santé ; (b) pour un utilisateur : le lanceur affiche les applications ouvertes à ses établissements (p. ex. la tuile IPES pour un établissement `higher`), avec accent et vocabulaire par type.
6. **Plusieurs IPES** : chaque institution est soit un client distinct, soit un établissement `higher` d'un client « groupe ». Les deux cas doivent fonctionner ; les données restent isolées par client.
7. Les applications (`src/Modules/Apps/<App>`) déclarent leur manifeste (clé, cible, modules requis, rôles, permissions) lu par le catalogue ; elles n'utilisent que les `Contracts/` du socle.

## Questions ouvertes
- Qui est l'administrateur de plateforme (vous seul, une équipe) et comment est-il authentifié ?
- La demande d'accès est-elle publique (formulaire sans compte) ou sur invitation seulement ?
- Facturation des applications aux clients : hors périmètre ici, ou à prévoir ?
- Un client IPES déjà retenu peut-il ouvrir lui-même une deuxième application, ou tout passe par l'administrateur de plateforme ?
- Un groupe d'institutions IPES est-il un client unique ou plusieurs clients ?

## Impact sur le plan IPES
Ce module est un **préalable de la vague 0** (avec les contrats d'écriture D1–D21 et S1–S21 demandés par les analyses). Les tâches sont à ajouter à `tasks.md` du socle sous le numéro T450 et suivants (réservé par la répartition des analyses : T330–T399 pour B, T400–T459 pour A ; ce module prend T460–T479).

## Décisions de l'utilisateur (2026-10-09), qui répondent aux questions ci-dessus
1. **Administrateur de plateforme** : l'utilisateur seul pour l'instant, compte `emm.foka@gmail.com`, **avec double authentification obligatoire**, même si la double authentification est désactivée pour les autres rôles (`AUTH_2FA_REQUIRED=false` côté serveur). La politique doit donc forcer la 2FA pour ce rôle.
2. **Demande d'accès publique** : formulaire sans compte, avec protections contre l'abus (limitation de débit, champ piège, validation stricte).
3. **Facturation des applications** : gratuite pendant une période d'essai de **3 mois**, puis abonnement **mensuel** (montant paramétrable par application ; exemple : 60 000 FCFA par mois), payé par mobile money via la passerelle de paiement du socle. Sans paiement, l'application est suspendue après un délai de grâce (les données restent conservées et lisibles pour l'export).
4. **Ouverture d'une autre application** : le client peut l'ouvrir lui-même (libre-service, avec son propre essai de 3 mois) ; l'administrateur de plateforme peut aussi l'ouvrir ou la fermer.
5. **Un client = une institution IPES** : pas de client « groupe » d'institutions IPES. Plusieurs IPES = plusieurs clients. (Le rôle de direction de groupe du socle reste valable à l'intérieur d'un client qui aurait plusieurs établissements.)

## Catalogue présenté par niveau et par régime (décision du 2026-10-21)
- Une application **par type d'établissement** : Supérieur (IPES), École maternelle, École primaire, Enseignement secondaire. Pour la maternelle et le primaire, deux **régimes** : privé ou public ; le régime désigne l'application technique à ouvrir (`nursery`/`public_nursery`, `primary`/`public_primary`, le secondaire n'a qu'une application) et porte ses propres règles, rôles et tarifs (privé 60 000 XAF, public 30 000 XAF).
- Le libellé ne porte pas le régime : `sector` (`private`|`public`) et `family` (niveau, identique à `target`) sont exposés par `GET /public/applications` et par `meta.catalog` de `GET /me/applications`. Ordre : par niveau (higher, nursery, primary, secondary), le privé avant le public.
- Le **module commun des écoles publiques** (`public_schools`) n'est pas un produit : `listed = 0`, absent du catalogue public, du catalogue client et des choix de demande d'accès ; une demande qui le vise est refusée (422), de même que son ouverture en libre-service. Il reste visible de l'administrateur de plateforme.
- Secondaire : une seule application (`secondary`, pas de régime ni de `public_secondary`) ; le **type d'établissement** choisi par la personne la personnalise. Champ OBLIGATOIRE à la demande d'accès pour le secondaire, liste fermée contrôlée par le serveur (`establishment_subtype` : `leg`, `lycee_technique`, `ces`, `cetic`, `college_prive`, `lycee_prive`, `autre`), stocké sur la demande puis sur l'établissement créé à l'acceptation ; refusé pour les autres niveaux. Il ne passe plus par le message.
- Migrations : `202610211000_platform_catalog_regime.sql` (colonnes `sector`, `listed`, libellés), `202610211100_platform_access_request_subtype.sql` et `202610211101_organisation_establishment_subtype.sql` (colonne `establishment_subtype`).
