<?php
if (!defined('ABSPATH')) exit;
/**
 * Otázky víry, témata a našeptávač.
 *  - Otázky víry (?bsw_otazka=slug): kurátorský seznam otázek (data-questions.php) s klíčovými slovy a biblickými verši;
 *    odpověď tvoří úryvky z kázání, kde zazněla nejvíc klíčových slov (žádné generování textu, jen citace s odkazem na video).
 *  - Našeptávač: REST /bsw/v1/suggest?q=… doplňuje slova z archivu.
 * Všechna data se odvozují z přepisů, takže se s novými kázáními obnovují sama (cache podle BSW_DB::rev()).
 */
final class BSW_Topics {
    const PER_QUESTION = 8;

    public static function init() {
        add_action('template_redirect', array(__CLASS__, 'template_redirect'));
        add_action('rest_api_init', function () {
            register_rest_route('bsw/v1', '/suggest', array('methods' => 'GET', 'callback' => array(__CLASS__, 'rest_suggest'), 'permission_callback' => '__return_true'));
        });
    }

    /* ---------- otázky ---------- */

    public static function questions() {
        static $list = null;
        if ($list === null) {
            $file = BSW_DIR . 'includes/data-questions.php';
            $list = is_readable($file) ? include $file : array();
            if (!is_array($list)) $list = array();
        }
        return $list;
    }

    public static function find($slug) {
        foreach (self::questions() as $q) if ($q['slug'] === $slug) return $q;
        return null;
    }

    /** Otázka z adresy, jinak null. */
    public static function requested() {
        if (!isset($_GET['bsw_otazka']) || !is_string($_GET['bsw_otazka'])) return null;
        return self::find(sanitize_title(wp_unslash($_GET['bsw_otazka'])));
    }

    public static function url($slug, $base = null) {
        if ($base === null) $base = (is_singular() && get_queried_object_id()) ? get_permalink() : get_permalink(BSW_Transcript::page_id());
        return add_query_arg('bsw_otazka', rawurlencode($slug), $base);
    }

    private static function bible_url($ref, $base) {
        return add_query_arg(array_filter(array('bsw_kniha' => $ref[0], 'bsw_kap' => $ref[1], 'bsw_vers' => $ref[2]), function ($v) { return $v; }), $base);
    }

    /** Biblické odkazy otázky jako [book, chapter, verse_from, verse_to]. */
    private static function verse_refs($q) {
        $out = array();
        foreach ($q['verses'] ?? array() as $text) {
            $r = BSW_Bible::parse_query($text);
            if ($r && !BSW_Bible::validate($r[0], $r[1], $r[2], $r[3])) $out[] = $r;
        }
        return $out;
    }

    /** Varianty slov klíčových slov otázky: [skupina => [tvar => je_prefix]]. */
    private static function variants($q) {
        $out = array();
        foreach ($q['kw'] as $kw) {
            $w = BSW_Stem::words(BSW_DB::normalize($kw));
            if ($w) $out[] = BSW_Stem::variants($w[0]);
        }
        return $out;
    }

    /** Nejlepší úryvky z kázání k otázce: jeden na kázání, seřazené podle počtu pokrytých klíčových slov. */
    public static function answers($q) {
        global $wpdb;
        $key = 'bsw_ans_' . md5($q['slug']) . '_' . BSW_DB::rev();
        $cached = get_transient($key);
        if (is_array($cached)) return $cached;
        if (!BSW_DB::index_ready()) return array();
        $variants = self::variants($q);
        $terms = array();
        foreach ($variants as $vs) foreach ($vs as $v => $prefix) if (strlen($v) >= 4) $terms[] = $v . ($prefix ? '*' : '');
        if (!$terms) return array();
        $need = min(2, count($variants));
        $sg = BSW_DB::segments(); $st = BSW_DB::sermons();
        $against = implode(' ', array_unique($terms));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT seg.sermon_id, seg.start_seconds, seg.body, MATCH(seg.index_text) AGAINST (%s IN BOOLEAN MODE) score
            FROM $sg seg INNER JOIN $st ser ON ser.id=seg.sermon_id
            WHERE MATCH(seg.index_text) AGAINST (%s IN BOOLEAN MODE) AND ser.caption_status='done' AND ser." . BSW_DB::PUBLIC_SQL . ' ORDER BY score DESC LIMIT 400', $against, $against));
        $best = array();
        foreach ($rows as $r) {
            $words = BSW_Stem::words(BSW_DB::normalize($r->body));
            $matched = 0;
            foreach ($variants as $vs) foreach ($words as $w) if (BSW_Stem::matches($w, $vs)) { $matched++; break; }
            if ($matched < $need) continue;
            $score = $matched * 10 + (float) $r->score;
            $sid = (int) $r->sermon_id;
            if (!isset($best[$sid]) || $score > $best[$sid]['score']) $best[$sid] = array('score' => $score, 'sermon_id' => $sid, 't' => (int) $r->start_seconds, 'body' => $r->body);
        }
        uasort($best, function ($a, $b) { return $b['score'] <=> $a['score']; });
        $best = array_slice($best, 0, self::PER_QUESTION, true);
        $out = array();
        if ($best) {
            $meta = array();
            foreach ($wpdb->get_results("SELECT id, video_id, title, preacher, published_at FROM $st WHERE id IN (" . implode(',', array_map('intval', array_keys($best))) . ')') as $m) $meta[(int) $m->id] = $m;
            foreach ($best as $sid => $b) {
                if (!isset($meta[$sid])) continue;
                $next = $wpdb->get_var($wpdb->prepare("SELECT body FROM $sg WHERE sermon_id=%d AND start_seconds>%d ORDER BY start_seconds LIMIT 1", $sid, $b['t']));
                $m = $meta[$sid];
                $out[] = array('video_id' => $m->video_id, 'title' => BSW_DB::display_title($m->title, $m->preacher), 'preacher' => $m->preacher, 'date' => $m->published_at,
                    't' => $b['t'], 'text' => trim($b['body'] . ($next ? ' ' . $next : '')));
            }
        }
        // dokud se archiv ještě rozebírá, výsledek se brzy přepočítá
        set_transient($key, $out, BSW_Analysis::pending() ? 5 * MINUTE_IN_SECONDS : WEEK_IN_SECONDS);
        return $out;
    }

    private static function clock($s) {
        $s = (int) $s; $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60);
        return $h ? sprintf('%d:%02d:%02d', $h, $m, $s % 60) : sprintf('%d:%02d', $m, $s % 60);
    }

    /** Seznam všech otázek podle kategorií. */
    public static function render_index() {
        $groups = array();
        foreach (self::questions() as $q) $groups[$q['cat']][] = $q;
        if (!$groups) { echo '<div class="bsw-empty"><h3>Otázky víry se připravují</h3></div>'; return; }
        $base = get_permalink();
        echo '<p class="bsw-summary">K často kladeným otázkám o víře ukážeme místa z našich kázání, kde na ně zazněla odpověď – s odkazem přímo na dané místo ve videu.</p>';
        foreach ($groups as $cat => $list) {
            echo '<section class="bsw-books"><h3>' . esc_html($cat) . '</h3><ul class="bsw-qlist">';
            foreach ($list as $q) echo '<li><a href="' . esc_url(self::url($q['slug'], $base)) . '">' . esc_html($q['q']) . '</a></li>';
            echo '</ul></section>';
        }
        echo '<p class="bsw-note"><strong>Upozornění:</strong> Odpovědi nejsou oficiální stanovisko sboru ani vyčerpávající výklad. Úryvky mohou být vytržené z kontextu a obsahovat chyby. Rozhodující je Písmo a celé kázání.</p>';
    }

    public static function render_question($q) {
        $base = get_permalink();
        $back = add_query_arg('bsw_rezim', 'otazky', $base);
        echo '<article class="bsw-question">';
        echo '<p class="bsw-back"><a href="' . esc_url($back) . '">← Všechny otázky</a></p>';
        echo '<header class="bsw-hero is-compact"><span class="bsw-eyebrow">' . esc_html($q['cat']) . '</span><h2 class="bsw-title">' . esc_html($q['q']) . '</h2></header>';
        $refs = self::verse_refs($q);
        if ($refs) {
            echo '<section class="bsw-video"><h3>Klíčové biblické texty</h3><p class="bsw-chips">';
            foreach ($refs as $r) echo '<a class="bsw-chip" href="' . esc_url(self::bible_url($r, $base)) . '">' . esc_html(BSW_Bible::format($r[0], $r[1], $r[2], $r[3])) . '</a>';
            echo '</p><p class="bsw-note">Odkaz ukáže kázání, ve kterých se o daném místě mluvilo.</p></section>';
        }
        $answers = self::answers($q);
        $variants = self::variants($q);
        echo '<h3 class="bsw-sub">Co o tom zaznělo v kázáních</h3>';
        if (!$answers) echo '<div class="bsw-empty"><h3>Zatím jsme nic vhodného nenašli</h3><p>Zkuste vyhledávání slov nebo biblických knih.</p></div>';
        foreach ($answers as $a) {
            $meta = array();
            if ($a['date']) $meta[] = date_i18n('j. n. Y', strtotime($a['date'] . ' UTC'));
            if ($a['preacher']) $meta[] = $a['preacher'];
            echo '<article class="bsw-video"><div class="bsw-video-head"><h3><a href="' . esc_url(BSW_Transcript::url($a['video_id'], '', $a['t'], $base)) . '">' . esc_html($a['title']) . '</a></h3></div>';
            echo '<p class="bsw-meta">' . esc_html(implode(' · ', $meta)) . '</p>';
            echo '<div class="bsw-hit"><a class="bsw-t" href="' . esc_url('https://www.youtube.com/watch?v=' . rawurlencode($a['video_id']) . '&t=' . (int) $a['t'] . 's') . '" target="_blank" rel="noopener" aria-label="Přehrát od ' . esc_attr(self::clock($a['t'])) . '">'
                . esc_html(self::clock($a['t'])) . '</a><span>' . BSW_Bible::link_refs(BSW_Search::highlight_variants($a['text'], $variants)) . '</span></div>';
            echo '<p class="bsw-meta"><a class="bsw-read" href="' . esc_url(BSW_Transcript::url($a['video_id'], '', $a['t'], $base)) . '">Číst přepis od tohoto místa</a></p></article>';
        }
        $more = array();
        foreach (self::questions() as $o) if ($o['cat'] === $q['cat'] && $o['slug'] !== $q['slug']) $more[] = $o;
        if ($more) {
            echo '<section class="bsw-video"><h3>Další otázky: ' . esc_html($q['cat']) . '</h3><ul class="bsw-qlist">';
            foreach (array_slice($more, 0, 6) as $o) echo '<li><a href="' . esc_url(self::url($o['slug'], $base)) . '">' . esc_html($o['q']) . '</a></li>';
            echo '</ul></section>';
        }
        echo '<p class="bsw-note"><strong>Upozornění:</strong> Úryvky nejsou stanovisko sboru k celé otázce, mohou být vytržené z kontextu a obsahovat chyby. Rozhodující je Písmo a celé kázání.</p>';
        echo '</article>';
    }

    public static function template_redirect() {
        if (!isset($_GET['bsw_otazka'])) return;
        $q = self::requested();
        if (!$q) {
            status_header(404);
            nocache_headers();
            add_filter('wp_robots', function ($r) { $r['noindex'] = true; return $r; });
            return;
        }
        $title = $q['q'] . ' – odpovědi z kázání';
        $desc = 'Co o otázce „' . $q['q'] . '“ zaznělo v kázáních sboru: úryvky s odkazem na video a biblické texty.';
        BSW_Transcript::override_head(self::url($q['slug'], get_permalink()), $title, $desc, null);
    }

    /* ---------- populární hledání a našeptávač ---------- */

    /** Nejčastější hledání s výsledky za posledního půl roku (bez dotazů, které jsou biblický odkaz). */
    public static function popular($limit = 6) {
        global $wpdb;
        $key = 'bsw_popular_' . $limit;
        $cached = get_transient($key);
        if (is_array($cached)) return $cached;
        $rows = $wpdb->get_col($wpdb->prepare('SELECT query_text FROM ' . BSW_DB::queries() . ' WHERE results >= 2 AND searches >= 2 AND last_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 DAY) ORDER BY searches DESC, last_at DESC LIMIT %d', $limit * 3));
        $out = array();
        foreach ($rows as $q) {
            if (mb_strlen($q, 'UTF-8') < 3 || BSW_Bible::parse_query($q)) continue;
            $out[] = $q;
            if (count($out) >= $limit) break;
        }
        set_transient($key, $out, HOUR_IN_SECONDS);
        return $out;
    }

    public static function rest_suggest(WP_REST_Request $req) {
        $q = trim((string) $req->get_param('q'));
        $q = mb_substr($q, 0, 60, 'UTF-8');
        $words = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
        $out = array();
        if ($words) {
            $last = array_pop($words);
            $head = $words ? implode(' ', $words) . ' ' : '';
            foreach (BSW_Analysis::complete($last, 6) as $w) $out[] = $head . $w;
        }
        $res = rest_ensure_response(array_values($out));
        $res->header('Cache-Control', 'public, max-age=3600');
        return $res;
    }

    /** Přiloží našeptávač k hlavnímu vyhledávacímu poli (datalist plněný z REST). */
    public static function suggest_script() {
        static $printed = false;
        if ($printed) return;
        $printed = true;
        echo '<script>(function(){var i=document.currentScript.parentNode.querySelector("input[name=bsw_q]");if(!i||!window.fetch)return;'
            . 'var l=document.createElement("datalist");l.id="bsw-suggest";i.setAttribute("list","bsw-suggest");i.parentNode.appendChild(l);var t,last="";'
            . 'i.addEventListener("input",function(){clearTimeout(t);var v=i.value;if(v.length<3||v===last)return;t=setTimeout(function(){last=v;'
            . 'fetch(' . wp_json_encode(esc_url_raw(rest_url('bsw/v1/suggest'))) . '+(' . wp_json_encode(strpos(rest_url('bsw/v1/suggest'), '?') === false ? '?' : '&') . ')+"q="+encodeURIComponent(v)).then(function(r){return r.json();})'
            . '.then(function(a){l.innerHTML="";(a||[]).forEach(function(s){var o=document.createElement("option");o.value=s;l.appendChild(o);});}).catch(function(){});},200);});})();</script>';
    }
}
