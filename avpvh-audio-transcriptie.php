<?php
/**
 * Plugin Name:       AVPVH Audio Transcriptie
 * Plugin URI:        https://github.com/grmt/avpvh-audio-transcriptie
 * Description:       Progressieve audiotranscriptie met een editor, revisiehistorie en optionele samenvattingen.
 * Version:           0.5.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            AVPVH
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       avpvh-audio-transcriptie
 * Domain Path:       /languages
 *
 * @package AVPVH_Audio_Transcriptie
 */

defined( 'ABSPATH' ) || exit;

define( 'AVPVH_AT_VERSION', '0.5.0' );
define( 'AVPVH_AT_FILE', __FILE__ );
define( 'AVPVH_AT_DIR', plugin_dir_path( __FILE__ ) );
define( 'AVPVH_AT_URL', plugin_dir_url( __FILE__ ) );

require_once AVPVH_AT_DIR . 'includes/class-avpvh-at-plugin.php';

register_activation_hook( AVPVH_AT_FILE, array( 'AVPVH_AT_Plugin', 'activate' ) );
add_action( 'plugins_loaded', array( 'AVPVH_AT_Plugin', 'init' ) );
