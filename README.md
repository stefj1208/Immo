# Immo Maurice

Veille personnelle des bons plans immobiliers dans le Nord de l'île Maurice :
sites d'annonces, groupes et pages Facebook publics, le tout trié et consultable
sur téléphone.

Application : <https://immo.ile-maurice.site>

## Ce que fait l'application

- **Collecte** toutes les 20 minutes sur les sites d'annonces lisibles, et deux
  fois par jour sur Facebook (via Apify, uniquement du contenu public, sans
  compte Facebook).
- **Trie** chaque annonce selon quatre recherches : appartement et maison à pied
  de la plage, terrain résidentiel sur la côte, terrain agricole dans tout le Nord.
- **Note** chaque annonce sur 100 : marge sous le budget, prix au m² comparé aux
  autres annonces, distance à la plage, vente par le propriétaire, vendeur pressé.
- **Regroupe** les doublons (même bien publié par plusieurs agences).
- **Prévient** par notification sur le téléphone, sauf la nuit.
- **Garde** le suivi : gardée, contactée, visitée, écartée, avec des notes.

## Les quatre recherches

| Recherche | Secteurs | Budget maximum |
|---|---|---|
| Appartement | Trou aux Biches, Mont Choisy, Pointe aux Canonniers, Grand Baie, Pereybère, Bain Boeuf, Cap Malheureux, Anse la Raie | 6 M Rs |
| Maison | les mêmes | 10 M Rs |
| Terrain résidentiel | les mêmes | 3 M Rs |
| Terrain agricole | tout le Nord : les mêmes plus Cottage, Solitude, Pamplemousses, Amaury, Triolet, Goodlands, Mapou… | 2,5 M Rs |

Les budgets se changent dans Réglages. Une annonce jusqu'à 15 % au-dessus reste
visible, signalée « à négocier ».

## Technique

PHP 8 et SQLite, sans dépendance ni étape de construction. Hébergé sur
l'offre Hostinger existante ; la base vit hors du dossier public.

```
index.html, assets/, sw.js   application (PWA)
api/index.php                API
app/                         moteur : collecte, lecture, classement
app/sources.sites.php        sites suivis
app/sources.facebook.php     groupes et pages Facebook suivis
tests/run.php                tests de lecture des prix, surfaces, localités
```

Commandes utiles sur le serveur :

```
php app/cli.php install        prépare ou met à jour la base et les sources
php app/cli.php tick --force   lance une collecte complète
php app/cli.php status         état des sources
php app/cli.php key            crée une nouvelle clé d'accès
```

Mise à jour : pousser sur `main`, puis redéployer le dépôt depuis hPanel (Git).

Les clés (accès, Gemini, Apify) sont enregistrées dans la base depuis l'écran
Réglages. Aucune n'est dans ce dépôt.
