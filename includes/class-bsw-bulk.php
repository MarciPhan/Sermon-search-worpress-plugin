<?php
if (!defined('ABSPATH')) exit;

/** Secure, resumable batch import of locally downloaded SRT/VTT subtitle ZIPs. */
final class BSW_Bulk {
    const MAX_ARCHIVE_ENTRIES = 1600;
    const MAX_TEXT_BYTES = 2097152; // 2 MiB per caption file
    const MAX_UNPACKED_BYTES = 157286400; // 150 MiB per ZIP
    const BATCH_SIZE = 2; // Keep shared-host requests reasonably short
    const JOB_TTL = 7200;

    public static function init() {
        add_action('wp_ajax_bsw_bulk_start', array(__CLASS__, 'start'));
        add_action('wp_ajax_bsw_bulk_step', array(__CLASS__, 'step'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    public static function assets($hook) {
        if ($hook !== 'toplevel_page_bsw-settings') return;
        wp_enqueue_script('bsw-bulk', BSW_URL . 'assets/bulk.js', array(), BSW_VERSION, true);
        wp_localize_script('bsw-bulk', 'BSWBulk', array(
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bsw_bulk'),
        ));
    }

    private static function fail($message, $code = 400) {
        wp_send_json_error(array('message' => $message), $code);
    }

    private static function guard() {
        if (!current_user_can('manage_options')) self::fail('Nemáte oprávnění.', 403);
        if (!check_ajax_referer('bsw_bulk', 'nonce', false)) self::fail('Neplatný bezpečnostní token. Obnovte stránku.', 403);
        if (!class_exists('ZipArchive')) self::fail('Server nepodporuje rozšíření PHP ZipArchive.', 500);
    }

    private static function stage_dir() {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) return new WP_Error('bsw_uploads', 'Adresář nahrávání WordPressu není dostupný.');
        $dir = trailingslashit($uploads['basedir']) . 'bsw-import-staging';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) return new WP_Error('bsw_mkdir', 'Nelze vytvořit pracovní adresář.');
        if (!file_exists($dir . '/index.php')) file_put_contents($dir . '/index.php', '<?php // Silence is golden.');
        if (!file_exists($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule mod_access_compat.c>\nDeny from all\n</IfModule>\n");
        return $dir;
    }

    private static function clean_old($dir) {
        foreach (glob($dir . '/bsw-*.zip') ?: array() as $file) {
            if (is_file($file) && filemtime($file) < time() - DAY_IN_SECONDS) @unlink($file);
        }
    }

    private static function job_key($id) { return 'bsw_bulk_' . $id; }

    /** Strictly accept filenames emitted by the accompanying yt-dlp helper. */
    public static function subtitle_id($filename) {
        $filename = basename(str_replace('\\', '/', $filename));
        if (!preg_match('/^([A-Za-z0-9_-]{11})\.cs(?:-[A-Za-z0-9_-]{1,30})?\.(vtt|srt)$/i', $filename, $m)) return '';
        return $m[1];
    }

    public static function start() {
        self::guard();
        if (empty($_FILES['archive']) || !is_array($_FILES['archive']) || (int)$_FILES['archive']['error'] !== UPLOAD_ERR_OK) {
            self::fail('ZIP se nenahrál. Ověřte velikost souboru a limit hostingu.');
        }
        $file = $_FILES['archive'];
        if (!is_uploaded_file($file['tmp_name']) || (int)$file['size'] <= 0 || (int)$file['size'] > wp_max_upload_size()) {
            self::fail('Soubor překračuje povolenou velikost nahrávání WordPressu.');
        }
        if (!preg_match('/\.zip$/i', (string)$file['name'])) self::fail('Vyberte soubor .zip.');
        $dir = self::stage_dir();
        if (is_wp_error($dir)) self::fail($dir->get_error_message());
        self::clean_old($dir);
        try { $id = bin2hex(random_bytes(16)); }
        catch (Exception $e) { self::fail('Nepodařilo se vytvořit identifikátor importu.', 500); }
        $path = $dir . '/bsw-' . $id . '.zip';
        if (!move_uploaded_file($file['tmp_name'], $path)) self::fail('Nepodařilo se uložit ZIP na server.', 500);
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            @unlink($path); self::fail('ZIP nelze otevřít.');
        }
        $entries = array(); $unpacked = 0;
        if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            $zip->close(); @unlink($path); self::fail('ZIP obsahuje příliš mnoho souborů. Rozdělte ho na menší části.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) continue;
            $name = $stat['name'];
            $size = (int)$stat['size'];
            $unpacked += $size;
            if ($unpacked > self::MAX_UNPACKED_BYTES) {
                $zip->close(); @unlink($path); self::fail('ZIP obsahuje příliš mnoho rozbalených dat.');
            }
            $video_id = self::subtitle_id($name);
            if ($video_id === '') continue;
            if ($size > self::MAX_TEXT_BYTES || $size < 10) continue;
            // Ignore duplicate tracks of the same video within one archive.
            if (!isset($entries[$video_id])) $entries[$video_id] = $i;
        }
        $zip->close();
        if (!$entries) { @unlink($path); self::fail('V ZIPu nejsou titulky ve formátu VIDEO_ID.cs.vtt/srt.'); }
        $job = array('id' => $id, 'user' => get_current_user_id(), 'path' => $path,
            'entries' => array_values($entries), 'total' => count($entries), 'offset' => 0,
            'imported' => 0, 'skipped' => 0, 'segments' => 0, 'errors' => array());
        set_transient(self::job_key($id), $job, self::JOB_TTL);
        wp_send_json_success(array('job' => $id, 'total' => $job['total']));
    }

    private static function metadata($zip) {
        $index = $zip->locateName('manifest.json', ZipArchive::FL_NOCASE);
        if ($index === false) return array();
        $stat = $zip->statIndex($index);
        if (!$stat || $stat['size'] > 1024 * 1024) return array();
        $json = $zip->getFromIndex($index);
        $data = json_decode($json, true);
        return is_array($data) ? $data : array();
    }

    private static function import_one($zip, $index, $manifest) {
        global $wpdb;
        $stat = $zip->statIndex($index);
        if (!$stat) return new WP_Error('bsw_missing', 'Chybí položka v ZIP.');
        $video_id = self::subtitle_id($stat['name']);
        if (!$video_id || $stat['size'] > self::MAX_TEXT_BYTES) return new WP_Error('bsw_name', 'Neplatný soubor titulků.');
        $content = $zip->getFromIndex($index);
        if (!is_string($content) || strlen($content) > self::MAX_TEXT_BYTES) return new WP_Error('bsw_read', 'Titulky nejde načíst.');
        $cues = BSW_DB::parse_subtitles($content);
        if (!$cues) return new WP_Error('bsw_empty', 'Žádné rozpoznané časované titulky.');

        $table = BSW_DB::sermons();
        $existing_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE video_id = %s", $video_id));
        if ($existing_id) {
            $sermon_id = $existing_id;
        } else {
            $meta = isset($manifest[$video_id]) && is_array($manifest[$video_id]) ? $manifest[$video_id] : array();
            $title = !empty($meta['title']) ? (string)$meta['title'] : 'Kázání ' . $video_id;
            $date = !empty($meta['published_at']) ? (string)$meta['published_at'] : '';
            $description = !empty($meta['description']) ? (string)$meta['description'] : '';
            $avail = (string)($meta['availability'] ?? 'public');
            $live = (string)($meta['live_status'] ?? '');
            $visibility = $live === 'is_upcoming' ? 'upcoming' : (in_array($avail, array('private', 'needs_auth', 'subscriber_only', 'premium_only'), true) ? 'private' : 'public');
            $sermon_id = BSW_DB::upsert_sermon($video_id, $title, $description, $date, '', $visibility);
        }
        if (!$sermon_id) return new WP_Error('bsw_sermon', 'Nepodařilo se vytvořit kázání.');
        return BSW_DB::replace_segments($sermon_id, $cues);
    }

    public static function step() {
        self::guard();
        $id = isset($_POST['job']) && is_string($_POST['job']) ? sanitize_text_field(wp_unslash($_POST['job'])) : '';
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) self::fail('Neplatná úloha.');
        $job = get_transient(self::job_key($id));
        if (!is_array($job) || (int)($job['user'] ?? 0) !== get_current_user_id()) self::fail('Úloha nebyla nalezena, vypršela nebo patří jinému správci.');
        $path = $job['path'];
        if (!is_file($path)) { delete_transient(self::job_key($id)); self::fail('Pracovní soubor ZIP chybí.'); }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) self::fail('ZIP nejde znovu otevřít.');
        $metadata = self::metadata($zip);
        $begin = (int)$job['offset'];
        $stop = min($begin + self::BATCH_SIZE, (int)$job['total']);
        for ($i = $begin; $i < $stop; $i++) {
            $index = $job['entries'][$i];
            $result = self::import_one($zip, $index, $metadata);
            if (is_wp_error($result)) {
                $job['skipped']++;
                if (count($job['errors']) < 12) {
                    $name = $zip->getNameIndex($index);
                    $job['errors'][] = basename((string)$name) . ': ' . $result->get_error_message();
                }
            } else {
                $job['imported']++;
                $job['segments'] += (int)$result;
            }
            $job['offset'] = $i + 1;
        }
        $zip->close();
        $done = $job['offset'] >= $job['total'];
        $response = array('done' => $done, 'processed' => $job['offset'], 'total' => $job['total'],
            'imported' => $job['imported'], 'errors_count' => $job['skipped'], 'segments' => $job['segments'],
            'errors' => $job['errors']);
        if ($done) {
            @unlink($path);
            delete_transient(self::job_key($id));
        } else {
            set_transient(self::job_key($id), $job, self::JOB_TTL);
        }
        wp_send_json_success($response);
    }
}
