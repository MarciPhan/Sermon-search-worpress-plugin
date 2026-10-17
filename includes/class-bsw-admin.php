<?php
if (!defined('ABSPATH')) exit;
final class BSW_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        foreach (array('save','connect','oauth_callback','disconnect','start_backfill','next_page','captions_now','retry','upload','legacy_table','legacy_upload','auto_run','auto_key','auto_channel','websub','auto_maint','synonyms','queries_clear') as $action) {
            add_action('admin_post_bsw_' . $action, array(__CLASS__, $action));
        }
        add_action('wp_ajax_bsw_refs_step', array(__CLASS__, 'refs_step'));
        add_action('wp_ajax_bsw_index_step', array(__CLASS__, 'index_step'));
    }
    public static function menu() {
        add_menu_page('Kázání – vyhledávač', 'Kázání', 'manage_options', 'bsw-settings', array(__CLASS__, 'page'), 'dashicons-video-alt3', 58);
    }
    private static function url() { return admin_url('admin.php?page=bsw-settings'); }
    private static function redirect($message, $error = false) {
        set_transient('bsw_notice_' . get_current_user_id(), array($message, $error), 60);
        wp_safe_redirect(self::url()); exit;
    }
    private static function check($action) {
        if (!current_user_can('manage_options')) wp_die('Nemáte oprávnění.');
        check_admin_referer('bsw_' . $action);
    }
    private static function form($action, $button, $confirm = '') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:4px 4px 4px 0">';
        echo '<input type="hidden" name="action" value="bsw_' . esc_attr($action) . '">';
        wp_nonce_field('bsw_' . $action);
        echo '<button class="button button-secondary" type="submit"' . ($confirm ? ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm)) . ')"' : '') . '>' . esc_html($button) . '</button></form>';
    }
    public static function page() {
        if (!current_user_can('manage_options')) wp_die('Nemáte oprávnění.');
        $settings = BSW_YouTube::settings(); $stats = BSW_DB::stats();
        $notice = get_transient('bsw_notice_' . get_current_user_id());
        delete_transient('bsw_notice_' . get_current_user_id());
        echo '<div class="wrap"><h1>Kázání – Bible Search</h1>';
        echo '<p>Všechny údaje se ukládají do stejné databáze jako WordPress přes <code>$wpdb</code>. Pro vlastní YouTube kanál používáme Google OAuth 2.0.</p>';
        if ($notice) echo '<div class="notice ' . ($notice[1] ? 'notice-error' : 'notice-success') . ' is-dismissible"><p>' . esc_html($notice[0]) . '</p></div>';
        $last = get_option('bsw_last_error');
        if ($last) echo '<div class="notice notice-warning"><p>Poslední chyba importu: ' . esc_html($last) . '</p></div>';
        self::auto_section();
        echo '<hr><h2>1. Připojení YouTube kanálu (volitelné)</h2>';
        echo '<p>Automatika výše funguje i bez OAuth. Připojení přes Google přidá další nezávislý zdroj (oficiální API) a umožní načíst celou historii kanálu.</p>';
        echo '<p>V <a target="_blank" rel="noopener" href="https://console.cloud.google.com/apis/library/youtube.googleapis.com">Google Cloud Console</a> aktivujte <strong>YouTube Data API v3</strong>. Vytvořte OAuth klienta typu <strong>Web application</strong>. Do povolených Redirect URI přidejte přesně:</p>';
        echo '<p><code style="user-select:all">' . esc_html(BSW_YouTube::callback_url()) . '</code></p>';
        echo '<p>V OAuth consent screen přidejte svůj Google účet mezi testovací uživatele. Pro dlouhodobý automatický přístup je potřeba řešit publikování OAuth aplikace (režim Testing obvykle ukončí obnovovací token po 7 dnech).</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bsw_save">';
        wp_nonce_field('bsw_save');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="bsw_client_id">OAuth Client ID</label></th><td><input id="bsw_client_id" type="text" class="regular-text" name="client_id" value="' . esc_attr($settings['client_id'] ?? '') . '" autocomplete="off"></td></tr>';
        echo '<tr><th><label for="bsw_secret">OAuth Client Secret</label></th><td><input id="bsw_secret" type="password" class="regular-text" name="client_secret" placeholder="' . (!empty($settings['client_secret']) ? 'Uloženo (pro zachování ponechte prázdné)' : 'Vložte Secret') . '" value="" autocomplete="new-password"><p class="description">Nikdy se nevypisuje na veřejné stránce ani neukládá do souborů pluginu.</p></td></tr>';
        echo '<tr><th><label for="bsw_playlist">Playlist ID (volitelné)</label></th><td><input id="bsw_playlist" type="text" class="regular-text" name="playlist_id" value="' . esc_attr($settings['playlist_id'] ?? '') . '" placeholder="PL…"><p class="description">Prázdné = celý kanál. Vyplňte playlist kázání, pokud kanál obsahuje také jiné pořady.</p></td></tr>';
        echo '</tbody></table>'; submit_button('Uložit nastavení'); echo '</form>';
        if (BSW_YouTube::connected()) {
            echo '<p><strong>Připojeno:</strong> ' . esc_html($settings['channel_title'] ?? 'YouTube') . ' (<code>' . esc_html($settings['channel_id'] ?? '') . '</code>)</p>';
            self::form('disconnect', 'Odpojit kanál', 'Opravdu odpojit kanál? Uložená kázání a titulky zůstanou zachované.');
        } elseif (!empty($settings['client_id']) && !empty($settings['client_secret'])) {
            self::form('connect', 'Připojit YouTube přes Google');
        } else echo '<p>Nejdříve uložte OAuth Client ID a Client Secret.</p>';
        echo '<hr><h2>2. Backfill videí a titulků</h2>';
        echo '<p><strong>Stav:</strong> ' . (get_option('bsw_sync_running') ? 'Probíhá import seznamu videí' : 'Historický import seznamu dokončen nebo ještě nespouštěn') . '. Poslední kontrola: ' . esc_html(get_option('bsw_last_sync', 'zatím žádná')) . '</p>';
        echo '<p><strong>Statistiky:</strong> videí ' . (int)$stats['videos'] . ', s titulky ' . (int)$stats['done'] . ', čeká ' . (int)$stats['pending'] . ', bez českých titulků ' . (int)$stats['missing'] . ', chyba ' . (int)$stats['error'] . ', indexovaných úseků ' . (int)$stats['segments'] . '.</p>';
        echo '<p><strong>API rozpočet pro dnešek (UTC):</strong> ' . (int)BSW_YouTube::quota_used() . ' / ' . (int)BSW_YouTube::DAILY_BUDGET . ' jednotek; zbytek do standardní YouTube kvóty je rezerva.</p>';
        echo '<p>Import zpracovává až 50 videí v jedné dávce. Pokud API titulky nevydá (automatické přepisy často končí chybou 403), automatika je zkusí stáhnout jinou cestou.</p>';
        if (BSW_YouTube::connected()) {
            self::form('start_backfill', 'Spustit celý backfill od začátku');
            self::form('next_page', 'Importovat další stránku (50 videí)');
            self::form('captions_now', 'Zpracovat jeden přepis teď');
            self::form('retry', 'Zopakovat chybové přepisy');
        }
        echo '<hr><h2>3. Rychlý hromadný backfill (ZIP, bez API kvóty)</h2>';
        echo '<p>Stáhněte dostupné české titulky jednorázově na PC pomocí <code>tools/backfill.py</code> (je v ZIPu pluginu). Skript připraví malé ZIPy s časovanými titulky a metadaty. Není potřeba OAuth, a tyto importy nespotřebují YouTube Data API kvótu. Použití yt-dlp může YouTube omezit.</p>';
        echo '<p>Vyberte jeden z vytvořených ZIPů a spusťte import. PHP zpracovává po 2 videích na požadavek a zobrazuje postup. Maximální velikost souboru na tomto WordPressu: <strong>' . esc_html(size_format(wp_max_upload_size())) . '</strong>. ZIPy snižte parametrem <code>--part-mb</code>, pokud limit překračují.</p>';
        if (!class_exists('ZipArchive')) echo '<div class="notice notice-error inline"><p>Na serveru není dostupné PHP rozšíření ZipArchive, bez něj tento import nefunguje.</p></div>';
        echo '<form id="bsw-bulk-form"><p><input type="file" name="archive" accept=".zip,application/zip" multiple required> <button class="button button-primary" id="bsw-bulk-button" type="submit">Naimportovat vybrané ZIPy</button></p><p class="description">Můžete vybrat <strong>všechny ZIPy najednou</strong> (v dialogu Ctrl+A) – naimportují se postupně jeden po druhém.</p></form>';
        echo '<p><progress id="bsw-bulk-progress" value="0" max="100" style="width:min(550px,100%)" hidden></progress></p><p id="bsw-bulk-status" role="status" aria-live="polite"></p>';
        echo '<p>Už existující kázání se nepřepíše názvem z archivu; titulky se aktualizují a uloží do databáze WordPressu. Pro import jednotlivého souboru pokračujte níže.</p>';
        echo '<hr><h2>4. Ruční doplnění titulků SRT / VTT</h2>';
        echo '<p>Pokud YouTube přepis přes API nevydá, můžete vlastní titulky vyexportovat z YouTube Studia a nahrát sem. Import nevytváří žádné soubory na hostingu.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bsw_upload">';
        wp_nonce_field('bsw_upload');
        echo '<p><label>ID YouTube videa (11 znaků): <input type="text" name="video_id" pattern="[A-Za-z0-9_-]{11}" maxlength="11" required></label></p>';
        echo '<p><input type="file" name="subtitles" accept=".srt,.vtt,text/plain" required></p>';
        submit_button('Nahrát a indexovat titulky', 'secondary'); echo '</form>';
        echo '<hr><h2>5. Biblické odkazy</h2>';
        echo '<p>Odkazy na knihy, kapitoly a verše se při každém importu titulků <strong>rozpoznávají automaticky</strong> z přepisu (např. „Jan 3 16“, „Galatským 5 22“, „Filipským druhá kapitola“) a z názvu videa (např. „2. List Petrův 2,4-6 | Karel Hýsek“). Z názvu se doplní i kazatel.</p>';
        echo '<p><strong>Uloženo:</strong> z přepisů ' . (int)$stats['refs_auto'] . ', z názvů videí ' . (int)$stats['refs_title'] . ', ze staré ruční databáze ' . (int)$stats['refs_legacy'] . '.</p>';
        echo '<p>Po aktualizaci pluginu (nebo úpravě rozpoznávání) přepočítejte odkazy u již naimportovaných kázání. Ruční odkazy zůstanou zachované.</p>';
        echo '<p><button type="button" class="button button-secondary" id="bsw-refs-button">Přepočítat odkazy ze všech přepisů</button></p>';
        echo '<p><progress id="bsw-refs-progress" value="0" max="100" style="width:min(550px,100%)" hidden></progress></p><p id="bsw-refs-status" role="status" aria-live="polite"></p>';
        echo '<h3>Stará ruční databáze</h3>';
        echo '<p>Převede ručně vedenou tabulku (sloupce <code>kazani_datum, kazatel, id_kazani, nazev_kazani, url_video, kniha, podari_knihy, zakon, kapitola, cislo_verse, cas, kod, druh, duraz, druh_videa</code>) do nového vyhledávání. Kázání, která ještě nejsou v indexu, se založí podle odkazu na video. Opakovaný převod nejdřív smaže dřívější ruční odkazy, takže nevznikají duplicity.</p>';
        $legacy = BSW_DB::legacy_table();
        if ($legacy) {
            echo '<p>V databázi byla nalezena tabulka <code>' . esc_html($legacy) . '</code>.</p>';
            self::form('legacy_table', 'Převést tabulku ' . $legacy, 'Převést starou databázi? Dříve převedené ruční odkazy budou nahrazeny.');
        } else {
            echo '<p>Tabulka <code>kazani</code> v databázi WordPressu není – nahrajte její export (CSV/TSV z phpMyAdminu nebo Excelu, kódování UTF-8 nebo Windows-1250).</p>';
        }
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bsw_legacy_upload">';
        wp_nonce_field('bsw_legacy_upload');
        echo '<p><input type="file" name="legacy" accept=".csv,.tsv,.txt,text/csv,text/tab-separated-values,text/plain" required> ';
        echo '<button class="button button-secondary" type="submit" onclick="return confirm(' . esc_attr(wp_json_encode('Nahrát starou databázi? Dříve převedené ruční odkazy budou nahrazeny.')) . ')">Nahrát a převést export</button></p></form>';
        self::search_section();
        echo '<hr><h2>7. Zobrazení na webu</h2><p>Na stránku vložte shortcode <code>[kazani_search]</code> (nebo <code>[biblesearch]</code>). Stránka má dva režimy: <em>Slova a fráze</em> a <em>Biblické knihy</em>. Biblický odkaz napsaný do hledání (např. <code>Ř 8,28</code>) se rozpozná automaticky.</p>';
        echo '<p>WP-Cron nepracuje při nulové návštěvnosti. Pro spolehlivý provoz na WEDOS nastavte plánované HTTP volání <code>' . esc_html(site_url('/wp-cron.php?doing_wp_cron')) . '</code>. Neukládejte přihlašovací tokeny do veřejných logů.</p>';
        echo '</div>';
    }
    /** current_time('mysql') je v časové zóně webu – převést na skutečný timestamp. */
    private static function local_ts($mysql) {
        if ($mysql === '') return 0;
        try { return (new DateTime($mysql, wp_timezone()))->getTimestamp(); } catch (Exception $e) { return 0; }
    }
    private static function ago($ts) {
        if (!$ts) return 'zatím nikdy';
        return sprintf('před %s (%s)', human_time_diff($ts, time()), wp_date('j. n. Y H:i', $ts));
    }
    private static function auto_section() {
        $s = BSW_YouTube::settings(); $runs = get_option('bsw_auto_runs', array());
        $stats = BSW_DB::stats();
        echo '<h2>Automatické doplňování kázání a titulků</h2>';
        echo '<p>Databáze se rozšiřuje sama několika <strong>nezávislými mechanismy</strong> – když jeden selže, ostatní pokračují. Stav: <strong>' . (int)$stats['done'] . '</strong> kázání s přepisem, <strong>' . (int)$stats['pending'] . '</strong> ve frontě, <strong>' . (int)$stats['missing'] . '</strong> zatím bez titulků (zkouší se znovu s rostoucím odstupem), <strong>' . (int)$stats['error'] . '</strong> s chybou. Skryto (premiéry, soukromá, smazaná nebo zatím neověřená videa): <strong>' . (int)$stats['hidden'] . '</strong> – ve vyhledávání se objeví, až budou veřejná. Playlistů: <strong>' . (int)$stats['playlists'] . '</strong>; z playlistů pojmenovaných po kazatelích se doplňují kazatelé.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bsw_auto_channel">';
        wp_nonce_field('bsw_auto_channel');
        echo '<p><label>Sledovaný kanál: <input type="text" class="regular-text" name="channel" value="' . esc_attr(BSW_Auto::channel_id()) . '" placeholder="https://www.youtube.com/@… nebo UC…"></label> <button class="button">Uložit kanál</button></p></form>';
        $wv = get_option('bsw_websub_verified');
        $mech = array(
            array('<strong>A) WP-Cron</strong> – plánovač WordPressu', max($runs['wp-cron 10 min'] ?? 0, $runs['wp-cron hodinový'] ?? 0, $runs['opakování nového videa'] ?? 0), 'titulky každých 10 min, RSS každou hodinu, nová videa znovu za 20 min / 3 h / 24 h, denní údržba'),
            array('<strong>B) Návštěvy webu</strong> – bez cronu', max($runs['návštěva webu'] ?? 0, $runs['návštěva administrace'] ?? 0), 'po odeslání stránky návštěvníkovi, max. 1× za 10 min; RSS 1× za hodinu'),
            array('<strong>C) Domácí PC</strong> – yt-dlp, jiná IP, bez serverového stahování', $runs['push z PC'] ?? 0, 'skript auto-sync (systemd timer), posílá přes REST /push'),
            array('D) WebSub – okamžité oznámení od YouTube', $runs['WebSub'] ?? 0, is_array($wv) ? 'odběr potvrzen ' . self::ago($wv['at']) . ', obnovuje se denně' : 'zatím nepřihlášeno – klikněte níže'),
            array('E) Cron URL pro WEDOS', $runs['cron URL (WEDOS)'] ?? 0, 'plánovač hostingu, nezávislý na WP-Cronu'),
            array('YouTube Data API (OAuth, volitelné)', BSW_YouTube::connected() ? self::local_ts((string)get_option('bsw_last_sync', '')) : 0, BSW_YouTube::connected() ? 'připojeno – záložní zdroj titulků, používá se až po ověření, že je video veřejné' : 'nepřipojeno'),
        );
        echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Mechanismus</th><th>Naposledy</th><th>Poznámka</th></tr></thead><tbody>';
        foreach ($mech as $m) echo '<tr><td>' . wp_kses($m[0], array('strong' => array())) . '</td><td>' . esc_html(self::ago($m[1])) . '</td><td>' . esc_html($m[2]) . '</td></tr>';
        echo '</tbody></table>';
        $cron = wp_next_scheduled('bsw_captions_tick');
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) echo '<p><strong>WP-Cron je vypnutý</strong> (DISABLE_WP_CRON) – určitě nastavte cron URL níže.</p>';
        elseif ($cron) echo '<p>Další běh WP-Cron: ' . esc_html(wp_date('j. n. H:i', $cron)) . '.</p>';
        echo '<p>';
        self::form('auto_run', 'Spustit kontrolu teď');
        self::form('auto_maint', 'Načíst celý kanál a playlisty teď');
        self::form('websub', is_array($wv) ? 'Obnovit odběr WebSub' : 'Přihlásit okamžitá oznámení (WebSub)');
        self::form('auto_key', 'Vygenerovat nový tajný klíč', 'Starý klíč přestane platit – bude nutné upravit cron na WEDOS i skript na PC.');
        echo '</p>';
        echo '<p><strong>Cron URL pro WEDOS</strong> (Administrace WEDOS → Webhosting → Cron úlohy → typ „URL“, např. každých 30 minut):<br><code style="user-select:all">' . esc_html(BSW_Auto::cron_url()) . '</code></p>';
        echo '<p><strong>Klíč pro skript na PC</strong> (<code>auto-sync.sh</code>): <code style="user-select:all">' . esc_html(BSW_Auto::key()) . '</code> · adresa webu: <code>' . esc_html(home_url('/')) . '</code></p>';
        $log = get_option('bsw_auto_log', array());
        if ($log) {
            echo '<details><summary>Záznam posledních běhů (' . count($log) . ')</summary><table class="widefat striped" style="max-width:980px"><tbody>';
            foreach ($log as $l) echo '<tr><td style="white-space:nowrap">' . esc_html(wp_date('j. n. H:i', $l[0])) . '</td><td>' . esc_html($l[1]) . '</td><td>' . esc_html($l[2]) . '</td></tr>';
            echo '</tbody></table></details>';
        }
    }
    public static function auto_run() {
        self::check('auto_run');
        @set_time_limit(120);
        self::redirect('Kontrola dokončena: ' . BSW_Auto::run_all('ruční spuštění'));
    }
    public static function auto_maint() {
        self::check('auto_maint');
        @set_time_limit(180);
        BSW_Auto::maintenance('ruční spuštění', 30, 90);
        $log = get_option('bsw_auto_log', array());
        self::redirect('Hotovo: ' . ($log[0][2] ?? ''));
    }
    public static function auto_key() {
        self::check('auto_key'); BSW_Auto::regenerate_key(); self::redirect('Vygenerován nový tajný klíč.');
    }
    public static function websub() {
        self::check('websub');
        $res = BSW_Auto::websub_subscribe();
        self::redirect(is_wp_error($res) ? $res->get_error_message() : 'Žádost o odběr odeslána (HTTP ' . (int)$res . '). Potvrzení se objeví v tabulce během minuty.', is_wp_error($res));
    }
    public static function auto_channel() {
        self::check('auto_channel');
        $id = BSW_Auto::resolve_channel(wp_unslash($_POST['channel'] ?? ''));
        if (is_wp_error($id)) self::redirect($id->get_error_message(), true);
        $s = BSW_YouTube::settings(); $s['public_channel_id'] = $id; update_option('bsw_settings', $s, false);
        if (get_option('bsw_websub_enabled')) BSW_Auto::websub_subscribe();
        self::redirect('Sledovaný kanál: ' . $id);
    }
    public static function save() {
        self::check('save');
        $prev = BSW_YouTube::settings();
        $client_id = sanitize_text_field(wp_unslash($_POST['client_id'] ?? ''));
        $secret = trim((string)wp_unslash($_POST['client_secret'] ?? ''));
        $playlist = sanitize_text_field(wp_unslash($_POST['playlist_id'] ?? ''));
        if ($playlist !== '' && !preg_match('/^[A-Za-z0-9_-]{10,100}$/', $playlist)) self::redirect('Playlist ID má neplatný formát.', true);
        if ($client_id !== '' && !preg_match('/^[a-zA-Z0-9._-]+\.apps\.googleusercontent\.com$/', $client_id)) self::redirect('Client ID nemá očekávaný formát Google OAuth.', true);
        if (strlen($secret) > 512) self::redirect('Příliš dlouhý Client Secret.', true);
        $changed_credentials = $client_id !== ($prev['client_id'] ?? '') || ($secret !== '' && $secret !== ($prev['client_secret'] ?? ''));
        $changed_playlist = $playlist !== ($prev['playlist_id'] ?? '');
        if ($changed_credentials && BSW_YouTube::connected()) BSW_YouTube::disconnect();
        $current = BSW_YouTube::settings();
        $current['client_id'] = $client_id;
        if ($secret !== '') $current['client_secret'] = $secret;
        $current['playlist_id'] = $playlist;
        update_option('bsw_settings', $current, false);
        if ($changed_playlist) { delete_option('bsw_next_page'); if (BSW_YouTube::connected()) update_option('bsw_sync_running', true, false); }
        self::redirect('Nastavení uloženo.');
    }
    public static function connect() {
        self::check('connect');
        $s = BSW_YouTube::settings();
        if (empty($s['client_id']) || empty($s['client_secret'])) self::redirect('Chybí Google OAuth údaje.', true);
        $state = bin2hex(random_bytes(24));
        set_transient('bsw_oauth_' . $state, get_current_user_id(), 10 * MINUTE_IN_SECONDS);
        wp_redirect(BSW_YouTube::auth_url($state)); exit;
    }
    public static function oauth_callback() {
        if (!current_user_can('manage_options')) wp_die('Nemáte oprávnění.');
        $state = isset($_GET['state']) && is_string($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if (!preg_match('/^[a-f0-9]{48}$/', $state)) self::redirect('Neplatný OAuth state.', true);
        $user = get_transient('bsw_oauth_' . $state);
        delete_transient('bsw_oauth_' . $state);
        if ((int)$user !== get_current_user_id()) self::redirect('Neplatné nebo vypršené OAuth přihlášení.', true);
        if (isset($_GET['error'])) self::redirect('Google nepovolil připojení: ' . sanitize_text_field(wp_unslash($_GET['error'])), true);
        $code = isset($_GET['code']) && is_string($_GET['code']) ? wp_unslash($_GET['code']) : '';
        if (!$code || strlen($code) > 4096) self::redirect('Chybí autorizační kód Google.', true);
        $result = BSW_YouTube::finish_oauth($code);
        self::redirect(is_wp_error($result) ? $result->get_error_message() : 'YouTube kanál připojen. Backfill se spustí automaticky.', is_wp_error($result));
    }
    public static function disconnect() {
        self::check('disconnect'); BSW_YouTube::disconnect(); self::redirect('Kanál odpojen. Lokální data zůstala zachovaná.');
    }
    public static function start_backfill() {
        self::check('start_backfill');
        if (!BSW_YouTube::connected()) self::redirect('Nejprve připojte kanál.', true);
        delete_option('bsw_next_page');
        update_option('bsw_sync_running', true, false);
        $res = BSW_YouTube::sync_tick();
        self::redirect(is_wp_error($res) ? $res->get_error_message() : 'Import zahájen. Načteno ' . (int)$res . ' videí. Další budou doplněna automaticky.', is_wp_error($res));
    }
    public static function next_page() {
        self::check('next_page');
        if (!BSW_YouTube::connected()) self::redirect('Nejprve připojte kanál.', true);
        $res = BSW_YouTube::sync_page(!get_option('bsw_sync_running'));
        self::redirect(is_wp_error($res) ? $res->get_error_message() : 'Načteno/aktualizováno ' . (int)$res . ' videí.', is_wp_error($res));
    }
    public static function captions_now() {
        self::check('captions_now');
        if (!BSW_YouTube::connected()) self::redirect('Nejprve připojte kanál.', true);
        $res = BSW_YouTube::captions_tick();
        self::redirect(is_wp_error($res) ? $res->get_error_message() : ($res === null ? 'Žádný přepis nečeká nebo již běží import.' : 'Importováno ' . (int)$res . ' úseků titulků.'), is_wp_error($res));
    }
    public static function retry() {
        self::check('retry');
        global $wpdb; $t = BSW_DB::sermons();
        $count = $wpdb->query("UPDATE $t SET caption_status='pending',caption_error='' WHERE caption_status='error'");
        self::redirect('Do fronty vráceno ' . (int)$count . ' chybných přepisů.');
    }
    private static function search_section() {
        global $wpdb;
        echo '<hr><h2>6. Vyhledávání: tvary slov a co lidé hledají</h2>';
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . BSW_DB::sermons());
        if (BSW_DB::index_ready()) {
            echo '<p><strong>Index tvarů slov je hotový.</strong> Hledání „Bůh“ najde i „Boha“, „Bohu“, „Bože“; „odpuštění“ i „odpustil“. Nová kázání se indexují hned při importu.</p>';
        } else {
            $left = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . BSW_DB::sermons() . ' WHERE id > %d', (int) get_option('bsw_index_cursor', 0)));
            echo '<p><strong>Index tvarů slov se připravuje</strong> (zbývá asi ' . (int) $left . ' z ' . $total . ' kázání). Doplňuje se sám při návštěvách a v cronu; do té doby se hledá postaru. Můžete ho dokončit hned tlačítkem.</p>';
        }
        echo '<p><button type="button" class="button button-secondary" id="bsw-index-button">' . (BSW_DB::index_ready() ? 'Přepočítat index tvarů slov znovu' : 'Dokončit index tvarů slov teď') . '</button></p>';
        echo '<p><progress id="bsw-index-progress" value="0" max="100" style="width:min(550px,100%)" hidden></progress></p><p id="bsw-index-status" role="status" aria-live="polite"></p>';

        if (BSW_DB::index_ready()) {
            $an = BSW_Analysis::step(4);
            echo '<p><strong>Rozbor kázání</strong> (osnovy, související kázání, témata, návrhy slov): ' . ($an['done'] ? 'hotový, nová kázání se rozeberou sama.' : 'zbývá ' . (int) $an['left'] . ' kázání – dělá se sám na pozadí (každé otevření této stránky ho o kus posune).') . '</p>';
        }
        echo '<h3>Tvary a synonyma</h3>';
        echo '<p>Každý řádek je jedna skupina slov, která se mají hledat společně (oddělte čárkou). Např. <code>Hospodin, Jahve, Jehova</code> nebo <code>apoštol, učedník</code>. Hledání kteréhokoli slova ze skupiny najde i ostatní, ve všech tvarech. Řádek začínající <code>#</code> je poznámka.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bsw_synonyms">';
        wp_nonce_field('bsw_synonyms');
        echo '<p><textarea name="synonyms" rows="6" class="large-text code" placeholder="Hospodin, Jahve">' . esc_textarea((string) get_option('bsw_synonyms', '')) . '</textarea></p>';
        submit_button('Uložit skupiny slov', 'secondary', 'submit', false);
        echo '</form>';

        $table = BSW_DB::queries();
        $miss = $wpdb->get_results("SELECT query_text, searches, last_at FROM $table WHERE results = 0 ORDER BY searches DESC, last_at DESC LIMIT 30");
        $top = $wpdb->get_results("SELECT query_text, results, searches FROM $table WHERE results > 0 ORDER BY searches DESC, last_at DESC LIMIT 30");
        echo '<h3>Co lidé hledají</h3>';
        echo '<p>Anonymní statistika: ukládá se jen text dotazu, počet nalezených kázání a kolikrát se hledal (bez IP adres, bez přihlášených správců a robotů, jen hledání bez filtrů). Záznamy starší než rok se mažou. Dotazy bez výsledků napoví, co doplnit do skupin slov výše.</p>';
        $table_html = function ($rows, $cols) {
            if (!$rows) { echo '<p><em>Zatím nic.</em></p>'; return; }
            echo '<table class="widefat striped" style="max-width:640px"><thead><tr>';
            foreach ($cols as $label) echo '<th>' . esc_html($label) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($rows as $r) {
                echo '<tr>';
                foreach (array_keys($cols) as $k) {
                    $v = $r->$k;
                    if ($k === 'query_text') $v = '<a href="' . esc_url(add_query_arg('bsw_q', rawurlencode($r->query_text), get_permalink(BSW_Transcript::page_id()))) . '" target="_blank" rel="noopener">' . esc_html($v) . '</a>';
                    elseif ($k === 'last_at') $v = esc_html(self::ago(strtotime($v . ' UTC')));
                    else $v = (int) $v;
                    echo '<td>' . $v . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        };
        echo '<h4>Hledání bez výsledků</h4>';
        $table_html($miss, array('query_text' => 'Dotaz', 'searches' => 'Kolikrát', 'last_at' => 'Naposledy'));
        echo '<h4>Nejčastější hledání</h4>';
        $table_html($top, array('query_text' => 'Dotaz', 'searches' => 'Kolikrát', 'results' => 'Nalezených kázání'));
        if ($miss || $top) self::form('queries_clear', 'Smazat statistiku hledání', 'Opravdu smazat celou statistiku hledání?');
    }
    public static function synonyms() {
        self::check('synonyms');
        $text = isset($_POST['synonyms']) ? sanitize_textarea_field(wp_unslash($_POST['synonyms'])) : '';
        update_option('bsw_synonyms', mb_substr($text, 0, 20000, 'UTF-8'), false);
        self::redirect('Skupiny slov uloženy. Platí hned pro všechna hledání.');
    }
    public static function queries_clear() {
        self::check('queries_clear');
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . BSW_DB::queries());
        self::redirect('Statistika hledání smazána.');
    }
    public static function index_step() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Nemáte oprávnění.'), 403);
        if (!check_ajax_referer('bsw_bulk', 'nonce', false)) wp_send_json_error(array('message' => 'Neplatný bezpečnostní token. Obnovte stránku.'), 403);
        global $wpdb;
        if (!empty($_POST['restart']) && BSW_DB::index_ready()) BSW_DB::index_restart();
        $res = BSW_DB::index_step(8);
        $res['total'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . BSW_DB::sermons());
        wp_send_json_success($res);
    }
    public static function refs_step() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Nemáte oprávnění.'), 403);
        if (!check_ajax_referer('bsw_bulk', 'nonce', false)) wp_send_json_error(array('message' => 'Neplatný bezpečnostní token. Obnovte stránku.'), 403);
        $offset = isset($_POST['offset']) ? max(0, (int)$_POST['offset']) : 0;
        $res = BSW_DB::reindex_batch($offset, 15);
        $res['offset'] = $offset + $res['processed'];
        $res['done'] = $res['processed'] === 0 || $res['offset'] >= $res['total'];
        if ($res['done']) delete_transient('bsw_public_stats');
        wp_send_json_success($res);
    }
    private static function legacy_report($r) {
        $msg = sprintf('Převedeno %d odkazů z %d řádků, nově založeno %d kázání, přeskočeno %d řádků.', $r['refs'], $r['rows'], $r['created'], $r['skipped']);
        if ($r['errors']) $msg .= ' Příklady: ' . implode('; ', $r['errors']);
        self::redirect($msg, !$r['refs']);
    }
    public static function legacy_table() {
        self::check('legacy_table');
        $table = BSW_DB::legacy_table();
        if (!$table) self::redirect('Stará tabulka nebyla nalezena.', true);
        self::legacy_report(BSW_Legacy::import(BSW_Legacy::read_table($table)));
    }
    public static function legacy_upload() {
        self::check('legacy_upload');
        if (empty($_FILES['legacy']) || (int)$_FILES['legacy']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['legacy']['tmp_name'])) self::redirect('Soubor se nepodařilo nahrát.', true);
        $file = $_FILES['legacy'];
        if ($file['size'] <= 0 || $file['size'] > 8 * 1024 * 1024 || !preg_match('/\.(csv|tsv|txt)$/i', $file['name'])) self::redirect('Použijte CSV, TSV nebo TXT do 8 MB.', true);
        $rows = BSW_Legacy::read_file($file['tmp_name']);
        if (is_wp_error($rows)) self::redirect($rows->get_error_message(), true);
        self::legacy_report(BSW_Legacy::import($rows));
    }
    public static function upload() {
        self::check('upload');
        $id = sanitize_text_field(wp_unslash($_POST['video_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) self::redirect('Neplatné ID videa.', true);
        if (empty($_FILES['subtitles']) || (int)$_FILES['subtitles']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['subtitles']['tmp_name'])) self::redirect('Nepodařilo se nahrát titulky.', true);
        $file = $_FILES['subtitles'];
        if ($file['size'] > 5 * 1024 * 1024 || $file['size'] <= 0 || !preg_match('/\.(srt|vtt)$/i', $file['name'])) self::redirect('Použijte SRT nebo VTT do 5 MB.', true);
        $content = file_get_contents($file['tmp_name'], false, null, 0, 5 * 1024 * 1024);
        $segments = BSW_DB::parse_subtitles($content);
        if (!$segments) self::redirect('Soubor neobsahuje čitelné titulky.', true);
        global $wpdb; $t = BSW_DB::sermons();
        $sermon_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE video_id=%s", $id));
        if (!$sermon_id) self::redirect('Video nebylo nalezeno v indexu. Nejprve načtěte seznam kanálu.', true);
        $res = BSW_DB::replace_segments($sermon_id, $segments);
        self::redirect(is_wp_error($res) ? $res->get_error_message() : 'Nahráno ' . (int)$res . ' úseků pro video ' . $id . '.', is_wp_error($res));
    }
}
