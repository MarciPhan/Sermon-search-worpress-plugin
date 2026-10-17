<?php
/**
 * Plugin Name: Bible Search – WEDOS & YouTube
 * Description: Vyhledávání slov a biblických odkazů v kázáních, propojení vlastního YouTube kanálu a import českých titulků. Používá databázi WordPressu.
 * Version: 3.5.11
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: biblesearch-wedos
 */
if (!defined('ABSPATH')) { exit; }
define('BSW_VERSION', '3.5.11');
define('BSW_DIR', plugin_dir_path(__FILE__));
define('BSW_URL', plugin_dir_url(__FILE__));
require_once BSW_DIR . 'includes/class-bsw-bible.php';
require_once BSW_DIR . 'includes/class-bsw-stem.php';
require_once BSW_DIR . 'includes/class-bsw-db.php';
require_once BSW_DIR . 'includes/class-bsw-analysis.php';
require_once BSW_DIR . 'includes/class-bsw-topics.php';
require_once BSW_DIR . 'includes/class-bsw-legacy.php';
require_once BSW_DIR . 'includes/class-bsw-youtube.php';
require_once BSW_DIR . 'includes/class-bsw-search.php';
require_once BSW_DIR . 'includes/class-bsw-transcript.php';
require_once BSW_DIR . 'includes/class-bsw-admin.php';
require_once BSW_DIR . 'includes/class-bsw-bulk.php';
require_once BSW_DIR . 'includes/class-bsw-auto.php';

register_activation_hook(__FILE__, function () {
    BSW_DB::install();
    BSW_Auto::ensure_schedule();
    if (!wp_next_scheduled('bsw_sync_tick')) wp_schedule_event(time() + 300, 'hourly', 'bsw_sync_tick');
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('bsw_sync_tick');
    BSW_Auto::unschedule();
});
add_action('init', function () {
    if (get_option('bsw_db_version') !== BSW_VERSION) BSW_DB::install();
    if (!wp_next_scheduled('bsw_sync_tick')) wp_schedule_event(time() + 300, 'hourly', 'bsw_sync_tick');
});
add_action('bsw_sync_tick', array('BSW_YouTube', 'sync_tick'));
add_action('bsw_captions_tick', function () { BSW_DB::index_step(20); BSW_Analysis::step(8); BSW_Auto::recheck_hidden('wp-cron 10 min', 40, 25); BSW_Auto::worker('wp-cron 10 min'); });
add_shortcode('kazani_search', array('BSW_Search', 'render'));
add_shortcode('biblesearch', array('BSW_Search', 'render'));
function bsw_enqueue_assets() {
    // Šablona Baptisté Jablonec už Montserrat a Outfit načítá – nestahovat je podruhé.
    $deps = array();
    if (get_template() !== 'baptiste-jablonec-theme') {
        wp_enqueue_style('bsw-fonts', 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Outfit:wght@300;400;600&display=swap', array(), null);
        $deps[] = 'bsw-fonts';
    }
    // Styly vkládáme přímo do stránky: web odstraňuje „?ver=“ z adres a soubory drží 3 dny v cache,
    // takže by si prohlížeče po aktualizaci pluginu nechaly starý vzhled.
    if (!wp_style_is('bsw-search', 'registered')) {
        wp_register_style('bsw-search', false, $deps, BSW_VERSION);
        wp_add_inline_style('bsw-search', bsw_inline_css());
    }
    wp_enqueue_style('bsw-search');
}
function bsw_inline_css() {
    static $css = null;
    if ($css === null) {
        $css = (string) @file_get_contents(BSW_DIR . 'assets/search.css');
        $css = preg_replace('~/\*.*?\*/~s', '', $css);          // bez komentářů
        $css = trim(preg_replace('/\s+/', ' ', $css));           // na jeden řádek
    }
    return $css;
}
// Styly do <head>, když je shortcode přímo v obsahu; jinak je přidá samotný shortcode (šablona, builder, widget).
add_action('wp_enqueue_scripts', function () {
    global $post;
    if (is_singular() && $post && (has_shortcode($post->post_content, 'kazani_search') || has_shortcode($post->post_content, 'biblesearch'))) bsw_enqueue_assets();
});
BSW_Admin::init();
BSW_Transcript::init();
BSW_Topics::init();
BSW_Bulk::init();
BSW_Auto::init();
