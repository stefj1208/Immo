<?php
// Tests des lectures de texte : php tests/run.php
require __DIR__ . '/../app/bootstrap.php';
$fail = 0;
function eq($label, $got, $want) { global $fail; if ($got !== $want) { $fail++; echo "✗ $label\n   obtenu  : " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n   attendu : " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; } }

$prices = [
    'Rs 8,200,000' => 8200000, 'Rs8.2M' => 8200000, 'Prix: 2.8M' => 2800000, '3,5 millions de roupies' => 3500000,
    'MUR 2 500 000' => 2500000, 'Rs. 950,000' => 950000, 'terrain 10 perches a 100 m de la plage, 2500000' => 2500000,
    'Villa 3 ch, 150 m2, prix 9.5 M negociable' => 9500000, 'Rs 5.9m' => 5900000, '€180 000' => 9540000,
    'Appartement 2 chambres 85m2 Rs 4,750,000 nego' => 4750000, 'A 5 min de la plage, 12 perches' => null,
    'Tel 5725 1234 terrain 8 perches' => null, 'Rs 25,000 par mois' => null, 'Prix 1.9M Rs' => 1900000,
    'PRIX : Rs 2,300,000 (négociable)' => 2300000, '2.3 millions' => 2300000, 'Rs 1 800 000' => 1800000, 'price 4.2' => 4200000,
];
foreach ($prices as $txt => $want) eq("prix « $txt »", Text::price($txt)['amount'] ?? null, $want);
eq('prix à la toise', Text::price('Terrain 200 toises, Rs 12,000 la toise')['unit'] ?? null, 'toise');
eq('prix par perche', Text::price('Rs 250,000 par perche')['unit'] ?? null, 'perche');

eq('perches', Text::areas('terrain de 10 perches', 'terrain')['land'], 422);
eq('toises', Text::areas('Terrain 250 toises', 'terrain')['land'], 950);
eq('arpent', Text::areas('1 arpent agricole', 'terrain_agri')['land'], 4221);
eq('m2 appart', Text::areas('Appartement de 85 m2', 'appartement')['built'], 85);
eq('maison terrain+bati', Text::areas('Maison de 150 m² sur terrain de 12 perches', 'maison'), ['land' => 507, 'built' => 150]);
eq('chambres', Text::beds('Villa 3 chambres'), 3);
eq('bedrooms', Text::beds('2 bedroom apartment'), 2);

eq('tel mobile', Text::phone('Appelez le 5725 1234'), '23057251234');
eq('tel +230', Text::phone('WhatsApp +230 5 912 3456'), '23059123456');
eq('tel aucun', Text::phone('Prix 2500000 terrain 8 perches'), null);
eq('wa.me', Text::phone('', '<a href="https://wa.me/23054567890">WA</a>'), '23054567890');

$zones = [
    'Terrain à vendre à Péreybère' => ['Pereybère', 'cote'], 'Villa Grand-Baie proche plage' => ['Grand Baie', 'cote'],
    'Land for sale in Trou-aux-Biches' => ['Trou aux Biches', 'cote'], 'Terrain agricole Pamplemousses' => ['Pamplemousses', 'nord'],
    'Charming cottage in Grand Baie' => ['Grand Baie', 'cote'], 'Terrain agricole à Cottage' => ['Cottage', 'nord'],
    'Maison à Flic en Flac' => ['Flic En Flac', 'hors'], 'Terrain Solitude 1 arpent' => ['Solitude', 'nord'],
    'Appartement belle vue mer Mont Choisy' => ['Mont Choisy', 'cote'], 'Terrain a vendre' => [null, null],
    'Terrain dans le nord' => ['Nord (à préciser)', 'nord?'], 'Plot at Anse La Raie' => ['Anse la Raie', 'cote'],
    'Terrain Amaury 50 perches' => ['Amaury', 'nord'], 'Cap-Malheureux: maison' => ['Cap Malheureux', 'cote'],
];
foreach ($zones as $txt => [$z, $g]) { $d = Geo::detect($txt); eq("zone « $txt »", [$d['zone'], $d['group']], [$z, $g]); }

$kinds = [
    'Appartement 2 chambres à vendre' => 'appartement', 'Villa avec piscine' => 'maison', 'Terrain agricole 1 arpent' => 'terrain_agri',
    'Terrain résidentiel 10 perches' => 'terrain_res', 'Maison sur terrain de 12 perches' => 'maison', 'Land for sale 200 toises' => 'terrain',
    'Local commercial à vendre' => 'autre', 'Beautiful penthouse for sale' => 'appartement', 'Terrain de 8 perches avec maison' => 'maison',
    'A vendre: portion de terre sous cannes' => 'terrain_agri', 'Plot in gated morcellement' => 'terrain_res',
];
foreach ($kinds as $txt => $k) eq("nature « $txt »", Classify::kind($txt), $k);

eq('location exclue', Classify::excluded('Appartement à louer Grand Baie', 'Rs 25,000 par mois'), 'location');
eq('recherche exclue', Classify::excluded('Bonjour je cherche un terrain à Pereybère', ''), 'recherche');
eq('vente gardée', Classify::excluded('Maison à vendre ou à louer', ''), null);
eq('looking for', Classify::excluded('Looking for land in Grand Baie', 'budget 2M'), 'recherche');
eq('plage m', Classify::beach('Appartement à 100 m de la plage')[0], '≈ 100 m de la plage');
eq('plage min', Classify::beach('5 minutes à pied de la plage publique')[0], '≈ 5 min à pied de la plage');
eq('pieds dans l eau', Classify::beach('Villa pieds dans l\'eau')[0], 'bord de mer');
eq('pas de plage', Classify::beach('Terrain de 100 m2')[0], null);

$mk = fn($t, $x = '') => Classify::evaluate(['title' => $t, 'text' => $x, 'kind' => Classify::kind($t, $x), 'zone' => Geo::detect($t)['zone'], 'zone_group' => Geo::detect($t)['group'],
    'price' => Text::price($t . ' ' . $x)['amount'] ?? null, 'price_unit' => Text::price($t . ' ' . $x)['unit'] ?? null, 'land_m2' => Text::areas($t, Classify::kind($t))['land'], 'built_m2' => null, 'source_kind' => 'fb_group']);
$v = $mk('Appartement à vendre Grand Baie Rs 5,200,000 à 200 m de la plage');
eq('appart deal', [$v['profile'], $v['bucket']], ['appart', 'deal']);
$v = $mk('Appartement à vendre Grand Baie Rs 9,200,000');
eq('appart trop cher', $v['bucket'], 'none');
$v = $mk('Maison à vendre Triolet Rs 4,000,000');
eq('maison hors côte', $v['bucket'], 'none');
$v = $mk('Terrain agricole Solitude 1 arpent Rs 2,300,000');
eq('agri deal', [$v['profile'], $v['bucket']], ['terrain_agri', 'deal']);
$v = $mk('Terrain résidentiel Pereybère 8 perches prix en privé');
eq('sans prix -> check', [$v['profile'], $v['bucket']], ['terrain_res', 'check']);
$v = $mk('Terrain résidentiel Cap Malheureux 60 toises Rs 30,000 la toise');
eq('prix toise calculé', [$v['bucket'], $v['price'] ?? null], ['deal', 1800000]);
$v = $mk('Maison à vendre Rs 6,500,000 3 chambres');
eq('sans zone -> check', $v['bucket'], 'check');
$v = $mk('Villa Pereybère Rs 10,900,000');
eq('maison un peu au-dessus', [$v['bucket'], str_contains(implode(' ', $v['flags']), 'au-dessus')], ['deal', true]);

echo $fail ? "\n$fail échec(s)\n" : "Tous les tests passent.\n";
exit($fail ? 1 : 0);
