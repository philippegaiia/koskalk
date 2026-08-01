# Conception de la page d’accueil Soapkraft V8-fr

## Objectif

Créer une version française autonome de V8 dans un nouveau dossier `v8-fr`, sans modifier V8 ni les générations précédentes. La page doit conserver la structure, le design, les animations, les interactions et les prix de V8, mais remplacer l’intégralité du contenu visible et des métadonnées par une adaptation française naturelle.

V8-fr s’adresse en priorité aux artisans, formulateurs et petites entreprises, tout en restant immédiatement compréhensible pour les amateurs. La page doit représenter équitablement la savonnerie et la formulation cosmétique.

## Positionnement éditorial

La rédaction suivra une progression volontaire :

- langage simple et concret dans le hero et les premiers appels à l’action ;
- vocabulaire de formulation précis dans les sections produit ;
- terminologie professionnelle pour l’étiquetage, la traçabilité et la production.

Le texte doit parler aux savonniers, aux formulateurs cosmétiques, aux artisans et aux petites marques. Il ne doit pas employer de formules publicitaires vagues telles que « créateurs passionnés », « révolutionnez votre activité » ou « solution tout-en-un ».

## Hero

Titre :

> Vos formules, vos ingrédients, votre atelier. Tout au même endroit.

Sous-titre :

> Réalisez rapidement un calcul de soude, ou construisez et enregistrez une formule complète de savon ou de cosmétique avec ses phases, ses ingrédients, ses coûts et les informations utiles pour l’étiquetage.

Actions :

- `Lancer un calcul`
- `Ouvrir mon espace gratuit`

Mention :

> Aucun compte nécessaire pour calculer · Inscrivez-vous uniquement lorsque vous souhaitez enregistrer votre travail

## Terminologie savon et cosmétique

Pour le savon, employer selon le contexte : huiles, soude, potasse, eau, surgraissage, additifs, propriétés du savon et calcul de saponification.

Pour la cosmétique, employer : phases aqueuse, huileuse et de refroidissement, pourcentages, actifs, conservateurs, parfums et formule cosmétique.

Pour les fonctions communes, employer : matières premières, formule, mode opératoire, coût de revient, images, notes, versions, liste INCI, allergènes à déclarer et références IFRA.

Le mot `recette` peut apparaître uniquement dans un passage très accessible destiné aux amateurs. Le terme principal reste `formule`.

## Calculateur et espace gratuit

La page doit distinguer clairement deux accès gratuits :

- **Calculateur sans compte :** calcul de soude ou de potasse, réglage de l’eau et du surgraissage, sans enregistrement.
- **Espace gratuit avec inscription :** formules enregistrées, matières premières personnelles dans les limites du plan, images, notes, versions et fiches de lot simplifiées en nombre limité.

Le mot `gratuit` ne doit jamais laisser entendre que l’enregistrement des formules fonctionne sans compte.

## Fiche de lot et module de production

Employer cette distinction de manière constante :

- **Fiche de lot simplifiée :** conserver la trace de ce qui a été fabriqué, avec un instantané de la formule et les numéros de lot des matières premières.
- **Module de production :** planifier, suivre et gérer les prochaines fabrications, les consommations de matières, les stocks, les fournisseurs et les références fournisseurs.

Ne pas traduire `batch record` par `snapshot de batch`. Ne pas présenter la fiche de lot simplifiée comme une gestion de production. Ne pas qualifier Soapkraft d’ERP simplifié.

## Comparaison des parcours

Adapter la comparaison par catégories sans citer de concurrents :

- Calculate → Calculer
- Formulate → Formuler
- Save → Enregistrer
- Labels → Étiquetage
- Batch record → Fiche de lot
- Production → Production
- Team → Équipe

Catégories : calculateurs rapides, logiciels de formulation, logiciels de gestion de production et Soapkraft.

Employer une réserve claire :

> La couverture fonctionnelle varie selon les logiciels. Chez Soapkraft, la gestion de production est incluse à partir de Studio et le travail en équipe à partir de Team.

## Fondateur

Conserver une voix directe à la première personne. Philippe explique qu’il utilise des outils de formulation depuis vingt ans, qu’il a développé Soapkraft à partir de problèmes rencontrés dans son propre travail et qu’il l’utilise encore à son atelier.

Éviter la biographie emphatique, les témoignages anonymes et les chiffres non vérifiés.

## Plans

Conserver les noms de produit `Free`, `Maker`, `Studio` et `Team` ainsi que les prix et le sélecteur mensuel/annuel de V8.

Adapter leur présentation :

- **Free :** petit portefeuille de formules, matières premières personnelles dans les limites du plan, images, notes, versions et fiches de lot simplifiées en nombre limité.
- **Maker :** davantage de formules, de matières premières et de fiches de lot ; génération d’étiquettes ; indications sur les allergènes et références IFRA.
- **Studio :** module de production complet avec planification, consommations de matières, stocks, fournisseurs et références fournisseurs.
- **Team :** contenu de Studio avec membres, rôles, droits d’accès et espace de travail partagé.

Ne pas inventer de limites numériques. Employer `dans les limites du plan`, `en nombre limité` et `capacité supérieure`.

## Étiquetage et conformité

Employer `informations utiles pour l’étiquetage`, `indications`, `signalement`, `références` et `aide`.

Ne jamais promettre une conformité réglementaire. La FAQ doit préciser que Soapkraft ne remplace pas la validation d’un professionnel qualifié.

## Conventions françaises

- Définir `<html lang="fr">`.
- Adapter le titre et la meta description.
- Employer une ponctuation française naturelle, y compris les espaces avant `:`, `;`, `?` et `!` lorsque le HTML le permet.
- Employer des apostrophes typographiques dans le texte visible.
- Conserver les prix en euros et les montants mensuels de V8.
- Afficher `par mois`, `facturé mensuellement` et `facturé annuellement`.
- Conserver les noms techniques NaOH, KOH, INCI et IFRA.

## Structure et interactions

La structure reste identique à V8 : hero, bandeau défilant, démonstration de l’atelier, deux parcours gratuits, savon et cosmétique, dossier de formule, comparaison des parcours, fondateur, distinction production, tarifs, FAQ, appel final et pied de page.

Conserver :

- le menu mobile accessible ;
- les révélations progressives ;
- l’animation contrôlable de la démonstration ;
- le bandeau défilant avec pause au survol et au focus ;
- la préférence de réduction des animations ;
- le sélecteur mensuel/annuel et son annonce accessible ;
- les états de focus et les cibles tactiles.

## Vérification

Créer une suite de tests V8-fr qui confirme :

- la langue et les métadonnées françaises ;
- la présence explicite du savon et de la cosmétique ;
- les deux accès gratuits et leur différence ;
- la distinction entre fiche de lot simplifiée et module de production ;
- les quatre plans et les prix mensuels/annuels ;
- l’absence de promesse de conformité ;
- l’absence des principaux textes anglais visibles, hors noms de plans et sigles techniques ;
- la conservation des contrats d’accessibilité, d’animation et de références locales de V8.

V8 doit rester inchangée après la création de V8-fr.
