<?php
if (!defined('ABSPATH')) exit;
final class BSW_DB {
    public static function sermons() { global $wpdb; return $wpdb->prefix . 'bsw_sermons'; }
    public static function segments() { global $wpdb; return $wpdb->prefix . 'bsw_segments'; }
    public static function refs() { global $wpdb; return $wpdb->prefix . 'bsw_refs'; }
    public static function playlists() { global $wpdb; return $wpdb->prefix . 'bsw_playlists'; }
    public static function playlist_items() { global $wpdb; return $wpdb->prefix . 'bsw_playlist_items'; }
    public static function queries() { global $wpdb; return $wpdb->prefix . 'bsw_queries'; }
    /** Hledat se smí jen ve veřejných videích (ne premiéry, soukromá, smazaná). */
    const PUBLIC_SQL = "visibility = 'public'";
    const VISIBILITY = array('public' => 'veřejné', 'unknown' => 'neověřeno', 'upcoming' => 'premiéra / plánované', 'private' => 'soukromé nebo smazané');
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        $sermons = self::sermons(); $segments = self::segments(); $refs = self::refs();
        dbDelta("CREATE TABLE $sermons (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          video_id varchar(20) NOT NULL,
          title varchar(255) NOT NULL,
          description longtext NOT NULL,
          preacher varchar(120) NOT NULL DEFAULT '',
          preacher_source varchar(10) NOT NULL DEFAULT '',
          video_type varchar(10) NOT NULL DEFAULT '',
          type_source varchar(10) NOT NULL DEFAULT '',
          published_at datetime DEFAULT NULL,
          caption_status varchar(20) NOT NULL DEFAULT 'pending',
          caption_error varchar(255) NOT NULL DEFAULT '',
          caption_checked_at datetime DEFAULT NULL,
          caption_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
          visibility varchar(10) NOT NULL DEFAULT 'public',
          visibility_checked_at datetime DEFAULT NULL,
          updated_at datetime NOT NULL,
          PRIMARY KEY  (id),
          UNIQUE KEY video_id (video_id),
          KEY caption_status (caption_status),
          KEY published_at (published_at),
          KEY preacher (preacher),
          KEY video_type (video_type),
          KEY visibility (visibility)
        ) $collate;");
        $playlists = self::playlists(); $items = self::playlist_items();
        dbDelta("CREATE TABLE $playlists (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          playlist_id varchar(64) NOT NULL,
          title varchar(255) NOT NULL DEFAULT '',
          item_count int(10) unsigned NOT NULL DEFAULT 0,
          is_preacher tinyint(1) NOT NULL DEFAULT 0,
          source varchar(10) NOT NULL DEFAULT '',
          synced_at datetime DEFAULT NULL,
          PRIMARY KEY  (id),
          UNIQUE KEY playlist_id (playlist_id)
        ) $collate;");
        dbDelta("CREATE TABLE $items (
          playlist_id varchar(64) NOT NULL,
          video_id varchar(20) NOT NULL,
          position int(10) unsigned NOT NULL DEFAULT 0,
          PRIMARY KEY  (playlist_id,video_id),
          KEY video_id (video_id)
        ) $collate;");
        dbDelta("CREATE TABLE $segments (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          sermon_id bigint(20) unsigned NOT NULL,
          start_seconds int(10) unsigned NOT NULL DEFAULT 0,
          body text NOT NULL,
          search_text text NOT NULL,
          index_text text NOT NULL,
          join_text varchar(255) NOT NULL DEFAULT '',
          PRIMARY KEY  (id),
          KEY sermon_id (sermon_id),
          KEY sermon_time (sermon_id,start_seconds),
          FULLTEXT KEY search_text (search_text),
          FULLTEXT KEY index_text (index_text)
        ) $collate;");
        // Anonymní statistika hledání (jen text dotazu, počet výsledků a hledání) – pro doplnění slovníku tvarů.
        $queries = self::queries();
        dbDelta("CREATE TABLE $queries (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          query_text varchar(150) NOT NULL,
          results int(10) unsigned NOT NULL DEFAULT 0,
          searches int(10) unsigned NOT NULL DEFAULT 0,
          last_at datetime NOT NULL,
          PRIMARY KEY  (id),
          UNIQUE KEY query_text (query_text),
          KEY last_at (last_at)
        ) $collate;");
        // source: auto = rozpoznáno v přepisu, title = z názvu videa, legacy = ruční databáze.
        dbDelta("CREATE TABLE $refs (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          sermon_id bigint(20) unsigned NOT NULL,
          book tinyint(3) unsigned NOT NULL,
          chapter smallint(5) unsigned NOT NULL DEFAULT 0,
          verse_from smallint(5) unsigned NOT NULL DEFAULT 0,
          verse_to smallint(5) unsigned NOT NULL DEFAULT 0,
          start_seconds int(10) unsigned NOT NULL DEFAULT 0,
          source varchar(10) NOT NULL DEFAULT 'auto',
          kind varchar(10) NOT NULL DEFAULT '',
          emphasis char(1) NOT NULL DEFAULT '',
          PRIMARY KEY  (id),
          KEY book_chapter (book,chapter,verse_from),
          KEY sermon_id (sermon_id,source)
        ) $collate;");
        BSW_Analysis::install($collate);
        if (!get_option('bsw_visibility_migrated')) {
            // Videa bez přepisu (premiéry, nově objevená) se zobrazí až po ověření, že jsou veřejná.
            // Ověřit i nedávná videa s přepisem – starší verze přes Data API indexovala i premiéry vlastníka kanálu.
            $wpdb->query("UPDATE $sermons SET visibility='unknown' WHERE caption_status<>'done' OR published_at IS NULL OR published_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY)");
            update_option('bsw_visibility_migrated', 1, false);
        }
        update_option('bsw_db_version', BSW_VERSION, false);
    }

    /** Kazatel z názvu videa ve tvaru „Téma | Jméno Příjmení“. */
    public static function preacher_from_title($title) {
        $parts = explode('|', (string) $title);
        if (count($parts) < 2) return '';
        $name = trim(end($parts));
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 2 || count($words) > 4) return '';
        foreach ($words as $w) {
            if (!preg_match('/^\p{Lu}[\p{Ll}\'’-]+\.?$/u', $w)) return '';
        }
        return mb_substr($name, 0, 120, 'UTF-8');
    }

    const TYPES = array('kazani' => 'Kázání', 'biblicka' => 'Biblická hodina', 'ostatni' => 'Ostatní');

    /**
     * Druh videa: podle názvu, jinak podle času vysílání (pražský čas) –
     * středa = biblická hodina, neděle = kázání. Ruční údaj ze staré databáze má přednost.
     */
    public static function classify($title, $published_utc) {
        $n = BSW_Bible::normalize($title);
        if (preg_match('/biblick|vyucovan|studium bible/', $n)) return 'biblicka';
        if (preg_match('/\b(pisen|pisne|pisnicka|chval|worship|zamysleni|rozlouceni|pohreb|svatb|koncert|pozvank|upoutavk|trailer|oznameni)/', $n)) return 'ostatni';
        $dow = 0;
        if ($published_utc && strtotime($published_utc . ' UTC')) {
            $dt = new DateTime($published_utc, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('Europe/Prague'));
            $dow = (int) $dt->format('N');
        }
        if ($dow === 3) return 'biblicka';
        if ($dow === 7) return 'kazani';
        if (preg_match('/#\s*\d+/', $n)) return 'biblicka';
        return 'kazani';
    }

    /** Název bez připojeného jména kazatele. */
    public static function display_title($title, $preacher) {
        $title = (string) $title;
        if ($preacher !== '' && preg_match('/^(.*\S)\s*\|\s*' . preg_quote($preacher, '/') . '\s*$/u', $title, $m)) return $m[1];
        return $title;
    }

    public static function upsert_sermon($id, $title, $description, $date, $preacher = '', $visibility = null) {
        global $wpdb;
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) return false;
        $table = self::sermons();
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id, caption_status, caption_checked_at, preacher, published_at, type_source FROM $table WHERE video_id = %s", $id));
        $data = array('title' => sanitize_text_field($title), 'description' => sanitize_textarea_field($description), 'updated_at' => current_time('mysql', true));
        if ($date && strtotime($date)) $data['published_at'] = gmdate('Y-m-d H:i:s', strtotime($date));
        $preacher = $preacher !== '' ? $preacher : self::preacher_from_title($title);
        if (!$existing || $existing->type_source !== 'manual') {
            $data['video_type'] = self::classify($title, $data['published_at'] ?? ($existing->published_at ?? ''));
            $data['type_source'] = 'auto';
        }
        if ($existing && $visibility && isset(self::VISIBILITY[$visibility])) $data['visibility'] = $visibility;
        if ($existing) {
            if ($existing->caption_status === 'missing' && $existing->caption_checked_at && strtotime($existing->caption_checked_at . ' UTC') < time() - 14 * DAY_IN_SECONDS) {
                $data['caption_status'] = 'pending';
            }
            if ($existing->preacher === '' && $preacher !== '') { $data['preacher'] = sanitize_text_field($preacher); $data['preacher_source'] = 'title'; }
            $wpdb->update($table, $data, array('id' => (int) $existing->id));
            self::replace_title_refs((int) $existing->id, $data['title']);
            return (int) $existing->id;
        }
        $data['video_id'] = $id;
        $data['caption_status'] = 'pending';
        $data['visibility'] = $visibility && isset(self::VISIBILITY[$visibility]) ? $visibility : 'unknown';
        $data['preacher'] = sanitize_text_field($preacher);
        $data['preacher_source'] = $preacher !== '' ? 'title' : '';
        $wpdb->insert($table, $data);
        $new_id = (int) $wpdb->insert_id;
        if ($new_id) self::replace_title_refs($new_id, $data['title']);
        return $new_id;
    }
    public static function set_visibility($sermon_id, $visibility) {
        global $wpdb;
        if (!isset(self::VISIBILITY[$visibility])) return;
        $old = $wpdb->get_var($wpdb->prepare('SELECT visibility FROM ' . self::sermons() . ' WHERE id=%d', $sermon_id));
        $wpdb->update(self::sermons(), array('visibility' => $visibility, 'visibility_checked_at' => current_time('mysql', true)), array('id' => (int) $sermon_id));
        delete_transient('bsw_book_counts'); delete_transient('bsw_public_stats');
        if ($old !== $visibility) self::touch();
    }

    /**
     * Číslo verze dat: mění se s každým novým nebo změněným přepisem a změnou veřejnosti.
     * Cache témat, otázek a souvisejících kázání jsou na něm závislé, takže se obnoví samy.
     */
    public static function rev() { return (int) get_option('bsw_data_rev', 1); }
    public static function touch() { update_option('bsw_data_rev', self::rev() + 1, false); }

    /** Uloží playlist a jeho videa; u playlistů pojmenovaných po kazateli doplní kazatele. */
    public static function save_playlist($playlist_id, $title, $video_ids, $source) {
        global $wpdb;
        if (!preg_match('/^[A-Za-z0-9_-]{10,64}$/', $playlist_id)) return 0;
        $video_ids = array_values(array_unique(array_filter($video_ids, function ($v) { return preg_match('/^[A-Za-z0-9_-]{11}$/', $v); })));
        $pt = self::playlists(); $it = self::playlist_items(); $st = self::sermons();
        $title = sanitize_text_field($title);
        $is_preacher = self::playlist_is_preacher($title, $video_ids) ? 1 : 0;
        $wpdb->query($wpdb->prepare("INSERT INTO $pt (playlist_id,title,item_count,is_preacher,source,synced_at) VALUES (%s,%s,%d,%d,%s,%s)
            ON DUPLICATE KEY UPDATE title=VALUES(title), item_count=VALUES(item_count), is_preacher=VALUES(is_preacher), source=VALUES(source), synced_at=VALUES(synced_at)",
            $playlist_id, $title, count($video_ids), $is_preacher, $source, current_time('mysql', true)));
        $wpdb->delete($it, array('playlist_id' => $playlist_id));
        foreach (array_chunk($video_ids, 300, true) as $chunk) {
            $values = array(); $args = array();
            foreach ($chunk as $pos => $vid) { $values[] = '(%s,%s,%d)'; array_push($args, $playlist_id, $vid, $pos); }
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $it (playlist_id,video_id,position) VALUES " . implode(',', $values), $args));
        }
        if ($is_preacher && $video_ids) {
            $in = implode(',', array_fill(0, count($video_ids), '%s'));
            $wpdb->query($wpdb->prepare("UPDATE $st SET preacher=%s, preacher_source='playlist' WHERE preacher='' AND video_id IN ($in)", array_merge(array($title), $video_ids)));
        } elseif (!$is_preacher) {
            // Playlist už není považován za kazatele → vrátit jen to, co z něj dříve přišlo.
            $wpdb->query($wpdb->prepare("UPDATE $st SET preacher='', preacher_source='' WHERE preacher_source='playlist' AND preacher=%s", $title));
        }
        delete_transient('bsw_playlist_options');
        return count($video_ids);
    }

    /**
     * Playlist je „kazatelský“, když se jmenuje jako člověk (Jméno Příjmení), není to biblická kniha
     * a žádné jeho video nemá v názvu jiného kazatele (vyloučí např. „Tisícileté Království“).
     */
    public static function playlist_is_preacher($title, $video_ids) {
        if (self::preacher_from_title('x | ' . $title) === '') return false;
        $words = explode(' ', BSW_Bible::normalize($title));
        // Témata a série („Ovoce Ducha Svatého“, „Tisícileté Království“) nejsou lidé.
        foreach ($words as $w) {
            if (preg_match('/^(duch|svat|boz|krist|jezis|pan[aeu]?$|kralovst|tisicilet|konferenc|pobyt|pisn|knih|list|modlit|manzel|identit|ovoc|rodin|lask|vir[ay]|nadej|evangel|cirkev|sbor|seri|kazan|vanoc|velikon|zjeven|proroc|zalm|otcov|spasen|milost)/', $w)) return false;
        }
        // Jméno může znít jako kniha (Matouš Smith, Daniel Reichmann) – vadí jen, když kniha není první slovo.
        $refs = BSW_Bible::extract($title, 'title');
        if ($refs && !BSW_Bible::extract($words[0], 'title')) return false;
        global $wpdb;
        $st = self::sermons();
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $st WHERE preacher=%s", $title))) return true;
        if (!$video_ids) return false;
        $in = implode(',', array_fill(0, count($video_ids), '%s'));
        $others = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $st WHERE preacher<>'' AND preacher<>%s AND video_id IN ($in)", array_merge(array($title), $video_ids)));
        return $others === 0;
    }

    public static function caption_status($sermon_id, $status, $error = '') {
        global $wpdb;
        return $wpdb->update(self::sermons(), array(
            'caption_status' => $status,
            'caption_error' => substr(sanitize_text_field($error), 0, 250),
            'caption_checked_at' => current_time('mysql', true)
        ), array('id' => (int) $sermon_id));
    }
    public static function normalize($str) {
        return strtolower(remove_accents(wp_strip_all_tags($str)));
    }
    public static function replace_segments($sermon_id, $segments) {
        global $wpdb;
        $sermon_id = (int) $sermon_id;
        if ($sermon_id < 1 || !$segments) return new WP_Error('bsw_no_cues', 'Soubor neobsahuje čitelné titulky.');
        $table = self::segments();
        $wpdb->query('START TRANSACTION');
        try {
            if ($wpdb->delete($table, array('sermon_id' => $sermon_id)) === false) throw new Exception('Mazání starých titulků selhalo.');
            $rows = array();
            foreach ($segments as $cue) {
                $body = sanitize_text_field($cue['text']);
                if ($body) $rows[] = array((int) $cue['start'], $body);
            }
            foreach (self::index_rows($rows) as $i => $ix) {
                if ($wpdb->insert($table, array('sermon_id' => $sermon_id, 'start_seconds' => $rows[$i][0], 'body' => $rows[$i][1],
                    'search_text' => $ix['search_text'], 'index_text' => $ix['index_text'], 'join_text' => $ix['join_text']), array('%d','%d','%s','%s','%s','%s')) === false) {
                    throw new Exception('Nepodařilo se uložit úseky titulků.');
                }
            }
            $wpdb->query('COMMIT');
            self::caption_status($sermon_id, 'done');
            self::replace_speech_refs($sermon_id, $segments);
            BSW_Analysis::invalidate($sermon_id);
            self::touch();
            return count($segments);
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('bsw_db', $e->getMessage());
        }
    }

    /**
     * Prohledávané sloupce pro úseky jednoho kázání (seřazené podle času): $rows = [[start, body], …].
     * join_text = začátek následujícího úseku, aby se našla i fráze, která přechází přes hranici úseků.
     */
    public static function index_rows($rows) {
        $out = array(); $norm = array();
        foreach ($rows as $i => $r) $norm[$i] = self::normalize($r[1]);
        foreach ($rows as $i => $r) {
            $next = isset($norm[$i + 1]) ? implode(' ', array_slice(BSW_Stem::words($norm[$i + 1]), 0, 12)) : '';
            $out[$i] = array('search_text' => $norm[$i], 'index_text' => BSW_Stem::index_text($norm[$i]), 'join_text' => substr($next, 0, 250));
        }
        return $out;
    }

    /** Je index tvarů slov (index_text) hotový pro aktuální verzi stemmeru? Do té doby se hledá postaru. */
    public static function index_ready() {
        return (int) get_option('bsw_index_done') === BSW_Stem::VERSION;
    }

    /**
     * Přepočítá index tvarů slov u starších kázání (po aktualizaci pluginu / změně stemmeru),
     * po kázáních, dokud nevyprší časový rozpočet. Volá se z cronu, návštěv i z administrace.
     */
    /** Spustí přepočet indexu znovu od začátku (do dokončení se hledá postaru). */
    public static function index_restart() {
        delete_option('bsw_index_done');
        update_option('bsw_index_target', 0, false);
    }

    public static function index_step($budget_seconds = 15) {
        global $wpdb;
        if (self::index_ready()) return array('done' => true, 'processed' => 0, 'left' => 0);
        if ((int) get_option('bsw_index_target') !== BSW_Stem::VERSION) {
            update_option('bsw_index_target', BSW_Stem::VERSION, false);
            update_option('bsw_index_cursor', 0, false);
        }
        $st = self::sermons(); $sg = self::segments();
        $cursor = (int) get_option('bsw_index_cursor', 0);
        $started = microtime(true); $processed = 0;
        while (microtime(true) - $started < $budget_seconds) {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $st WHERE id > %d ORDER BY id LIMIT 10", $cursor));
            if (!$ids) {
                update_option('bsw_index_done', BSW_Stem::VERSION, false);
                return array('done' => true, 'processed' => $processed, 'left' => 0);
            }
            foreach ($ids as $sid) {
                $segs = $wpdb->get_results($wpdb->prepare("SELECT id, start_seconds, body FROM $sg WHERE sermon_id=%d ORDER BY start_seconds, id", $sid));
                $rows = array();
                foreach ($segs as $g) $rows[] = array((int) $g->start_seconds, $g->body);
                // Po dávkách jedním dotazem (INSERT … ON DUPLICATE KEY UPDATE) – řádek po řádku to na sdíleném hostingu trvá dlouho.
                foreach (array_chunk(self::index_rows($rows), 80, true) as $chunk) {
                    $values = array(); $args = array();
                    foreach ($chunk as $i => $ix) {
                        $values[] = '(%d,%d,%d,%s,%s,%s,%s)';
                        array_push($args, (int) $segs[$i]->id, (int) $sid, $rows[$i][0], $rows[$i][1], $ix['search_text'], $ix['index_text'], $ix['join_text']);
                    }
                    $wpdb->query($wpdb->prepare("INSERT INTO $sg (id,sermon_id,start_seconds,body,search_text,index_text,join_text) VALUES " . implode(',', $values)
                        . ' ON DUPLICATE KEY UPDATE search_text=VALUES(search_text), index_text=VALUES(index_text), join_text=VALUES(join_text)', $args));
                }
                $cursor = (int) $sid; $processed++;
                update_option('bsw_index_cursor', $cursor, false);
                if (microtime(true) - $started >= $budget_seconds) break;
            }
        }
        $left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $st WHERE id > %d", $cursor));
        return array('done' => false, 'processed' => $processed, 'left' => $left);
    }

    /** Hromadné vložení odkazů po dávkách. Řádek: [sermon, book, ch, v1, v2, start, source, kind, emphasis]. */
    public static function insert_refs($rows) {
        global $wpdb;
        $table = self::refs();
        foreach (array_chunk($rows, 200) as $chunk) {
            $values = array(); $args = array();
            foreach ($chunk as $r) {
                $values[] = '(%d,%d,%d,%d,%d,%d,%s,%s,%s)';
                array_push($args, $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8]);
            }
            $wpdb->query($wpdb->prepare("INSERT INTO $table (sermon_id,book,chapter,verse_from,verse_to,start_seconds,source,kind,emphasis) VALUES " . implode(',', $values), $args));
        }
        delete_transient('bsw_book_counts');
    }

    /** Přepočítá automaticky rozpoznané odkazy z přepisu (ruční data ze staré databáze zůstávají). */
    public static function replace_speech_refs($sermon_id, $segments) {
        global $wpdb;
        $wpdb->delete(self::refs(), array('sermon_id' => (int) $sermon_id, 'source' => 'auto'));
        $rows = array(); $seen = array();
        foreach ($segments as $cue) {
            $text = isset($cue['text']) ? $cue['text'] : (isset($cue['body']) ? $cue['body'] : '');
            foreach (BSW_Bible::extract($text, 'speech') as $r) {
                $key = $cue['start'] . ':' . implode(':', $r);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $rows[] = array((int) $sermon_id, $r[0], $r[1], $r[2], $r[3], (int) $cue['start'], 'auto', '', '');
            }
        }
        if ($rows) self::insert_refs($rows); else delete_transient('bsw_book_counts');
        return count($rows);
    }

    public static function replace_title_refs($sermon_id, $title) {
        global $wpdb;
        $wpdb->delete(self::refs(), array('sermon_id' => (int) $sermon_id, 'source' => 'title'));
        $topic = explode('|', (string) $title)[0];
        $rows = array();
        foreach (BSW_Bible::extract($topic, 'title') as $r) {
            $rows[] = array((int) $sermon_id, $r[0], $r[1], $r[2], $r[3], 0, 'title', '', 'h');
        }
        if ($rows) self::insert_refs($rows);
        return count($rows);
    }

    /** Přepočet odkazů a kazatelů pro již uložená kázání (po aktualizaci pluginu). */
    public static function reindex_batch($offset, $limit) {
        global $wpdb;
        $table = self::sermons(); $segs = self::segments();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, title, preacher, published_at, type_source FROM $table ORDER BY id LIMIT %d OFFSET %d", $limit, $offset));
        $found = 0;
        foreach ($rows as $s) {
            if ($s->preacher === '') {
                $p = self::preacher_from_title($s->title);
                if ($p !== '') $wpdb->update($table, array('preacher' => $p, 'preacher_source' => 'title'), array('id' => (int) $s->id));
            }
            if ($s->type_source !== 'manual') $wpdb->update($table, array('video_type' => self::classify($s->title, $s->published_at), 'type_source' => 'auto'), array('id' => (int) $s->id));
            $found += self::replace_title_refs((int) $s->id, $s->title);
            $segments = $wpdb->get_results($wpdb->prepare("SELECT start_seconds AS start, body FROM $segs WHERE sermon_id=%d ORDER BY start_seconds", $s->id), ARRAY_A);
            if ($segments) $found += self::replace_speech_refs((int) $s->id, $segments);
        }
        return array('processed' => count($rows), 'refs' => $found, 'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"));
    }

    private static function cue_seconds($stamp) {
        $parts = explode(':', str_replace(',', '.', $stamp));
        return count($parts) === 3 ? (int)$parts[0]*3600 + (int)$parts[1]*60 + (int)$parts[2] : (int)$parts[0]*60 + (int)$parts[1];
    }

    public static function parse_subtitles($content) {
        if (!is_string($content) || strlen($content) > 5 * 1024 * 1024) return array();
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = str_replace(array("\r\n", "\r"), "\n", $content);
        $blocks = preg_split('/\n\s*\n/', trim($content));
        $cues = array();
        // Automatické titulky YouTube „rolují“: každý řádek se zopakuje i v dalším bloku.
        // Proto ukládáme jen řádky, které nejsou shodné s některým z posledních dvou vypsaných.
        $recent = array();
        foreach ($blocks as $block) {
            $lines = explode("\n", trim($block));
            $index = -1; $start = 0;
            foreach ($lines as $i => $line) {
                if (preg_match('/((?:\d{1,2}:)?\d{2}:\d{2}[.,]\d{3})\s*-->/', $line, $m)) {
                    $index = $i; $start = self::cue_seconds($m[1]); break;
                }
            }
            if ($index < 0) continue;
            $fresh = array();
            foreach (array_slice($lines, $index + 1) as $line) {
                $line = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ($line === '' || in_array($line, $recent, true)) continue;
                $fresh[] = $line;
                $recent[] = $line;
                if (count($recent) > 2) array_shift($recent);
            }
            if (!$fresh) continue;
            $cues[] = array('start' => $start, 'text' => implode(' ', $fresh));
            if (count($cues) > 20000) break;
        }
        return self::bucket_cues($cues);
    }

    /** Sloučení titulků do cca 30s úseků: nižší zatížení sdílené DB, lepší FULLTEXT. */
    public static function bucket_cues($cues) {
        $out = array(); $bucket = ''; $bucket_start = 0;
        foreach ($cues as $cue) {
            if ($bucket === '') $bucket_start = $cue['start'];
            if ($bucket !== '' && (($cue['start'] - $bucket_start >= 30) || strlen($bucket) > 350)) {
                $out[] = array('start' => $bucket_start, 'text' => $bucket);
                $bucket = ''; $bucket_start = $cue['start'];
            }
            $bucket .= ($bucket ? ' ' : '') . $cue['text'];
        }
        if ($bucket !== '') $out[] = array('start' => $bucket_start, 'text' => $bucket);
        return $out;
    }
    public static function stats() {
        global $wpdb;
        $table = self::sermons(); $segs = self::segments(); $refs = self::refs();
        return array('videos' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'done' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE caption_status='done'"),
            'pending' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE caption_status='pending'"),
            'missing' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE caption_status='missing'"),
            'error' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE caption_status='error'"),
            'segments' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $segs"),
            'refs_auto' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $refs WHERE source='auto'"),
            'refs_title' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $refs WHERE source='title'"),
            'refs_legacy' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $refs WHERE source='legacy'"),
            'hidden' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE visibility<>'public'"),
            'playlists' => (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . self::playlists()));
    }

    /** Veřejné statistiky do úvodu vyhledávače (cache 1 h). */
    public static function public_stats() {
        $cached = get_transient('bsw_public_stats');
        if (is_array($cached)) return $cached;
        global $wpdb;
        $segs = self::segments(); $st = self::sermons();
        $stats = array(
            'sermons' => (int)$wpdb->get_var("SELECT COUNT(DISTINCT g.sermon_id) FROM $segs g JOIN $st s ON s.id=g.sermon_id WHERE s." . self::PUBLIC_SQL),
            'hours' => (int)round((float)$wpdb->get_var("SELECT SUM(m) FROM (SELECT MAX(g.start_seconds) m FROM $segs g JOIN $st s ON s.id=g.sermon_id WHERE s." . self::PUBLIC_SQL . " GROUP BY g.sermon_id) x") / 3600),
            'segments' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $segs g JOIN $st s ON s.id=g.sermon_id WHERE s." . self::PUBLIC_SQL),
            'refs' => (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . self::refs() . " r JOIN $st s ON s.id=r.sermon_id WHERE s." . self::PUBLIC_SQL),
        );
        set_transient('bsw_public_stats', $stats, HOUR_IN_SECONDS);
        return $stats;
    }

    public static function legacy_table() {
        global $wpdb;
        foreach (array($wpdb->prefix . 'kazani', 'kazani') as $candidate) {
            $match = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($candidate)));
            if ($match !== $candidate) continue;
            $columns = $wpdb->get_col('SHOW COLUMNS FROM `' . str_replace('`', '``', $candidate) . '`', 0);
            if (!array_diff(array('nazev_kazani','url_video','kniha','kapitola','cislo_verse'), $columns)) return $candidate;
        }
        return false;
    }
}
