<?php
if (!defined('ABSPATH')) exit;
/** Sitemapa přepisů kázání: /wp-sitemap-kazani-1.xml. Načítá se jen při generování sitemapy. */
final class BSW_Sitemap extends WP_Sitemaps_Provider {
    const PER_PAGE = 2000;

    public function __construct() {
        $this->name = 'kazani';
        $this->object_type = 'kazani';
    }

    private static function where() {
        return "caption_status = 'done' AND " . BSW_DB::PUBLIC_SQL;
    }

    public function get_url_list($page_num, $object_subtype = '') {
        global $wpdb;
        $page = BSW_Transcript::page_id();
        if (!$page) return array();
        $base = get_permalink($page);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT video_id, updated_at FROM ' . BSW_DB::sermons() . ' WHERE ' . self::where() . ' ORDER BY id LIMIT %d OFFSET %d',
            self::PER_PAGE, max(0, ((int) $page_num - 1) * self::PER_PAGE)));
        $out = array();
        if ((int) $page_num === 1) foreach (BSW_Topics::questions() as $q) $out[] = array('loc' => BSW_Topics::url($q['slug'], $base));
        foreach ($rows as $r) {
            $item = array('loc' => BSW_Transcript::url($r->video_id, '', 0, $base));
            if ($r->updated_at) $item['lastmod'] = gmdate('Y-m-d\TH:i:s+00:00', strtotime($r->updated_at . ' UTC'));
            $out[] = $item;
        }
        return $out;
    }

    public function get_max_num_pages($object_subtype = '') {
        global $wpdb;
        if (!BSW_Transcript::page_id()) return 0;
        return (int) ceil((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . BSW_DB::sermons() . ' WHERE ' . self::where()) / self::PER_PAGE);
    }
}
