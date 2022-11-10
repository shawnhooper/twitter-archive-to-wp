<?php
/**
 * Plugin Name:     Archive Twitter to WP
 * Plugin URI:      PLUGIN SITE HERE
 * Description:     X
 * Author:          Shawn M. Hooper
 * Author URI:      https://shawnhooper.ca/
 * Text Domain:     birdsite-archive
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Birdsite_Archive
 */

namespace ShawnHooper\BirdSiteArchive;

use DateTime;
use JsonException;
use stdClass;
use WP_CLI;
use const ABSPATH;

class BirdSiteArchive {

	private int $tweets_processed = 0;

	/**
	 * Hook this plugin into WordPress' actions & filters
	 */
	public function hooks() : void {
		add_action( 'cli_init', [ $this, 'register_cli_commands' ] );
		add_action( 'init', [ $this, 'create_twitter_post_type' ] );
		add_filter( 'manage_tweet_posts_columns', [$this, 'set_custom_columns'] );
		add_filter( 'manage_tweet_posts_custom_column', [$this, 'populate_tweet_columns'], 10, 2 );
	}

	public function register_cli_commands() : void {
		WP_CLI::add_command( 'import-twitter', [$this, 'handleCommand']);
	}

	public function create_twitter_post_type() {
		register_post_type( 'tweet',
			// CPT Options
			array(
				'labels' => array(
					'name' => __( 'Tweets' ),
					'singular_name' => __( 'Tweet' )
				),
				'public' => true,
				'has_archive' => true,
				'rewrite' => array('slug' => 'tweets'),
				'show_in_rest' => true,

			)
		);
	}

	/**
	 * @throws JsonException
	 */
	public function handleCommand() : void  {

		$tweets = $this->get_tweet_array();
		$total_tweets = count($tweets);

		WP_CLI::line('Starting Import of ' . count($tweets) . ' tweets');

		foreach($tweets as $tweet) {
			$this->process_tweet($tweet, $total_tweets);
		}

	}

	/**
	 * @throws JsonException
	 */
	public function get_tweet_array() {
		$tweets = file_get_contents(ABSPATH . 'wp-content/twitter-archive/tweets.js');
		$tweets = str_replace("window.YTD.tweets.part0 = ", '', $tweets);
		return json_decode($tweets, false, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @throws JsonException
	 */
	public function process_tweet(stdClass $tweet, int $total_tweets) : int {
		$this->tweets_processed++;
		WP_CLI::line("Processing Tweet $this->tweets_processed of $total_tweets");

		$created_at = DateTime::createFromFormat('D M d H:i:s O Y', $tweet->tweet->created_at)->format('c');

		$args = [
			'post_type' => 'tweet',
			'post_status' => 'publish',
			'post_title' => $tweet->tweet->full_text,
			'post_content' => json_encode($tweet, JSON_THROW_ON_ERROR),
			'post_date' => $created_at,
			'post_date_gmt' => $created_at,
		];

		$post_id = wp_insert_post($args);

		update_post_meta($post_id, '_retweet_count', $tweet->tweet->retweet_count );
		update_post_meta($post_id, '_favorite_count', $tweet->tweet->favorite_count );

		return $post_id;

	}

	public function set_custom_columns(array $columns) {
		unset($columns['title']);
		$columns['title'] = __( 'Tweet', 'birdsite_archive' );
		$columns['favorite_count'] = __( 'Likes', 'birdsite_archive' );
		$columns['retweet_count'] = __( 'Retweets', 'birdsite_archive' );

		return $columns;
	}

	public function populate_tweet_columns(string $column, int $post_id): void
	{
		switch ( $column ) {
			case 'retweet_count' :
				echo get_post_meta($post_id, '_retweet_count', true);
				break;
			case 'favorite_count' :
				echo get_post_meta($post_id, '_favorite_count', true);
				break;
		}
	}


}

$_GLOBALS['BirdSiteArchive'] = new BirdSiteArchive();
$_GLOBALS['BirdSiteArchive']->hooks();
