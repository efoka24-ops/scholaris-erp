# Recherche sur les textes du MINEDUB (23 octobre 2026)

**But** : remplacer, quand c'est possible, des valeurs par défaut inventées par des valeurs tirées de textes officiels du Ministère de l'Éducation de Base (MINEDUB), pour le primaire et la maternelle (privés et publics). **Règle inchangée** : tout ce qui suit devient un **paramètre** à valeur de départ « source officielle », jamais une constante dans le code ; l'établissement (ou la plateforme pour le public) peut l'ajuster, avec historique.

Source principale : site [minedub.cm](https://www.minedub.cm) (rubrique « Textes législatifs », communiqués). Plusieurs documents sont des scans ; ils ont été lus page à page.

## 1. Arrêté conjoint du 14 août 2026 fixant le calendrier de l'année scolaire 2026-2027 (MINEDUB-MINESEC)
Document : `https://www.minedub.cm/download/294/communiques/7959/calendrier-annee-scolaire-2026-2027.pdf` (visa des services du Premier ministre du 13 août 2026).
- **Article 6 (temps scolaire)** : maternelle et centres préscolaires communautaires : **30 h par semaine**, réparties entre routines et activités d'initiation, soit **720 h par an**. Primaire (écoles d'application et ordinaires) : **1 161 h par an** = **840 h d'enseignement systématique + 240 h d'initiation à l'intégration des ressources et à l'évaluation des compétences + 81 h de pause** ; quota hebdomadaire **32 h 15** à plein temps ; **mi-temps : 981 h par an, 26 h par semaine**.
- **Article 7 (horaires)** : maternelle **7 h 30 à 13 h 30** (6 h d'activités) ; primaire plein temps **7 h 30 à 15 h**, pauses **9 h 30-10 h** et **12 h-12 h 30** ; mi-temps : matin **7 h 30-12 h 40**, après-midi **13 h-17 h 30** du lundi au vendredi, samedi matin **7 h 30-12 h 30**.
- **Article 10 (périodes 2026-2027)** : 1er trimestre **7 sept. au 27 nov. 2026** (12 semaines) ; 2e trimestre **30 nov. au 18 déc. 2026** (3 semaines) puis **4 janv. au 5 mars 2027** (9 semaines) ; 3e trimestre **8 au 25 mars 2027** (2 semaines) puis **12 avril au 11 juin 2027** (8 semaines ; compositions internes du **3 au 21 mai 2027**).
- **Article 11** : chaque trimestre comprend enseignement systématique, initiation à l'intégration des acquis, **évaluations diagnostique, formative et sommative**, correction des copies, **remédiation**, orientation-conseil ; les responsables veillent à la **confection des bulletins de notes**.
- **Remédiation (article précédant la section 2)** : formes attendues : groupes de besoin, fiches différenciées, tutorat par les pairs ; **au moins 1 h par semaine et par discipline fondamentale** ; **registre de remédiation** et **fiche de suivi individualisée** = outils de traçabilité **obligatoires**.
- **Article 14 (effectifs)** : maternelle et préscolaire **30 élèves par classe au plus** ; primaire **60 au plus** ; secondaire 50. En zone dense, au-delà des plafonds : dédoublement en **mi-temps** ou nouvelles divisions **dans les 2 semaines** suivant la rentrée, avec **déclaration obligatoire des effectifs réels**.
- Article 12-13 : promotion des ressources numériques et de l'IA « dans le respect de la protection des données personnelles ».

## 2. Circulaire sur l'exécution du budget de fonctionnement des écoles maternelles et primaires publiques (visa MINFI du 7 mars 2022)
Document : `https://www.minedub.cm/download/336/texts-legislatifs-fr/5673/...pdf`.
- **Écoles maternelles publiques (EM, EMA, EMI, EMIA)** : fonctionnement assuré par une **contribution exigible de 7 500 FCFA par élève** (arrêté interministériel n° 20/B1/1464/MINEFI/MINEDUC/CAB du 31 mai 1996) ; **perçue en une seule tranche à la rentrée** par le directeur ou la directrice, **contre reçu** ; fonds répartis selon des **quotas** fixés par la circulaire (pages suivantes, à reprendre) ; **souches des quittances conservées** par le directeur pour les contrôles.
- **Écoles primaires publiques (EPP, EPPA, EPPI, EPPIA)** : **aucune contribution exigible** ; budget de l'État (et partenaires techniques et financiers).
- Conséquence pour SCHOLARIS : au **primaire public**, aucun frais de scolarité ne peut être exigé ; l'**APEE** reste une association de parents (cotisation volontaire, distincte), jamais une condition d'accès aux services. À la **maternelle publique**, la contribution de 7 500 FCFA est le paramètre par défaut, une tranche, reçu obligatoire, quotas de répartition paramétrables.

## 3. Examens officiels du primaire (CEP, FSLC, Common Entrance)
- **Communiqué radio-presse du 12 juin 2026** (MINEDUB, Direction des examens, des concours et de la certification) : épreuves **écrites et orales du CEP et du FSLC 2026 les 16 et 17 juin 2026** sur tout le territoire ; candidats présents **dès 7 h** dans leurs **sous-centres** de composition ; **carte d'identité scolaire ou « acceptance slip »** obligatoire ; matériel autorisé (stylo bleu, violet ou noir, règle, crayon, compas, gomme, équerre, rapporteur).
- **Décret n° 2010/1738/PM du 7 juin 2010** (indemnités et vacations des examens du MINEDUB) : examens concernés : **CEP, FSLC, CAPIEMP, Common Entrance, concours des ENIEG** ; fonctions : **président de jury, chargé de mission, chef de centre, chef de sous-centre, rapporteur, chef de secrétariat (centre, sous-centre), coordonnateur d'EPS, chef de salle de correction, chef d'atelier d'EPS, membre de secrétariat** ; vacations **1 500 FCFA par demi-journée**, correction **60 FCFA la copie** (CEP, FSLC, Common Entrance), indemnité du directeur d'école **50 FCFA par candidat**. Utile pour le module Examens (centres, sous-centres, jurys, états de vacations) ; montants = paramètres.
- Le CEP est passé par le sous-système francophone, le FSLC par l'anglophone ; épreuves **pratiques d'EPS** en mai (session 2025).
- **Non trouvé en ligne** : la liste officielle des épreuves du CEP, leurs **coefficients**, la note d'admission, le format des listes de candidats : **restent des paramètres et une question au MINEDUB** (circulaire de préparation des examens 2024 présente sur le site, mais son texte n'est pas extractible).

## 4. Ce qui n'est PAS publié en ligne (reste paramètre + question)
- **Barème et échelle d'évaluation des compétences** du primaire (curriculum MINEDUB 2018, approche par compétences) et **modèle officiel de bulletin** : aucun texte trouvé. Défaut retenu : échelle qualitative paramétrable (acquis / en cours / non acquis) + notes chiffrées optionnelles ; modèle de bulletin paramétrable.
- **Livret ou carnet d'évaluation de la maternelle** et règles de **passage au primaire** : aucun texte trouvé ; défauts paramétrables.
- **APEE** : aucun texte réglementaire récent en ligne ; la presse indique que les frais APEE persistent malgré la gratuité (maintien annoncé pour 2024-2025). Traitement : cotisation volontaire paramétrable, reçus, jamais bloquante.
- **Cantine** : aucun texte trouvé ; module optionnel paramétrable.

## 5. Sources
- [MINEDUB, textes législatifs](https://www.minedub.cm/textes-legislatifs/)
- [Arrêté conjoint du 14 août 2026, calendrier 2026-2027](https://www.minedub.cm/arrete-conjoint-du-14-aout-2026-fixant-le-calendrier-de-lannee-scolaire-2026-2027/)
- [Communiqué CEP et FSLC 2026](https://www.minedub.cm/communique-relative-aux-epreuves-ecrites-et-orales-du-cep-et-du-fscl-de-la-session-2026/)
- [Examens officiels session 2026](https://www.minedub.cm/examens-officiels-session-2026/)
- [UNESCO, CEP et FSLC](https://nada.uis.unesco.org/nada/en/index.php/catalogue/153)
- [Le360, frais APEE maintenus (2024)](https://afrique.le360.ma/autres-pays/societe/2022/06/19/38428-cameroun-de-plus-en-plus-decoles-primaires-mais-point-de-gratuite-38428/)
