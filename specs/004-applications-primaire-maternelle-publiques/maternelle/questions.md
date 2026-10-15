# Questions pour l'utilisateur : Maternelle publique

Date : 2026-10-15. Chaque question a une valeur par défaut **paramétrable, « à confirmer »**, utilisée tant qu'il n'y a pas de réponse. Aucune règle officielle n'est inventée : les défauts reprennent le CDC ou restent neutres.

| N° | Priorité | Question | Défaut « à confirmer » | Bloque |
|---|---|---|---|---|
| Q-01 | Haute | La maternelle publique est-elle une application distincte du catalogue (`public_nursery`) ou la clé existante `nursery` (« bientôt disponible ») ? Comment distinguer une école publique d'une privée ? | Clé distincte `public_nursery` ; établissement public = client qui a le droit d'usage `public_nursery` (cohérent avec le choix du commun) | M0-c |
| Q-02 | Haute | Où se lit l'heure de « fin des cours » (EX-SOR-04) : horaires de l'école (ECO) ou paramètre ? Délai avant l'alerte du directeur après l'appel des parents ? | Horaires de l'école (A2) ; repli `day_end_time` saisi par l'école ; alerte directeur 15 min après l'alerte parents | T726 |
| Q-03 | Haute | Un ajout de personne autorisée par le parent est-il actif tout de suite ou validé par l'école ? Le parent doit-il venir présenter une pièce ? | Actif tout de suite, notifié au directeur ; paramètre `parent_entries_need_validation = false` | T721 |
| Q-04 | Moyenne | Autorisation ponctuelle (EX-SOR-03) : le parent la crée par le portail seulement, ou aussi par SMS entrant / USSD ? | Portail (et guichet du directeur) ; SMS entrant et USSD quand la passerelle commune existe | T724 |
| Q-05 | Haute | Restriction de garde (RG-SOR-2) : quand la personne visée est un parent, NOT-07 et les messages de sortie doivent-ils l'exclure ? Qui saisit la décision et quelle pièce est exigée ? | Le parent visé ne reçoit ni NOT-07, ni carte DOC-21, ni information de sortie ; saisie par le directeur avec référence obligatoire, pièce facultative | T722, T723 |
| Q-06 | Haute | « Consentement écrit » (RG-DEV-2) : l'acceptation dans le portail parent suffit-elle, ou faut-il un formulaire papier signé et numérisé ? | Les deux sont acceptés ; le papier numérisé est requis pour un parent sans compte | T705, T745 |
| Q-07 | Haute | Conservation des photos : RG-DEV-3 dit « jusqu'à la fin de l'année suivante », la partie Sécurité dit « au bout d'un an ». Laquelle ? Année scolaire ou civile ? | Fin de l'année **scolaire** suivante (RG-DEV-3, qui porte un identifiant) ; paramètre `lifebook.retention` | T746 |
| Q-08 | Moyenne | Hors ligne : le CDC exige 30 jours sans connexion pour le contrôle des sorties, et RG-SOR-3 « la liste chargée le matin ». Au-delà de combien de jours une liste ancienne fait-elle passer en mode « doute » ? | 1 jour (mode doute : remise seulement après appel au directeur) ; lecture de la liste jusqu'à 30 jours | T725 |
| Q-09 | Haute | Quel fournisseur pour l'appel automatique (NOT-06, NOT-07, EX-COM-02) au pilote ? Budget ? | Pas d'appel vocal au pilote : SMS urgent + notification au directeur + appel manuel proposé à l'écran | T704, T765 |
| Q-10 | Haute | Référentiel officiel du préscolaire (domaines, items par section) et liste de termes à éviter dans le carnet : qui les fournit, quand ? | Exemple du CDC (6 domaines), marqué « à valider par l'Inspection générale » ; liste de termes vide | T741, T744 |
| Q-11 | Basse | Cible de suivi (SC-6) : part des sections qui respectent « une observation par domaine et par mois » ? | 80 %, indicateur seulement, jamais bloquant | SC-6 |
| Q-12 | Moyenne | Passage (EX-TRA-01) : sous quelle forme est recueillie la demande de maintien des parents (portail, papier) et l'avis de l'inspecteur (dans l'application, par R07) ? | Demande par le portail ou papier numérisé (référence obligatoire) ; avis saisi par R07 dans l'application | T761 |
| Q-13 | Moyenne | Groupe scolaire : la fiche de liaison DOC-20 est-elle aussi utile quand l'enfant reste dans le même groupe (même directeur) ? | Oui, produite pour toute la grande section | T762 |
| Q-14 | Moyenne | Allergies (EX-CAN-03) : « personnel concerné » = éducatrice, aide de la section et responsable de cantine (R17) ? Le directeur aussi ? | Éducatrice, aide, R17 et directeur | T764 |
| Q-15 | Basse | Arrivées : faut-il enregistrer qui a déposé l'enfant le matin, ou seulement l'heure d'arrivée (appel) ? | Heure d'arrivée par l'appel ; accompagnant facultatif | T723 |
| Q-16 | Basse | Photo de la personne autorisée : obligatoire pour qu'une autorisation permanente soit utilisable ? | Facultative ; écran « vérifier une pièce » quand il n'y a pas de photo | T721 |

## Questions ouvertes du CDC qui touchent aussi la maternelle (reprises du commun, sans défaut nouveau)
- Arrondissement pilote et **nombre de maternelles** concernées.
- Qui finance téléphones, données et SMS (coût des alertes NOT-06/NOT-07, plafond NF-P6).
- Durée légale de conservation des données des élèves (défaut CDC : scolarité + 5 ans).

## Priorité de réponse
1. Q-05, Q-02, Q-09 (sécurité de l'enfant, P1). 2. Q-01, Q-03, Q-08 (P1). 3. Q-06, Q-07, Q-10 (P2). 4. Le reste (P3).
