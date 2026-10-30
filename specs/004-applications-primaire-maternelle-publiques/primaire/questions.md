# Questions pour l'utilisateur : Primaire publique et modules communs

Date : 2026-10-15. Chaque question a une **valeur par défaut paramétrable « à confirmer »** ; le développement l'utilise sans jamais la présenter comme officielle. B = bloque une tâche (citée) ; NB = ne bloque pas. Les 7 questions ouvertes du CDC sont Q1, Q3, Q8, Q9, Q10, Q14, Q12.

| N° | Question | Défaut « à confirmer » | Bloque |
|---|---|---|---|
| Q1 | Commanditaire et arrondissement pilote (nombre d'écoles, maternelles comprises) ? | un client « MINEDUB pilote », 1 région, 1 département, 1 arrondissement de fixtures `SYN-*`, 50 écoles simulées | NB (recette T694) |
| Q2 | Application Android native (< 30 Mo) exigée dès le pilote, ou la PWA hors ligne actuelle suffit-elle ? | PWA installable ; API de lots compatible avec une future application native | NB ; B pour le lot mobile natif |
| Q3 | Format officiel du questionnaire statistique et de la remontée vers l'annuaire du MINEDUB ? | gabarit interne reprenant les rubriques du CDC (effectifs par niveau, sexe, âge, redoublants, enseignants, infrastructures), export CSV/XLSX | B T652 (export officiel) |
| Q4 | Validation du choix d'architecture : `PublicSchools` + `PublicPrimary` + `PublicNursery`, préfixes `pub_`, `pp_`, `pn_`, routes `/public/*` et `/public-primary/*` (et non `/primary/*` du CDC) ? | oui (plan.md §2) | B T600 |
| Q5 | Écoles bilingues : un établissement peut-il avoir deux sous-systèmes ? | `subsystem` ∈ francophone, anglophone, bilingual ; une classe porte son sous-système | NB |
| Q6 | Format de l'identifiant national de l'élève ; existe-t-il déjà un identifiant officiel (MINEDUB, état civil) ? | `<code région>-<AAAA>-<7 chiffres>`, attribué par la plateforme, jamais modifié | NB (format paramétrable) |
| Q7 | Niveau territorial du contrôle de doublons (arrondissement, département, client) ? | département | NB |
| Q8 | Référentiels d'évaluation officiels à jour (disciplines, compétences, critères par niveau et sous-système) ? | référentiel d'exemple étiqueté `example_*` : Français, Mathématiques, English avec C1-C3 du CDC | B T661 (chargement officiel) |
| Q9 | Règle de mention et politique de passage validées par l'Inspection générale ? | celles du CDC (deux tiers, A/ECA/NA, tableau « Politique de passage ») marquées « exemple à valider » | NB pour le code ; B pour la mise en service |
| Q10 | Formats des listes CEP, FSLC, entrée en 6e, Common Entrance ? | CSV : identifiant national, nom, prénoms, sexe, date et lieu de naissance, référence d'acte, école, classe | B T681 (format officiel) |
| Q11 | Seuil d'abandon présumé (jours d'absence continue) ? | 20 jours ouvrés | NB |
| Q12 | Durée légale de conservation des données des élèves (loi 2024/017) ? | scolarité + 5 ans (CDC) | NB (paramètre de rétention) |
| Q13 | Délai de recours sur une décision de passage ? | 8 jours calendaires | NB |
| Q14 | Qui finance téléphones, données et SMS ? Budget SMS par école ? | budget du programme ; compteur par école | NB |
| Q15 | Plafond de SMS par élève et par an (NF-P6) ? | 12 SMS non essentiels par élève et par an | NB |
| Q16 | Fournisseurs USSD et voix (opérateur, code court, coût) ? | passerelles désactivées + simulateur (comme Sungku) | B T688, T689 (raccordement réel) |
| Q17 | Les certificats CEP/FSLC : signature électronique ou impression puis signature manuscrite du délégué ? | attestation PDF avec QR, signature manuscrite ; statut « signé » saisi par R06 | NB |
| Q18 | Transfert vers le Secondaire : l'application Secondaire existera-t-elle pendant le pilote ? | export d'un dossier JSON signé, import différé | NB (EX-EXA-07 Should) |
| Q19 | Hébergement à l'échelle régionale/nationale : reste-t-on sur Camoo mutualisé ? Hébergement au Cameroun exigé (loi 2024/017) : Camoo convient-il ? | Camoo pour le pilote ; décision avant l'extension | NB pilote, B extension |
| Q20 | Charge réelle visée au pilote : 40 000 lots/heure (NF-P2) est national ; quelle cible pour le pilote ? | 500 lots/heure au pilote | NB |
| Q21 | Le président et le trésorier de l'APEE ont-ils un compte (ce sont des parents) ? Rôle cumulable avec le rôle parent ? | oui, comptes nominatifs, rôles cumulables | NB |
| Q22 | Contrôle aléatoire de présence des enseignants par l'inspection : à distance (appel téléphonique) ou sur place seulement ? | sur place ou par appel, résultat saisi par l'inspecteur | NB |
| Q23 | Le site de production est en HTTP (HANDOVER §2) : HTTPS sera-t-il en place avant toute donnée réelle d'élève (loi 2024/017, données de mineurs) ? | pilote technique avec données synthétiques seulement tant qu'HTTPS n'est pas actif | B pour l'entrée de données réelles (P4) |

## Questions prioritaires
Q23 (HTTPS avant données réelles de mineurs), Q4 (architecture, débloque tout), Q8 et Q9 (règles d'évaluation), Q3 et Q10 (formats officiels), Q2 (mobile natif), Q19 (hébergement au-delà du pilote).

## Réponses de l'utilisateur
- 2026-10-15, Q4 : **oui**. Architecture validée : `PublicSchools` (commun, `pub_`), `PublicPrimary` (`pp_`), `PublicNursery` (`pn_`). T600 débloquée.
- 2026-10-15, Q23 : le site reste en **HTTP** le temps d'activer HTTPS. Conséquence (valeur par défaut retenue) : pilote technique avec données synthétiques seulement ; aucune donnée réelle d'élève avant HTTPS.
