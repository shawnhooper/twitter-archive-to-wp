<?php

namespace ShawnHooper\BirdSiteArchive;
use \WP_CLI;

class Tweet_Post_Type {

	protected ?bool $has_tweets = null;

	public function wordpress_hooks() {
		if ( ! $this->setHasTweets()){
			return;
		}
		add_action( 'init', [ $this, 'create_twitter_post_type' ] );
		add_filter( 'init', [$this, 'create_hashtag_taxonomy']);
		add_filter( 'manage_birdsite_tweet_posts_columns', [$this, 'set_custom_columns'] );
		add_filter( 'manage_birdsite_tweet_posts_custom_column', [$this, 'populate_tweet_columns'], 10, 2 );
	}

	public function create_hashtag_taxonomy(): void
	{

		// Add new taxonomy, NOT hierarchical (like tags)
		$labels = array(
			'name' => _x( 'Hashtags', 'taxonomy general name' ),
			'singular_name' => _x( 'Hashtag', 'taxonomy singular name' ),
			'search_items' =>  __( 'Search Hashtags' ),
			'popular_items' => __( 'Popular Hashtags' ),
			'all_items' => __( 'All Hashtags' ),
			'parent_item' => null,
			'parent_item_colon' => null,
			'edit_item' => __( 'Edit Hashtag' ),
			'update_item' => __( 'Update Hashtag' ),
			'add_new_item' => __( 'Add New Hashtag' ),
			'new_item_name' => __( 'New Hashtag Name' ),
			'separate_items_with_commas' => __( 'Separate tags with commas' ),
			'add_or_remove_items' => __( 'Add or remove tags' ),
			'choose_from_most_used' => __( 'Choose from the most used tags' ),
			'menu_name' => __( 'Hashtags' ),
		);

		register_taxonomy('birdsite_hashtags','birdsite_tweet',array(
			'hierarchical' => false,
			'labels' => $labels,
			'show_ui' => true,
			'query_var' => true,
			'rewrite' => array( 'slug' => 'birdsite_hashtags' ),
			'show_in_rest' => true, // add support for Gutenberg editor
		));
	}

	public function create_twitter_post_type(): void
	{
		register_post_type( 'birdsite_tweet',
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
				'taxonomies' => ['birdsite_hashtags']
			)
		);
	}

	public function set_custom_columns(array $columns) : array
	{
		unset($columns['title']);
		$columns['title'] = __( 'Tweet', 'birdsite_archive' );
		$columns['favorite_count'] = __( 'Likes', 'birdsite_archive' );
		$columns['retweet_count'] = __( 'Retweets', 'birdsite_archive' );
		$columns['has_media'] = __( 'Has Media', 'birdsite_archive' );
		$columns['is_thread'] = __( 'Is Thread', 'birdsite_archive' );

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
			case 'has_media' :
				echo get_post_meta($post_id, '_tweet_media_type', true);
				break;
			case 'is_thread' :
				if( get_post_meta($post_id, '_is_twitter_thread', true) ) {
					echo 'Yes (' . get_comment_count($post_id)['all'] .')';
				}
				break;
		}
	}

	private function setHasTweets() : bool {
		if ( null !== $this->has_tweets) {
			return $this->has_tweets;
		}

		global $wpdb;
		$count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_type='birdsite_tweet'");

		if ( $count > 0 ) {
			$this->has_tweets = true;
			return true;
		}

		$this->has_tweets = false;
		return false;
	}


}
