# Revue des exigences WTPDF

[English](README.md) · [Français](README.fr.md)

L’[inventaire des exigences](requirements.json) relie chaque obligation,
branche conditionnelle ou permission évaluable séparément à sa clause et à sa
page physique dans `Well-Tagged-PDF-WTPDF-1.0.pdf` conservé (version 1.0.0,
février 2024). Les 57 pages et 173 annotations de lien ont été relues ; les
annotations ne contiennent aucune correction normative. L’original est sous
licence CC-BY-4.0 de la PDF Association et garde ses octets et son SHA-256 dans
le [manifeste des sources du bundle](../iso32000-2-bundle/sources.json).

Chaque ligne indique sa portée précise, sa justification, son implémentation
ou sa frontière et les fichiers de preuve. Son statut est l’un des suivants :

- `executed` : le modèle ou contrôle natif cité exécute l’aspect indiqué ;
- `human` : l’intention ou la pertinence sémantique demande une preuve humaine ;
- `external` : validation indépendante de format, programme, confiance ou accessibilité ;
- `absent` : un aspect mécaniquement évaluable n’a pas de contrôle implémenté.

Les ordinaux après deux-points distinguent les aspects d’une même clause ; ils
n’inventent pas des numéros de paragraphe imprimés. Certains paragraphes ont une
condition mécanique exécutée et une obligation humaine ou externe, consignées
séparément. Les annexes informatives restent identifiées comme exemples. Les
pages physiques du PDF ont trois pages d’écart avec les folios imprimés.

`coverage.json` résume les mécanismes. Il ne remplace pas l’inventaire des
exigences. Aucun des deux ne fournit de pourcentage de complétude ni ne transforme
une déclaration, un sous-ensemble vérifié ou l’absence d’échec détecté en
certificat WTPDF/PDF/UA. Budgets de parcours et preuves externes encore nécessaires
sur les fontes/contenus restent explicites dans l’API et les constats d’audit.

Le conseil sur les dépréciations de §6.2 utilise **SHOULD**, avec des
contrôles natifs en contexte et des constats `Recommendation` distincts.
Le [catalogue et guide de migration bilingue](deprecations/README.fr.md)
présente les 47 familles examinées, le contexte trailer fourni, les exceptions
des sources et les frontières externes restantes. Cela n’affaiblit pas les
interdictions séparées concernant certaines annotations et XFA.

L’équivalence du texte enrichi en 8.10.3.3 (page physique 46) dispose désormais
d’une capacité XML explicite et exécutable. Elle se sélectionne avec
`new WtPdfGraphChecks(richText: new WtPdfRichTextChecks(new WtPdfXmlRichTextParser()))`.
Ce backend optionnel demande `ext-dom`, utilise des objets DOM locaux et
désactive DTD, développement d’entités, ressources externes et contenu actif.
Il compare le contenu Unicode des paragraphes/spans, après décodage des entités
et normalisation des fins de ligne, à la valeur canonique `V` héritée du champ.
Des budgets bornent octets, nœuds, profondeur et chaîne des parents. Un backend
absent, un flux RV encodé opaque ou une sémantique d’espace de noms/CSS non
prise en charge restent en attente ; aucun succès n’est déduit. L’interface du
parseur permet de sélectionner explicitement un backend plus complet. Cet
ajout ne certifie pas les exigences sémantiques ou externes restantes.

Après installation des dépendances Python de validation verrouillées, contrôler
l’identité de source, les clauses normatives terminales et chaque chemin de
preuve déclaré avec :

```sh
python tools/check_wtpdf_requirements.py
python -m unittest discover -s tests -p test_wtpdf_requirements.py
```

Les régressions négatives refusent une clause omise, une empreinte source altérée
et une preuve absente ou extérieure au dépôt. La présence d’une clause ne prouve
pas que chaque obligation de ses paragraphes est exécutée ; lire le statut de
chaque aspect.
