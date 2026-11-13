<?php
// Sites d'annonces suivis. « lists » : pages de liste ; « link » : motif des fiches.
// « pages » : nombre de pages de liste lues à chaque passage (paramètre page_param).
return [
    [
        'name' => 'Expat.com',
        'lists' => [
            'https://www.expat.com/fr/immobilier/afrique/ile-maurice/a-vendre.html',
            'https://www.expat.com/fr/immobilier/afrique/ile-maurice/terrains-a-vendre.html',
            'https://www.expat.com/fr/immobilier/afrique/ile-maurice/maisons-a-vendre.html',
            'https://www.expat.com/fr/immobilier/afrique/ile-maurice/appartements-a-vendre.html',
        ],
        'link' => 'expat\.com/fr/immobilier/afrique/ile-maurice/4[234]-[a-z-]+/\d+-[^/]+\.html$',
        'pages' => 3, 'page_param' => 'page', 'seller' => null,
    ],
    [
        'name' => 'Aster Vender',
        'lists' => ['https://www.astervender.mu/petites-annonces?sort=created_at'],
        'link' => 'astervender\.mu/Real-Estate/[^/?]+-\d+$',
        'pages' => 6, 'page_param' => 'page', 'seller' => null,
    ],
    [
        'name' => 'Westimmo (real-estate-mauritius.mu)',
        'lists' => [
            'https://real-estate-mauritius.mu/en/buy/',
            'https://real-estate-mauritius.mu/en/buy-property-mauritius/land-for-sale/',
            'https://real-estate-mauritius.mu/en/buy-property-mauritius/apartments/',
            'https://real-estate-mauritius.mu/en/buy-property-mauritius/villas/',
        ],
        'link' => 'real-estate-mauritius\.mu/en/buy/[^/]+-\d+/?$',
    ],
    [
        'name' => 'Pam Golding',
        'lists' => [
            'https://www.pamgolding.mu/index.php?action=list&ctypmandatmeta=v&clangue=fr&cregionmeta=nord',
            'https://www.pamgolding.mu/index.php?action=list&seolink=0&ctypmandatmeta=v&ctypmeta=terr&clangue=fr&cregionmeta=nord',
            'https://www.pamgolding.mu/fr/terrains-a-vendre-ile-maurice.html',
        ],
        'link' => 'pamgolding\.mu/fr/annonces-immobilieres/offre/[^/]+/bien/\d+/',
        'pages' => 2, 'page_param' => 'page',
    ],
    [
        'name' => 'Park Lane',
        'lists' => [
            'https://parklane.mu/en/buy.html',
            'https://parklane.mu/index.php?action=list&ctypmandatmeta=v&cregionmeta=nord&clangue=en&search_currency=MUR&cdevise=MUR',
        ],
        'link' => 'parklane\.mu/en/realestate-adverts/offer/[^/]+/property/\d+/',
        'pages' => 2, 'page_param' => 'page',
    ],
    [
        'name' => 'FM Immobilier',
        'lists' => [
            'https://www.fmimmobilier.com/fr/nord/biens-en-vente.html',
            'https://www.fmimmobilier.com/index.php?action=list&cregionmeta=nord&ctypmandatmeta=v',
        ],
        'link' => 'fmimmobilier\.com/fr/annonces-immobilieres/offre/[^/]+/bien/\d+/',
        'pages' => 3, 'page_param' => 'page',
    ],
    [
        'name' => 'Primmo',
        'lists' => [
            'https://www.primmo.mu/achat/maison-a-vendre-ile-maurice',
            'https://www.primmo.mu/achat/appartement-a-vendre-ile-maurice',
            'https://www.primmo.mu/achat/terrain-a-vendre-ile-maurice',
        ],
        'link' => 'primmo\.mu/achat/[^/]+/item/\d+-[^/?]+$',
        'exclude' => 'request_info|email_to_friend',
    ],
    [
        'name' => 'My Immo Mauritius',
        'lists' => ['https://myimmomauritius.com/proprietes/', 'https://myimmomauritius.com/'],
        'link' => 'myimmomauritius\.com/proprietes/myi\d+/?$',
    ],
    [
        'name' => 'Villa Vie',
        'lists' => [
            'https://www.villa-vie.com/achat-vente-immobilier-ile-maurice-pour-mauriciens/maisons-villas/',
            'https://www.villa-vie.com/achat-vente-immobilier-ile-maurice-pour-mauriciens/appartements-penthouses/',
            'https://www.villa-vie.com/achat-vente-immobilier-ile-maurice-pour-mauriciens/terrains/',
        ],
        'link' => 'villa-vie\.com/achat-vente-immobilier-ile-maurice-pour-mauriciens/[^/]+/[^/]+/?$',
        'exclude' => '/page/',
    ],
    [
        'name' => 'Nestenn',
        'lists' => [
            'https://ile-maurice.nestenn.com/vente/maison/30501-grand-baie',
            'https://ile-maurice.nestenn.com/vente/appartement/30501-grand-baie',
            'https://ile-maurice.nestenn.com/vente/terrain/30501-grand-baie',
            'https://ile-maurice.nestenn.com/acheter',
        ],
        'link' => 'ile-maurice\.nestenn\.com/[a-z0-9-]+-ref-\d+$',
    ],
    [
        'name' => 'OFIM',
        'lists' => ['https://www.ofim.mu/liste-vente.html'],
        'link' => 'ofim\.mu/\d+/[^/]+\.html$',
        'exclude' => '/blog/',
        'pages' => 2, 'page_param' => 'page',
    ],
    [
        'name' => 'Seeff',
        'lists' => ['https://www.seeff.mu/property/?property_status=sale'],
        'link' => 'seeff\.mu/property/[a-z0-9-]+/?$',
    ],
    [
        'name' => 'Century 21',
        'lists' => ['https://www.century21.mu/results/residential/for-sale/'],
        'link' => 'century21\.mu/results/residential/for-sale/[^?]+/\d+/?$',
    ],
    [
        'name' => 'Immo.mu',
        'lists' => ['https://www.immo.mu/fr/catalog/north', 'https://www.immo.mu/fr/compilations/resale'],
        'link' => 'immo\.mu/fr/properties/[a-z0-9-]+$',
    ],
    [
        'name' => 'Decordier',
        'lists' => ['https://www.decordier-immobilier.mu/fr/achat/&new_research=1', 'https://www.decordier-immobilier.mu/fr/grand-baie-annonces/'],
        'link' => 'decordier-immobilier\.mu/fr/[^?&]*\d{3,}',
    ],
    // Lecture bloquée par ces portails depuis un serveur : désactivés tant qu'aucun accès n'est possible.
    [
        'name' => 'PropertyCloud (ex-Lexpress Property)',
        'lists' => [
            'https://www.propertycloud.mu/apartments-for-sale/north',
            'https://www.propertycloud.mu/houses-for-sale/north',
            'https://www.propertycloud.mu/residential-land-for-sale/north',
            'https://www.propertycloud.mu/agricultural-land-for-sale/north',
        ],
        'link' => 'propertycloud\.mu/(fr/a-vendre|for-sale)/[a-z-]+-[a-z]+\d+$',
        'pages' => 3, 'page_param' => 'page', 'enabled' => 0,
    ],
];
