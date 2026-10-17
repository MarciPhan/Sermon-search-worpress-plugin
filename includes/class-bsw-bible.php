<?php
if (!defined('ABSPATH')) exit;

/**
 * Katalog biblických knih a rozpoznávání odkazů v češtině.
 *
 * Pracuje nad normalizovaným textem (malá písmena bez diakritiky), takže zvládne
 * automatické titulky YouTube („jan 3 16“, „galatským 5 22“, „filipským druhá kapitola“),
 * názvy videí („2. List Petrův 2,4-6“) i dotazy uživatelů („Ř 8,28“, „1 Kor 13“).
 */
final class BSW_Bible {
    /** Číslo knihy (pořadí jako ve staré databázi) => [název, testament, počet kapitol]. */
    const BOOKS = array(
        1 => array('Genesis', 'ST', 50), 2 => array('Exodus', 'ST', 40), 3 => array('Leviticus', 'ST', 27),
        4 => array('Numeri', 'ST', 36), 5 => array('Deuteronomium', 'ST', 34), 6 => array('Jozue', 'ST', 24),
        7 => array('Soudců', 'ST', 21), 8 => array('Rút', 'ST', 4), 9 => array('1. Samuelova', 'ST', 31),
        10 => array('2. Samuelova', 'ST', 24), 11 => array('1. Královská', 'ST', 22), 12 => array('2. Královská', 'ST', 25),
        13 => array('1. Paralipomenon', 'ST', 29), 14 => array('2. Paralipomenon', 'ST', 36), 15 => array('Ezdráš', 'ST', 10),
        16 => array('Nehemjáš', 'ST', 13), 17 => array('Ester', 'ST', 10), 18 => array('Jób', 'ST', 42),
        19 => array('Žalmy', 'ST', 150), 20 => array('Přísloví', 'ST', 31), 21 => array('Kazatel', 'ST', 12),
        22 => array('Píseň písní', 'ST', 8), 23 => array('Izajáš', 'ST', 66), 24 => array('Jeremjáš', 'ST', 52),
        25 => array('Pláč', 'ST', 5), 26 => array('Ezechiel', 'ST', 48), 27 => array('Daniel', 'ST', 12),
        28 => array('Ozeáš', 'ST', 14), 29 => array('Jóel', 'ST', 4), 30 => array('Ámos', 'ST', 9),
        31 => array('Abdijáš', 'ST', 1), 32 => array('Jonáš', 'ST', 4), 33 => array('Micheáš', 'ST', 7),
        34 => array('Nahum', 'ST', 3), 35 => array('Abakuk', 'ST', 3), 36 => array('Sofonjáš', 'ST', 3),
        37 => array('Ageus', 'ST', 2), 38 => array('Zacharjáš', 'ST', 14), 39 => array('Malachiáš', 'ST', 3),
        40 => array('Matouš', 'NZ', 28), 41 => array('Marek', 'NZ', 16), 42 => array('Lukáš', 'NZ', 24),
        43 => array('Jan', 'NZ', 21), 44 => array('Skutky apoštolů', 'NZ', 28), 45 => array('Římanům', 'NZ', 16),
        46 => array('1. Korintským', 'NZ', 16), 47 => array('2. Korintským', 'NZ', 13), 48 => array('Galatským', 'NZ', 6),
        49 => array('Efezským', 'NZ', 6), 50 => array('Filipským', 'NZ', 4), 51 => array('Koloským', 'NZ', 4),
        52 => array('1. Tesalonickým', 'NZ', 5), 53 => array('2. Tesalonickým', 'NZ', 3), 54 => array('1. Timoteovi', 'NZ', 6),
        55 => array('2. Timoteovi', 'NZ', 4), 56 => array('Titovi', 'NZ', 3), 57 => array('Filemonovi', 'NZ', 1),
        58 => array('Židům', 'NZ', 13), 59 => array('Jakubův', 'NZ', 5), 60 => array('1. Petrův', 'NZ', 5),
        61 => array('2. Petrův', 'NZ', 3), 62 => array('1. Janův', 'NZ', 5), 63 => array('2. Janův', 'NZ', 1),
        64 => array('3. Janův', 'NZ', 1), 65 => array('Judův', 'NZ', 1), 66 => array('Zjevení', 'NZ', 22),
    );

    /** Počet veršů v každé kapitole (vyšší z ČEP a Kralické, české číslování). */
    const VERSES = array(
        1 => '31,25,24,26,32,22,24,22,29,32,32,20,18,24,21,16,27,33,38,18,34,24,20,67,34,35,46,22,35,43,55,33,20,31,29,43,36,30,23,23,57,38,34,34,28,34,31,22,33,26',
        2 => '22,25,22,31,23,30,29,32,35,29,10,51,22,31,27,36,16,27,25,26,37,31,33,18,40,37,21,43,46,38,18,35,23,35,35,38,29,31,43,38',
        3 => '17,16,17,35,26,30,38,36,24,20,47,8,59,57,33,34,16,30,37,27,24,33,44,23,55,46,34',
        4 => '54,34,51,49,31,27,89,26,23,36,35,16,33,45,41,50,28,32,22,29,35,41,30,25,19,65,23,31,40,17,54,42,56,29,34,13',
        5 => '46,37,29,49,33,25,26,20,29,22,32,32,19,29,23,22,20,22,21,20,23,30,26,22,19,19,26,69,29,20,30,52,29,12',
        6 => '18,24,17,24,15,27,26,35,27,43,23,24,33,15,63,10,18,28,51,9,45,34,16,33',
        7 => '36,23,31,24,31,40,25,35,57,18,40,15,25,20,20,31,13,31,30,48,25',
        8 => '22,23,18,22',
        9 => '28,36,21,22,12,21,17,22,27,27,15,25,23,52,35,23,58,30,24,42,16,23,29,23,44,25,12,25,11,31,13',
        10 => '27,32,39,12,25,23,29,18,13,19,27,31,39,33,37,23,29,33,44,26,22,51,39,25',
        11 => '53,46,28,34,32,38,51,66,28,29,43,33,34,31,34,34,24,46,21,43,29,54',
        12 => '18,25,27,44,27,33,20,29,37,36,21,22,25,29,38,20,41,37,37,21,26,20,37,20,30',
        13 => '54,55,24,43,41,81,40,40,44,14,47,41,14,17,29,43,27,17,19,8,30,19,32,31,31,32,34,21,30',
        14 => '18,18,17,22,14,42,22,18,31,19,23,16,23,15,19,14,19,34,11,37,20,12,21,27,28,23,9,27,36,27,21,33,25,33,27,23',
        15 => '11,70,13,24,17,22,28,36,15,44',
        16 => '11,20,38,23,19,19,73,18,38,40,36,47,31',
        17 => '22,23,15,17,14,14,10,17,32,3',
        18 => '22,13,26,21,27,30,21,22,35,22,20,25,28,22,35,22,16,21,29,29,34,30,17,25,6,14,23,28,25,31,40,22,33,37,16,33,24,41,30,32,34,17',
        19 => '6,12,9,9,13,11,18,10,21,18,7,9,6,7,5,11,15,51,15,10,14,32,6,10,22,12,14,9,11,13,25,11,22,23,28,13,40,23,14,18,14,12,5,27,18,12,10,15,21,23,21,11,7,9,24,14,12,12,18,14,9,13,12,11,14,20,8,36,37,6,24,20,28,23,11,13,21,72,13,20,17,8,19,13,14,17,7,19,53,17,16,16,5,23,11,13,12,9,9,5,8,29,22,35,45,48,43,14,31,7,10,10,9,8,18,19,2,29,176,7,8,9,4,8,5,6,5,6,8,8,3,18,3,3,21,26,9,8,24,14,10,8,12,15,21,10,20,14,9,6',
        20 => '33,22,35,27,23,35,27,36,18,32,31,28,25,35,33,33,28,24,29,30,31,29,35,34,28,28,27,28,27,33,31',
        21 => '18,26,22,17,20,12,29,17,18,20,10,14',
        22 => '17,17,11,16,16,13,14,14',
        23 => '31,22,26,6,30,13,25,23,21,34,16,6,22,32,9,14,14,7,25,6,17,25,18,23,12,21,13,29,24,33,9,20,24,17,10,22,38,22,8,31,29,25,28,28,25,13,15,22,26,11,23,15,12,17,13,12,21,14,21,22,11,12,19,12,25,24',
        24 => '19,37,25,31,31,30,34,23,26,25,23,17,27,22,21,21,27,23,15,18,14,30,40,10,38,24,22,17,32,24,40,44,26,22,19,32,21,28,18,16,18,22,13,30,5,28,7,47,39,46,64,34',
        25 => '22,22,66,22,22',
        26 => '28,10,27,17,17,14,27,18,11,22,25,28,23,23,8,63,24,32,14,49,37,31,49,27,17,21,36,26,21,26,18,32,33,31,15,38,28,23,29,49,26,20,27,31,25,24,23,35',
        27 => '21,49,33,37,31,29,28,27,27,21,45,13',
        28 => '11,25,5,19,15,11,16,14,17,15,12,15,16,10',
        29 => '20,32,21,21',
        30 => '15,16,15,13,27,14,17,14,15',
        31 => '21',
        32 => '17,11,10,11',
        33 => '16,13,12,14,15,16,20',
        34 => '15,14,19',
        35 => '17,20,19',
        36 => '18,15,20',
        37 => '15,23',
        38 => '21,17,10,14,11,15,14,23,17,12,17,14,9,21',
        39 => '14,17,24,6',
        40 => '25,23,17,25,48,34,29,34,38,42,30,50,58,36,39,28,27,35,30,34,46,46,39,51,46,75,66,20',
        41 => '45,28,35,41,43,56,37,38,50,52,33,44,37,72,47,20',
        42 => '80,52,38,44,39,49,50,56,62,42,54,59,35,35,32,31,37,43,48,47,38,71,56,53',
        43 => '51,25,36,54,47,71,53,59,41,42,57,50,38,31,27,33,26,40,42,31,25',
        44 => '26,47,26,37,42,15,60,40,43,48,30,25,52,28,41,40,34,28,41,38,40,30,35,27,27,32,44,31',
        45 => '32,29,31,25,21,23,25,39,33,21,36,21,14,23,33,27',
        46 => '31,16,23,21,13,20,40,13,27,33,34,31,13,40,58,24',
        47 => '24,17,18,18,21,18,16,24,15,18,33,21,14',
        48 => '24,21,29,31,26,18',
        49 => '23,22,21,32,33,24',
        50 => '30,30,21,23',
        51 => '29,23,25,18',
        52 => '10,20,13,18,28',
        53 => '12,17,18',
        54 => '20,15,16,16,25,21',
        55 => '18,26,17,22',
        56 => '16,15,15',
        57 => '25',
        58 => '14,18,19,16,14,20,28,13,28,39,40,29,25',
        59 => '27,26,18,17,20',
        60 => '25,25,22,19,14',
        61 => '21,22,18',
        62 => '10,29,24,21,21',
        63 => '13',
        64 => '15',
        65 => '25',
        66 => '20,29,22,11,14,17,17,13,21,11,19,18,18,20,8,21,18,24,21,15,27,21',
    );

    /**
     * Skupiny tvarů názvů: [kmen v řeči, zkratky jen pro dotazy, kniha nebo pořadí=>kniha, přísné].
     * „Přísné“ knihy znějí jako jména (Jan, Marek, Daniel…) – v přepisech je bereme jen s veršem.
     * Knihy s pořadím (1./2. Korintským…) se v přepisech berou jen s vysloveným pořadím.
     */
    private static $groups = array(
        array('mojzisov[a-z]*', '', array(1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5), false),
        array('genesis|genese', 'gn|gen', 1, false),
        array('exod[a-z]*', 'ex', 2, false),
        array('levitik[a-z]*|leviticus', 'lv|lev', 3, false),
        array('numeri', 'nu|nm|num', 4, false),
        array('deuteronom[a-z]*', 'dt|deut', 5, false),
        array('jozue|jozua', 'joz', 6, true),
        array('soudc[a-z]*', 'sd|soud', 7, false),
        array('rut|ruth', 'rt', 8, true),
        array('samuel[a-z]*', 's|sam', array(1 => 9, 2 => 10), false),
        array('kralovsk[a-z]*', 'kr|kral', array(1 => 11, 2 => 12), false),
        array('paralipomenon|letopis[a-z]*', 'pa|par|let', array(1 => 13, 2 => 14), false),
        array('ezdras[a-z]*', 'ezd', 15, false),
        array('nehemjas[a-z]*|nehemias[a-z]*', 'neh|ne', 16, false),
        array('ester', 'est', 17, true),
        array('job|joba|jobovi|jobu|jobov[a-z]*', 'jb', 18, true),
        array('zalm[a-z]*', 'z|zl', 19, false),
        array('prislovi', 'pr|pris|prisl', 20, false),
        array('kazatel[a-z]*', 'kaz|kz', 21, true),
        array('pisen pisni|pisne pisni|pisni pisni', 'pis|pp|pisen', 22, false),
        array('izajas[a-z]*|izaias[a-z]*', 'iz', 23, false),
        array('jeremjas[a-z]*|jeremias[a-z]*', 'jr|jer', 24, false),
        array('plac jerem[a-z]*', 'pl|plac', 25, false),
        array('ezechiel[a-z]*', 'ez|ezech', 26, false),
        array('daniel[a-z]*', 'da|dan', 27, true),
        array('ozeas[a-z]*', 'oz', 28, false),
        array('joel[a-z]*', 'jl', 29, false),
        array('amos[a-z]*', 'am', 30, false),
        array('abdijas[a-z]*', 'abd', 31, false),
        array('jonas[a-z]*', 'jon', 32, true),
        array('micheas[a-z]*', 'mi|mich', 33, false),
        array('nahum[a-z]*', 'na|nah', 34, false),
        array('abakuk[a-z]*', 'abk|ab', 35, false),
        array('sofonjas[a-z]*|sofonias[a-z]*', 'sf|sof', 36, false),
        array('age(?:us|a|ovi|em|ov[a-z]*)', 'ag', 37, false),
        array('zacharjas[a-z]*|zacharias[a-z]*', 'za|zach', 38, false),
        array('malachias[a-z]*|malachjas[a-z]*', 'mal', 39, false),
        array('matous[a-z]*', 'mt|mat', 40, false),
        array('marek|marka|markov[a-z]*|markem', 'mk|mar', 41, true),
        array('lukas[a-z]*', 'l|lk|luk', 42, false),
        array('jan|jana|janovi|janem|janov[a-z]*|januv', 'j|jn', array(0 => 43, 1 => 62, 2 => 63, 3 => 64), true),
        array('skutk[a-z]*|skutc[a-z]*', 'sk|skut', 44, true),
        array('rimanum|riman[a-z]*|rimsk[a-z]*', 'r|ri|rim', 45, false),
        array('korint[a-z]*', 'k|kor', array(1 => 46, 2 => 47), false),
        array('galat[a-z]*', 'ga|gal', 48, false),
        array('efez[a-z]*|efesk[a-z]*', 'ef', 49, false),
        array('filipsk[a-z]*|filipan[a-z]*|filipenum', 'fp|flp|fil|filipsky', 50, false),
        array('kolos[a-z]*', 'ko|kol', 51, false),
        array('tesalonic[a-z]*|tesalonsk[a-z]*|soluns[a-z]*', 'te|tes|tess', array(1 => 52, 2 => 53), false),
        array('timote[a-z]*|timotej[a-z]*', 'tm|tim', array(1 => 54, 2 => 55), false),
        array('titovi|titus|titu', 'tt|tit', 56, true),
        array('filemon[a-z]*', 'fm|flm', 57, false),
        array('zidum|hebrejum|zidovsk[a-z]*', 'zd|zid|hebr|heb', 58, false),
        array('jakub[a-z]*', 'jk|jak', 59, true),
        array('petr[a-z]*', 'p|pt', array(1 => 60, 2 => 61), false),
        array('juduv|judova|judove|judovo', 'ju|jud', 65, false),
        array('zjeveni(?: janov[a-z]*)?|apokalyps[a-z]*', 'zj|zjev|apok', 66, false),
    );

    const ORD = '1|2|3|4|5|prvn[a-z]*|druh[a-z]*|tret[a-z]*|ctvrt[a-z]*|pat[a-z]*';
    const CHAPTER_WORDS = array('devatenact' => 19, 'sedmnact' => 17, 'sestnact' => 16, 'osmnact' => 18, 'patnact' => 15,
        'ctrnact' => 14, 'trinact' => 13, 'dvanact' => 12, 'jedenact' => 11, 'dvacat' => 20, 'desat' => 10, 'devat' => 9,
        'sedm' => 7, 'sest' => 6, 'osm' => 8, 'ctvrt' => 4, 'tret' => 3, 'druh' => 2, 'prvn' => 1, 'pat' => 5);

    private static $speech_re = null;
    private static $query_re = null;

    public static function normalize($text) {
        static $map = null;
        if ($map === null) {
            $from = array('á','č','ď','é','ě','í','ň','ó','ř','š','ť','ú','ů','ý','ž','ä','ö','ü','ľ','ĺ','ŕ','Á','Č','Ď','É','Ě','Í','Ň','Ó','Ř','Š','Ť','Ú','Ů','Ý','Ž','Ä','Ö','Ü','Ľ','–','—');
            $to   = array('a','c','d','e','e','i','n','o','r','s','t','u','u','y','z','a','o','u','l','l','r','a','c','d','e','e','i','n','o','r','s','t','u','u','y','z','a','o','u','l','-','-');
            $map = array_combine($from, $to);
        }
        $text = strtr((string) $text, $map);
        return strtolower(preg_replace('/\s+/u', ' ', $text));
    }

    public static function name($book) { return isset(self::BOOKS[$book]) ? self::BOOKS[$book][0] : ''; }
    public static function chapters($book) { return count(self::verse_table($book)); }

    /** Počet veršů kapitoly, 0 = kapitola neexistuje. */
    public static function verses($book, $chapter) {
        $t = self::verse_table($book);
        return isset($t[$chapter - 1]) ? $t[$chapter - 1] : 0;
    }
    private static function verse_table($book) {
        static $cache = array();
        if (!isset($cache[$book])) $cache[$book] = isset(self::VERSES[$book]) ? array_map('intval', explode(',', self::VERSES[$book])) : array();
        return $cache[$book];
    }

    /**
     * Ověří odkaz. Vrací null, je-li platný, jinak českou hlášku pro uživatele.
     */
    public static function validate($book, $chapter = 0, $from = 0, $to = 0) {
        if (!isset(self::BOOKS[$book])) return 'Neznámá kniha.';
        $name = self::name($book);
        $max = self::chapters($book);
        if ($book === 19 && $chapter > $max) return 'Žalmů je jen 150.';
        if ($chapter && $chapter > $max) return sprintf('%s má jen %d %s.', $name, $max, $max === 1 ? 'kapitolu' : ($max <= 4 ? 'kapitoly' : 'kapitol'));
        if ($from && !$chapter) return 'Zadejte i kapitolu.';
        if ($chapter && $from) {
            $vmax = self::verses($book, $chapter);
            if ($from > $vmax || $to > $vmax) return sprintf('%s %d má jen %d %s.', $book === 19 ? 'Žalm' : $name, $chapter, $vmax, $vmax <= 4 ? 'verše' : 'veršů');
        }
        return null;
    }

    /** Čitelný odkaz, např. „Jan 3,16–18“, „Žalm 23“, „Zacharjáš“. */
    public static function format($book, $chapter = 0, $from = 0, $to = 0) {
        $name = self::name($book);
        if ($book === 19 && $chapter) $name = 'Žalm';
        if (!$chapter) return $name;
        $out = $name . ' ' . (int) $chapter;
        if ($from) $out .= ',' . (int) $from . ($to > $from ? '–' . (int) $to : '');
        return $out;
    }

    /** Rozdělí alternativy podle „|“ jen mimo závorky. */
    private static function split_top($pattern) {
        $out = array(); $depth = 0; $cur = '';
        foreach (str_split($pattern) as $c) {
            if ($c === '(') $depth++;
            if ($c === ')') $depth--;
            if ($c === '|' && $depth === 0) { $out[] = $cur; $cur = ''; continue; }
            $cur .= $c;
        }
        $out[] = $cur;
        return $out;
    }

    private static function alternation($pieces) {
        $pieces = array_values(array_filter(array_unique($pieces), 'strlen'));
        usort($pieces, function ($a, $b) { return strlen($b) - strlen($a); });
        return implode('|', $pieces);
    }

    private static function chapter_words_re() {
        return implode('|', array_keys(self::CHAPTER_WORDS));
    }

    private static function speech_regex() {
        if (self::$speech_re) return self::$speech_re;
        $names = array();
        foreach (self::$groups as $g) foreach (self::split_top($g[0]) as $p) $names[] = $p;
        $name = self::alternation($names);
        $ord = self::ORD;
        $words = self::chapter_words_re();
        self::$speech_re = '/(?<![a-z0-9])(?:(?P<ord>' . $ord . ')\.?\s+(?:(?:list[a-z]*|knih[a-z]*|epistol[a-z]*)\s+)?)?'
            . '(?:(?P<ctx>list[a-z]*|knih[a-z]*|evangeli[a-z]*(?: podle)?|prorok[a-z]*|proroctvi)\s+)?'
            . '(?P<name>' . $name . ')(?![a-z])'
            . '(?:\s*[,:.]?\s*(?P<ch>\d{1,3})(?!\d)(?:(?:\s*[,:.]\s*|\s+)(?:vers[a-z]*\s*)?(?P<v>\d{1,3})(?!\d)(?:\s*(?:-|az)\s*(?P<v2>\d{1,3})(?!\d))?|\.?\s*kapitol[a-z]*,?\s+(?P<v4>\d{1,3})(?!\d)\.?\s*vers[a-z]*)?'
            . '|\s+(?:kapitol[a-z]*|kap\.?)\s*(?P<ch2>\d{1,3})(?!\d)'
            . '|\s+(?P<cv>\d{3,4})(?!\d)'
            . '|\s+(?:(?:ve?|z|ze|do|na)\s+)?(?P<chw>' . $words . ')[a-z]*\s+kapitol[a-z]*(?:,?\s+(?:a\s+)?(?:ve?\s+)?vers[a-z]*\s+(?P<v3>\d{1,3})(?!\d))?)?/';
        return self::$speech_re;
    }

    private static function query_regex() {
        if (self::$query_re) return self::$query_re;
        $names = array();
        foreach (self::$groups as $g) {
            foreach (self::split_top($g[0]) as $p) $names[] = $p;
            foreach (self::split_top($g[1]) as $p) $names[] = $p;
        }
        $name = self::alternation($names);
        self::$query_re = '/^(?:(?P<ord>1|2|3|4|5|i{1,3}|prvn[a-z]*|druh[a-z]*|tret[a-z]*)\.?\s*)?'
            . '(?:(?:list[a-z]*|knih[a-z]*|evangeli[a-z]*(?: podle)?)\s+)?'
            . '(?P<name>' . $name . ')\.?'
            . '(?:\s*(?P<ch>\d{1,3})(?:\s*[,:. ]\s*(?P<v>\d{1,3})(?:\s*-\s*(?P<v2>\d{1,3}))?)?)?$/';
        return self::$query_re;
    }

    private static function ord_number($ord) {
        if ($ord === '' || $ord === null) return 0;
        if (ctype_digit($ord)) return (int) $ord;
        if (preg_match('/^i{1,3}$/', $ord)) return strlen($ord);
        foreach (array('prvn' => 1, 'druh' => 2, 'tret' => 3, 'ctvrt' => 4, 'pat' => 5) as $stem => $n) {
            if (strpos($ord, $stem) === 0) return $n;
        }
        return 0;
    }

    /** Vrátí [kniha, přísné] nebo [0, false]. $query povoluje zkratky a výchozí 1. díl. */
    private static function resolve($name, $ord, $query) {
        foreach (self::$groups as $g) {
            $pattern = $query && $g[1] !== '' ? $g[0] . '|' . $g[1] : $g[0];
            if (!preg_match('/^(?:' . $pattern . ')$/', $name)) continue;
            $map = $g[2];
            if (!is_array($map)) return array($map, $g[3]);
            $n = self::ord_number($ord);
            if ($n && isset($map[$n])) return array($map[$n], $n ? false : $g[3]);
            if (isset($map[0])) return array($map[0], $g[3]);
            if ($query) return array(reset($map), false);
            return array(0, false);
        }
        return array(0, false);
    }

    private static function finish($book, $ch, $v, $v2, $allow_book_only) {
        $max = self::chapters($book);
        if (!$max) return null;
        if ($ch && $max === 1 && $ch > 1 && !$v) { $v = $ch; $ch = 1; }
        if (!$ch || $ch > $max) return $allow_book_only ? array($book, 0, 0, 0) : null;
        $vmax = self::verses($book, $ch);
        if ($v < 1 || $v > $vmax) { $v = 0; $v2 = 0; }
        if ($v2 <= $v || $v2 > $vmax) $v2 = 0;
        return array($book, $ch, $v, $v2);
    }

    /**
     * Najde biblické odkazy v textu.
     * $mode 'speech' = přepis (přísnější), 'title' = název videu (povolí i samotnou knihu).
     * @return array<int, array{0:int,1:int,2:int,3:int}>
     */
    public static function extract($text, $mode = 'speech') {
        $norm = self::normalize($text);
        if (!preg_match_all(self::speech_regex(), $norm, $all, PREG_SET_ORDER)) return array();
        $out = array();
        foreach ($all as $m) {
            list($book, $strict) = self::resolve($m['name'], $m['ord'] ?? '', false);
            if (!$book) continue;
            $ch = (int) ($m['ch'] ?? 0);
            if (!$ch && !empty($m['ch2'])) $ch = (int) $m['ch2'];
            if (!$ch && !empty($m['chw'])) $ch = self::CHAPTER_WORDS[$m['chw']];
            $v = (int) ($m['v'] ?? 0); $v2 = (int) ($m['v2'] ?? 0);
            if (!$v && !empty($m['v3'])) $v = (int) $m['v3'];
            if (!$v && !empty($m['v4'])) $v = (int) $m['v4'];
            $explicit = !empty($m['ch2']) || !empty($m['chw']);
            if ($mode === 'speech') {
                if (!$ch) continue;
                if ($strict && !$v && !$explicit) continue;
                $ref = self::finish($book, $ch, $v, $v2, false);
            } else {
                // V názvu stačí samotná kniha, u knih znějících jako jméno jen s „prorok/kniha/list“.
                $book_only = !$strict || !empty($m['ctx']);
                $ref = self::finish($book, $ch, $v, $v2, $book_only);
            }
            if ($ref) $out[implode(':', $ref)] = $ref;
        }
        return array_values($out);
    }

    /** Odkaz na verš v Bibli (Bibleserver, překlad ČEP – stejně jako ve článcích na webu). */
    public static function url($book, $chapter, $from = 0, $to = 0) {
        $name = $book === 19 ? 'Žalm' : self::name($book);
        $ref = $name . (int) $chapter . ($from ? ',' . (int) $from . ($to > $from ? '-' . (int) $to : '') : '');
        return 'https://www.bibleserver.com/CEP/' . rawurlencode($ref);
    }

    /**
     * Do HTML přepisu (už escapovaného, může obsahovat <mark>) vloží odkazy na biblické verše.
     * Hledá stejně jako extract(); text uvnitř značek a už existujících odkazů se nemění.
     */
    public static function link_refs($html) {
        if (strpos($html, '<') === false) return self::link_text($html);
        $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $in_a = false;
        foreach ($parts as $i => $part) {
            if ($part === '') continue;
            if ($part[0] === '<') {
                if (preg_match('/^<a[\s>]/i', $part)) $in_a = true;
                elseif (strncasecmp($part, '</a', 3) === 0) $in_a = false;
                continue;
            }
            if (!$in_a) $parts[$i] = self::link_text($part);
        }
        return implode('', $parts);
    }

    private static function link_text($text) {
        $norm = self::normalize($text);
        // Odsazení v normalizovaném textu musí sedět na původní (jinak by se odkaz vložil na špatné místo).
        if ($norm === '' || mb_strlen($norm) !== mb_strlen($text)) return $text;
        if (!preg_match_all(self::speech_regex(), $norm, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $text;
        $out = ''; $pos = 0;
        foreach ($all as $m) {
            $plain = array();
            foreach ($m as $k => $v) if (is_string($k)) $plain[$k] = $v[0];
            $ref = self::match_ref($plain);
            if (!$ref) continue;
            $start = mb_strlen(substr($norm, 0, $m[0][1]));
            $len = mb_strlen($m[0][0]);
            if ($start < $pos) continue;
            $out .= mb_substr($text, $pos, $start - $pos)
                . '<a class="bsw-bv" href="' . esc_url(self::url($ref[0], $ref[1], $ref[2], $ref[3])) . '" target="_blank" rel="noopener noreferrer">'
                . mb_substr($text, $start, $len) . '</a>';
            $pos = $start + $len;
        }
        return $out . mb_substr($text, $pos);
    }

    /** Odkaz z jedné shody speech_regex (režim přepisu); null, pokud to není platný odkaz. */
    private static function match_ref($m) {
        list($book, $strict) = self::resolve($m['name'], $m['ord'] ?? '', false);
        if (!$book) return null;
        $ch = (int) ($m['ch'] ?? 0);
        if (!$ch && !empty($m['ch2'])) $ch = (int) $m['ch2'];
        if (!$ch && !empty($m['chw'])) $ch = self::CHAPTER_WORDS[$m['chw']];
        $v = (int) ($m['v'] ?? 0); $v2 = (int) ($m['v2'] ?? 0);
        if (!$v && !empty($m['v3'])) $v = (int) $m['v3'];
        if (!$v && !empty($m['v4'])) $v = (int) $m['v4'];
        // Přepis hlasu často slepí kapitolu a verš („leviticus 1822“ = 18,22; „231“ = 23,1).
        if (!$strict) {
            $digits = !empty($m['cv']) ? $m['cv'] : (($ch && !$v && strlen((string) $ch) >= 3 && $ch > self::chapters($book)) ? (string) $ch : '');
            $split = $digits !== '' ? self::split_merged($book, $digits) : null;
            if ($split) { $ch = $split[0]; $v = $split[1]; $v2 = 0; }
        }
        $explicit = !empty($m['ch2']) || !empty($m['chw']);
        if (!$ch) return null;
        if ($strict && !$v && !$explicit) return null;
        return self::finish($book, $ch, $v, $v2, false);
    }

    /** Rozdělí slepené číslice na kapitolu a verš, které v dané knize existují (delší kapitola má přednost). */
    private static function split_merged($book, $digits) {
        $max = self::chapters($book);
        for ($k = strlen($digits) - 1; $k >= 1; $k--) {
            $ch = (int) substr($digits, 0, $k); $v = (int) substr($digits, $k);
            if ($ch >= 1 && $ch <= $max && $v >= 1 && $v <= self::verses($book, $ch)) return array($ch, $v);
        }
        return null;
    }

    /** Rozpozná dotaz typu „Jan 3,16“, „ř 8 28“, „1 Kor 13“, „Žalm 23“, „Římanům“. */
    public static function parse_query($query) {
        $norm = trim(self::normalize($query));
        $norm = trim(preg_replace('/\s*([,:.\-])\s*/', '$1', $norm));
        $norm = str_replace(array(',', ':'), ' ', $norm);
        $norm = preg_replace('/\s+/', ' ', $norm);
        if ($norm === '' || strlen($norm) > 60) return null;
        if (!preg_match(self::query_regex(), $norm, $m)) return null;
        list($book) = self::resolve($m['name'], $m['ord'] ?? '', true);
        if (!$book) return null;
        $ch = (int) ($m['ch'] ?? 0);
        // Jednopísmenná zkratka bez čísla („j“, „l“, „r“) je spíš slovo než kniha.
        if (!$ch && strlen($m['name']) < 3 && empty($m['ord'])) return null;
        // Dotaz se nezkracuje – neexistující kapitolu/verš ohlásí BSW_Bible::validate().
        $v = (int) ($m['v'] ?? 0); $v2 = (int) ($m['v2'] ?? 0);
        if ($ch && self::chapters($book) === 1 && $ch > 1 && !$v) { $v = $ch; $ch = 1; }
        return array($book, $ch, $v, $v2 > $v ? $v2 : 0);
    }

    /** Kniha ze staré databáze: přednostně podle pořadí (1–66), jinak podle názvu. */
    public static function legacy_book($name, $order) {
        $order = (int) $order;
        if ($order >= 1 && $order <= 66) return $order;
        $ref = self::parse_query($name);
        return $ref ? $ref[0] : 0;
    }
}
