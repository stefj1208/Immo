<?php
declare(strict_types=1);

/**
 * Localités du Nord de Maurice.
 *  - "cote" : les villages balnéaires visés pour appartement, maison et terrain résidentiel.
 *  - "nord" : le Nord élargi, valable pour les terrains agricoles.
 */
final class Geo
{
    /** nom affiché => [groupe, variantes d'écriture (sans accents)] */
    public const ZONES = [
        'Trou aux Biches' => ['cote', ['trou aux biches', 'trou-aux-biches', 'trou o biches', 'trou au biche', 'trou aux biche', 'trouauxbiches']],
        'Mont Choisy' => ['cote', ['mont choisy', 'mont-choisy', 'montchoisy', 'mon choisy', 'mon-choisy', 'monchoisy']],
        'Pointe aux Canonniers' => ['cote', ['pointe aux canonniers', 'pointe-aux-canonniers', 'pointe aux cannoniers', 'pointe aux canonnier', 'pte aux canonniers', 'pointe canonniers']],
        'Grand Baie' => ['cote', ['grand baie', 'grand-baie', 'grand bay', 'grandbaie', 'grand-bay', 'grande baie', 'la salette', 'chemin vingt pieds', 'chemin 20 pieds']],
        'Pereybère' => ['cote', ['pereybere', 'perebere', 'pereybeire', 'pereyberre', 'pereybère']],
        'Bain Boeuf' => ['cote', ['bain boeuf', 'bain-boeuf', 'bain beuf', 'bainboeuf', 'bain bœuf']],
        'Cap Malheureux' => ['cote', ['cap malheureux', 'cap-malheureux', 'capmalheureux', 'cap malheureu', 'kap malere']],
        'Anse la Raie' => ['cote', ['anse la raie', 'anse-la-raie', 'anse la rai', 'st francois', 'saint francois', 'st-francois']],

        'Calodyne' => ['nord', ['calodyne', 'kalodyne']],
        'Grand Gaube' => ['nord', ['grand gaube', 'grand-gaube', 'grand gaub']],
        'Goodlands' => ['nord', ['goodlands', 'goodland']],
        'Petit Raffray' => ['nord', ['petit raffray', 'petit-raffray', 'petit rafray']],
        'Roche Terre' => ['nord', ['roche terre', 'roche-terre']],
        'Poudre d\'Or' => ['nord', ['poudre d\'or', 'poudre d or', 'poudre dor', 'poudre-d-or']],
        'Cottage' => ['nord', ['cottage']],
        'Solitude' => ['nord', ['solitude']],
        'Pamplemousses' => ['nord', ['pamplemousses', 'pamplemousse']],
        'Amaury' => ['nord', ['amaury']],
        'Triolet' => ['nord', ['triolet']],
        'Fond du Sac' => ['nord', ['fond du sac', 'fond-du-sac']],
        'Plaine des Papayes' => ['nord', ['plaine des papayes', 'plaine-des-papayes', 'plaines des papayes']],
        'Mapou' => ['nord', ['mapou']],
        'Piton' => ['nord', ['piton']],
        'The Vale' => ['nord', ['the vale', 'the-vale', 'vale']],
        'Sottise' => ['nord', ['sottise']],
        'Forbach' => ['nord', ['forbach']],
        'Labourdonnais' => ['nord', ['labourdonnais', 'mon loisir']],
        'Belle Vue' => ['nord', ['belle vue harel', 'belle vue maurel', 'belle-vue-harel', 'belle vue']],
        'Pointe aux Piments' => ['nord', ['pointe aux piments', 'pointe-aux-piments', 'pte aux piments']],
        'Balaclava' => ['nord', ['balaclava', 'baie aux tortues', 'turtle bay']],
        'Arsenal' => ['nord', ['arsenal']],
        'Morcellement Saint André' => ['nord', ['morcellement st andre', 'morcellement saint andre', 'st andre', 'saint andre']],
        'D\'Épinay' => ['nord', ['d\'epinay', 'depinay', 'd epinay']],
        'Calebasses' => ['nord', ['calebasses', 'calebasse']],
        'Rivière du Rempart' => ['nord', ['riviere du rempart', 'riviere-du-rempart', 'rempart']],
        'Roches Noires' => ['nord', ['roches noires', 'roches-noires', 'roche noire']],
        'Plaine des Roches' => ['nord', ['plaine des roches', 'plaines des roches']],
        'Gokoola' => ['nord', ['gokoola']],
        'Schoenfeld' => ['nord', ['schoenfeld', 'schoenfield']],
        'Espérance Trébuchet' => ['nord', ['esperance trebuchet', 'trebuchet']],
        'Bois Rouge' => ['nord', ['bois rouge']],
        'Barlow' => ['nord', ['barlow']],
        'Mon Goût' => ['nord', ['mon gout']],
        'Ilot' => ['nord', ['ilot']],
        'Bois Mangues' => ['nord', ['bois mangues']],
        'Congomah' => ['nord', ['congomah']],
        'Notre Dame' => ['nord', ['notre dame', 'notre-dame']],
        'Terre Rouge' => ['nord', ['terre rouge', 'terre-rouge', 'le hochet']],
        'Baie du Tombeau' => ['nord', ['baie du tombeau', 'tombeau bay', 'baie-du-tombeau']],
        'Bon Air' => ['nord', ['bon air']],
        'Belmont' => ['nord', ['belmont']],
        'Mare Sèche' => ['nord', ['mare seche']],
        'Petite Julie' => ['nord', ['petite julie']],
        'Pointe aux Biches' => ['nord', ['pointe aux biches', 'pointe-aux-biches']],
        'Mont Mascal' => ['nord', ['mont mascal', 'mont-mascal']],
        'Bois Pignolet' => ['nord', ['bois pignolet']],
        'Crève Cœur' => ['nord', ['creve coeur', 'creve-coeur']],
        'Ville Bague' => ['nord', ['ville bague']],
        'Grand Baie La Croisette' => ['cote', ['la croisette']],
    ];

    /** Noms de lieux qui sont aussi des mots courants : valables seulement dans un titre, et en dernier recours. */
    public const AMBIGUOUS = ['vale', 'cottage', 'piton', 'ilot', 'solitude', 'arsenal', 'rempart', 'belle vue', 'bon air', 'notre dame', 'belmont', 'barlow'];

    /** Ailleurs dans l'île : sert à écarter une annonce clairement hors zone. */
    public const ELSEWHERE = [
        'flic en flac', 'flic-en-flac', 'tamarin', 'riviere noire', 'black river', 'la gaulette', 'le morne',
        'albion', 'port louis', 'port-louis', 'curepipe', 'quatre bornes', 'vacoas', 'rose hill', 'beau bassin',
        'moka', 'saint pierre', 'st pierre', 'ebene', 'phoenix', 'floreal', 'mahebourg', 'blue bay', 'pointe d\'esny',
        'belle mare', 'trou d\'eau douce', 'flacq', 'poste de flacq', 'poste lafayette', 'bel ombre', 'souillac',
        'rose belle', 'plaine magnien', 'grand bois', 'chemin grenier', 'surinam', 'riviere des anguilles',
        'bambous', 'cascavelle', 'pailles', 'montagne longue', 'long mountain', 'quartier militaire', 'bel air',
        'grande riviere', 'pointe aux sables', 'la preneuse', 'chamarel', 'rodrigues', 'highlands', 'reduit',
        'palma', 'sodnac', 'trianon', 'wolmar', 'tamarina', 'petite riviere', 'gros cailloux', 'la tour koenig',
        'coromandel', 'roche bois', 'sainte croix', 'vallee des pretres', 'lallmatie', 'bon accueil', 'brisee verdiere',
        'centre de flacq', 'palmar', 'grand river south east', 'grse', 'beau champ', 'bois cheri', 'nouvelle france',
        'midlands', 'la laura', 'verdun', 'cote d\'or', 'helvetia', 'telfair',
        'belvedere', 'camp des masques', 'camp de masque', 'riviere des creoles', 'chebel', 'eau coulee', 'wooton',
        'plaine verte', 'cite la cure', 'saint julien', 'quatre soeurs', 'vieux grand port', 'old grand port', 'deux freres',
        'bel etang', 'camp ithier', 'olivia', 'ripailles', 'mesnil', 'castel', 'candos', 'beau climat',
        'pointe aux feuilles', 'anse jonchee', 'ferney', 'beau vallon', 'mare d\'albert', 'riviere du poste', 'plaine champagne',
        'camp diable', 'saint aubin', 'la flora', 'tyack', 'baie du cap', 'riambel', 'pointe aux roches', 'gris gris',
        'trou aux cerfs', 'forest side', 'glen park', 'mont roches', 'stanley', 'cassis', 'vallee pitot', 'plaine lauzun',
        'la marie', 'la caverne', 'henrietta', 'bonne terre', 'camp fouquereaux', 'pierrefonds', 'gros billot', 'la ferme',
        'clemencia', 'ecroignard', 'laventure', 'bramsthan', 'lallmatie', 'montagne blanche', 'medine',
        'magenta', 'case noyale', 'petite case noyale', 'le bouchon', 'bois des amourettes', 'grand sable', 'quatre cocos',
        'palmyre', 'saint martin', 'ebene city', 'cote d or', 'les guibies', 'beau plan',
    ];

    /** Mots qui suivent souvent « à » ou « at » dans un titre sans être des lieux. */
    private const NOT_PLACES = ['maurice', 'mauritius', 'ile', 'l\'ile', 'island', 'vendre', 'sale', 'rent', 'louer', 'the', 'un', 'une', 'prix',
        'rs', 'nord', 'north', 'beach', 'plage', 'gated', 'morcellement', 'residence', 'domaine', 'quartier', 'proximite', 'deux', 'quelques',
        'pied', 'pieds', 'saisir', 'vendre', 'partir', 'only', 'just', 'walking', 'minutes', 'min', 'from', 'rs.', 'mur', 'eur', 'usd', 'proche'];

    /**
     * Lieu nommé dans un titre (« … at Creve Coeur », « … à Bel Air ») même s'il n'est pas dans nos listes.
     */
    public static function placeInTitle(string $title): ?string
    {
        if (!preg_match_all("~(?:^|\\s)(?:at|à|a|in|au|aux|near|secteur|r[ée]gion|village)\\s+((?:[A-ZÀ-Ý][\\w'’-]+)(?:\\s+(?:[A-ZÀ-Ý][\\w'’-]+|de|des|du|la|le|aux|au|d'))*)~u", $title, $mm, PREG_SET_ORDER)) return null;
        foreach ($mm as $m) {
            $place = trim($m[1], " -'’");
            $first = Text::fold(explode(' ', $place)[0]);
            if (in_array($first, self::NOT_PLACES, true) || mb_strlen($place) < 3) continue;
            return $place;
        }
        return null;
    }

    /**
     * Cherche la localité : d'abord dans le titre et l'adresse, puis dans le texte.
     * Retourne ['zone' => nom|null, 'group' => 'cote'|'nord'|'nord?'|'hors'|null].
     */
    public static function detect(string $primary, string $secondary = '', string $title = ''): array
    {
        foreach ([$primary, $secondary] as $i => $hay) {
            if ($hay === '') continue;
            // Le titre nomme un lieu que nous ne connaissons pas : on ne va pas chercher dans le reste de la page,
            // qui contient souvent les noms d'autres annonces.
            if ($i === 1 && $title !== '' && ($place = self::placeInTitle($title))) {
                return ['zone' => $place, 'group' => null, 'unknown' => true];
            }
            $t = ' ' . preg_replace('/[^a-z0-9\']+/', ' ', Text::fold($hay)) . ' ';
            $best = null;      // [nom, groupe, position] pour les noms sans ambiguïté
            $weak = null;      // idem pour les noms qui sont aussi des mots courants
            foreach (self::ZONES as $name => [$group, $aliases]) {
                foreach ($aliases as $alias) {
                    $needle = ' ' . preg_replace('/[^a-z0-9\']+/', ' ', $alias) . ' ';
                    $pos = strpos($t, $needle);
                    if ($pos === false) continue;
                    if (in_array($alias, self::AMBIGUOUS, true)) {
                        if ($i === 0 && ($weak === null || $pos < $weak[2])) $weak = [$name, $group, $pos];
                        continue;
                    }
                    if ($best === null || $pos < $best[2]) $best = [$name, $group, $pos];
                }
            }
            $best ??= $weak;
            if ($best) return ['zone' => $best[0], 'group' => $best[1]];

            foreach (self::ELSEWHERE as $e) {
                if (str_contains($t, ' ' . preg_replace('/[^a-z0-9\']+/', ' ', $e) . ' ')) {
                    if ($i === 0) return ['zone' => ucwords($e), 'group' => 'hors'];
                }
            }
        }
        $t = Text::fold($primary . ' ' . $secondary);
        if (preg_match('~\b(dans le nord|au nord|nord de l\'ile|north of (the island|mauritius)|in the north|region nord|northern|cote nord|north coast)\b~', $t)) {
            return ['zone' => 'Nord (à préciser)', 'group' => 'nord?'];
        }
        return ['zone' => null, 'group' => null];
    }

    public static function zoneNames(string $group): array
    {
        $out = [];
        foreach (self::ZONES as $name => [$g]) if ($g === $group) $out[] = $name;
        return $out;
    }
}
