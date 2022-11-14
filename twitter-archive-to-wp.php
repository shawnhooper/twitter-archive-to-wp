<?php
/**
 * Plugin Name:     Import Twitter Data Archive
 * Plugin URI:      https://github.com/shawnhooper/twitter-archive-to-wp
 * Description:     Imports Twitter Archive as a custom post type
 * Author:          Shawn M. Hooper
 * Author URI:      https://shawnhooper.ca/
 * Text Domain:     birdsite-archive
 * Domain Path:     /languages
 * Version:         1.1.0
 *
 * @package         Birdsite_Archive
 */

namespace ShawnHooper\BirdSiteArchive;

use WP_CLI;

class BirdSiteArchive {

	private ?bool $has_tweets = null;

	/**
	 * Hook this plugin into WordPress' actions & filters
	 */
	public function hooks() : void {
		if (defined('WP_CLI')) {
			include_once('classes/import-twitter-command.php');
			$cli_command = new Import_Twitter_Command();
			WP_CLI::add_command( 'import-twitter', $cli_command );
		}

		/** Register hooks for the Tweet Post Type */
		include_once('classes/tweet-post-type.php');
		$tweet_post_type = new Tweet_Post_Type();
		$tweet_post_type->wordpress_hooks();
	}

}

$_GLOBALS['BirdSiteArchive'] = new BirdSiteArchive();
$_GLOBALS['BirdSiteArchive']->hooks();
