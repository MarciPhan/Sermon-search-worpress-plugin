<?php
if (!defined('ABSPATH')) exit;

/**
 * Automatické rozšiřování databáze kázání a titulků.
 *
 * Tři hlavní cesty doplňování titulků, které na sobě nijak nezávisí:
 *   A) WP-Cron        – plánovač WordPressu (10 min / hodina / den) na serveru.
 *   B) Návštěvy webu  – bez cronu: po odeslání stránky návštěvníkovi zpracuje frontu
 *                       (fastcgi_finish_request, záložně vlastní REST cesta /visit).
 *   C) Domácí PC      – bez serverového stahování i bez cronu na webu: yt-dlp na jiném
 *                       stroji a jiné IP, výsledky posílá přes REST /push (systemd timer).
 * Doplňkově: D) WebSub – YouTube oznámí nové video a titulky se zkusí stáhnout hned,
 *            E) cron URL pro plánovač WEDOS (nezávislý na WP-Cronu).
 * Výpadek jedné cesty (vypnutý WP-Cron, zablokovaná IP serveru, vypnuté PC) ostatní nezastaví.
 */
final class BSW_Auto {
    const DEFAULT_CHANNEL = 'UCACia2Oqickw50AYoTOFfLw'; // Baptisté Jablonec
    const HUB = 'https://pubsubhubbub.appspot.com/subscribe';
    const WORKER_BATCH = 3;
    const KICK_INTERVAL = 900;

    public static function init() {
        add_filter('cron_schedules', array(__CLASS__, 'schedules'));
        add_action('init', array(__CLASS__, 'ensure_schedule'));
        add_action('bsw_auto_discover', array(__CLASS__, 'cron_discover'));
        add_action('bsw_auto_daily', array(__CLASS__, 'cron_daily'));
        add_action('bsw_auto_video', array(__CLASS__, 'cron_video'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('shutdown', array(__CLASS__, 'visit_trigger'));
    }

    public static function schedules($s) {
        $s['bsw_ten_minutes'] = array('interval' => 600, 'display' => 'Každých 10 minut (Bible Search)');
        return $s;
    }

    /** Samoopravné plánování: chybějící události se při každém načtení doplní. */
    public static function ensure_schedule() {
        if (!wp_next_scheduled('bsw_captions_tick')) wp_schedule_event(time() + 300, 'bsw_ten_minutes', 'bsw_captions_tick');
        if (!wp_next_scheduled('bsw_auto_discover')) wp_schedule_event(time() + 120, 'hourly', 'bsw_auto_discover');
        if (!wp_next_scheduled('bsw_auto_daily')) wp_schedule_event(time() + 900, 'daily', 'bsw_auto_daily');
    }
    public static function unschedule() {
        foreach (array('bsw_captions_tick', 'bsw_auto_discover', 'bsw_auto_daily') as $hook) wp_clear_scheduled_hook($hook);
    }

    /* ---------- nastavení, klíč, log ---------- */

    public static function channel_id() {
        $s = BSW_YouTube::settings();
        $id = $s['public_channel_id'] ?? '';
        if (!$id && !empty($s['channel_id'])) $id = $s['channel_id'];
        return preg_match('/^UC[A-Za-z0-9_-]{22}$/', $id) ? $id : self::DEFAULT_CHANNEL;
    }
    public static function key() {
        $key = get_option('bsw_auto_key');
        if (!is_string($key) || strlen($key) < 32) {
            $key = bin2hex(random_bytes(20));
            update_option('bsw_auto_key', $key, false);
        }
        return $key;
    }
    public static function regenerate_key() { delete_option('bsw_auto_key'); return self::key(); }
    public static function cron_url() { return add_query_arg('key', self::key(), rest_url('bsw/v1/cron')); }

    public static function log($trigger, $message) {
        $log = get_option('bsw_auto_log', array());
        if (!is_array($log)) $log = array();
        array_unshift($log, array(time(), $trigger, mb_substr(wp_strip_all_tags($message), 0, 240, 'UTF-8')));
        update_option('bsw_auto_log', array_slice($log, 0, 60), false);
        $runs = get_option('bsw_auto_runs', array());
        $runs[$trigger] = time();
        update_option('bsw_auto_runs', $runs, false);
    }

    /** Převede kanál zadaný jako URL / @handle / UC… na ID kanálu. */
    public static function resolve_channel($input) {
        $input = trim((string) $input);
        if ($input === '') return '';
        if (preg_match('~(UC[A-Za-z0-9_-]{22})~', $input, $m)) return $m[1];
        if ($input[0] === '@') $input = 'https://www.youtube.com/' . $input;
        if (!preg_match('~^https://(www\.|m\.)?youtube\.com/~', $input)) return new WP_Error('bsw_channel', 'Zadejte adresu kanálu (https://www.youtube.com/@…) nebo ID začínající UC.');
        $res = wp_remote_get($input, array('timeout' => 15, 'headers' => array('User-Agent' => 'Mozilla/5.0', 'Accept-Language' => 'cs')));
        if (is_wp_error($res)) return $res;
        if (preg_match('~"(?:externalId|channelId)":"(UC[A-Za-z0-9_-]{22})"~', wp_remote_retrieve_body($res), $m)) return $m[1];
        return new WP_Error('bsw_channel', 'ID kanálu se na stránce nepodařilo najít.');
    }

    /* ---------- objevování nových videí ---------- */

    /** RSS kanálu: posledních ~15 videí, bez OAuth i bez kvóty. */
    public static function discover_rss() {
        $res = wp_remote_get('https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode(self::channel_id()), array('timeout' => 20));
        if (is_wp_error($res)) return $res;
        if ((int) wp_remote_retrieve_response_code($res) !== 200) return new WP_Error('bsw_rss', 'RSS kanálu vrátilo HTTP ' . wp_remote_retrieve_response_code($res));
        return self::ingest_feed(wp_remote_retrieve_body($res));
    }

    /** Zpracuje Atom (RSS i WebSub) a vrátí ID nově založených kázání. */
    public static function ingest_feed($xml) {
        if (!preg_match_all('~<entry>(.*?)</entry>~s', (string) $xml, $entries)) return array();
        global $wpdb;
        $table = BSW_DB::sermons(); $new = array();
        foreach ($entries[1] as $e) {
            if (!preg_match('~<yt:videoId>([A-Za-z0-9_-]{11})</yt:videoId>~', $e, $id)) continue;
            $title = preg_match('~<title>(.*?)</title>~s', $e, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : '';
            $published = preg_match('~<published>([^<]+)</published>~', $e, $m) ? $m[1] : '';
            $desc = preg_match('~<media:description>(.*?)</media:description>~s', $e, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : '';
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE video_id=%s", $id[1]));
            if ($exists) continue;
            $sid = BSW_DB::upsert_sermon($id[1], $title !== '' ? $title : 'Kázání ' . $id[1], $desc, $published);
            if ($sid) $new[] = (int) $sid;
        }
        if ($new) delete_transient('bsw_public_stats');
        return $new;
    }

    /** YouTube generuje automatické titulky se zpožděním – zkusíme to několikrát. */
    public static function schedule_retries($sermon_id) {
        foreach (array(20 * MINUTE_IN_SECONDS, 3 * HOUR_IN_SECONDS, DAY_IN_SECONDS) as $delay) {
            wp_schedule_single_event(time() + $delay, 'bsw_auto_video', array((int) $sermon_id));
        }
    }

    /* ---------- titulky přímo ze serveru ---------- */

    /**
     * Zjistí stav videa a případně stáhne české titulky přes interní API přehrávače (klient Android).
     * Vrací pole s klíčem 'visibility' (public/upcoming/private); bez titulků vrací 'cues' => null.
     */
    public static function fetch_direct($video_id, $with_captions = true) {
        $payload = array('context' => array('client' => array('clientName' => 'ANDROID', 'clientVersion' => '20.10.38',
            'androidSdkVersion' => 30, 'hl' => 'cs', 'gl' => 'CZ')), 'videoId' => $video_id);
        $res = wp_remote_post('https://www.youtube.com/youtubei/v1/player?prettyPrint=false', array('timeout' => 20,
            'headers' => array('Content-Type' => 'application/json', 'User-Agent' => 'com.google.android.youtube/20.10.38 (Linux; U; Android 11) gzip'),
            'body' => wp_json_encode($payload)));
        if (is_wp_error($res)) return $res;
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($data)) return new WP_Error('bsw_direct', 'YouTube vrátil neplatnou odpověď (HTTP ' . wp_remote_retrieve_response_code($res) . ').');
        $status = $data['playabilityStatus']['status'] ?? '';
        $reason = $data['playabilityStatus']['reason'] ?? $status;
        $out = array('visibility' => 'public', 'cues' => null, 'title' => $data['videoDetails']['title'] ?? '',
            'description' => $data['videoDetails']['shortDescription'] ?? '', 'reason' => '');
        if (!empty($data['videoDetails']['isUpcoming']) || $status === 'LIVE_STREAM_OFFLINE' || !empty($data['playabilityStatus']['liveStreamability'])) {
            $out['visibility'] = 'upcoming'; $out['reason'] = 'Premiéra / přenos ještě neproběhl: ' . $reason;
            return $out;
        }
        if (in_array($status, array('LOGIN_REQUIRED', 'ERROR', 'UNPLAYABLE', 'AGE_CHECK_REQUIRED'), true)) {
            // YouTube někdy omezí datacentrové IP („přihlaste se, nejste robot“) – to není soukromé video.
            if (stripos($reason, 'bot') !== false || stripos($reason, 'robot') !== false) return new WP_Error('bsw_direct_blocked', 'YouTube omezil server: ' . $reason);
            $out['visibility'] = 'private'; $out['reason'] = 'Video není veřejné: ' . $reason;
            return $out;
        }
        if ($status !== 'OK') return new WP_Error('bsw_direct_blocked', 'YouTube nepovolil přístup: ' . ($reason ?: 'neznámý důvod'));
        if (!$with_captions) return $out;
        $tracks = $data['captions']['playerCaptionsTracklistRenderer']['captionTracks'] ?? array();
        $best = null;
        foreach ($tracks as $t) {
            $lang = strtolower($t['languageCode'] ?? '');
            if ($lang !== 'cs' && strpos($lang, 'cs-') !== 0) continue;
            if (!$best || (($best['kind'] ?? '') === 'asr' && ($t['kind'] ?? '') !== 'asr')) $best = $t; // ruční mají přednost
        }
        if (!$best || empty($best['baseUrl'])) { $out['reason'] = 'Video zatím nemá české titulky.'; return $out; }
        $url = $best['baseUrl'];
        if (strpos($url, 'https://www.youtube.com/') !== 0) return new WP_Error('bsw_direct', 'Neočekávaná adresa titulků.');
        $sub = wp_remote_get($url, array('timeout' => 25, 'limit_response_size' => 5 * 1024 * 1024));
        if (is_wp_error($sub)) return $sub;
        $out['cues'] = self::parse_timedtext(wp_remote_retrieve_body($sub));
        if (!$out['cues']) return new WP_Error('bsw_direct_empty', 'Titulky se stáhly prázdné (YouTube mohl požadavek omezit).');
        return $out;
    }

    /** Formát srv3 (<p t="ms">…</p>) i starší formát 1 (<text start="s">…</text>). */
    public static function parse_timedtext($xml) {
        $cues = array();
        if (preg_match_all('~<p\s+t="(\d+)"[^>]*>(.*?)</p>~s', (string) $xml, $m, PREG_SET_ORDER)) {
            foreach ($m as $p) $cues[] = array('start' => intdiv((int) $p[1], 1000), 'text' => $p[2]);
        } elseif (preg_match_all('~<text\s+start="([\d.]+)"[^>]*>(.*?)</text>~s', (string) $xml, $m, PREG_SET_ORDER)) {
            foreach ($m as $p) $cues[] = array('start' => (int) $p[1], 'text' => $p[2]);
        }
        $out = array();
        foreach ($cues as $c) {
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($c['text']), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($text !== '') $out[] = array('start' => $c['start'], 'text' => $text);
        }
        return BSW_DB::bucket_cues($out);
    }

    /* ---------- zpracování jednoho videa ---------- */

    /**
     * Ověří, že je video veřejné, a zkusí všechny dostupné zdroje titulků.
     * Nezveřejněná videa (premiéra, soukromé, smazané) se do vyhledávání nedostanou.
     */
    public static function process($sermon_id, $trigger) {
        global $wpdb;
        $table = BSW_DB::sermons();
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, video_id, title, caption_status, caption_attempts, visibility FROM $table WHERE id=%d", $sermon_id));
        if (!$row) return new WP_Error('bsw_missing', 'Kázání neexistuje.');
        if ($row->caption_status === 'done' && $row->visibility === 'public') return 0;
        $wpdb->update($table, array('caption_attempts' => (int) $row->caption_attempts + 1), array('id' => (int) $row->id));
        $errors = array();
        $direct = self::fetch_direct($row->video_id, $row->caption_status !== 'done');
        if (!is_wp_error($direct)) {
            BSW_DB::set_visibility((int) $row->id, $direct['visibility']);
            if ($direct['visibility'] !== 'public') {
                if ($row->caption_status !== 'done') BSW_DB::caption_status((int) $row->id, 'missing', $direct['reason']);
                self::log($trigger, 'Skryto (' . BSW_DB::VISIBILITY[$direct['visibility']] . '): ' . $row->title);
                return new WP_Error('bsw_not_public', $direct['reason']);
            }
            if ($row->caption_status === 'done') { self::log($trigger, 'Zveřejněno, nyní vyhledatelné: ' . $row->title); return 0; }
            if ($direct['cues']) {
                $saved = BSW_DB::replace_segments((int) $row->id, $direct['cues']);
                if (!is_wp_error($saved)) {
                    delete_transient('bsw_public_stats');
                    self::log($trigger, 'Staženo ze serveru: ' . $row->title . ' (' . (int) $saved . ' úseků)');
                    return $saved;
                }
                $errors[] = $saved->get_error_message();
            } else {
                $errors[] = $direct['reason'];
            }
        } else {
            $errors[] = $direct->get_error_message();
        }
        // Data API je až druhý zdroj – vlastník kanálu přes OAuth vidí i soukromá videa, proto až po kontrole veřejnosti.
        if (BSW_YouTube::connected() && (!is_wp_error($direct) || $row->visibility === 'public')) {
            $res = BSW_YouTube::import_captions((int) $row->id, $row->video_id);
            if (!is_wp_error($res)) { self::log($trigger, 'Data API: ' . $row->title . ' (' . (int) $res . ' úseků)'); return $res; }
            $errors[] = 'API: ' . $res->get_error_message();
        }
        $missing = !is_wp_error($direct) && !$direct['cues'];
        if ($row->caption_status !== 'done') BSW_DB::caption_status((int) $row->id, $missing ? 'missing' : 'error', implode(' | ', $errors));
        self::log($trigger, ($missing ? 'Zatím bez titulků: ' : 'Chyba: ') . $row->title . ' – ' . implode(' | ', $errors));
        return new WP_Error($missing ? 'bsw_no_cs' : 'bsw_direct', implode(' | ', $errors));
    }

    /**
     * Rychlé ověření veřejnosti (bez stahování titulků) – desítky videí za běh.
     * Neověřená a premiéry se kontrolují po hodině, soukromá jednou denně.
     */
    public static function recheck_hidden($trigger, $limit, $budget_seconds = 30) {
        global $wpdb;
        $t = BSW_DB::sermons();
        $now = current_time('mysql', true);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, video_id, title, caption_status FROM $t WHERE visibility IN ('unknown','upcoming','private') AND (visibility_checked_at IS NULL
            OR (visibility IN ('unknown','upcoming') AND visibility_checked_at < DATE_SUB(%s, INTERVAL 1 HOUR))
            OR visibility_checked_at < DATE_SUB(%s, INTERVAL 1 DAY)) ORDER BY visibility_checked_at IS NULL DESC, published_at DESC LIMIT %d", $now, $now, $limit));
        $started = time(); $checked = 0; $public = 0;
        foreach ($rows as $r) {
            if (time() - $started > $budget_seconds) break;
            $res = self::fetch_direct($r->video_id, false);
            if (is_wp_error($res)) { if ($res->get_error_code() === 'bsw_direct_blocked') break; continue; }
            BSW_DB::set_visibility((int) $r->id, $res['visibility']);
            $checked++;
            if ($res['visibility'] === 'public') {
                $public++;
                // Veřejné video bez přepisu → do fronty pro stažení titulků.
                if ($r->caption_status === 'missing') $wpdb->update($t, array('caption_status' => 'pending'), array('id' => (int) $r->id));
            }
        }
        if ($checked) self::log($trigger, 'Ověřeno ' . $checked . ' videí, veřejných ' . $public . '.');
        return $checked;
    }

    /** (Původní pomalá varianta – ponecháno pro jednotlivá videa.) */
    public static function recheck_hidden_full($trigger, $limit) {
        global $wpdb;
        $t = BSW_DB::sermons();
        $now = current_time('mysql', true);
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t WHERE visibility IN ('unknown','upcoming','private') AND (visibility_checked_at IS NULL
            OR (visibility IN ('unknown','upcoming') AND visibility_checked_at < DATE_SUB(%s, INTERVAL 1 HOUR))
            OR visibility_checked_at < DATE_SUB(%s, INTERVAL 1 DAY)) ORDER BY visibility_checked_at IS NULL DESC, published_at DESC LIMIT %d", $now, $now, $limit));
        foreach ($ids as $id) self::process((int) $id, $trigger);
        return count($ids);
    }

    /** Průběžná kontrola veřejných videí – kdyby některé bylo skryto nebo smazáno. */
    public static function audit_public($trigger, $limit) {
        global $wpdb;
        $t = BSW_DB::sermons();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, video_id, title FROM $t WHERE visibility='public' ORDER BY visibility_checked_at IS NOT NULL, visibility_checked_at ASC LIMIT %d", $limit));
        $hidden = 0;
        foreach ($rows as $r) {
            $res = self::fetch_direct($r->video_id, false);
            if (is_wp_error($res)) continue;
            BSW_DB::set_visibility((int) $r->id, $res['visibility']);
            if ($res['visibility'] !== 'public') { $hidden++; self::log($trigger, 'Skryto (' . BSW_DB::VISIBILITY[$res['visibility']] . '): ' . $r->title); }
        }
        return $hidden;
    }

    /* ---------- celý kanál a playlisty (interní API, bez OAuth) ---------- */

    private static function browse($body) {
        $body = array('context' => array('client' => array('clientName' => 'WEB', 'clientVersion' => '2.20251001.00.00', 'hl' => 'cs', 'gl' => 'CZ'))) + $body;
        $res = wp_remote_post('https://www.youtube.com/youtubei/v1/browse?prettyPrint=false', array('timeout' => 25,
            'headers' => array('Content-Type' => 'application/json', 'User-Agent' => 'Mozilla/5.0'), 'body' => wp_json_encode($body)));
        if (is_wp_error($res)) return $res;
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return is_array($data) ? $data : new WP_Error('bsw_browse', 'YouTube vrátil neplatnou odpověď (HTTP ' . wp_remote_retrieve_response_code($res) . ').');
    }

    /** Projde odpověď a vrátí položky [id, název, typ] a token další stránky. */
    private static function lockups($data, &$items, &$token) {
        if (!is_array($data)) return;
        if (isset($data['lockupViewModel']['contentId'])) {
            $l = $data['lockupViewModel'];
            $items[] = array($l['contentId'], $l['metadata']['lockupMetadataViewModel']['title']['content'] ?? '', $l['contentType'] ?? '');
        }
        if (isset($data['playlistVideoRenderer']['videoId'])) {
            $r = $data['playlistVideoRenderer'];
            $items[] = array($r['videoId'], $r['title']['runs'][0]['text'] ?? '', 'LOCKUP_CONTENT_TYPE_VIDEO');
        }
        if (isset($data['continuationCommand']['token'])) $token = $data['continuationCommand']['token'];
        foreach ($data as $v) if (is_array($v)) self::lockups($v, $items, $token);
    }

    private static function browse_all($first, $type, $max_pages = 40) {
        $out = array(); $body = $first;
        for ($page = 0; $page < $max_pages; $page++) {
            $data = self::browse($body);
            if (is_wp_error($data)) return $page ? $out : $data;
            $items = array(); $token = null;
            self::lockups($data, $items, $token);
            foreach ($items as $it) if ($it[2] === $type && !isset($out[$it[0]])) $out[$it[0]] = $it[1];
            if (!$token) break;
            $body = array('continuation' => $token);
        }
        return $out;
    }

    /** Celý kanál (playlist „Nahraná videa“) – doplní i starší videa, která v RSS nejsou. */
    public static function discover_channel() {
        $videos = self::browse_all(array('browseId' => 'VLUU' . substr(self::channel_id(), 2)), 'LOCKUP_CONTENT_TYPE_VIDEO');
        if (is_wp_error($videos)) return $videos;
        global $wpdb;
        $t = BSW_DB::sermons(); $new = 0;
        $known = array_flip($wpdb->get_col("SELECT video_id FROM $t"));
        foreach ($videos as $vid => $title) {
            if (isset($known[$vid]) || !preg_match('/^[A-Za-z0-9_-]{11}$/', $vid)) continue;
            if (BSW_DB::upsert_sermon($vid, $title !== '' ? $title : 'Kázání ' . $vid, '', '', '', 'unknown')) $new++;
        }
        return $new;
    }

    /** Playlisty kanálu a jejich videa; po dávkách, aby jeden běh netrval dlouho. */
    public static function sync_playlists($budget_seconds = 25) {
        $started = time();
        $lists = self::browse_all(array('browseId' => self::channel_id(), 'params' => 'EglwbGF5bGlzdHPyBgQKAkIA'), 'LOCKUP_CONTENT_TYPE_PLAYLIST', 10);
        if (is_wp_error($lists)) return $lists;
        global $wpdb;
        $pt = BSW_DB::playlists();
        $synced = array();
        foreach ($wpdb->get_results("SELECT playlist_id, synced_at FROM $pt") as $r) $synced[$r->playlist_id] = $r->synced_at;
        // Nejdřív nikdy nesynchronizované, pak nejdéle neaktualizované.
        uksort($lists, function ($a, $b) use ($synced) { return strcmp($synced[$a] ?? '', $synced[$b] ?? ''); });
        $done = 0;
        foreach ($lists as $pid => $title) {
            if (time() - $started > $budget_seconds) break;
            $videos = self::browse_all(array('browseId' => 'VL' . $pid), 'LOCKUP_CONTENT_TYPE_VIDEO');
            if (is_wp_error($videos)) continue;
            BSW_DB::save_playlist($pid, $title, array_keys($videos), 'server');
            $done++;
        }
        return array('total' => count($lists), 'synced' => $done);
    }

    /** Zpracuje několik čekajících videí (nejnovější první) se zámkem proti souběhu. */
    public static function worker($trigger, $limit = self::WORKER_BATCH) {
        if (get_transient('bsw_worker_lock')) return 0;
        set_transient('bsw_worker_lock', 1, 4 * MINUTE_IN_SECONDS);
        $done = 0;
        try {
            global $wpdb;
            $t = BSW_DB::sermons();
            $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t WHERE caption_status='pending' ORDER BY published_at DESC, id DESC LIMIT %d", $limit));
            $started = time();
            foreach ($ids as $id) {
                if (time() - $started > 40) break;
                $res = self::process((int) $id, $trigger);
                if (!is_wp_error($res)) $done++;
            }
        } finally { delete_transient('bsw_worker_lock'); }
        return $done;
    }

    /** Videa bez titulků vrací do fronty s rostoucím odstupem: 1 h, 6 h, 1 d, 3 d, 7 d, pak měsíčně. */
    public static function requeue() {
        global $wpdb;
        $t = BSW_DB::sermons();
        $now = current_time('mysql', true);
        return (int) $wpdb->query($wpdb->prepare("UPDATE $t SET caption_status='pending'
            WHERE caption_status IN ('missing','error') AND (caption_checked_at IS NULL OR caption_checked_at < DATE_SUB(%s, INTERVAL
            (CASE WHEN caption_attempts <= 1 THEN 1 WHEN caption_attempts = 2 THEN 6 WHEN caption_attempts = 3 THEN 24
                  WHEN caption_attempts = 4 THEN 72 WHEN caption_attempts = 5 THEN 168 ELSE 720 END) HOUR))", $now));
    }

    /** Celý cyklus: objevit nová videa → vrátit do fronty → stáhnout titulky. */
    public static function run_all($trigger, $schedule_retries = false) {
        $parts = array();
        $new = self::discover_rss();
        if (!is_wp_error($new) && $schedule_retries) foreach ($new as $sid) self::schedule_retries($sid);
        $parts[] = is_wp_error($new) ? 'RSS chyba: ' . $new->get_error_message() : 'nová videa z RSS: ' . count($new);
        if (BSW_YouTube::connected()) {
            $api = BSW_YouTube::sync_page(true);
            if (is_wp_error($api)) $parts[] = 'API: ' . $api->get_error_message();
        }
        if (!BSW_DB::index_ready()) {
            $ix = BSW_DB::index_step(20);
            $parts[] = $ix['done'] ? 'index tvarů slov hotový' : 'index tvarů slov: zbývá ' . $ix['left'] . ' kázání';
        }
        if (BSW_DB::index_ready() && BSW_Analysis::pending()) {
            $an = BSW_Analysis::step(8);
            $parts[] = 'rozbor kázání: ' . ($an['done'] ? 'hotový' : 'zbývá ' . $an['left']);
        }
        $parts[] = 'znovu ve frontě: ' . self::requeue();
        $parts[] = 'nové přepisy: ' . self::worker($trigger);
        $parts[] = 'ověřeno skrytých: ' . self::recheck_hidden($trigger, 80, 40);
        $msg = implode(', ', $parts);
        self::log($trigger, $msg);
        return $msg;
    }

    /* ---------- spouštěče ---------- */

    public static function cron_discover() { self::run_all('wp-cron hodinový', true); }
    public static function cron_video($sermon_id) { self::process((int) $sermon_id, 'opakování nového videa'); }
    public static function cron_daily() {
        self::run_all('wp-cron denní', true);
        self::maintenance('wp-cron denní', 40, 60);
        if (get_option('bsw_websub_enabled')) self::websub_subscribe();
    }

    /**
     * Cesta B – návštěvy webu, bez cronu. Na konci libovolného požadavku (veřejná stránka
     * i administrace), nejvýš jednou za 10 minut: odpověď se nejdřív odešle návštěvníkovi
     * (fastcgi_finish_request), teprve pak se zkontroluje RSS a zpracuje fronta.
     * Bez PHP-FPM se použije neblokující požadavek na vlastní REST cestu /visit.
     */
    public static function visit_trigger() {
        if (wp_doing_cron() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)) return;
        if (get_transient('bsw_visit_throttle')) return;
        set_transient('bsw_visit_throttle', 1, 600);
        $trigger = is_admin() ? 'návštěva administrace' : 'návštěva webu';
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            if (function_exists('ignore_user_abort')) ignore_user_abort(true);
            @set_time_limit(90);
            self::visit_cycle($trigger);
            return;
        }
        wp_remote_post(add_query_arg('key', self::key(), rest_url('bsw/v1/visit')), array('blocking' => false, 'timeout' => 0.5,
            'body' => array('trigger' => $trigger), 'sslverify' => apply_filters('https_local_ssl_verify', false)));
    }
    /** Celý kanál, playlisty a kontrola veřejnosti – součást denní údržby (cesty A i B). */
    public static function maintenance($trigger, $audit, $budget) {
        $parts = array();
        $new = self::discover_channel();
        $parts[] = is_wp_error($new) ? 'kanál: ' . $new->get_error_message() : 'nová videa z celého kanálu: ' . (int) $new;
        $pl = self::sync_playlists($budget);
        $parts[] = is_wp_error($pl) ? 'playlisty: ' . $pl->get_error_message() : 'playlisty ' . $pl['synced'] . '/' . $pl['total'];
        $parts[] = 'nově skrytých: ' . self::audit_public($trigger, $audit);
        self::log($trigger, implode(', ', $parts));
    }

    public static function visit_cycle($trigger) {
        $parts = array();
        if (!get_transient('bsw_visit_daily')) {
            set_transient('bsw_visit_daily', 1, 12 * HOUR_IN_SECONDS);
            self::maintenance($trigger, 10, 20);
        }
        if (!get_transient('bsw_visit_rss')) {
            set_transient('bsw_visit_rss', 1, HOUR_IN_SECONDS);
            $new = self::discover_rss();
            $parts[] = is_wp_error($new) ? 'RSS chyba: ' . $new->get_error_message() : 'nová videa z RSS: ' . count($new);
        }
        if (!BSW_DB::index_ready()) {
            $ix = BSW_DB::index_step(20);
            $parts[] = $ix['done'] ? 'index tvarů slov hotový' : 'index tvarů slov: zbývá ' . $ix['left'] . ' kázání';
        }
        $parts[] = 'znovu ve frontě: ' . self::requeue();
        $parts[] = 'nové přepisy: ' . self::worker($trigger, 1);
        $parts[] = 'ověřeno skrytých: ' . self::recheck_hidden($trigger, 25, 15);
        self::log($trigger, implode(', ', $parts));
    }

    /* ---------- REST: cron URL, push z PC, WebSub ---------- */

    public static function routes() {
        register_rest_route('bsw/v1', '/cron', array('methods' => array('GET', 'POST'), 'callback' => array(__CLASS__, 'rest_cron'), 'permission_callback' => array(__CLASS__, 'check_key')));
        register_rest_route('bsw/v1', '/visit', array('methods' => 'POST', 'callback' => array(__CLASS__, 'rest_visit'), 'permission_callback' => array(__CLASS__, 'check_key')));
        register_rest_route('bsw/v1', '/playlists', array('methods' => 'POST', 'callback' => array(__CLASS__, 'rest_playlists'), 'permission_callback' => array(__CLASS__, 'check_key')));
        register_rest_route('bsw/v1', '/pending', array('methods' => 'GET', 'callback' => array(__CLASS__, 'rest_pending'), 'permission_callback' => array(__CLASS__, 'check_key')));
        register_rest_route('bsw/v1', '/push', array('methods' => 'POST', 'callback' => array(__CLASS__, 'rest_push'), 'permission_callback' => array(__CLASS__, 'check_key')));
        register_rest_route('bsw/v1', '/websub', array('methods' => array('GET', 'POST'), 'callback' => array(__CLASS__, 'rest_websub'), 'permission_callback' => '__return_true'));
    }

    public static function check_key(WP_REST_Request $req) {
        $given = (string) ($req->get_header('x-bsw-key') ?: $req->get_param('key'));
        return $given !== '' && hash_equals(self::key(), $given);
    }

    public static function rest_cron(WP_REST_Request $req) {
        $trigger = sanitize_text_field((string) $req->get_param('trigger')) ?: 'cron URL (WEDOS)';
        if (function_exists('ignore_user_abort')) ignore_user_abort(true);
        return array('ok' => true, 'result' => self::run_all(mb_substr($trigger, 0, 40, 'UTF-8')));
    }

    public static function rest_visit(WP_REST_Request $req) {
        if (function_exists('ignore_user_abort')) ignore_user_abort(true);
        $trigger = $req->get_param('trigger') === 'návštěva administrace' ? 'návštěva administrace' : 'návštěva webu';
        self::visit_cycle($trigger);
        return array('ok' => true);
    }

    /** Cesta C pro playlisty: PC je zjistí přes yt-dlp a pošle sem jako JSON. */
    public static function rest_playlists(WP_REST_Request $req) {
        $lists = $req->get_json_params()['playlists'] ?? null;
        if (!is_array($lists)) return new WP_Error('bsw_json', 'Očekávám {"playlists": [{"id","title","videos":[…]}]}.', array('status' => 400));
        $n = 0;
        foreach (array_slice($lists, 0, 300) as $pl) {
            if (!is_array($pl) || empty($pl['id']) || !isset($pl['videos']) || !is_array($pl['videos'])) continue;
            BSW_DB::save_playlist((string) $pl['id'], (string) ($pl['title'] ?? ''), array_map('strval', $pl['videos']), 'pc');
            $n++;
        }
        self::log('push z PC', 'Přijato playlistů: ' . $n);
        return array('ok' => true, 'playlists' => $n);
    }

    /** Seznam videí bez titulků pro skript na PC (ten je stáhne přes yt-dlp a pošle zpět). */
    public static function rest_pending(WP_REST_Request $req) {
        global $wpdb;
        $t = BSW_DB::sermons();
        $limit = min(200, max(1, (int) ($req->get_param('limit') ?: 50)));
        $ids = $wpdb->get_col($wpdb->prepare("SELECT video_id FROM $t WHERE caption_status IN ('pending','missing','error') ORDER BY published_at DESC LIMIT %d", $limit));
        return array('channel_id' => self::channel_id(), 'videos' => $ids);
    }

    public static function rest_push(WP_REST_Request $req) {
        $id = (string) $req->get_param('video_id');
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) return new WP_Error('bsw_id', 'Neplatné video_id.', array('status' => 400));
        $files = $req->get_file_params();
        $content = '';
        if (!empty($files['subtitles']['tmp_name']) && is_uploaded_file($files['subtitles']['tmp_name']) && $files['subtitles']['size'] <= 5 * 1024 * 1024) {
            $content = file_get_contents($files['subtitles']['tmp_name']);
        } elseif (is_string($req->get_param('subtitles'))) {
            $content = $req->get_param('subtitles');
        }
        $cues = BSW_DB::parse_subtitles($content);
        if (!$cues) return new WP_Error('bsw_subs', 'Titulky chybí nebo jsou nečitelné.', array('status' => 400));
        global $wpdb;
        $sid = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . BSW_DB::sermons() . ' WHERE video_id=%s', $id));
        if (!$sid) {
            $sid = (int) BSW_DB::upsert_sermon($id, (string) ($req->get_param('title') ?: 'Kázání ' . $id), (string) $req->get_param('description'), (string) $req->get_param('published_at'), '', 'unknown');
        }
        if (!$sid) return new WP_Error('bsw_sermon', 'Kázání se nepodařilo založit.', array('status' => 500));
        $saved = BSW_DB::replace_segments($sid, $cues);
        if (is_wp_error($saved)) return $saved;
        $vis = (string) $req->get_param('visibility');
        if (in_array($vis, array('public', 'upcoming', 'private'), true)) BSW_DB::set_visibility($sid, $vis);
        delete_transient('bsw_public_stats');
        self::log('push z PC', 'Přijaty titulky ' . $id . ' (' . (int) $saved . ' úseků)');
        return array('ok' => true, 'segments' => $saved);
    }

    /** WebSub: YouTube pošle oznámení hned po zveřejnění videa. */
    public static function websub_topic() { return 'https://www.youtube.com/xml/feeds/videos.xml?channel_id=' . self::channel_id(); }
    private static function websub_secret() {
        $s = get_option('bsw_websub_secret');
        if (!is_string($s) || strlen($s) < 32) { $s = bin2hex(random_bytes(20)); update_option('bsw_websub_secret', $s, false); }
        return $s;
    }
    public static function websub_subscribe() {
        $res = wp_remote_post(self::HUB, array('timeout' => 20, 'body' => array(
            'hub.callback' => rest_url('bsw/v1/websub'), 'hub.topic' => self::websub_topic(), 'hub.mode' => 'subscribe',
            'hub.verify' => 'async', 'hub.secret' => self::websub_secret(), 'hub.lease_seconds' => 10 * DAY_IN_SECONDS)));
        if (is_wp_error($res)) { self::log('WebSub', 'Odběr selhal: ' . $res->get_error_message()); return $res; }
        $code = (int) wp_remote_retrieve_response_code($res);
        update_option('bsw_websub_enabled', 1, false);
        self::log('WebSub', $code === 202 || $code === 204 ? 'Žádost o odběr odeslána, čeká se na ověření.' : 'Hub vrátil HTTP ' . $code . ': ' . wp_remote_retrieve_body($res));
        return $code;
    }
    public static function rest_websub(WP_REST_Request $req) {
        if ($req->get_method() === 'GET') {
            $topic = (string) $req->get_param('hub_topic');
            $challenge = (string) $req->get_param('hub_challenge');
            if ($topic !== self::websub_topic() || $challenge === '' || strlen($challenge) > 255) return new WP_Error('bsw_websub', 'Neplatné ověření.', array('status' => 404));
            if ($req->get_param('hub_mode') === 'subscribe') {
                update_option('bsw_websub_verified', array('at' => time(), 'lease' => (int) $req->get_param('hub_lease_seconds')), false);
                self::log('WebSub', 'Odběr potvrzen na ' . round((int) $req->get_param('hub_lease_seconds') / 86400, 1) . ' dne.');
            }
            header('Content-Type: text/plain; charset=utf-8');
            echo preg_replace('/[^A-Za-z0-9_.~-]/', '', $challenge);
            exit;
        }
        $body = $req->get_body();
        $sig = (string) $req->get_header('x-hub-signature');
        if (!preg_match('/^sha1=([a-f0-9]{40})$/', $sig, $m) || !hash_equals(hash_hmac('sha1', $body, self::websub_secret()), $m[1])) {
            return new WP_REST_Response(null, 202); // tiše ignorovat cizí požadavky
        }
        $new = self::ingest_feed($body);
        self::log('WebSub', 'Oznámení od YouTube, nových videí: ' . count($new));
        if ($new && function_exists('fastcgi_finish_request')) {
            // Hubu odpovíme hned a titulky zkusíme stáhnout v témže procesu – bez cronu.
            status_header(204);
            fastcgi_finish_request();
            if (function_exists('ignore_user_abort')) ignore_user_abort(true);
            foreach (array_slice($new, 0, 3) as $sid) self::process($sid, 'WebSub');
            exit;
        }
        foreach (array_slice($new, 0, 1) as $sid) self::process($sid, 'WebSub');
        return new WP_REST_Response(null, 204);
    }
}
