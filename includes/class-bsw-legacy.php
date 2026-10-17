<?php
if (!defined('ABSPATH')) exit;

/**
 * Převod staré ručně vedené databáze odkazů (tabulka `kazani` nebo její export CSV/TSV)
 * do tabulky bsw_refs. Import je opakovatelný: nejdřív smaže dřívější ruční odkazy.
 */
final class BSW_Legacy {
    const COLUMNS = array('kazani_datum', 'kazatel', 'id_kazani', 'nazev_kazani', 'url_video', 'kniha', 'podari_knihy',
        'zakon', 'kapitola', 'cislo_verse', 'cas', 'kod', 'druh', 'duraz', 'druh_videa');
    const MONTHS = array('led' => 1, 'uno' => 2, 'bre' => 3, 'dub' => 4, 'kve' => 5, 'cvn' => 6, 'cvc' => 7, 'srp' => 8, 'zar' => 9, 'rij' => 10, 'lis' => 11, 'pro' => 12);

    public static function video_id($url) {
        $url = trim((string) $url);
        if (preg_match('~(?:[?&]v=|youtu\.be/|/embed/|/live/|/shorts/)([A-Za-z0-9_-]{11})~', $url, $m)) return $m[1];
        return preg_match('/^[A-Za-z0-9_-]{11}$/', $url) ? $url : '';
    }

    public static function seconds($url, $cas) {
        if (preg_match('/[?&#]t=(?:(\d+)h)?(?:(\d+)m)?(\d+)s?/', (string) $url, $m)) return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
        $sec = 0;
        foreach (array_reverse(explode(':', trim((string) $cas))) as $i => $v) $sec += (int) $v * (int) pow(60, $i);
        return $sec;
    }

    /** Data ve tvarech „8-dub-20“, „17.03.2019“, „26.1.2020“, „2020-01-26“. */
    public static function date($raw) {
        $raw = trim(BSW_Bible::normalize($raw));
        if (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{2,4})$/', $raw, $m)) list(, $d, $mo, $y) = $m;
        elseif (preg_match('/^(\d{1,2})-([a-z]{3})[a-z]*-(\d{2,4})$/', $raw, $m) && isset(self::MONTHS[$m[2]])) { $d = $m[1]; $mo = self::MONTHS[$m[2]]; $y = $m[3]; }
        elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) { $y = $m[1]; $mo = $m[2]; $d = $m[3]; }
        else return '';
        $y = (int) $y; if ($y < 100) $y += 2000;
        return checkdate((int) $mo, (int) $d, $y) ? sprintf('%04d-%02d-%02d 09:30:00', $y, $mo, $d) : '';
    }

    /** Načte CSV/TSV (oddělovač tabulátor, středník, čárka nebo 2+ mezery). */
    public static function read_file($path) {
        $content = file_get_contents($path, false, null, 0, 8 * 1024 * 1024);
        if (!is_string($content) || $content === '') return new WP_Error('bsw_legacy_read', 'Soubor je prázdný.');
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (!preg_match('//u', $content)) $content = iconv('WINDOWS-1250', 'UTF-8//IGNORE', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $first = '';
        foreach ($lines as $l) { if (trim($l) !== '') { $first = $l; break; } }
        if (strpos($first, "\t") !== false) $split = function ($l) { return explode("\t", $l); };
        elseif (substr_count($first, ';') >= 5) $split = function ($l) { return str_getcsv($l, ';'); };
        elseif (substr_count($first, ',') >= 5) $split = function ($l) { return str_getcsv($l, ','); };
        else $split = function ($l) { return preg_split('/ {2,}/', trim($l)); };

        $header = array_map(function ($h) { return strtolower(trim($h)); }, $split($first));
        $has_header = in_array('url_video', $header, true) && in_array('kniha', $header, true);
        if (!$has_header) $header = self::COLUMNS;
        $rows = array(); $skip_first = $has_header;
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            if ($skip_first) { $skip_first = false; continue; }
            $cells = array_map('trim', $split($line));
            $row = array();
            foreach ($header as $i => $name) $row[$name] = isset($cells[$i]) ? $cells[$i] : '';
            $rows[] = $row;
            if (count($rows) > 100000) break;
        }
        return $rows;
    }

    public static function read_table($table) {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM `' . str_replace('`', '``', $table) . '`', ARRAY_A) ?: array();
    }

    public static function import($rows) {
        global $wpdb;
        $sermons = BSW_DB::sermons();
        $wpdb->delete(BSW_DB::refs(), array('source' => 'legacy'));
        $cache = array(); $out = array(); $seen = array();
        $report = array('rows' => count($rows), 'refs' => 0, 'created' => 0, 'skipped' => 0, 'errors' => array());
        foreach ($rows as $n => $row) {
            $vid = self::video_id($row['url_video'] ?? '');
            $book = BSW_Bible::legacy_book($row['kniha'] ?? '', $row['podari_knihy'] ?? 0);
            $chapter = (int) ($row['kapitola'] ?? 0);
            $verse = (int) ($row['cislo_verse'] ?? 0);
            if (!$vid || !$book || $chapter < 1 || $chapter > BSW_Bible::chapters($book)) {
                $report['skipped']++;
                if (count($report['errors']) < 8) $report['errors'][] = 'řádek ' . ($n + 2) . ': ' . (!$vid ? 'chybí odkaz na video' : (!$book ? 'neznámá kniha „' . ($row['kniha'] ?? '') . '“' : 'neplatná kapitola'));
                continue;
            }
            if (!isset($cache[$vid])) {
                $type = BSW_Bible::normalize($row['druh_videa'] ?? '');
                $type = strpos($type, 'biblick') === 0 ? 'biblicka' : (strpos($type, 'kazan') === 0 ? 'kazani' : '');
                $existing = $wpdb->get_row($wpdb->prepare("SELECT id, preacher FROM $sermons WHERE video_id=%s", $vid));
                $preacher = sanitize_text_field($row['kazatel'] ?? '');
                if ($existing) {
                    if ($preacher !== '' && $existing->preacher === '') $wpdb->update($sermons, array('preacher' => $preacher, 'preacher_source' => 'legacy'), array('id' => (int) $existing->id));
                    $cache[$vid] = (int) $existing->id;
                } else {
                    $title = trim($row['nazev_kazani'] ?? '') ?: 'Kázání ' . $vid;
                    $cache[$vid] = (int) BSW_DB::upsert_sermon($vid, $title, '', self::date($row['kazani_datum'] ?? ''), $preacher);
                    if ($cache[$vid]) $report['created']++;
                }
                if ($cache[$vid] && $type) $wpdb->update($sermons, array('video_type' => $type, 'type_source' => 'manual'), array('id' => $cache[$vid]));
            }
            if (!$cache[$vid]) { $report['skipped']++; continue; }
            $kind = BSW_Bible::normalize($row['druh'] ?? '');
            $kind = in_array($kind, array('citace', 'odkaz'), true) ? $kind : '';
            $emph = strtolower(trim($row['duraz'] ?? ''));
            $emph = in_array($emph, array('h', 'v'), true) ? $emph : '';
            $t = self::seconds($row['url_video'] ?? '', $row['cas'] ?? '');
            $verse = ($verse >= 1 && $verse <= 176) ? $verse : 0;
            $key = $cache[$vid] . ':' . $book . ':' . $chapter . ':' . $verse . ':' . $t . ':' . $kind;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = array($cache[$vid], $book, $chapter, $verse, 0, $t, 'legacy', $kind, $emph);
        }
        if ($out) BSW_DB::insert_refs($out);
        delete_transient('bsw_public_stats');
        $report['refs'] = count($out);
        return $report;
    }
}
