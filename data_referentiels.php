<?php
/**
 * data_referentiels.php — Données statiques : nationalités et villes de France
 * Sources : liste ISO 3166-1 des nationalités (FR), communes INSEE > 2000 hab.
 */

/**
 * Retourne la liste des nationalités (libellé français → code ISO 3166-1 alpha-2)
 */
function getNationalites(): array {
    return [
        'Afghane'          => 'AF', 'Albanaise'       => 'AL', 'Algérienne'       => 'DZ',
        'Allemande'        => 'DE', 'Andorrane'       => 'AD', 'Angolaise'        => 'AO',
        'Antiguaise'       => 'AG', 'Argentine'       => 'AR', 'Arménienne'       => 'AM',
        'Australienne'     => 'AU', 'Autrichienne'    => 'AT', 'Azerbaïdjanaise'  => 'AZ',
        'Bahamienne'       => 'BS', 'Bahreïnienne'    => 'BH', 'Bangladaise'      => 'BD',
        'Barbadienne'      => 'BB', 'Bélarussienne'   => 'BY', 'Belge'            => 'BE',
        'Bélizienne'       => 'BZ', 'Béninoise'       => 'BJ', 'Bhoutanaise'      => 'BT',
        'Bolivienne'       => 'BO', 'Bosnienne'       => 'BA', 'Botswanaise'      => 'BW',
        'Brésilienne'      => 'BR', 'Britannique'     => 'GB', 'Brunéienne'       => 'BN',
        'Bulgare'          => 'BG', 'Burkinabée'      => 'BF', 'Burundaise'       => 'BI',
        'Cambodgienne'     => 'KH', 'Camerounaise'    => 'CM', 'Canadienne'       => 'CA',
        'Cap-verdienne'    => 'CV', 'Centrafricaine'  => 'CF', 'Chilienne'        => 'CL',
        'Chinoise'         => 'CN', 'Chypriote'       => 'CY', 'Colombienne'      => 'CO',
        'Comorienne'       => 'KM', 'Congolaise'      => 'CG', 'Costaricaine'     => 'CR',
        'Croate'           => 'HR', 'Cubaine'         => 'CU', 'Danoise'          => 'DK',
        'Djiboutienne'     => 'DJ', 'Dominicaine'     => 'DO', 'Dominiquaise'     => 'DM',
        'Égyptienne'       => 'EG', 'Émiratie'        => 'AE', 'Équatorienne'     => 'EC',
        'Érythréenne'      => 'ER', 'Espagnole'       => 'ES', 'Estonienne'       => 'EE',
        'Éthiopienne'      => 'ET', 'Fidjienne'       => 'FJ', 'Finlandaise'      => 'FI',
        'Française'        => 'FR', 'Gabonaise'       => 'GA', 'Gambienne'        => 'GM',
        'Géorgienne'       => 'GE', 'Ghanéenne'       => 'GH', 'Grecque'          => 'GR',
        'Grenadienne'      => 'GD', 'Guatémaltèque'   => 'GT', 'Guinéenne'        => 'GN',
        'Guinéo-équatorienne' => 'GQ', 'Guyanienne'  => 'GY', 'Haïtienne'        => 'HT',
        'Hondurienne'      => 'HN', 'Hongroise'       => 'HU', 'Indienne'         => 'IN',
        'Indonésienne'     => 'ID', 'Irakienne'       => 'IQ', 'Iranienne'        => 'IR',
        'Irlandaise'       => 'IE', 'Islandaise'      => 'IS', 'Israélienne'      => 'IL',
        'Italienne'        => 'IT', 'Ivoirienne'      => 'CI', 'Jamaïcaine'       => 'JM',
        'Japonaise'        => 'JP', 'Jordanienne'     => 'JO', 'Kazakhstanaise'   => 'KZ',
        'Kényane'          => 'KE', 'Kirghize'        => 'KG', 'Kiribatienne'     => 'KI',
        'Koweïtienne'      => 'KW', 'Laotienne'       => 'LA', 'Lesothane'        => 'LS',
        'Lettone'          => 'LV', 'Libanaise'       => 'LB', 'Libérienne'       => 'LR',
        'Libyenne'         => 'LY', 'Liechtensteinoise' => 'LI', 'Lituanienne'   => 'LT',
        'Luxembourgeoise'  => 'LU', 'Macédonienne'    => 'MK', 'Malgache'         => 'MG',
        'Malaisienne'      => 'MY', 'Malawite'        => 'MW', 'Maldivienne'      => 'MV',
        'Malienne'         => 'ML', 'Maltaise'        => 'MT', 'Marocaine'        => 'MA',
        'Marshallaise'     => 'MH', 'Mauritanienne'   => 'MR', 'Mauricienne'      => 'MU',
        'Mexicaine'        => 'MX', 'Micronésienne'   => 'FM', 'Moldave'          => 'MD',
        'Monégasque'       => 'MC', 'Mongole'         => 'MN', 'Monténégrine'     => 'ME',
        'Mozambicaine'     => 'MZ', 'Namibienne'      => 'NA', 'Nauruane'         => 'NR',
        'Népalaise'        => 'NP', 'Nicaraguayenne'  => 'NI', 'Nigériane'        => 'NG',
        'Nigérienne'       => 'NE', 'Nord-coréenne'   => 'KP', 'Norvégienne'      => 'NO',
        'Néo-zélandaise'   => 'NZ', 'Omanaise'        => 'OM', 'Ougandaise'       => 'UG',
        'Ouzbèke'          => 'UZ', 'Pakistanaise'    => 'PK', 'Palauane'         => 'PW',
        'Palestinienne'    => 'PS', 'Panaméenne'      => 'PA', 'Papouasienne'     => 'PG',
        'Paraguayenne'     => 'PY', 'Péruvienne'      => 'PE', 'Philippine'       => 'PH',
        'Polonaise'        => 'PL', 'Portugaise'      => 'PT', 'Qatarienne'       => 'QA',
        'Roumaine'         => 'RO', 'Ruandaise'       => 'RW', 'Russe'            => 'RU',
        'Saint-Lucienne'   => 'LC', 'Salvadorienne'   => 'SV', 'Samoane'          => 'WS',
        'Santoméenne'      => 'ST', 'Saoudienne'      => 'SA', 'Sénégalaise'      => 'SN',
        'Serbe'            => 'RS', 'Seychelloise'    => 'SC', 'Sierra-léonaise'  => 'SL',
        'Singapourienne'   => 'SG', 'Slovaque'        => 'SK', 'Slovène'          => 'SI',
        'Somalienne'       => 'SO', 'Soudanaise'      => 'SD', 'Sri-lankaise'     => 'LK',
        'Sud-africaine'    => 'ZA', 'Sud-coréenne'    => 'KR', 'Sudanaise'        => 'SS',
        'Suédoise'         => 'SE', 'Suisse'          => 'CH', 'Surinamaise'      => 'SR',
        'Syrienne'         => 'SY', 'Tadjike'         => 'TJ', 'Taïwanaise'       => 'TW',
        'Tanzanienne'      => 'TZ', 'Tchadienne'      => 'TD', 'Tchèque'          => 'CZ',
        'Thaïlandaise'     => 'TH', 'Timoraise'       => 'TL', 'Togolaise'        => 'TG',
        'Trinidadienne'    => 'TT', 'Tunisienne'      => 'TN', 'Turkmène'         => 'TM',
        'Turque'           => 'TR', 'Tuvaluane'       => 'TV', 'Ukrainienne'      => 'UA',
        'Uruguayenne'      => 'UY', 'Vanuataise'      => 'VU', 'Vénézuélienne'    => 'VE',
        'Vietnamienne'     => 'VN', 'Yéménite'        => 'YE', 'Zambienne'        => 'ZM',
        'Zimbabwéenne'     => 'ZW',
    ];
}

/**
 * Retourne les villes de France (communes > ~5 000 hab., communes normandes incluses).
 * Triées alphabétiquement.
 */
function getVillesFrance(): array {
    $villes = [
        'Abbeville','Agde','Agen','Aix-en-Provence','Aix-les-Bains','Ajaccio','Albi',
        'Alençon','Alès','Alfortville','Allauch','Amiens','Angers','Angoulême','Annecy',
        'Annemasse','Annonay','Antibes','Antony','Arles','Arras','Asnières-sur-Seine',
        'Aubagne','Auch','Aubervilliers','Aulnay-sous-Bois','Aurillac','Auxerre','Avignon',
        'Avon','Bagneux','Bagnolet','Bayonne','Beauvais','Belfort','Besançon','Béthune',
        'Béziers','Blois','Bobigny','Bordeaux','Boulogne-Billancourt','Boulogne-sur-Mer',
        'Bourg-en-Bresse','Bourges','Brest','Brive-la-Gaillarde','Caen','Calais','Cannes',
        'Carcassonne','Castres','Cergy','Chambéry','Champigny-sur-Marne','Chartres',
        'Châteauroux','Chelles','Chennevières-sur-Marne','Chessy','Cholet','Clamart',
        'Clermont-Ferrand','Clichy','Clichy-sous-Bois','Colmar','Colombes','Compiègne',
        'Corbeil-Essonnes','Courbevoie','Creil','Créteil','Dijon','Dinan','Drancy',
        'Draguignan','Dunkerque','Épinay-sur-Seine','Évreux','Évry-Courcouronnes',
        'Flers','Fontainebleau','Fontenay-sous-Bois','Fréjus','Gaillard','Gap',
        'Gennevilliers','Grenoble','Grigny','Grasse','Guingamp','Havre (Le)',
        'Hyères','Issy-les-Moulineaux','Ivry-sur-Seine','Laval','Lens','Levallois-Perret',
        'Libourne','Lille','Limoges','Livry-Gargan','Longwy','Lorient','Lyon',
        'Mantes-la-Jolie','Marseille','Martigues','Massy','Matoury','Meaux','Melun',
        'Metz','Meudon','Miramas','Montauban','Montélimar','Montivilliers','Montluçon',
        'Montpellier','Montreuil','Mulhouse','Muret','Nancy','Nantes','Narbonne',
        'Neuilly-sur-Marne','Neuilly-sur-Seine','Nice','Nîmes','Niort','Noisiel',
        'Noisy-le-Grand','Noisy-le-Sec','Olivet','Orléans','Orsay','Pantin',
        'Paris','Pau','Perpignan','Pessac','Poissy','Poitiers','Pontoise',
        'Puteaux','Quimper','Reims','Rennes','Rouen','Rueil-Malmaison',
        'Saint-Brieuc','Saint-Denis','Saint-Égrève','Saint-Étienne','Saint-Herblain',
        'Saint-Lô','Saint-Louis','Saint-Maur-des-Fossés','Saint-Nazaire','Saint-Quentin',
        'Sartrouville','Sète','Strasbourg','Suresnes','Tarbes','Thionville','Thonon-les-Bains',
        'Toulon','Toulouse','Tourcoing','Tours','Troyes','Valenciennes','Valence',
        'Vannes','Versailles','Villejuif','Villeurbanne','Vincennes','Vitry-sur-Seine',
        // Normandie
        'Bois-Guillaume','Déville-lès-Rouen','Elbeuf','Fécamp','Grand-Quevilly (Le)',
        'Louviers','Mont-Saint-Aignan','Petit-Quevilly (Le)','Rouen','Saint-Étienne-du-Rouvray',
        'Sotteville-lès-Rouen','Val-de-Reuil','Vernon','Dieppe',
        // Villes hors-métropole
        'Cayenne','Fort-de-France','Pointe-à-Pitre','Saint-Denis de La Réunion',
    ];

    $villes = array_unique($villes);
    sort($villes, SORT_LOCALE_STRING);
    return $villes;
}
