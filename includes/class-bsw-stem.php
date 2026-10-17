<?php
if (!defined('ABSPATH')) exit;
/**
 * Jednoduchý český stemmer nad textem bez diakritiky (výstup BSW_DB::normalize).
 * Odřízne pádové a nejčastější slovesné koncovky, sjednotí vkladné e (církev/církve)
 * a měkčení (ruka/ruce, kniha/knize). Stejná funkce se volá na index i na dotaz,
 * takže nevadí, že kmen není jazykově přesný – důležité je, aby tvary jednoho slova
 * dostaly stejný kmen. Index navíc drží i původní slova, takže hledání najde vždy
 * aspoň to, co dřív.
 */
final class BSW_Stem {
    /** Při změně pravidel zvýšit – index se pak sám přepočítá. */
    const VERSION = 1;
    const MIN = 3;

    /** Nepravidelné tvary, které pravidla nesjednotí. */
    const LEXICON = array(
        'buh' => 'boh', 'boze' => 'boh', 'bohove' => 'boh', 'bozi' => 'boh', 'bohum' => 'boh',
        'clovek' => 'clovek', 'cloveka' => 'clovek', 'cloveku' => 'clovek', 'clovece' => 'clovek', 'clovekem' => 'clovek',
        'lide' => 'lid', 'lidi' => 'lid', 'lidem' => 'lid', 'lidmi' => 'lid', 'lidech' => 'lid',
        'dite' => 'dit', 'ditete' => 'dit', 'diteti' => 'dit', 'detem' => 'dit', 'deti' => 'dit', 'detmi' => 'dit', 'detech' => 'dit',
        'oko' => 'ok', 'oci' => 'ok', 'ocima' => 'ok', 'ucho' => 'uch', 'usi' => 'uch',
        'den' => 'den', 'dne' => 'den', 'dni' => 'den', 'dnem' => 'den', 'dnu' => 'den', 'dny' => 'den', 'dnech' => 'den',
        'pan' => 'pan', 'pani' => 'pan', 'pane' => 'pan',
        'krev' => 'krv', 'krve' => 'krv', 'krvi' => 'krv',
        'jezis' => 'jezis', 'jezisi' => 'jezis', 'jezise' => 'jezis', 'jezisem' => 'jezis',
        'kristus' => 'krist', 'krista' => 'krist', 'kristu' => 'krist', 'kriste' => 'krist', 'kristem' => 'krist',
        'duch' => 'duch', 'ducha' => 'duch', 'duchu' => 'duch', 'duchem' => 'duch', 'dusi' => 'dus',
    );

    /** Koncovky od nejdelší; odřízne se první, po které zbydou aspoň MIN znaky. */
    const SUFFIXES = array(
        'ovala', 'ovali', 'ovalo', 'ujeme', 'ujete',
        'ovat', 'oval', 'nout', 'nula', 'nuli', 'nulo', 'etem', 'ujou',
        'ich', 'ych', 'ech', 'ach', 'ami', 'emi', 'ymi', 'imi', 'eho', 'emu', 'imu', 'ymu', 'iho', 'ovi', 'ove',
        'eti', 'eni', 'ani', 'uje', 'uji', 'uju', 'ila', 'ili', 'ilo', 'ala', 'ali', 'alo', 'nul',
        'ime', 'ite', 'eme', 'ete',
        'ou', 'em', 'om', 'um', 'am', 'ym', 'im', 'us', 'it', 'at', 'et', 'il', 'al', 'mi',
        'a', 'e', 'i', 'o', 'u', 'y',
    );

    public static function word($w) {
        if (isset(self::LEXICON[$w])) return self::LEXICON[$w];
        $len = strlen($w);
        if ($len <= self::MIN || !ctype_alpha($w)) return $w;
        foreach (self::SUFFIXES as $s) {
            $l = strlen($s);
            // Infinitiv (-at/-et/-it) jen u delších slov, ať ze „sabat“ nezbyde „sab“.
            $min = ($l === 2 && $s[1] === 't') ? self::MIN + 1 : self::MIN;
            if ($len - $l >= $min && substr($w, -$l) === $s) { $w = substr($w, 0, $len - $l); break; }
        }
        // Vkladné e: církev/církve, kostel/kostela, otec/otce, píseň/písně.
        if (strlen($w) >= 4 && preg_match('/[^aeiouy]e([kclvn])$/', $w, $m)) $w = substr($w, 0, -2) . $m[1];
        // Měkčení na konci kmene: ruka/ruce, otec/otce, kniha/knize.
        $last = substr($w, -1);
        if ($last === 'c' && strlen($w) >= 3) $w = substr($w, 0, -1) . 'k';
        elseif ($last === 'z' && strlen($w) >= 4) $w = substr($w, 0, -1) . 'h';
        return $w;
    }

    /** Slova normalizovaného textu (bez diakritiky, malá písmena). */
    public static function words($normalized) {
        return preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Text pro index: slova úseku a za nimi kmeny, které se od slova liší.
     * Mezery na krajích umožní hledat začátek slova přes LIKE '% kmen%'.
     */
    public static function index_text($normalized) {
        $words = self::words($normalized);
        $stems = array();
        foreach ($words as $w) {
            $s = self::word($w);
            if ($s !== $w) $stems[$s] = true;
        }
        return ' ' . implode(' ', $words) . ($stems ? ' ' . implode(' ', array_keys($stems)) : '') . ' ';
    }

    /** Skupiny slov se stejným významem z administrace („tvary a synonyma“), klíčované kmenem. */
    private static function groups() {
        static $groups = null;
        if ($groups !== null) return $groups;
        $groups = array();
        foreach (preg_split('/\R/', (string) get_option('bsw_synonyms', '')) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $members = array();
            foreach (preg_split('/[,;=]+/', $line) as $item) {
                foreach (self::words(BSW_DB::normalize($item)) as $w) $members[$w] = true;
            }
            if (count($members) < 2) continue;
            $members = array_keys($members);
            foreach ($members as $w) {
                $key = self::word($w);
                $groups[$key] = array_values(array_unique(array_merge($groups[$key] ?? array(), $members)));
            }
        }
        return $groups;
    }

    /**
     * Varianty jednoho hledaného slova: [výraz => true = začátek slova, false = celé slovo].
     * Napsané slovo a slova ze skupiny se hledají od začátku slova (jako dřív), kmen jen když je
     * dost dlouhý – krátký kmen „boh“ by jinak našel i „bohatství“, proto se hledá jako celé slovo.
     */
    public static function variants($w) {
        $out = array($w => true);
        $add = function ($word) use (&$out) {
            $out[$word] = true;
            $stem = self::word($word);
            if (!isset($out[$stem])) $out[$stem] = strlen($stem) >= 5;
        };
        $add($w);
        foreach (self::groups()[self::word($w)] ?? array() as $g) $add($g);
        // Výraz hledaný od začátku slova pokryje všechny, které jím začínají.
        $keep = array();
        foreach ($out as $v => $prefix) {
            foreach ($out as $u => $up) if ($up && $u !== $v && strpos($v, $u) === 0) continue 2;
            $keep[$v] = $prefix;
        }
        return $keep;
    }

    /** Odpovídá slovo indexu (bez diakritiky) některé variantě? Kontroluje slovo i jeho kmen. */
    public static function matches($n, $variants) {
        $stem = self::word($n);
        foreach ($variants as $v => $prefix) {
            if ($prefix ? (strpos($n, $v) === 0 || strpos($stem, $v) === 0) : ($n === $v || $stem === $v)) return true;
        }
        return false;
    }
}
