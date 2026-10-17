<?php
if (!defined('ABSPATH')) exit;
final class BSW_YouTube {
    const SCOPE = 'https://www.googleapis.com/auth/youtube.force-ssl';
    const DAILY_BUDGET = 8000; // Rezerva vůči běžné kvótě 10 000 jednotek/den.
    public static function settings() { return (array)get_option('bsw_settings', array()); }
    public static function callback_url() { return admin_url('admin-post.php?action=bsw_oauth_callback'); }
    public static function connected() { $s = self::settings(); $t = get_option('bsw_tokens'); return !empty($t['refresh_token']) && !empty($s['channel_id']); }
    public static function auth_url($state) {
        $s = self::settings();
        return add_query_arg(array(
            'client_id' => $s['client_id'], 'redirect_uri' => self::callback_url(),
            'response_type' => 'code', 'scope' => self::SCOPE, 'access_type' => 'offline',
            'prompt' => 'consent select_account', 'state' => $state
        ), 'https://accounts.google.com/o/oauth2/v2/auth');
    }
    private static function token_exchange($post) {
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array('timeout' => 25, 'body' => $post));
        if (is_wp_error($response)) return $response;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || empty($data['access_token'])) {
            return new WP_Error('bsw_google_token', 'Google OAuth: ' . sanitize_text_field($data['error_description'] ?? $data['error'] ?? 'neznámá chyba'));
        }
        return $data;
    }
    public static function finish_oauth($code) {
        $s = self::settings();
        $data = self::token_exchange(array('code' => $code, 'client_id' => $s['client_id'],
            'client_secret' => $s['client_secret'], 'redirect_uri' => self::callback_url(), 'grant_type' => 'authorization_code'));
        if (is_wp_error($data)) return $data;
        if (empty($data['refresh_token'])) return new WP_Error('bsw_no_refresh', 'Google nevrátil obnovovací token. Odpojte aplikaci v Google účtu a připojte ji znovu.');
        $tokens = array('access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token'], 'expires_at' => time() + (int)($data['expires_in'] ?? 3600));
        update_option('bsw_tokens', $tokens, false);
        $res = self::api_get('https://www.googleapis.com/youtube/v3/channels', array('part' => 'snippet,contentDetails', 'mine' => 'true'));
        if (is_wp_error($res)) { delete_option('bsw_tokens'); return $res; }
        $items = $res['items'] ?? array();
        if (empty($items[0]['id']) || empty($items[0]['contentDetails']['relatedPlaylists']['uploads'])) {
            delete_option('bsw_tokens');
            return new WP_Error('bsw_no_channel', 'Google účet nevrátil YouTube kanál. Při autorizaci zvolte správný účet nebo Brand Account.');
        }
        $s['channel_id'] = sanitize_text_field($items[0]['id']);
        $s['channel_title'] = sanitize_text_field($items[0]['snippet']['title'] ?? 'YouTube kanál');
        $s['uploads_playlist'] = sanitize_text_field($items[0]['contentDetails']['relatedPlaylists']['uploads']);
        update_option('bsw_settings', $s, false);
        update_option('bsw_sync_running', true, false);
        delete_option('bsw_next_page');
        return true;
    }
    public static function disconnect() {
        delete_option('bsw_tokens');
        $s = self::settings();
        unset($s['channel_id'], $s['channel_title'], $s['uploads_playlist']);
        update_option('bsw_settings', $s, false);
        update_option('bsw_sync_running', false, false);
        delete_option('bsw_next_page');
    }
    private static function access_token() {
        $s = self::settings(); $t = get_option('bsw_tokens');
        if (empty($t['refresh_token'])) return new WP_Error('bsw_not_connected', 'Kanál není připojen.');
        if (!empty($t['access_token']) && (int)($t['expires_at'] ?? 0) > time() + 60) return $t['access_token'];
        $data = self::token_exchange(array('refresh_token' => $t['refresh_token'], 'client_id' => $s['client_id'],
            'client_secret' => $s['client_secret'], 'grant_type' => 'refresh_token'));
        if (is_wp_error($data)) return $data;
        $t['access_token'] = $data['access_token'];
        $t['expires_at'] = time() + (int)($data['expires_in'] ?? 3600);
        if (!empty($data['refresh_token'])) $t['refresh_token'] = $data['refresh_token'];
        update_option('bsw_tokens', $t, false);
        return $t['access_token'];
    }
    private static function quota($units) {
        $q = get_option('bsw_quota', array());
        $day = gmdate('Y-m-d');
        if (($q['day'] ?? '') !== $day) $q = array('day' => $day, 'units' => 0);
        if ($q['units'] + $units > self::DAILY_BUDGET) return false;
        $q['units'] += $units;
        update_option('bsw_quota', $q, false);
        return true;
    }
    public static function quota_used() {
        $q = get_option('bsw_quota', array());
        return ($q['day'] ?? '') === gmdate('Y-m-d') ? (int)($q['units'] ?? 0) : 0;
    }
    private static function api_get($endpoint, $params = array(), $cost = 1, $raw = false) {
        if (!self::quota($cost)) return new WP_Error('bsw_quota', 'Dosažen interní denní limit YouTube API. Import bude pokračovat další den.');
        $token = self::access_token();
        if (is_wp_error($token)) return $token;
        if ($params) $endpoint = add_query_arg($params, $endpoint);
        $res = wp_remote_get($endpoint, array('timeout' => 35, 'redirection' => 0, 'limit_response_size' => 5 * 1024 * 1024,
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => $raw ? 'text/plain' : 'application/json')));
        if (is_wp_error($res)) return $res;
        $http = (int)wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        if ($http !== 200) {
            $data = json_decode($body, true);
            $msg = $data['error']['message'] ?? ('YouTube API HTTP ' . $http);
            return new WP_Error('bsw_youtube_' . $http, substr(sanitize_text_field($msg), 0, 220));
        }
        if ($raw) return $body;
        $data = json_decode($body, true);
        return is_array($data) ? $data : new WP_Error('bsw_json', 'YouTube API vrátilo neplatnou odpověď.');
    }
    private static function fail($error) {
        update_option('bsw_last_error', substr($error->get_error_message(), 0, 220), false);
        return $error;
    }
    public static function sync_tick() {
        if (!self::connected()) return;
        if (get_transient('bsw_sync_lock')) return;
        set_transient('bsw_sync_lock', 1, 4 * MINUTE_IN_SECONDS);
        try { return self::sync_page(!get_option('bsw_sync_running')); } finally { delete_transient('bsw_sync_lock'); }
    }
    public static function sync_page($head_only = false) {
        if (!self::connected()) return new WP_Error('bsw_not_connected', 'Nejprve připojte kanál.');
        $s = self::settings();
        $playlist = !empty($s['playlist_id']) ? $s['playlist_id'] : $s['uploads_playlist'];
        $params = array('part' => 'snippet,contentDetails', 'playlistId' => $playlist, 'maxResults' => 50);
        $page = $head_only ? '' : (string)get_option('bsw_next_page', '');
        if ($page !== '') $params['pageToken'] = $page;
        $result = self::api_get('https://www.googleapis.com/youtube/v3/playlistItems', $params);
        if (is_wp_error($result)) return self::fail($result);
        $saved = 0;
        foreach (($result['items'] ?? array()) as $item) {
            $id = $item['contentDetails']['videoId'] ?? $item['snippet']['resourceId']['videoId'] ?? '';
            $title = $item['snippet']['title'] ?? '';
            if (!$id || in_array($title, array('Private video', 'Deleted video'), true)) continue;
            if (BSW_DB::upsert_sermon($id, $title, $item['snippet']['description'] ?? '',
                $item['contentDetails']['videoPublishedAt'] ?? $item['snippet']['publishedAt'] ?? '') > 0) $saved++;
        }
        $next = $result['nextPageToken'] ?? '';
        if (!$head_only) {
            update_option('bsw_next_page', $next, false);
            if (!$next) update_option('bsw_sync_running', false, false);
        }
        delete_option('bsw_last_error');
        update_option('bsw_last_sync', current_time('mysql'), false);
        return $saved;
    }
    public static function captions_tick() {
        if (!self::connected()) return BSW_Auto::worker('ruční spuštění');
        if (get_transient('bsw_caption_lock')) return;
        set_transient('bsw_caption_lock', 1, 4 * MINUTE_IN_SECONDS);
        try {
            global $wpdb;
            $t = BSW_DB::sermons();
            $video = $wpdb->get_row("SELECT id, video_id FROM $t WHERE caption_status='pending' ORDER BY published_at DESC, id DESC LIMIT 1");
            if (!$video) return;
            return self::import_captions((int)$video->id, $video->video_id);
        } finally { delete_transient('bsw_caption_lock'); }
    }
    public static function import_captions($sermon_id, $video_id) {
        $result = self::api_get('https://www.googleapis.com/youtube/v3/captions', array('part' => 'snippet', 'videoId' => $video_id), 50);
        if (is_wp_error($result)) {
            if ($result->get_error_code() !== 'bsw_quota') BSW_DB::caption_status($sermon_id, 'error', $result->get_error_message());
            return self::fail($result);
        }
        $tracks = array();
        foreach (($result['items'] ?? array()) as $item) {
            $snippet = $item['snippet'] ?? array();
            $lang = strtolower($snippet['language'] ?? '');
            if (($lang === 'cs' || strpos($lang, 'cs-') === 0) && ($snippet['status'] ?? '') !== 'failed' && empty($snippet['isDraft'])) $tracks[] = $item;
        }
        usort($tracks, function ($a, $b) {
            return (int)(($a['snippet']['trackKind'] ?? '') === 'ASR') - (int)(($b['snippet']['trackKind'] ?? '') === 'ASR');
        });
        if (!$tracks) {
            BSW_DB::caption_status($sermon_id, 'missing', 'YouTube nevrací české titulky.');
            return new WP_Error('bsw_no_cs', 'Pro toto video nebyly nalezeny české titulky.');
        }
        $last = null;
        foreach ($tracks as $track) {
            $content = self::api_get('https://www.googleapis.com/youtube/v3/captions/' . rawurlencode($track['id']), array('tfmt' => 'srt'), 200, true);
            if (is_wp_error($content)) { $last = $content; if ($content->get_error_code() === 'bsw_quota') break; continue; }
            $segments = BSW_DB::parse_subtitles($content);
            if (!$segments) { $last = new WP_Error('bsw_parse', 'Titulky nelze převést do formátu vyhledávání.'); continue; }
            $saved = BSW_DB::replace_segments($sermon_id, $segments);
            if (is_wp_error($saved)) { $last = $saved; break; }
            delete_option('bsw_last_error');
            return $saved;
        }
        $last = $last ?: new WP_Error('bsw_caption', 'Titulky se nepodařilo stáhnout.');
        if ($last->get_error_code() !== 'bsw_quota') BSW_DB::caption_status($sermon_id, 'error', $last->get_error_message());
        return self::fail($last);
    }
}
