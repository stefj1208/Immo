<?php
// Sites d'annonces suivis. « lists » : pages de liste ; « link » : motif des fiches.
// « pages » : nombre de pages de liste lues à chaque passage (paramètre page_param).
return [
    [
        'name' => 'PropertyCloud (ex-Lexpress Property)',
        'lists' => [
            'https://www.propertycloud.mu/apartments-for-sale/north',
            'https://www.propertycloud.mu/houses-for-sale/north',
            'https://www.propertycloud.mu/residential-land-for-sale/north',
            'https://www.propertycloud.mu/agricultural-land-for-sale/north',
            'https://www.propertycloud.mu/land-for-sale/north',
        ],
        'link' => 'propertycloud\.mu/(fr/a-vendre|for-sale)/[a-z-]+-[a-z]+\d+$',
        'pages' => 3, 'page_param' => 'page',
    ],
    [
        'name' => 'Expat.com',
        'lists' => [
            'https://www.expat.com/fr/immobilier/afrique/ile-maurice/42-maisons-a-vendre/',
            'https://www.expat.com/fr/immobilier/afrique/ile-maurice/43-appartements-a-vendre/',
            'https://www.expat.com/fr/immobilier/afrique/ile-maurice/44-terrains-a-vendre/',
        ],
        'link' => 'expat\.com/fr/immobilier/afrique/ile-maurice/4[234]-[a-z-]+/\d+-[^/]+\.html$',
        'pages' => 2, 'page_param' => 'page',
    ],
    [
        'name' => 'Real Estate Mauritius',
        'lists' => [
            'https://real-estate-mauritius.mu/en/buy/',
            'https://real-estate-mauritius.mu/en/pereybere-real-estate/apartments-for-sale/',
        ],
        'link' => 'real-estate-mauritius\.mu/en/buy/[^/]+-\d+/?$',
    ],
];
