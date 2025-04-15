
Pour faire le suivi de la mise à jour du réseau routier Marocain.

- https://map.comptoir.net/maroc-reseau-routier-2025-01/
- https://wiki.openstreetmap.org/wiki/FR_talk:Maroc#R%C3%A9seau_routier_mise_%C3%A0_jour_2025-01
- https://community.openstreetmap.org/t/morocco-maroc-reseau-routier-mise-a-jour-2025-01/128074/
- https://forum.openstreetmap.fr/t/maroc-reseau-routier-mise-a-jour-2025-01/32655

## osm_check.php

Récupère les données des Axes sur OSM et fait des vérifications:
- il y a bien une et une seule relation ;
- la relation et ses ways ont la "ref" au bon format: préfix "R" ;
- les ways sont toutes reliées entre elles ;
- les ways et les Splits du Shapefile correspondent.

## extract_shapefile.php

Extrait les Axes du Shapefile en autant de fichiers Geojson.

- permet de comparer les traçés dans Josm sans charger le volumineux SHP.
- permet de générer des versions simplifiées des tracés pour alléger l'analyse.

## simplify_lines.php

Génère des versions simplifiées des Splits geojson.

Accélère et consomme moins de mémoire pour l'analyse.
