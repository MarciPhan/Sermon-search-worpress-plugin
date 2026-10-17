<?php
if (!defined('ABSPATH')) exit;
/**
 * Celý přepis jednoho kázání na stránce vyhledávače (?bsw_prepis=VIDEO_ID):
 * čitelný text s klikacími časy, přehrávač až na kliknutí (nic z YouTube se nenačte předem),
 * vlastní titulek, canonical a popis pro vyhledávače a zařazení do sitemapy webu.
 */
final class BSW_Transcript {
    public static function init() {
        add_action('template_redirect', array(__CLASS__, 'template_redirect'));
        add_action('wp_sitemaps_init', array(__CLASS__, 'sitemap'));
    }

    /** Kázání z adresy (jen veřejné a s přepisem), jinak false. */
    public static function requested() {
        static $cache = null;
        if ($cache !== null) return $cache;
        if (!isset($_GET['bsw_prepis']) || !is_string($_GET['bsw_prepis'])) return $cache = false;
        $id = sanitize_text_field(wp_unslash($_GET['bsw_prepis']));
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) return $cache = false;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, video_id, title, description, preacher, video_type, published_at, updated_at FROM ' . BSW_DB::sermons()
            . " WHERE video_id = %s AND caption_status = 'done' AND " . BSW_DB::PUBLIC_SQL, $id));
        return $cache = ($row ?: false);
    }

    /** Stránka s vyhledávačem (pro sitemapu a odkazy mimo ni). */
    public static function page_id() {
        $id = (int) get_option('bsw_page_id');
        if ($id && get_post_status($id) === 'publish') return $id;
        global $wpdb;
        $id = (int) $wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE post_type = 'page' AND post_status = 'publish'
            AND (post_content LIKE '%[kazani_search%' OR post_content LIKE '%[biblesearch%') ORDER BY ID LIMIT 1");
        if ($id) update_option('bsw_page_id', $id, false);
        return $id;
    }

    public static function url($video_id, $query = '', $t = 0, $base = null) {
        if ($base === null) $base = (is_singular() && get_queried_object_id()) ? get_permalink() : get_permalink(self::page_id());
        $args = array('bsw_prepis' => $video_id);
        if ($query !== '') $args['bsw_q'] = $query;
        return add_query_arg(array_map('rawurlencode', $args), $base) . ($t ? '#t-' . (int) $t : '');
    }

    private static function video_url($id, $t = 0) {
        return 'https://www.youtube.com/watch?v=' . rawurlencode($id) . ($t ? '&t=' . (int) $t . 's' : '');
    }

    private static function clock($seconds) {
        $seconds = (int) $seconds;
        $h = intdiv($seconds, 3600); $m = intdiv($seconds % 3600, 60); $s = $seconds % 60;
        return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    private static function title($s) {
        return BSW_DB::display_title($s->title, $s->preacher);
    }

    /** Popis pro vyhledávače: začátek přepisu. */
    private static function summary_text($s) {
        global $wpdb;
        $bodies = $wpdb->get_col($wpdb->prepare('SELECT body FROM ' . BSW_DB::segments() . ' WHERE sermon_id = %d ORDER BY start_seconds LIMIT 6', $s->id));
        $text = trim(preg_replace('/\s+/u', ' ', implode(' ', $bodies)));
        $lead = 'Přepis kázání' . ($s->preacher ? ' – ' . $s->preacher : '') . ': ';
        $text = $lead . $text;
        return mb_strlen($text, 'UTF-8') > 160 ? rtrim(mb_substr($text, 0, 157, 'UTF-8')) . '…' : $text;
    }

    public static function template_redirect() {
        if (!isset($_GET['bsw_prepis'])) return;
        $s = self::requested();
        if (!$s) {
            status_header(404);
            nocache_headers();
            add_filter('wp_robots', function ($r) { $r['noindex'] = true; return $r; });
            return;
        }
        $page = get_permalink();
        $url = self::url($s->video_id, '', 0, $page);
        $title = self::title($s) . ' – přepis kázání';
        $desc = self::summary_text($s);
        $image = 'https://i.ytimg.com/vi/' . rawurlencode($s->video_id) . '/hqdefault.jpg';
        // Hledání uvnitř přepisu (?bsw_q=…) je jen jiný pohled na stejnou stránku.
        if (isset($_GET['bsw_q'])) add_filter('wp_robots', function ($r) { $r['noindex'] = true; $r['follow'] = true; return $r; });
        self::override_head($url, $title, $desc, $image);
    }

    /** Šablona vypisuje vlastní canonical, popis a OG značky stránky vyhledávače – v hlavičce je přepíšeme. */
    public static function override_head($url, $title, $desc, $image) {
        add_filter('pre_get_document_title', function () use ($title) { return $title . ' | ' . get_bloginfo('name'); }, 99);
        add_filter('get_canonical_url', function () use ($url) { return $url; }, 99);
        ob_start(function ($html) use ($url, $title, $desc, $image) {
            $end = stripos($html, '</head>');
            if ($end === false) return $html;
            $head = substr($html, 0, $end);
            $set = function ($head, $attr, $name, $value) {
                $re = '/(<meta\s+' . $attr . '=["\']' . preg_quote($name, '/') . '["\']\s+content=["\'])[^"\']*(["\'])/i';
                return preg_replace_callback($re, function ($m) use ($value) { return $m[1] . esc_attr($value) . $m[2]; }, $head);
            };
            $head = preg_replace_callback('/(<link\s+rel=["\']canonical["\']\s+href=["\'])[^"\']*(["\'])/i', function ($m) use ($url) { return $m[1] . esc_url($url) . $m[2]; }, $head);
            $og = array('og:url' => $url, 'og:title' => $title, 'og:description' => $desc); if ($image) $og['og:image'] = $image;
            foreach ($og as $k => $v) $head = $set($head, 'property', $k, $v);
            $tw = array('description' => $desc, 'twitter:title' => $title, 'twitter:description' => $desc); if ($image) $tw['twitter:image'] = $image;
            foreach ($tw as $k => $v) $head = $set($head, 'name', $k, $v);
            return $head . substr($html, $end);
        });
    }

    public static function render($s, $query) {
        global $wpdb;
        $segs = $wpdb->get_results($wpdb->prepare('SELECT start_seconds, body FROM ' . BSW_DB::segments() . ' WHERE sermon_id = %d ORDER BY start_seconds, id', $s->id));
        $title = self::title($s);
        $base = get_permalink();
        $back = remove_query_arg(array('bsw_prepis'), add_query_arg(array()));
        if ($query === '') $back = remove_query_arg(array('bsw_q'), $back);

        // Hledání uvnitř přepisu.
        $variants = array(); $phrase = '';
        if ($query !== '') {
            $norm = BSW_DB::normalize($query);
            if (preg_match('/["„“]/u', $norm)) $phrase = trim(str_replace(array('"', '„', '“'), '', $norm));
            elseif (BSW_DB::index_ready()) foreach (array_slice(BSW_Stem::words($norm), 0, 8) as $w) $variants[] = BSW_Stem::variants($w);
            else $phrase = $norm;
        }
        $rows = array(); $found = array();
        foreach ($segs as $g) {
            if ($variants) $html = BSW_Search::highlight_variants($g->body, $variants);
            elseif ($phrase !== '') $html = BSW_Search::highlight($g->body, array($phrase), false);
            else $html = esc_html($g->body);
            if (strpos($html, '<mark>') !== false) $found[] = (int) $g->start_seconds;
            $html = BSW_Bible::link_refs($html);
            $rows[] = array((int) $g->start_seconds, $html);
        }

        echo '<article class="bsw-transcript">';
        echo '<p class="bsw-back"><a href="' . esc_url($back) . '">← Zpět na vyhledávání</a></p>';
        echo '<header class="bsw-video bsw-tr-head">';
        echo '<span class="bsw-eyebrow">Přepis kázání</span><h2 class="bsw-title">' . esc_html($title) . '</h2>';
        $meta = array();
        if ($s->published_at) $meta[] = date_i18n('j. n. Y', strtotime($s->published_at . ' UTC'));
        if ($s->preacher) $meta[] = $s->preacher;
        if (!empty($s->video_type) && isset(BSW_DB::TYPES[$s->video_type])) $meta[] = BSW_DB::TYPES[$s->video_type];
        if ($rows) $meta[] = 'délka přepisu ' . self::clock(end($rows)[0]);
        echo '<p class="bsw-meta">' . esc_html(implode(' · ', $meta)) . ' · <a class="bsw-full" href="' . esc_url(self::video_url($s->video_id)) . '" target="_blank" rel="noopener">Otevřít na YouTube</a></p>';
        // Přehrávač se načte až po kliknutí – do té doby jen náhledový obrázek.
        echo '<div class="bsw-player" data-video="' . esc_attr($s->video_id) . '"><button type="button" class="bsw-play" aria-label="' . esc_attr('Přehrát video: ' . $title) . '">'
            . '<img src="' . esc_url('https://i.ytimg.com/vi/' . rawurlencode($s->video_id) . '/hqdefault.jpg') . '" alt="" loading="lazy" width="480" height="360"><span aria-hidden="true"></span></button></div>';
        echo '<form class="bsw-search bsw-tr-search" method="get" action="' . esc_url(get_permalink()) . '" role="search">';
        if (get_option('permalink_structure') === '') echo '<input type="hidden" name="page_id" value="' . esc_attr(get_queried_object_id()) . '">';
        echo '<input type="hidden" name="bsw_prepis" value="' . esc_attr($s->video_id) . '">';
        echo '<input type="search" name="bsw_q" value="' . esc_attr($query) . '" placeholder="Hledat v tomto kázání…" aria-label="Hledat v tomto kázání" maxlength="150"><button type="submit">Hledat</button></form>';
        if ($query !== '') {
            $n = count($found);
            echo '<p class="bsw-summary bsw-tr-found">' . ($n
                ? 'Výraz <strong>' . esc_html($query) . '</strong> je v přepisu na ' . (int) $n . ' ' . ($n === 1 ? 'místě' : 'místech') . '. <a class="bsw-switch" href="#t-' . (int) $found[0] . '">Přejít na první</a>'
                : 'Výraz <strong>' . esc_html($query) . '</strong> jsme v tomto přepisu nenašli.') . '</p>';
        }
        echo '</header>';
        self::refs_block($s, $base);
        if ($query === '') self::outline_block($s, $base);

        if (!$rows) { echo '<div class="bsw-empty"><h3>Přepis je prázdný</h3></div></article>'; return; }
        echo '<section class="bsw-video bsw-tr-body" aria-label="Text přepisu">';
        foreach ($rows as $r) {
            echo '<p class="bsw-hit" id="t-' . (int) $r[0] . '"><a class="bsw-t" data-t="' . (int) $r[0] . '" href="' . esc_url(self::video_url($s->video_id, $r[0])) . '" target="_blank" rel="noopener" aria-label="Přehrát od ' . esc_attr(self::clock($r[0])) . '">'
                . esc_html(self::clock($r[0])) . '</a><span>' . $r[1] . '</span><button type="button" class="bsw-share" data-t="' . (int) $r[0] . '" title="Zkopírovat odkaz na toto místo" aria-label="Sdílet místo ' . esc_attr(self::clock($r[0])) . '">Sdílet</button></p>';
        }
        echo '</section>';
        self::related_block($s);
        echo '<aside class="bsw-about" role="note"><div><h3>Přepis je strojový</h3><p>Vznikl automaticky (titulky YouTube nebo Whisper) a může obsahovat chyby, hlavně ve jménech. <strong>V&nbsp;případě pochybností platí vždy záznam.</strong></p></div></aside>';
        echo '</article>';
        self::player_script();
    }


    /** Biblické texty, které v kázání zazněly (nejčastější a hlavní napřed), s odkazem na kázání ke stejnému místu. */
    private static function refs_block($s, $base) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT book, chapter, COUNT(*) n, MAX(emphasis=\'h\') main FROM ' . BSW_DB::refs() . ' WHERE sermon_id=%d AND chapter>0 GROUP BY book, chapter ORDER BY main DESC, n DESC, book, chapter LIMIT 14', $s->id));
        if (!$rows) return;
        echo '<section class="bsw-video bsw-refs"><h3>Biblické texty v kázání</h3><p class="bsw-chips">';
        foreach ($rows as $r) echo '<a class="bsw-chip' . ($r->main ? ' is-main' : '') . '" href="' . esc_url(add_query_arg(array('bsw_kniha' => (int) $r->book, 'bsw_kap' => (int) $r->chapter), $base)) . '" title="Další kázání k tomuto místu">' . esc_html(BSW_Bible::format((int) $r->book, (int) $r->chapter)) . '</a>';
        echo '</p><p class="bsw-note"><strong>Upozornění:</strong> Seznam je orientační a může být neúplný nebo nepřesný.</p></section>';
    }

    /** Minimální rozestup mezi body osnovy (s) a mezi opakováním téže kapitoly (s). */
    const OUTLINE_GAP = 90;
    const OUTLINE_SAME_CHAPTER_GAP = 300;

    /** Osnova kázání podle biblických odkazů z přepisu, v pořadí, jak zazněly; odkazy blízko sebe se slučují. */
    private static function outline_block($s, $base) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT book, chapter, verse_from, verse_to, start_seconds FROM ' . BSW_DB::refs() . " WHERE sermon_id=%d AND source<>'title' AND chapter>0 ORDER BY start_seconds, id", (int) $s->id));
        $items = array(); $last_t = null; $last_by = array();
        foreach ($rows as $r) {
            $t = (int) $r->start_seconds; $key = $r->book . ':' . $r->chapter;
            if ($last_t !== null && $t - $last_t < self::OUTLINE_GAP) continue;
            if (isset($last_by[$key]) && $t - $last_by[$key] < self::OUTLINE_SAME_CHAPTER_GAP) continue;
            $items[] = $r; $last_t = $t; $last_by[$key] = $t;
        }
        if (count($items) < 3) return;
        echo '<section class="bsw-video bsw-outline"><h3>Osnova podle biblických textů</h3><ol>';
        foreach (array_slice($items, 0, 20) as $r) {
            // id odstavců v přepisu = začátek úseku, ne čas verše; odkaz musí mířit na úsek, ve kterém verš zazněl.
            $seg = $wpdb->get_var($wpdb->prepare('SELECT MAX(start_seconds) FROM ' . BSW_DB::segments() . ' WHERE sermon_id=%d AND start_seconds<=%d', (int) $s->id, (int) $r->start_seconds));
            $anchor = $seg !== null ? (int) $seg : (int) $r->start_seconds;
            echo '<li><a class="bsw-ot" href="#t-' . $anchor . '">' . esc_html(self::clock($r->start_seconds)) . '</a> '
                . '<a class="bsw-chip" href="#t-' . $anchor . '">'
                . esc_html(BSW_Bible::format((int) $r->book, (int) $r->chapter, (int) $r->verse_from, (int) $r->verse_to)) . '</a></li>';
        }
        echo '</ol><p class="bsw-note"><strong>Upozornění:</strong> Osnova vznikla automaticky z biblických odkazů v přepisu a může být neúplná. Rozhoduje záznam kázání.</p></section>';
    }

    private static function related_block($s) {
        $rel = BSW_Analysis::related((int) $s->id, 5);
        if (!$rel) return;
        echo '<section class="bsw-video bsw-related"><h3>Související kázání</h3><ul class="bsw-qlist">';
        foreach ($rel as $r) {
            $meta = array();
            if ($r['date']) $meta[] = date_i18n('j. n. Y', strtotime($r['date'] . ' UTC'));
            if ($r['preacher']) $meta[] = $r['preacher'];
            echo '<li><a href="' . esc_url(self::url($r['video_id'])) . '">' . esc_html($r['title']) . '</a>' . ($meta ? ' <small>' . esc_html(implode(' · ', $meta)) . '</small>' : '') . '</li>';
        }
        echo '</ul><p class="bsw-note"><strong>Upozornění:</strong> Související kázání jsou orientační a nemusí se týkat stejného tématu.</p></section>';
    }

    /** Klik na čas pustí video na stránce od daného místa (s Ctrl/Cmd se otevře YouTube jako dřív). */
    private static function player_script() {
        echo '<script>(function(){var a=document.currentScript.previousElementSibling;while(a&&!a.classList.contains("bsw-transcript"))a=a.previousElementSibling;if(!a)return;'
            . 'var p=a.querySelector(".bsw-player");if(!p)return;var id=p.getAttribute("data-video");'
            . 'function play(t){p.innerHTML="<iframe src=\"https://www.youtube-nocookie.com/embed/"+id+"?autoplay=1&start="+(t|0)+"\" title=\"Video kázání\" allow=\"autoplay; encrypted-media; picture-in-picture; fullscreen\" allowfullscreen></iframe>";p.classList.add("is-playing");p.scrollIntoView({behavior:"smooth",block:"center"});}'
            . 'p.querySelector(".bsw-play").addEventListener("click",function(){play(0);});'
            . 'a.addEventListener("click",function(e){var l=e.target.closest("a.bsw-t");if(!l||e.ctrlKey||e.metaKey||e.shiftKey||e.button)return;e.preventDefault();play(+l.getAttribute("data-t"));});'
            . 'a.addEventListener("click",function(e){var b=e.target.closest("button.bsw-share");if(!b)return;var u=location.href.split("#")[0]+"#t-"+b.getAttribute("data-t");history.replaceState(null,"","#t-"+b.getAttribute("data-t"));'
            . 'function done(m){var o=b.textContent;b.textContent=m;setTimeout(function(){b.textContent=o;},1800);}'
            . 'if(navigator.share&&matchMedia("(pointer:coarse)").matches){navigator.share({title:document.title,url:u}).catch(function(){});return;}'
            . 'if(navigator.clipboard)navigator.clipboard.writeText(u).then(function(){done("Zkopírováno");},function(){done("Odkaz v adrese");});else done("Odkaz v adrese");});'
            . '})();</script>';
    }

    /** Přepisy do sitemapy WordPressu (/wp-sitemap.xml). */
    public static function sitemap($sitemaps) {
        require_once BSW_DIR . 'includes/class-bsw-sitemap.php';
        $sitemaps->registry->add_provider('kazani', new BSW_Sitemap());
    }
}
