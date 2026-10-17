<?php
if (!defined('ABSPATH')) exit;
/**
 * Automatický rozbor přepisů (bez AI, jen PHP):
 *  - slovník kázání (tabulka bsw_terms: kmen, nejčastější tvar, počet výskytů),
 *  - související kázání (společná klíčová slova a biblické kapitoly),
 *  - návrhy slov při překlepu.
 * Rozbor běží po kázáních na pozadí (cron, návštěvy webu, administrace) a nové nebo
 * změněné přepisy se rozeberou samy – viz step().
 */
final class BSW_Analysis {
    /** Při změně algoritmu zvýšit – rozbor se pak sám přepočítá. */
    const VERSION = 2;
    const MIN_WORD = 4;
    /** Častá slova mluvené češtiny bez diakritiky – do slovníku kázání nepatří. */
    const STOP = 'aby abych abychom abys abyste ale alespon ano asi bez beze bude budem budeme budes budete budou budu byl byla byli bylo byly bych bychom bys byste byt cela cele celeho celem celkem cely celou celym coz dalsi dalsich dalsim dela delam delame delat dela dnes dneska docela dobre dobry dokonce dost dosti druha druhe druhy hned hodne jake jakeho jakem jakemu jaky jakou jakym jakmile jako jakoby jasne jedna jedne jedno jednom jednou jeden jejich jeho jemu jenom jeste jestli jestlize jinak jine jiny jiz jsem jses jsme jsou jste kazda kazde kazdeho kazdy kazdou kdo kdy kdyby kdybych kdybychom kdyz kolem kolik kterou ktera ktere ktereho kterem kteremu kteri kterou ktery kterych kterym kterymi kvuli lepe mame mate meli mela melo mely mezi mimo mnoho moc mohl mohla mohli mohlo moje mojeho moji muj mune muze muzem muzeme muzes muzete muzou mozna musi musim musime musis musite nam nami nase naseho nasem nasi nasich nasim nasimi nebo nebot nebude nebyl nebyla nebylo nech necht neco nejak nejake nejaky nejakou nejsem nejsme nejsou nekde nekdo nekdy nemam nemame nemate nemuze nemuzeme neni nenis nevim nevime nevite nezli nich nicmene nikdo nikdy nove novy obcas okolo opet opravdu ovsem pak plus pocit podle pokud potom potreba poze prave prece pred prede pres prijde prijit prijdu proc proste prosto proto protoze proti prvni pryc rekl rekla rekli reknu rika rikal rikala rikali rikam rikame rikat rikate rikaji samozrejme sami samo samy sebe sice snad spis spousta stale strasne sveho svem svemu svou svuj svych svym svymi tady taha taky takova takove takovy takovou takze tako takhle tamto tato tedy tech tehdy tema temi tenhle tento teprve tetho tim timto tobe tohle toho tohoto tom tomto tomu tomuto toto treba trochu tuhle tuto tvoje tvuj tyhle tyto uplne urcite vam vami vase vaseho vasi vecer velice velka velke velmi velky vidim vidime videt vite vlastne vsak vsechno vsechny vsecko vsech vsichni vubec vzdy vzdycky zacit zase zatim zda zde zrovna zjevne kterak dneska jakoze jakby takovi takovych takovym nekteri nektere nektery nekolik jiste prvne mozme muzu chci chce chceme chcete chtel chtela chteli chtit vim vime jdeme jdou jsi jest byti mit mam mas maji mel kdez tudiz teda toz hele jojo jako aha ehm eee';

    /** Slovo musí zaznít aspoň v tolika kázáních, aby šlo o klíčové slovo (jednorázové překlepy přepisu ne). */
    const MIN_DF = 3;
    /** df kmenů z posledního volání idf(). */
    private static $df = array();

    public static function terms() { global $wpdb; return $wpdb->prefix . 'bsw_terms'; }
    public static function table() { global $wpdb; return $wpdb->prefix . 'bsw_analysis'; }

    public static function install($collate) {
        $terms = self::terms(); $an = self::table();
        dbDelta("CREATE TABLE $terms (
          sermon_id bigint(20) unsigned NOT NULL,
          term varchar(40) NOT NULL,
          word varchar(40) NOT NULL DEFAULT '',
          tf smallint(5) unsigned NOT NULL DEFAULT 0,
          PRIMARY KEY  (sermon_id,term),
          KEY term (term),
          KEY word (word)
        ) $collate;");
        dbDelta("CREATE TABLE $an (
          sermon_id bigint(20) unsigned NOT NULL,
          version smallint(5) unsigned NOT NULL DEFAULT 0,
          keywords varchar(255) NOT NULL DEFAULT '',
          outline mediumtext NOT NULL,
          analyzed_at datetime NOT NULL,
          PRIMARY KEY  (sermon_id)
        ) $collate;");
    }

    /** Přepis se změnil → rozbor zahodit, step() ho udělá znovu. */
    public static function invalidate($sermon_id) {
        global $wpdb;
        $wpdb->delete(self::table(), array('sermon_id' => (int) $sermon_id));
        $wpdb->delete(self::terms(), array('sermon_id' => (int) $sermon_id));
    }

    private static function stop() {
        static $s = null;
        if ($s === null) $s = array_flip(explode(' ', self::STOP));
        return $s;
    }

    /** Slova úseku vhodná do slovníku: [kmen => tvar, …] v pořadí výskytu (s opakováním). */
    private static function tokens($body) {
        $stop = self::stop();
        $out = array();
        foreach (BSW_Stem::words(BSW_DB::normalize($body)) as $w) {
            if (strlen($w) < self::MIN_WORD || isset($stop[$w]) || ctype_digit($w)) continue;
            $out[] = array(BSW_Stem::word($w), $w);
        }
        return $out;
    }

    /* ---------- běh na pozadí ---------- */

    /** Kolik kázání s přepisem ještě čeká na rozbor. */
    public static function pending() {
        global $wpdb;
        $st = BSW_DB::sermons(); $an = self::table();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $st s LEFT JOIN $an a ON a.sermon_id=s.id WHERE s.caption_status='done' AND (a.sermon_id IS NULL OR a.version<>%d)", self::VERSION));
    }

    /**
     * Rozebere další kázání, dokud nevyprší časový rozpočet. Nejdřív slovníky všech kázání
     * (osnova potřebuje vědět, jak časté je slovo v celém archivu), pak osnovy.
     */
    public static function step($budget_seconds = 10) {
        global $wpdb;
        if (get_transient('bsw_analysis_lock')) return array('done' => false, 'processed' => 0, 'left' => self::pending(), 'locked' => true);
        set_transient('bsw_analysis_lock', 1, max(60, (int) $budget_seconds * 3));
        $st = BSW_DB::sermons(); $an = self::table(); $tt = self::terms();
        $started = microtime(true); $processed = 0;
        try {
            // 1) slovníky kázání, která ho ještě nemají
            while (microtime(true) - $started < $budget_seconds) {
                $ids = $wpdb->get_col("SELECT s.id FROM $st s WHERE s.caption_status='done' AND NOT EXISTS (SELECT 1 FROM $tt t WHERE t.sermon_id=s.id) AND NOT EXISTS (SELECT 1 FROM $an a WHERE a.sermon_id=s.id) ORDER BY s.id LIMIT 10");
                if (!$ids) break;
                foreach ($ids as $sid) {
                    if (!self::build_terms((int) $sid)) self::save((int) $sid, array(), array()); // prázdný přepis – nic nedělat znovu
                    $processed++;
                    if (microtime(true) - $started >= $budget_seconds) break 2;
                }
            }
            // 2) osnovy a klíčová slova
            while (microtime(true) - $started < $budget_seconds) {
                $ids = $wpdb->get_col($wpdb->prepare("SELECT s.id FROM $st s LEFT JOIN $an a ON a.sermon_id=s.id WHERE s.caption_status='done' AND (a.sermon_id IS NULL OR a.version<>%d) ORDER BY s.id LIMIT 5", self::VERSION));
                if (!$ids) break;
                foreach ($ids as $sid) {
                    self::analyze((int) $sid);
                    $processed++;
                    if (microtime(true) - $started >= $budget_seconds) break 2;
                }
            }
        } finally { delete_transient('bsw_analysis_lock'); }
        if ($processed) delete_transient('bsw_corpus_size');
        $left = self::pending();
        return array('done' => $left === 0, 'processed' => $processed, 'left' => $left);
    }

    private static function segments($sid) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT start_seconds, body FROM ' . BSW_DB::segments() . ' WHERE sermon_id=%d ORDER BY start_seconds, id', $sid));
    }

    /** Uloží slovník kázání. Vrací počet různých kmenů. */
    public static function build_terms($sid) {
        global $wpdb;
        $tf = array(); $forms = array();
        foreach (self::segments($sid) as $g) {
            foreach (self::tokens($g->body) as $t) {
                $tf[$t[0]] = ($tf[$t[0]] ?? 0) + 1;
                $forms[$t[0]][$t[1]] = ($forms[$t[0]][$t[1]] ?? 0) + 1;
            }
        }
        $wpdb->delete(self::terms(), array('sermon_id' => $sid));
        if (!$tf) return 0;
        $rows = array();
        foreach ($tf as $term => $n) {
            if (strlen($term) > 40) continue;
            arsort($forms[$term]);
            $rows[] = array($term, (string) key($forms[$term]), min(65535, $n));
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            $values = array(); $args = array();
            foreach ($chunk as $r) { $values[] = '(%d,%s,%s,%d)'; array_push($args, $sid, $r[0], substr($r[1], 0, 40), $r[2]); }
            $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::terms() . ' (sermon_id,term,word,tf) VALUES ' . implode(',', $values), $args));
        }
        return count($rows);
    }

    /** Počet kázání se slovníkem (pro IDF). */
    public static function corpus_size() {
        $n = get_transient('bsw_corpus_size');
        if ($n !== false) return max(1, (int) $n);
        global $wpdb;
        $n = (int) $wpdb->get_var('SELECT COUNT(DISTINCT sermon_id) FROM ' . self::terms());
        set_transient('bsw_corpus_size', $n, DAY_IN_SECONDS);
        return max(1, $n);
    }

    /** IDF pro dané kmeny: log(N / df). Slova v drtivé většině kázání (Bůh, víra…) mají váhu blízkou nule. */
    public static function idf($terms) {
        global $wpdb;
        $terms = array_values(array_unique($terms));
        if (!$terms) return array();
        $n = self::corpus_size(); $out = array();
        foreach (array_chunk($terms, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%s'));
            foreach ($wpdb->get_results($wpdb->prepare('SELECT term, COUNT(*) df FROM ' . self::terms() . " WHERE term IN ($in) GROUP BY term", $chunk)) as $r) {
                $out[$r->term] = log(($n + 1) / ((int) $r->df + 0.5));
                self::$df[$r->term] = (int) $r->df;
            }
        }
        foreach ($terms as $t) if (!isset($out[$t])) $out[$t] = log($n + 1);
        return $out;
    }

    private static function save($sid, $keywords, $outline) {
        global $wpdb;
        $wpdb->replace(self::table(), array('sermon_id' => $sid, 'version' => self::VERSION,
            'keywords' => mb_substr(implode(', ', $keywords), 0, 250, 'UTF-8'), 'outline' => wp_json_encode($outline), 'analyzed_at' => current_time('mysql', true)));
    }

    /* ---------- rozbor kázání ---------- */

    public static function analyze($sid) {
        global $wpdb;
        $segs = self::segments($sid);
        if (!$segs) { self::save($sid, array(), array()); return array(); }
        $all = array(); $forms = array();
        foreach ($segs as $g) {
            foreach (self::tokens($g->body) as $t) {
                $all[$t[0]] = ($all[$t[0]] ?? 0) + 1;
                $forms[$t[0]][$t[1]] = ($forms[$t[0]][$t[1]] ?? 0) + 1;
            }
        }
        if (!$all) { self::save($sid, array(), array()); return array(); }
        $idf = self::idf(array_keys($all));
        $word = function ($term) use ($forms) { $f = $forms[$term]; arsort($f); return (string) key($f); };

        // Klíčová slova celého kázání: tf-idf, slovo musí zaznít aspoň 3× a v archivu aspoň v MIN_DF kázáních
        // (slova, která vidí jen jedno kázání, jsou většinou překlepy přepisu a mají největší idf).
        $max_df = max(10, (int) (self::corpus_size() * 0.5));
        $ok = function ($t) use ($max_df) { $d = self::$df[$t] ?? 0; return $d >= self::MIN_DF && $d <= $max_df; };
        $score = array();
        foreach ($all as $t => $n) if ($n >= 3 && $ok($t)) $score[$t] = (1 + log($n)) * $idf[$t];
        arsort($score);
        $keywords = array();
        foreach (array_slice(array_keys($score), 0, 10) as $t) $keywords[] = $word($t);

        self::save($sid, $keywords, array());
        return $keywords;
    }

    public static function get($sid) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT keywords, outline FROM ' . self::table() . ' WHERE sermon_id=%d AND version=%d', $sid, self::VERSION));
        if (!$row) return null;
        $outline = json_decode($row->outline, true);
        return array('keywords' => $row->keywords === '' ? array() : explode(', ', $row->keywords), 'outline' => is_array($outline) ? $outline : array());
    }

    /* ---------- související kázání ---------- */

    /**
     * Kázání podobná danému: společná klíčová slova (tf-idf), stejné biblické kapitoly
     * (hlavní text kázání má větší váhu) a stejný playlist. Cache do další změny dat.
     */
    public static function related($sid, $limit = 5) {
        global $wpdb;
        $key = 'bsw_rel_' . $sid . '_' . BSW_DB::rev();
        $cached = get_transient($key);
        if (is_array($cached)) return $cached;
        $tt = self::terms(); $st = BSW_DB::sermons(); $rt = BSW_DB::refs();
        $score = array();
        $mine = $wpdb->get_results($wpdb->prepare("SELECT term, tf FROM $tt WHERE sermon_id=%d AND tf>=3", $sid));
        if ($mine) {
            $idf = self::idf(wp_list_pluck($mine, 'term'));
            $w = array();
            foreach ($mine as $m) $w[$m->term] = (1 + log((int) $m->tf)) * $idf[$m->term];
            arsort($w);
            $w = array_slice($w, 0, 25, true);
            $in = implode(',', array_fill(0, count($w), '%s'));
            $lens = array();
            foreach ($wpdb->get_results($wpdb->prepare("SELECT sermon_id, term, tf FROM $tt WHERE term IN ($in) AND sermon_id<>%d", array_merge(array_keys($w), array($sid)))) as $r) {
                $score[(int) $r->sermon_id] = ($score[(int) $r->sermon_id] ?? 0) + $w[$r->term] * (1 + log((int) $r->tf));
            }
            $norm = array_sum($w) ?: 1;
            foreach ($score as $id => $s) $score[$id] = $s / $norm;
        }
        // Biblické kapitoly: hlavní text (z názvu / ruční „h“) 1.5, jinak 0.4 za kapitolu.
        $chapters = $wpdb->get_results($wpdb->prepare("SELECT book, chapter, MAX(emphasis='h') main FROM $rt WHERE sermon_id=%d AND chapter>0 GROUP BY book, chapter", $sid));
        if ($chapters) {
            $conds = array(); $args = array();
            foreach (array_slice($chapters, 0, 40) as $c) { $conds[] = '(book=%d AND chapter=%d)'; array_push($args, $c->book, $c->chapter); }
            $main = array();
            foreach ($chapters as $c) $main[$c->book . ':' . $c->chapter] = (int) $c->main;
            $args[] = $sid;
            foreach ($wpdb->get_results($wpdb->prepare("SELECT sermon_id, book, chapter, MAX(emphasis='h') main FROM $rt WHERE (" . implode(' OR ', $conds) . ") AND sermon_id<>%d GROUP BY sermon_id, book, chapter", $args)) as $r) {
                $both = $main[$r->book . ':' . $r->chapter] && (int) $r->main;
                $score[(int) $r->sermon_id] = ($score[(int) $r->sermon_id] ?? 0) + ($both ? 1.5 : 0.4);
            }
        }
        if (!$score) { set_transient($key, array(), WEEK_IN_SECONDS); return array(); }
        arsort($score);
        $ids = array_slice(array_keys($score), 0, 30);
        $in = implode(',', array_map('intval', $ids));
        $rows = $wpdb->get_results("SELECT id, video_id, title, preacher, published_at FROM $st WHERE id IN ($in) AND caption_status='done' AND " . BSW_DB::PUBLIC_SQL);
        $by = array();
        foreach ($rows as $r) $by[(int) $r->id] = $r;
        $out = array();
        foreach ($ids as $id) {
            if (!isset($by[$id]) || $score[$id] < 0.25) continue;
            $r = $by[$id];
            $out[] = array('video_id' => $r->video_id, 'title' => BSW_DB::display_title($r->title, $r->preacher), 'preacher' => $r->preacher, 'date' => $r->published_at);
            if (count($out) >= $limit) break;
        }
        set_transient($key, $out, WEEK_IN_SECONDS);
        return $out;
    }

    /* ---------- návrhy slov ---------- */

    /** „Měli jste na mysli…“: nejčastější slovo archivu, které se od napsaného liší o 1–2 znaky. */
    public static function did_you_mean($query) {
        global $wpdb;
        $words = BSW_Stem::words(BSW_DB::normalize($query));
        if (!$words || count($words) > 3) return '';
        $fixed = array(); $changed = false;
        foreach ($words as $w) {
            if (strlen($w) < 4) { $fixed[] = $w; continue; }
            if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::terms() . ' WHERE word=%s', $w))) { $fixed[] = $w; continue; }
            $max = strlen($w) >= 7 ? 2 : 1;
            $cands = $wpdb->get_results($wpdb->prepare('SELECT word, COUNT(*) df FROM ' . self::terms() . ' WHERE word LIKE %s AND CHAR_LENGTH(word) BETWEEN %d AND %d GROUP BY word ORDER BY df DESC LIMIT 400',
                $wpdb->esc_like(substr($w, 0, 1)) . '%', strlen($w) - $max, strlen($w) + $max));
            $best = ''; $best_df = 0;
            foreach ($cands as $c) {
                if (levenshtein($w, $c->word) <= $max && (int) $c->df > $best_df) { $best = $c->word; $best_df = (int) $c->df; }
            }
            if ($best === '' || $best_df < 2) return '';
            $fixed[] = $best; $changed = true;
        }
        return $changed ? implode(' ', $fixed) : '';
    }

    /** Doplnění slova při psaní: nejčastější tvary archivu začínající na prefix. */
    public static function complete($prefix, $limit = 4) {
        global $wpdb;
        $p = BSW_DB::normalize($prefix);
        if (strlen($p) < 3 || !preg_match('/^[a-z0-9]+$/', $p)) return array();
        return $wpdb->get_col($wpdb->prepare('SELECT word FROM ' . self::terms() . ' WHERE word LIKE %s GROUP BY word HAVING COUNT(*) >= 3 ORDER BY COUNT(*) DESC LIMIT %d', $wpdb->esc_like($p) . '%', $limit));
    }
}
