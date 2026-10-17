<?php
if (!defined('ABSPATH')) exit;
final class BSW_Search {
    const PER_PAGE = 12;
    const VISIBLE_HITS = 3;

    private static $params = array();
    /** Všechny filtry s „prázdnou“ hodnotou – pro odkazy „Zrušit filtry“. */
    const RESET = array('bsw_druh' => '', 'bsw_kazatel' => '', 'bsw_playlist' => '', 'bsw_fkniha' => 0, 'bsw_fkap' => 0, 'bsw_zakon' => '', 'bsw_rok' => 0);

    private static function video_url($id, $seconds = 0) {
        return 'https://www.youtube.com/watch?v=' . rawurlencode($id) . '&t=' . max(0, (int)$seconds) . 's';
    }
    private static function clock($seconds) {
        $seconds = (int)$seconds;
        $h = intdiv($seconds, 3600); $m = intdiv($seconds % 3600, 60); $s = $seconds % 60;
        return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
    private static function get_str($key, $max = 150) {
        if (!isset($_GET[$key]) || !is_string($_GET[$key])) return '';
        $v = trim(sanitize_text_field(wp_unslash($_GET[$key])));
        return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
    }
    private static function get_int($key, $max) {
        $v = isset($_GET[$key]) && is_scalar($_GET[$key]) ? (int)$_GET[$key] : 0;
        return ($v > 0 && $v <= $max) ? $v : 0;
    }

    /** Odkaz na tuto stránku s parametry vyhledávání (zachová ?page_id u prostých permalinků). */
    private static function url($args) {
        // Filtry (kazatel, druh) se přenášejí do všech odkazů, pokud je odkaz výslovně nepřepíše.
        $args += array('bsw_kazatel' => self::$params['preacher'] ?? '', 'bsw_druh' => self::$params['type'] ?? '', 'bsw_playlist' => self::$params['playlist'] ?? '',
            'bsw_fkniha' => self::$params['fbook'] ?? 0, 'bsw_fkap' => self::$params['fchapter'] ?? 0, 'bsw_zakon' => self::$params['testament'] ?? '',
            'bsw_rok' => self::$params['year'] ?? 0, 'bsw_razeni' => self::$params['sort'] ?? '');
        $args = array_filter($args, function ($v) { return $v !== '' && $v !== 0 && $v !== null; });
        return add_query_arg(array_map('rawurlencode', $args), get_permalink());
    }
    private static function filters_active() {
        return self::filter_count() > 0;
    }
    private static function filter_count() {
        $p = self::$params;
        return (int)($p['type'] !== '') + (int)($p['preacher'] !== '') + (int)($p['playlist'] !== '') + (int)(bool)$p['fbook'] + (int)(bool)$p['fchapter'] + (int)($p['testament'] !== '') + (int)(bool)$p['year'];
    }
    private static function hidden_page_id() {
        if (get_option('permalink_structure') === '') echo '<input type="hidden" name="page_id" value="' . esc_attr(get_queried_object_id()) . '">';
    }

    /** Zvýrazní hledané výrazy bez ohledu na diakritiku a velikost písmen. Vrací bezpečné HTML. */
    public static function highlight($text, $terms, $prefix = true) {
        static $map = array('a' => '[aáä]', 'c' => '[cč]', 'd' => '[dď]', 'e' => '[eéě]', 'i' => '[ií]', 'n' => '[nň]',
            'o' => '[oóö]', 'r' => '[rř]', 's' => '[sš]', 't' => '[tť]', 'u' => '[uúůü]', 'y' => '[yý]', 'z' => '[zž]');
        $alts = array();
        foreach ($terms as $term) {
            $term = trim(BSW_Bible::normalize($term));
            if ($term === '') continue;
            $re = '';
            foreach (preg_split('//u', $term, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                if ($ch === ' ') $re .= '\s+';
                elseif (isset($map[$ch])) $re .= $map[$ch];
                else $re .= preg_quote($ch, '/');
            }
            $alts[] = $re . ($prefix ? '[\p{L}]*' : '');
        }
        if (!$alts) return esc_html($text);
        $parts = preg_split('/(?<![\p{L}\p{N}])(' . implode('|', $alts) . ')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) return esc_html($text);
        $html = '';
        foreach ($parts as $i => $part) $html .= $i % 2 ? '<mark>' . esc_html($part) . '</mark>' : esc_html($part);
        return $html;
    }

    public static function render($atts = array()) {
        $q = self::get_str('bsw_q');
        $force_text = isset($_GET['bsw_slovo']);
        $ref = ($q !== '' && !$force_text) ? BSW_Bible::parse_query($q) : null;
        $book = self::get_int('bsw_kniha', 66);
        $chapter = $book ? self::get_int('bsw_kap', 999) : 0;
        $verse = $book ? self::get_int('bsw_vers', 999) : 0;
        if ($ref) { list($book, $chapter, $verse) = $ref; }
        $rezim = self::get_str('bsw_rezim', 10);
        $mode = ($ref || $book || $rezim === 'bible') ? 'bible' : ((in_array($rezim, array('otazky'), true) && $q === '') ? $rezim : 'text');
        $type = self::get_str('bsw_druh', 10);
        $playlist = self::get_str('bsw_playlist', 64);
        self::$params = array('preacher' => self::get_str('bsw_kazatel', 120), 'type' => isset(BSW_DB::TYPES[$type]) ? $type : '',
            'playlist' => preg_match('/^[A-Za-z0-9_-]{10,64}$/', $playlist) ? $playlist : '',
            'fbook' => self::get_int('bsw_fkniha', 66), 'fchapter' => 0, 'testament' => in_array(self::get_str('bsw_zakon', 2), array('st', 'nz'), true) ? self::get_str('bsw_zakon', 2) : '', 'page' => max(1, isset($_GET['bsw_str']) ? (int)$_GET['bsw_str'] : 1),
            'year' => self::get_int('bsw_rok', 2100) >= 1990 ? self::get_int('bsw_rok', 2100) : 0, 'sort' => self::get_str('bsw_razeni', 10) === 'nove' ? 'nove' : '');
        if (self::$params['fbook'] && self::$params['testament'] !== '' && (self::$params['fbook'] <= 39) !== (self::$params['testament'] === 'st')) self::$params['fbook'] = 0;
        if (self::$params['fbook']) self::$params['fchapter'] = self::get_int('bsw_fkap', BSW_Bible::chapters(self::$params['fbook']));
        $verse_to = $ref ? $ref[3] : 0;
        $invalid = $book ? BSW_Bible::validate($book, $chapter, $verse, $verse_to) : null;
        $has_results = ($mode === 'text' && $q !== '') || ($mode === 'bible' && $book);

        bsw_enqueue_assets();
        $transcript = BSW_Transcript::requested();
        ob_start();
        // Záloha pro motivy, které pozdě přidané styly nevypíšou.
        // Hlavička už proběhla bez našich stylů (shortcode mimo obsah stránky) → vložit hned a označit jako vypsané.
        if (did_action('wp_head') && !wp_style_is('bsw-search', 'done')) {
            echo '<style id="bsw-search-inline">' . bsw_inline_css() . '</style>';
            wp_styles()->done[] = 'bsw-search';
        }
        echo '<div class="bsw-app">';
        if ($transcript) {
            BSW_Transcript::render($transcript, $q);
            echo '</div>';
            return ob_get_clean();
        }
        if (isset($_GET['bsw_prepis'])) {
            self::empty_state('Přepis jsme nenašli', 'Video možná není veřejné nebo k němu zatím nemáme přepis.');
            echo '<p class="bsw-back"><a href="' . esc_url(self::url(array())) . '">← Zpět na vyhledávání</a></p></div>';
            return ob_get_clean();
        }
        if (isset($_GET['bsw_otazka'])) {
            $question = BSW_Topics::requested();
            if ($question) BSW_Topics::render_question($question);
            else {
                self::empty_state('Otázku jsme nenašli', 'Podívejte se na seznam otázek víry.');
                echo '<p class="bsw-back"><a href="' . esc_url(self::url(array('bsw_rezim' => 'otazky'))) . '">← Otázky víry</a></p>';
            }
            echo '</div>';
            return ob_get_clean();
        }
        self::hero($mode, $q, $book, $chapter, $verse, $has_results);
        echo '<aside class="bsw-about" role="note"><div><h3>Přepisy jsou strojové</h3><p>Vznikly automaticky z&nbsp;titulků YouTube a nástrojem Whisper, proto mohou obsahovat chyby, hlavně ve jménech. <strong>V&nbsp;případě pochybností platí vždy záznam.</strong></p></div></aside>';
        echo '<div class="bsw-main">';
        if ($mode === 'text' && $q !== '') self::text_results($q);
        elseif ($mode === 'bible' && $book && $invalid) self::empty_state('Takové místo v Bibli není', $invalid . ' Upravte kapitolu nebo verš.');
        elseif ($mode === 'bible' && $book) self::book_results($book, $chapter, $verse, $ref ? $q : '', $verse_to);
        elseif ($mode === 'bible') self::book_grid();
        elseif ($mode === 'otazky') BSW_Topics::render_index();
        echo '</div>';
        echo '</div>';
        return ob_get_clean();
    }

    /**
     * Lišta filtrů nad výsledky. Počty se počítají z aktuálně nalezených kázání,
     * takže nabízí jen kazatele, playlisty a knihy, které ve výsledcích opravdu jsou.
     */
    private static function facets($sermon_ids, $query_args, $with_bible) {
        global $wpdb;
        $ids = array_values(array_unique(array_map('intval', $sermon_ids)));
        if (!$ids) return;
        $in = implode(',', $ids);
        $st = BSW_DB::sermons(); $rt = BSW_DB::refs(); $pt = BSW_DB::playlists(); $it = BSW_DB::playlist_items();
        $types = $wpdb->get_results("SELECT video_type v, COUNT(*) c FROM $st WHERE id IN ($in) AND video_type<>'' GROUP BY video_type", ARRAY_A);
        $preachers = $wpdb->get_results("SELECT preacher v, COUNT(*) c FROM $st WHERE id IN ($in) AND preacher<>'' GROUP BY preacher ORDER BY c DESC, preacher", ARRAY_A);
        $playlists = $wpdb->get_results("SELECT p.playlist_id v, p.title t, COUNT(DISTINCT s.id) c FROM $it i JOIN $pt p ON p.playlist_id=i.playlist_id JOIN $st s ON s.video_id=i.video_id
            WHERE s.id IN ($in) AND p.is_preacher=0 GROUP BY p.playlist_id, p.title ORDER BY p.title", ARRAY_A);
        $years = $wpdb->get_results("SELECT YEAR(published_at) v, COUNT(*) c FROM $st WHERE id IN ($in) AND published_at IS NOT NULL GROUP BY YEAR(published_at) ORDER BY v DESC", ARRAY_A);
        $books = $testaments = $chapters = array();
        if ($with_bible) {
            // Při vybraném zákonu nabízet jen jeho knihy.
            $range = self::$params['testament'] === 'st' ? ' AND book BETWEEN 1 AND 39' : (self::$params['testament'] === 'nz' ? ' AND book BETWEEN 40 AND 66' : '');
            $books = $wpdb->get_results("SELECT book v, COUNT(DISTINCT sermon_id) c FROM $rt WHERE sermon_id IN ($in)$range GROUP BY book ORDER BY book", ARRAY_A);
            $chapters = self::$params['fbook'] ? $wpdb->get_results($wpdb->prepare("SELECT chapter v, COUNT(DISTINCT sermon_id) c FROM $rt WHERE sermon_id IN ($in) AND book=%d AND chapter>0 GROUP BY chapter ORDER BY chapter", self::$params['fbook']), ARRAY_A) : array();
            $testaments = $wpdb->get_results("SELECT IF(book<=39,'st','nz') v, COUNT(DISTINCT sermon_id) c FROM $rt WHERE sermon_id IN ($in) GROUP BY IF(book<=39,'st','nz')", ARRAY_A);
        }
        $active = self::filters_active();
        $book_label = self::$params['fbook'] === 19 ? 'Žalm' : 'Kapitola';
        $count = self::filter_count();
        echo '<details class="bsw-facets-wrap"' . ($active ? ' open' : '') . '><summary>Filtrovat výsledky' . ($count ? ' <span>' . $count . '</span>' : '') . '</summary>';
        echo '<form class="bsw-facets" method="get" action="' . esc_url(get_permalink()) . '">';
        self::hidden_page_id();
        foreach ($query_args as $k => $v) if ($v !== '' && $v !== 0 && $v !== null) echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        $select = function ($name, $label, $all, $current, $options) {
            if (!$options && ($current === '' || $current === 0)) return;
            // Změna knihy vynuluje kapitolu; prázdné filtry se do adresy neposílají.
            $reset = $name === 'bsw_fkniha' ? 'if(this.form.bsw_fkap)this.form.bsw_fkap.value="";'
                : ($name === 'bsw_zakon' ? 'if(this.form.bsw_fkniha)this.form.bsw_fkniha.value="";if(this.form.bsw_fkap)this.form.bsw_fkap.value="";' : '');
            echo '<label class="bsw-facet"><span>' . esc_html($label) . '</span><select name="' . esc_attr($name) . '" onchange="' . esc_attr($reset) . 'for(var e of this.form.elements){if(!e.value)e.disabled=true}this.form.submit()"><option value="">' . esc_html($all) . '</option>';
            foreach ($options as $o) echo '<option value="' . esc_attr($o[0]) . '"' . selected((string)$current, (string)$o[0], false) . '>' . esc_html($o[1]) . ' (' . (int)$o[2] . ')</option>';
            echo '</select></label>';
        };
        $select('bsw_druh', 'Druh', 'vše', self::$params['type'], array_map(function ($r) { return array($r['v'], BSW_DB::TYPES[$r['v']] ?? $r['v'], $r['c']); }, $types));
        $select('bsw_kazatel', 'Kazatel', 'všichni', self::$params['preacher'], array_map(function ($r) { return array($r['v'], $r['v'], $r['c']); }, $preachers));
        $select('bsw_playlist', 'Playlist', 'všechny', self::$params['playlist'], array_map(function ($r) { return array($r['v'], $r['t'], $r['c']); }, $playlists));
        $select('bsw_rok', 'Rok', 'všechny', self::$params['year'], array_map(function ($r) { return array((int)$r['v'], (string)(int)$r['v'], $r['c']); }, $years));
        if ($with_bible) {
            $tl = array('st' => 'Starý zákon', 'nz' => 'Nový zákon');
            usort($testaments, function ($a, $b) { return strcmp($b['v'], $a['v']); });
            $select('bsw_zakon', 'Zákon', 'oba', self::$params['testament'], array_map(function ($r) use ($tl) { return array($r['v'], $tl[$r['v']], $r['c']); }, $testaments));
            $select('bsw_fkniha', 'Kniha', 'všechny', self::$params['fbook'], array_map(function ($r) { return array((int)$r['v'], BSW_Bible::name((int)$r['v']), $r['c']); }, $books));
            if (self::$params['fbook']) $select('bsw_fkap', $book_label, 'všechny', self::$params['fchapter'], array_map(function ($r) { return array((int)$r['v'], (string)(int)$r['v'], $r['c']); }, $chapters));
        }
        echo '<noscript><button type="submit">Filtrovat</button></noscript>';
        if ($active) echo '<a class="bsw-facet-reset" href="' . esc_url(self::url($query_args + self::RESET)) . '">Zrušit filtry</a>';
        echo '</form></details>';
        // Na počítači je lišta rozbalená, na mobilu se rozbalí až klepnutím.
        echo '<script>(function(d){if(d&&window.matchMedia("(min-width: 701px)").matches)d.open=true;})(document.currentScript.previousElementSibling);</script>';
    }

    /** SQL podmínka pro filtry kazatel / druh / playlist a veřejnost; $alias = alias tabulky kázání. */
    private static function filter_sql($alias, &$args) {
        $sql = " AND $alias." . BSW_DB::PUBLIC_SQL;
        if (self::$params['preacher'] !== '') { $sql .= " AND $alias.preacher = %s"; $args[] = self::$params['preacher']; }
        if (self::$params['type'] !== '') { $sql .= " AND $alias.video_type = %s"; $args[] = self::$params['type']; }
        if (self::$params['playlist'] !== '') { $sql .= " AND $alias.video_id IN (SELECT video_id FROM " . BSW_DB::playlist_items() . ' WHERE playlist_id = %s)'; $args[] = self::$params['playlist']; }
        if (self::$params['fbook']) {
            $sql .= " AND $alias.id IN (SELECT sermon_id FROM " . BSW_DB::refs() . ' WHERE book = %d' . (self::$params['fchapter'] ? ' AND chapter = %d' : '') . ')';
            $args[] = self::$params['fbook'];
            if (self::$params['fchapter']) $args[] = self::$params['fchapter'];
        }
        if (self::$params['year']) { $sql .= " AND $alias.published_at >= %s AND $alias.published_at < %s"; $args[] = self::$params['year'] . '-01-01 00:00:00'; $args[] = (self::$params['year'] + 1) . '-01-01 00:00:00'; }
        if (self::$params['testament'] !== '') $sql .= " AND $alias.id IN (SELECT sermon_id FROM " . BSW_DB::refs() . ' WHERE book ' . (self::$params['testament'] === 'st' ? 'BETWEEN 1 AND 39' : 'BETWEEN 40 AND 66') . ')';
        return $sql;
    }

    private static function hero($mode, $q, $book, $chapter, $verse, $compact) {
        echo '<section class="bsw-hero' . ($compact ? ' is-compact' : '') . '">';
        echo '<span class="bsw-eyebrow">Archiv kázání</span>';
        $titles = array('bible' => 'Kde se kázalo z Bible?', 'otazky' => 'Otázky víry');
        $leads = array('bible' => 'Vyberte knihu, kapitolu nebo verš a najdeme kázání, kde se o něm mluvilo – s odkazem přímo na dané místo ve videu.',
            'otazky' => 'Často kladené otázky o křesťanské víře a místa z našich kázání, kde na ně zazněla odpověď.');
        echo '<h2 class="bsw-title">' . esc_html($titles[$mode] ?? 'Co zaznělo v kázáních?') . '</h2>';
        echo '<p class="bsw-lead">' . esc_html($leads[$mode] ?? 'Napište slovo nebo frázi a najdeme všechna kázání, kde zazněla – s odkazem přímo na dané místo ve videu.') . '</p>';

        echo '<nav class="bsw-tabs" aria-label="Režim vyhledávání">';
        echo '<a href="' . esc_url(self::url(array())) . '"' . ($mode === 'text' ? ' class="is-active" aria-current="page"' : '') . '>Slova a fráze</a>';
        echo '<a href="' . esc_url(self::url(array('bsw_rezim' => 'bible'))) . '"' . ($mode === 'bible' ? ' class="is-active" aria-current="page"' : '') . '>Biblické knihy</a>';
        if (BSW_Topics::questions()) echo '<a href="' . esc_url(self::url(array('bsw_rezim' => 'otazky'))) . '"' . ($mode === 'otazky' ? ' class="is-active" aria-current="page"' : '') . '>Otázky víry</a>';
        echo '</nav>';

        echo '<form class="bsw-search' . ($mode === 'bible' ? ' is-bible' : '') . '" method="get" action="' . esc_url(get_permalink()) . '" role="search">';
        self::hidden_page_id();
        if ($mode !== 'bible') {
            echo '<input type="search" name="bsw_q" value="' . esc_attr($q) . '" placeholder="Např. milost, modlitba, Jan 3,16…" aria-label="Hledaný výraz" maxlength="150" autocomplete="off" required>';
            BSW_Topics::suggest_script();
        } else {
            echo '<input type="hidden" name="bsw_rezim" value="bible">';
            echo '<select name="bsw_kniha" aria-label="Kniha" required><option value="">Kniha…</option>';
            foreach (array('ST' => 'Starý zákon', 'NZ' => 'Nový zákon') as $code => $label) {
                echo '<optgroup label="' . esc_attr($label) . '">';
                foreach (BSW_Bible::BOOKS as $no => $b) if ($b[1] === $code) echo '<option value="' . (int)$no . '"' . selected($book, $no, false) . '>' . esc_html($b[0]) . '</option>';
                echo '</optgroup>';
            }
            echo '</select>';
            $cmax = $book ? BSW_Bible::chapters($book) : 150;
            $vmax = ($book && $chapter && $chapter <= $cmax) ? BSW_Bible::verses($book, $chapter) : 176;
            echo '<input type="number" name="bsw_kap" min="1" max="' . (int)$cmax . '" inputmode="numeric" value="' . ($chapter ? (int)$chapter : '') . '" placeholder="kapitola" aria-label="Kapitola">';
            echo '<input type="number" name="bsw_vers" min="1" max="' . (int)$vmax . '" inputmode="numeric" value="' . ($verse ? (int)$verse : '') . '" placeholder="verš" aria-label="Verš">';
            echo '<p class="bsw-limit" aria-live="polite"></p>';
            self::limits_script();
        }
        echo '<button type="submit">Hledat</button>';
        echo '</form>';

        if (!$compact && in_array($mode, array('text', 'bible'), true)) {
            $examples = $mode === 'text'
                ? array('milost', 'modlitba', 'Duch svatý', 'odpuštění', 'naděje')
                : array('Jan 3,16', 'Římanům 8', 'Žalm 23', 'Izajáš 53', '1 Kor 13');
            if ($mode === 'text' && ($popular = BSW_Topics::popular(6)) && count($popular) >= 3) $examples = $popular;
            echo '<div class="bsw-examples">' . ($mode === 'text' && isset($popular) && count($popular) >= 3 ? 'Často hledáte:' : 'Zkuste:');
            foreach ($examples as $e) echo '<a href="' . esc_url(self::url(array('bsw_q' => $e))) . '">' . esc_html($e) . '</a>';
            echo '</div>';
            echo '<p class="bsw-tip">' . ($mode === 'text'
                ? 'Diakritiku psát nemusíte. Přesný výraz hledejte v uvozovkách, např. "nezasloužená milost". Biblický odkaz (např. Ř 8,28) poznáme automaticky.'
                : 'Odkazy pocházejí z ruční databáze, z názvů videí a z automatického rozpoznání v přepisu – například „Jan 3 16“ nebo „Filipským druhá kapitola“.') . '</p>';
            $s = BSW_DB::public_stats();
            if ($s['segments']) {
                echo '<div class="bsw-stats">';
                echo '<div><strong>' . esc_html(number_format_i18n($s['sermons'])) . '</strong><span>kázání</span></div>';
                if ($s['hours']) echo '<div><strong>' . esc_html(number_format_i18n($s['hours'])) . '</strong><span>hodin záznamu</span></div>';
                echo '<div><strong>' . esc_html(number_format_i18n($s['segments'])) . '</strong><span>prohledávaných úseků</span></div>';
                if ($s['refs']) echo '<div><strong>' . esc_html(number_format_i18n($s['refs'])) . '</strong><span>biblických odkazů</span></div>';
                echo '</div>';
            }
        }
        echo '</section>';
    }

    /** Formulář hlídá skutečný počet kapitol a veršů vybrané knihy (Zjevení 60,1 nepustí). */
    private static function limits_script() {
        static $printed = false;
        if ($printed) return;
        $printed = true;
        $data = array();
        foreach (BSW_Bible::BOOKS as $no => $b) $data[$no] = array($b[0], BSW_Bible::VERSES[$no]);
        echo '<script>(function(){var B=' . wp_json_encode($data) . ';var f=document.currentScript.closest("form");if(!f)return;'
            . 'var b=f.querySelector("[name=bsw_kniha]"),c=f.querySelector("[name=bsw_kap]"),v=f.querySelector("[name=bsw_vers]"),m=f.querySelector(".bsw-limit");'
            . 'function vs(){return b.value&&B[b.value]?B[b.value][1].split(",").map(Number):[];}'
            . 'function upd(){var t=vs(),n=B[b.value]?B[b.value][0]:"",ch=+c.value,vv=+v.value,msg="";c.max=t.length||150;'
            . 'if(ch&&t.length&&ch>t.length){msg=n+" má jen "+t.length+" kapitol.";}'
            . 'v.max=(ch&&t[ch-1])?t[ch-1]:176;if(!msg&&vv&&ch&&t[ch-1]&&vv>t[ch-1]){msg=n+" "+ch+" má jen "+t[ch-1]+" veršů.";}'
            . 'if(vv&&!ch)msg="Zadejte i kapitolu.";c.setCustomValidity(msg&&ch>t.length?msg:"");v.setCustomValidity(msg&&!(ch>t.length)?msg:"");m.textContent=msg;}'
            . 'b.addEventListener("change",upd);c.addEventListener("input",upd);v.addEventListener("input",upd);upd();})();</script>';
    }

    /* ---------------- fulltext ---------------- */

    private static function text_results($query) {
        global $wpdb;
        $segments = BSW_DB::segments(); $sermons = BSW_DB::sermons();
        $norm = BSW_DB::normalize($query);
        $is_phrase = (bool)preg_match('/["„“]/u', $norm);
        $norm = trim(str_replace(array('"', '„', '“'), '', $norm));
        $ready = BSW_DB::index_ready();
        $words = $ready ? BSW_Stem::words($norm) : preg_split('/[^\p{L}\p{N}]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) { self::empty_state('Zadejte hledaný výraz.'); return; }
        $words = array_slice($words, 0, 8);
        list($where, $args, $variants) = self::text_where($words, $is_phrase, $ready);
        $where .= self::filter_sql('ser', $args);

        // 1) Kázání a počet zásahů v každém (pro řazení, filtry a stránkování), 2) úseky jen pro aktuální stránku.
        $agg = $wpdb->get_results($wpdb->prepare("SELECT ser.id, ser.title, ser.video_id, ser.published_at, ser.preacher, ser.video_type, COUNT(*) hits
            FROM $segments seg INNER JOIN $sermons ser ON ser.id=seg.sermon_id
            WHERE $where GROUP BY ser.id ORDER BY ser.published_at DESC, ser.id DESC", $args));
        if ($wpdb->last_error) { self::empty_state('Vyhledávání nyní není dostupné.'); return; }
        self::log_query($query, count($agg));

        if (!$agg) {
            if (self::filters_active()) {
                echo '<p class="bsw-summary"><a class="bsw-switch" href="' . esc_url(self::url(array('bsw_q' => $query) + self::RESET)) . '">Zrušit filtry a hledat znovu</a></p>';
            }
            if (($dym = BSW_Analysis::did_you_mean($query)) !== '') {
                echo '<p class="bsw-summary">Měli jste na mysli <a class="bsw-switch" href="' . esc_url(self::url(array('bsw_q' => $dym) + self::RESET)) . '"><strong>' . esc_html($dym) . '</strong></a>?</p>';
            }
            self::empty_state('Nic jsme nenašli', 'Zkuste jiný nebo kratší výraz. U některých kázání ještě nemusí být přepis.');
            return;
        }
        $groups = array(); $total_hits = 0;
        foreach ($agg as $r) {
            $hits = (int)$r->hits;
            $total_hits += $hits;
            // Relevance: počet míst v kázání; když hledané slovo stojí i v názvu, jde o téma kázání.
            $in_title = $is_phrase ? strpos(BSW_DB::normalize($r->title), implode(' ', $words)) !== false : self::title_matches($r->title, $variants);
            $groups[(int)$r->id] = array('sermon' => $r, 'count' => $hits, 'score' => $hits + ($in_title ? 10 + $hits : 0), 'hits' => array());
        }
        if (self::$params['sort'] !== 'nove') {
            // Stabilní řazení (PHP 8): při shodě zůstane novější kázání výš.
            uasort($groups, function ($a, $b) { return $b['score'] - $a['score']; });
        }
        $page_groups = self::paginate($groups);
        $ids = array_keys($page_groups);
        if ($ids) {
            $sql = "SELECT seg.sermon_id, seg.start_seconds, seg.body FROM $segments seg INNER JOIN $sermons ser ON ser.id=seg.sermon_id
                WHERE $where AND seg.sermon_id IN (" . implode(',', array_map('intval', $ids)) . ') ORDER BY seg.sermon_id, seg.start_seconds';
            $phrase = implode(' ', $words);
            foreach ($wpdb->get_results($wpdb->prepare($sql, $args)) as $r) {
                if ($is_phrase) {
                    // Fráze přes hranici úseků: v tomto úseku je jen její začátek – zvýraznit slova.
                    $html = strpos(BSW_DB::normalize($r->body), $phrase) !== false ? self::highlight($r->body, array($phrase), false) : self::highlight($r->body, $words, true);
                } else {
                    $html = $ready ? self::highlight_variants($r->body, $variants) : self::highlight($r->body, $words);
                }
                $page_groups[(int)$r->sermon_id]['hits'][] = array('t' => (int)$r->start_seconds, 'html' => $html);
            }
        }
        $ref = BSW_Bible::parse_query($query);
        $extra = $ref ? ' <a class="bsw-switch" href="' . esc_url(self::url(array('bsw_q' => $query))) . '">Hledat jako biblický odkaz ' . esc_html(BSW_Bible::format(...$ref)) . '</a>' : '';
        $base = array('bsw_q' => $query, 'bsw_slovo' => isset($_GET['bsw_slovo']) ? '1' : '');
        self::facets(array_keys($groups), $base + array('bsw_razeni' => self::$params['sort']), true);
        self::summary(count($groups), $total_hits, 'výskytů', $extra);
        if (count($groups) > 1) {
            $sorts = array('' => 'nejrelevantnější', 'nove' => 'nejnovější');
            echo '<nav class="bsw-sort" aria-label="Řazení výsledků"><span>Řadit:</span>';
            foreach ($sorts as $key => $label) {
                echo self::$params['sort'] === $key ? '<strong aria-current="true">' . esc_html($label) . '</strong>'
                    : '<a href="' . esc_url(self::url($base + array('bsw_razeni' => $key))) . '">' . esc_html($label) . '</a>';
            }
            echo '</nav>';
        }
        self::groups($page_groups, $base, count($groups), $query);
    }

    /**
     * WHERE pro fulltext. Vrací [sql, args, varianty slov].
     * S hotovým indexem tvarů hledá každé slovo podle všech variant (slovo, kmen, synonyma) od začátku slova;
     * fráze najde i přes hranici dvou úseků (join_text = začátek dalšího úseku).
     */
    private static function text_where($words, $is_phrase, $ready) {
        global $wpdb;
        if ($is_phrase) {
            $p = '%' . $wpdb->esc_like(implode(' ', $words)) . '%';
            if (!$ready) return array('seg.search_text LIKE %s', array($p), array());
            return array("(seg.search_text LIKE %s OR (CONCAT(seg.search_text, ' ', seg.join_text) LIKE %s AND seg.join_text NOT LIKE %s))", array($p, $p, $p), array());
        }
        if (!$ready) {
            $all_long = true;
            foreach ($words as $w) { if (strlen($w) < 4) { $all_long = false; break; } }
            if ($all_long) return array('MATCH(seg.search_text) AGAINST (%s IN BOOLEAN MODE)', array(implode(' ', array_map(function ($w) { return '+' . $w . '*'; }, $words))), array());
            $conds = array(); $args = array();
            foreach ($words as $w) { $conds[] = 'seg.search_text LIKE %s'; $args[] = '%' . $wpdb->esc_like($w) . '%'; }
            return array(implode(' AND ', $conds), $args, array());
        }
        $conds = array(); $args = array(); $ft = array(); $variants = array();
        foreach ($words as $w) {
            $vs = BSW_Stem::variants($w);
            $variants[] = $vs;
            $short = false;
            foreach (array_keys($vs) as $v) if (strlen($v) < 4) $short = true;
            if (!$short) {
                $terms = array();
                foreach ($vs as $v => $prefix) $terms[] = $v . ($prefix ? '*' : '');
                $ft[] = '+(' . implode(' ', $terms) . ')';
                continue;
            }
            // Krátké výrazy FULLTEXT neindexuje – LIKE od začátku slova, resp. celé slovo (index má mezery na krajích).
            $or = array();
            foreach ($vs as $v => $prefix) { $or[] = 'seg.index_text LIKE %s'; $args[] = '% ' . $wpdb->esc_like($v) . ($prefix ? '%' : ' %'); }
            $conds[] = '(' . implode(' OR ', $or) . ')';
        }
        if ($ft) { array_unshift($conds, 'MATCH(seg.index_text) AGAINST (%s IN BOOLEAN MODE)'); array_unshift($args, implode(' ', $ft)); }
        return array(implode(' AND ', $conds), $args, $variants);
    }

    /** Odpovídá slovo (v libovolném tvaru) některé variantě hledaného výrazu? */
    private static function word_matches($word, $variants) {
        return BSW_Stem::matches(BSW_DB::normalize($word), $variants);
    }

    private static function title_matches($title, $variants) {
        if (!$variants) return false;
        $words = BSW_Stem::words(BSW_DB::normalize(explode('|', (string)$title)[0]));
        foreach ($variants as $vs) {
            $found = false;
            foreach ($words as $w) if (self::word_matches($w, $vs)) { $found = true; break; }
            if (!$found) return false;
        }
        return true;
    }

    /** Zvýrazní slova, která odpovídají variantám hledaných slov (Bůh → Boha, Bohu, Bože). Vrací bezpečné HTML. */
    public static function highlight_variants($text, $variants) {
        $flat = array();
        foreach ($variants as $vs) $flat += $vs;
        if (!$flat) return esc_html($text);
        $parts = preg_split('/([\p{L}\p{N}]+)/u', (string)$text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) return esc_html($text);
        $html = '';
        foreach ($parts as $i => $part) {
            $html .= ($i % 2 && self::word_matches($part, $flat)) ? '<mark>' . esc_html($part) . '</mark>' : esc_html($part);
        }
        return $html;
    }

    /**
     * Anonymní statistika: jen text dotazu, počet nalezených kázání a kolikrát se hledal.
     * Bez IP adres a bez přihlášených uživatelů a robotů; jen první strana bez filtrů.
     */
    private static function log_query($query, $results) {
        global $wpdb;
        if (self::$params['page'] > 1 || self::filters_active() || is_user_logged_in()) return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|fetch|preview|monitor|curl|wget|python|java\/|headless|lighthouse|pingdom/i', $ua)) return;
        $q = mb_substr(trim(mb_strtolower(preg_replace('/\s+/u', ' ', $query), 'UTF-8')), 0, 150, 'UTF-8');
        if ($q === '') return;
        $table = BSW_DB::queries();
        $wpdb->query($wpdb->prepare("INSERT INTO $table (query_text, results, searches, last_at) VALUES (%s, %d, 1, %s)
            ON DUPLICATE KEY UPDATE results = VALUES(results), searches = searches + 1, last_at = VALUES(last_at)", $q, (int)$results, current_time('mysql', true)));
        if (mt_rand(1, 200) === 1) $wpdb->query("DELETE FROM $table WHERE last_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 YEAR)");
    }

    /* ---------------- biblické knihy ---------------- */

    private static function book_counts() {
        $counts = get_transient('bsw_book_counts');
        if (is_array($counts)) return $counts;
        global $wpdb;
        $counts = array();
        foreach ($wpdb->get_results('SELECT r.book, COUNT(DISTINCT r.sermon_id) c FROM ' . BSW_DB::refs() . ' r JOIN ' . BSW_DB::sermons() . ' s ON s.id=r.sermon_id WHERE s.' . BSW_DB::PUBLIC_SQL . ' GROUP BY r.book') as $r) $counts[(int)$r->book] = (int)$r->c;
        set_transient('bsw_book_counts', $counts, 6 * HOUR_IN_SECONDS);
        return $counts;
    }

    private static function book_grid() {
        $counts = self::book_counts();
        if (!$counts) { self::empty_state('Zatím žádné biblické odkazy', 'Správce je doplní importem přepisů nebo staré databáze.'); return; }
        foreach (array('ST' => 'Starý zákon', 'NZ' => 'Nový zákon') as $code => $label) {
            echo '<section class="bsw-books"><h3>' . esc_html($label) . '</h3><div class="bsw-book-grid">';
            foreach (BSW_Bible::BOOKS as $no => $b) {
                if ($b[1] !== $code) continue;
                $c = $counts[$no] ?? 0;
                if ($c) echo '<a href="' . esc_url(self::url(array('bsw_kniha' => $no, 'bsw_kazatel' => self::$params['preacher']))) . '"><span>' . esc_html($b[0]) . '</span><small>' . (int)$c . '</small></a>';
                else echo '<span class="is-empty"><span>' . esc_html($b[0]) . '</span></span>';
            }
            echo '</div></section>';
        }
        echo '<p class="bsw-note">Číslo udává počet kázání, ve kterých kniha zazní.</p>';
    }

    private static function chapter_nav($book, $chapter) {
        global $wpdb;
        $counts = array();
        foreach ($wpdb->get_results($wpdb->prepare('SELECT r.chapter, COUNT(DISTINCT r.sermon_id) c FROM ' . BSW_DB::refs() . ' r JOIN ' . BSW_DB::sermons() . ' s ON s.id=r.sermon_id WHERE r.book=%d AND r.chapter>0 AND s.' . BSW_DB::PUBLIC_SQL . ' GROUP BY r.chapter', $book)) as $r) $counts[(int)$r->chapter] = (int)$r->c;
        if (!$counts) return;
        echo '<nav class="bsw-chapters" aria-label="Kapitoly"><span>Kapitola:</span>';
        echo '<a href="' . esc_url(self::url(array('bsw_kniha' => $book, 'bsw_kazatel' => self::$params['preacher']))) . '"' . (!$chapter ? ' class="is-active"' : '') . '>vše</a>';
        for ($i = 1, $max = BSW_Bible::chapters($book); $i <= $max; $i++) {
            if (empty($counts[$i])) { echo '<span class="is-empty">' . $i . '</span>'; continue; }
            echo '<a href="' . esc_url(self::url(array('bsw_kniha' => $book, 'bsw_kap' => $i, 'bsw_kazatel' => self::$params['preacher']))) . '" title="' . esc_attr($counts[$i] . ' kázání') . '"' . ($chapter === $i ? ' class="is-active"' : '') . '>' . $i . '</a>';
        }
        echo '</nav>';
    }

    /** Sloučí verše stejného místa do rozsahů: [1,2,3,5] → „1–3, 5“. */
    private static function verse_label($book, $chapter, $verses) {
        if (!$chapter) return BSW_Bible::format($book);
        $verses = array_values(array_unique(array_filter($verses)));
        if (!$verses) return BSW_Bible::format($book, $chapter);
        sort($verses);
        $ranges = array(); $start = $prev = $verses[0];
        foreach (array_slice($verses, 1) as $v) {
            if ($v === $prev + 1) { $prev = $v; continue; }
            $ranges[] = $start === $prev ? (string)$start : $start . '–' . $prev;
            $start = $prev = $v;
        }
        $ranges[] = $start === $prev ? (string)$start : $start . '–' . $prev;
        return BSW_Bible::format($book, $chapter) . ',' . implode(', ', $ranges);
    }

    private static function book_results($book, $chapter, $verse, $typed, $verse_to) {
        global $wpdb;
        $refs = BSW_DB::refs(); $sermons = BSW_DB::sermons();
        $label = BSW_Bible::format($book, $chapter, $verse, $verse_to);
        echo '<div class="bsw-book-head"><h3>' . esc_html($label) . '</h3>';
        if ($typed !== '') echo '<a class="bsw-switch" href="' . esc_url(self::url(array('bsw_q' => $typed, 'bsw_slovo' => '1'))) . '">Hledat „' . esc_html($typed) . '“ jako slovo</a>';
        echo '<a class="bsw-switch" href="' . esc_url(self::url(array('bsw_rezim' => 'bible'))) . '">Všechny knihy</a></div>';
        self::chapter_nav($book, $chapter);

        $where = array('r.book = %d'); $args = array($book);
        if ($chapter) { $where[] = 'r.chapter = %d'; $args[] = $chapter; }
        if ($verse) {
            $to = $verse_to > $verse ? $verse_to : $verse;
            $where[] = '(r.verse_from = 0 OR (r.verse_from <= %d AND GREATEST(r.verse_to, r.verse_from) >= %d))';
            $args[] = $to; $args[] = $verse;
        }
        $filters = self::filter_sql('s', $args);
        $sql = "SELECT r.sermon_id, r.book, r.chapter, r.verse_from, r.verse_to, r.start_seconds, r.source, r.kind, r.emphasis,
                s.id, s.title, s.video_id, s.published_at, s.preacher, s.video_type
            FROM $refs r INNER JOIN $sermons s ON s.id = r.sermon_id
            WHERE " . implode(' AND ', $where) . $filters . ' ORDER BY s.published_at DESC, s.id DESC, r.start_seconds ASC LIMIT 4000';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args));
        if ($wpdb->last_error) { self::empty_state('Vyhledávání nyní není dostupné.'); return; }
        if (!$rows) {
            self::empty_state('K tomuto místu zatím nemáme kázání', $chapter ? 'Zkuste celou kapitolu nebo knihu.' : 'Odkazy se doplňují s každým importem přepisů.');
            return;
        }

        // Seskupení: kázání → čas → odkazy na stejné místo.
        $groups = array();
        foreach ($rows as $r) {
            $sid = (int)$r->sermon_id;
            if (!isset($groups[$sid])) $groups[$sid] = array('sermon' => $r, 'spots' => array(), 'main' => false);
            if ($r->emphasis === 'h') $groups[$sid]['main'] = true;
            $key = (int)$r->start_seconds . '|' . (int)$r->chapter . '|' . $r->source;
            if (!isset($groups[$sid]['spots'][$key])) $groups[$sid]['spots'][$key] = array('t' => (int)$r->start_seconds, 'book' => (int)$r->book,
                'chapter' => (int)$r->chapter, 'verses' => array(), 'source' => $r->source, 'kinds' => array(), 'main' => false);
            $spot = &$groups[$sid]['spots'][$key];
            for ($v = (int)$r->verse_from, $end = max((int)$r->verse_to, (int)$r->verse_from); $v && $v <= $end; $v++) $spot['verses'][] = $v;
            if ($r->kind) $spot['kinds'][$r->kind] = true;
            if ($r->emphasis === 'h') $spot['main'] = true;
            unset($spot);
        }
        // Kázání, kde jde o hlavní text (název videa / ruční „h“), jdou první.
        uasort($groups, function ($a, $b) { return (int)$b['main'] - (int)$a['main']; });
        $total_spots = 0;
        foreach ($groups as $g) $total_spots += count($g['spots']);
        self::facets(array_keys($groups), array('bsw_kniha' => $book, 'bsw_kap' => $chapter, 'bsw_vers' => $verse), false);
        self::summary(count($groups), $total_spots, 'míst');

        $page_groups = self::paginate($groups);
        $snippets = self::snippets(array_keys($page_groups));
        $stem = self::book_stem($book);
        foreach ($page_groups as $sid => &$g) {
            $g['hits'] = array();
            foreach ($g['spots'] as $spot) {
                $badges = array();
                if ($spot['source'] === 'title') $badges[] = array('Téma kázání', 'main');
                elseif ($spot['main']) $badges[] = array('Hlavní text', 'main');
                if ($spot['source'] === 'legacy') foreach (array_keys($spot['kinds']) as $k) $badges[] = array($k === 'odkaz' ? 'Odkaz' : 'Citace', '');
                if ($spot['source'] === 'auto') $badges[] = array('Zmínka v přepisu', 'soft');
                $html = '<strong class="bsw-ref">' . esc_html(self::verse_label($spot['book'], $spot['chapter'], $spot['verses'])) . '</strong>';
                foreach ($badges as $b) $html .= ' <em class="bsw-badge ' . esc_attr($b[1]) . '">' . esc_html($b[0]) . '</em>';
                $text = $spot['source'] === 'title' ? '' : self::snippet_for($snippets[$sid] ?? array(), $spot['t']);
                if ($text !== '') $html .= '<br>' . self::highlight($text, array($stem));
                $g['hits'][] = array('t' => $spot['t'], 'html' => $html);
            }
        }
        unset($g);
        self::groups($page_groups, array('bsw_kniha' => $book, 'bsw_kap' => $chapter, 'bsw_vers' => $verse), count($groups));
    }

    private static function book_stem($book) {
        $name = preg_replace('/^\d\.\s*/', '', BSW_Bible::normalize(BSW_Bible::name($book)));
        return substr($name, 0, min(5, strlen($name) - (strlen($name) > 4 ? 1 : 0)));
    }

    private static function snippets($sermon_ids) {
        global $wpdb;
        $ids = array_map('intval', $sermon_ids);
        if (!$ids) return array();
        $sql = 'SELECT sermon_id, start_seconds, body FROM ' . BSW_DB::segments() . ' WHERE sermon_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ') ORDER BY sermon_id, start_seconds';
        $out = array();
        foreach ($wpdb->get_results($wpdb->prepare($sql, $ids)) as $r) $out[(int)$r->sermon_id][] = array((int)$r->start_seconds, $r->body);
        return $out;
    }

    /** Úsek přepisu, který obsahuje daný čas (u ručních odkazů je čas zaokrouhlený). */
    private static function snippet_for($list, $t) {
        $best = '';
        foreach ($list as $item) {
            if ($item[0] > $t) break;
            $best = $item[1];
            $last = $item[0];
        }
        return ($best !== '' && $t - $last <= 90) ? $best : '';
    }

    /* ---------------- společné vykreslení ---------------- */

    private static function empty_state($title, $text = '') {
        echo '<div class="bsw-empty"><h3>' . esc_html($title) . '</h3>' . ($text ? '<p>' . esc_html($text) . '</p>' : '') . '</div>';
    }

    private static function summary($sermons, $hits, $unit, $extra = '') {
        echo '<p class="bsw-summary">Nalezeno v <strong>' . esc_html(number_format_i18n($sermons)) . '</strong> ' . esc_html(self::plural($sermons, 'kázání', 'kázáních', 'kázáních'))
            . ' (' . esc_html(number_format_i18n($hits)) . ' ' . esc_html($unit) . ')' . (self::$params['preacher'] !== '' ? ' · kazatel <strong>' . esc_html(self::$params['preacher']) . '</strong>' : '')
            . (self::$params['type'] !== '' ? ' · <strong>' . esc_html(BSW_DB::TYPES[self::$params['type']]) . '</strong>' : '')
            . (self::$params['playlist'] !== '' ? ' · playlist <strong>' . esc_html(self::playlist_title(self::$params['playlist'])) . '</strong>' : '')
            . (self::$params['testament'] !== '' ? ' · <strong>' . (self::$params['testament'] === 'st' ? 'Starý zákon' : 'Nový zákon') . '</strong>' : '')
            . (self::$params['fbook'] ? ' · <strong>' . esc_html(BSW_Bible::format(self::$params['fbook'], self::$params['fchapter'])) . '</strong>' : '') . '.' . $extra . '</p>';
    }
    private static function playlist_title($id) {
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare('SELECT title FROM ' . BSW_DB::playlists() . ' WHERE playlist_id=%s', $id));
    }
    private static function plural($n, $one, $few, $many) { return $n === 1 ? $one : ($n >= 2 && $n <= 4 ? $few : $many); }

    private static function paginate($groups) {
        $page = self::$params['page'];
        return array_slice($groups, ($page - 1) * self::PER_PAGE, self::PER_PAGE, true);
    }

    private static function groups($groups, $base, $total = null, $query = '') {
        $total = $total === null ? count($groups) : $total;
        $list = $total === count($groups) && count($groups) > self::PER_PAGE ? self::paginate($groups) : $groups;
        foreach ($list as $g) {
            $s = $g['sermon'];
            $hits = $g['hits'];
            $title = BSW_DB::display_title($s->title, $s->preacher);
            $meta = array();
            if ($s->published_at) $meta[] = date_i18n('j. n. Y', strtotime($s->published_at . ' UTC'));
            if ($s->preacher) $meta[] = $s->preacher;
            if (!empty($s->video_type) && $s->video_type !== 'kazani' && isset(BSW_DB::TYPES[$s->video_type])) $meta[] = BSW_DB::TYPES[$s->video_type];
            echo '<article class="bsw-video"><div class="bsw-video-head"><h3><a href="' . esc_url(self::video_url($s->video_id, $hits ? $hits[0]['t'] : 0)) . '" target="_blank" rel="noopener">' . esc_html($title) . '</a></h3>';
            echo '<span class="bsw-count">' . count($hits) . '×</span></div>';
            echo '<p class="bsw-meta">' . esc_html(implode(' · ', $meta)) . ($meta ? ' · ' : '') . '<a class="bsw-read" href="' . esc_url(BSW_Transcript::url($s->video_id, $query, $hits ? $hits[0]['t'] : 0)) . '">Číst přepis</a> · <a class="bsw-full" href="' . esc_url('https://www.youtube.com/watch?v=' . rawurlencode($s->video_id)) . '" target="_blank" rel="noopener">Přehrát celé video</a></p>';
            foreach (array_slice($hits, 0, self::VISIBLE_HITS) as $h) self::hit($s->video_id, $h);
            if (count($hits) > self::VISIBLE_HITS) {
                echo '<details><summary>další ' . (count($hits) - self::VISIBLE_HITS) . ' ' . esc_html(self::plural(count($hits) - self::VISIBLE_HITS, 'místo', 'místa', 'míst')) . '</summary>';
                foreach (array_slice($hits, self::VISIBLE_HITS) as $h) self::hit($s->video_id, $h);
                echo '</details>';
            }
            echo '</article>';
        }
        $pages = (int)ceil($total / self::PER_PAGE);
        if ($pages > 1) {
            $page = min(self::$params['page'], $pages);
            echo '<nav class="bsw-pages" aria-label="Stránkování">';
            if ($page > 1) echo '<a href="' . esc_url(self::url($base + array('bsw_str' => $page - 1))) . '">‹ Předchozí</a>';
            echo '<span>Strana ' . (int)$page . ' z ' . (int)$pages . '</span>';
            if ($page < $pages) echo '<a href="' . esc_url(self::url($base + array('bsw_str' => $page + 1))) . '">Další ›</a>';
            echo '</nav>';
        }
    }

    private static function hit($video_id, $h) {
        echo '<div class="bsw-hit"><a class="bsw-t" href="' . esc_url(self::video_url($video_id, $h['t'])) . '" target="_blank" rel="noopener" aria-label="Přehrát od ' . esc_attr(self::clock($h['t'])) . '">' . esc_html(self::clock($h['t'])) . '</a><span>' . BSW_Bible::link_refs($h['html']) . '</span></div>';
    }
}
