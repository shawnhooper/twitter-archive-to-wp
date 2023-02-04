<?php

namespace ShawnHooper\BirdSiteArchive;
use \WP_CLI;
use WP_Query;

class Import_Twitter_Command {

	private string $post_type = 'birdsite_tweet';

	private string $data_dir = '';

	private \stdClass $account_data;

	private int $tweets_processed = 0;
	private int $tweets_skipped = 0;

	private array $media_files_by_tweet = [];

	private array $id_to_post_id_map = [];

	private int $post_author_id = 0;
	private bool $skip_replies = false;

	private string $base_upload_folder_url;

	/**
	 * Imports Twitter Data archive to WordPress
	 *
	 * ## OPTIONS
	 *
	 * <post_author>
	 * : The ID of the user to mark as the author of the tweets
	 *
	 * [--skip-replies]
	 * : Skip tweets that are replies to someone else
	 *
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp import-twitter 1
	 *
	 * @when after_wp_load
	 */
	public function __invoke($args, $assoc_args) : void {
		$this->post_author_id = (int)$args[0];
		$this->skip_replies = isset($assoc_args['skip-replies']);
		$upload_dir = wp_upload_dir();
		$this->base_upload_folder_url = $upload_dir['baseurl'];

		if ($this->post_author_id === 0) {
			WP_CLI::error('Error: invalid post author ID');
		}

		$this->data_dir = (wp_upload_dir())['basedir'] . '/twitter-archive';
		$files = $this->get_multipart_tweet_archive_filenames();

		do_action('birdsite_import_start');

		try {
			$this->account_data = $this->get_account_data_from_file();
		} catch (\Exception $e) {
			WP_CLI::error('Error while reading account.js: ' . $e->getMessage());
			die();
		}

        $this->load_media_files();

		foreach($files as $filename) {
			$this->process_file($filename);
		}

		do_action('birdsite_import_end');

	}

    private function load_media_files() {
        $filename_list = scandir($this->data_dir . '/tweets_media');

        // Add keys to the array so we can use isset() later
        foreach ($filename_list as $filename) {
            $file_tweet_id = explode('-', $filename)[0];

            if ( ! isset($this->media_files_by_tweet[$file_tweet_id]) ) {
                $this->media_files_by_tweet[$file_tweet_id] = [];
            }

            array_push($this->media_files_by_tweet[$file_tweet_id], $filename);
        }
    }

	private function process_file(string $filename): void
	{
		do_action('birdsite_import_start_of_file', $filename);

		$tweets = $this->get_filtered_result_from_file($filename);
		usort($tweets, static function ($a, $b) { return strnatcmp($a->id, $b->id); });


		$total_tweets = count($tweets);

		WP_CLI::line('Starting Import of ' . count($tweets) . ' tweets');

		foreach ($tweets as $tweet) {
			$this->tweets_processed++;

			WP_CLI::line("Processing Tweet $this->tweets_processed of $total_tweets (ID: {$tweet->id})");

			if ( $this->does_tweet_already_exist($tweet->id)) {
				WP_CLI::success("Tweet already imported, skipping.");
				$this->tweets_skipped++;
				continue;
			}

			if (
				isset($tweet->in_reply_to_status_id) &&
				$this->skip_replies &&
				! isset($this->id_to_post_id_map[$tweet->in_reply_to_status_id]))
			{
				WP_CLI::success("Skipping Reply Tweet");
				$this->tweets_skipped++;
				continue;
			}

			$tweet_text = $tweet->full_text;

			if (isset($tweet->entities->urls)) {
				foreach($tweet->entities->urls as $url) {
					$link_tag = "<a href=\"{$url->expanded_url}\">{$url->display_url}</a>";
					$tweet_text = str_replace($url->url, $link_tag, $tweet_text);
				}
			}

			if (
				isset($tweet->in_reply_to_status_id, $this->id_to_post_id_map[$tweet->in_reply_to_status_id]))
			{
				do_action('birdsite_import_before_each_comment', $tweet);

				$comment_id = wp_insert_comment([
					'comment_post_ID' => $this->id_to_post_id_map[$tweet->in_reply_to_status_id],
					'comment_author' => get_user_by('ID', $this->post_author_id)->display_name,
					'user_id' =>  $this->post_author_id,
					'comment_content' => apply_filters('birdsite_import_comment_tweet_text', $tweet_text, $tweet),
				]);

				add_comment_meta('_tweet_id', $tweet->id, $comment_id, true);

				update_post_meta($this->id_to_post_id_map[$tweet->in_reply_to_status_id], '_is_twitter_thread', '1');
				$this->id_to_post_id_map[$tweet->id] = $this->id_to_post_id_map[$tweet->in_reply_to_status_id];

				do_action('birdsite_import_after_each_comment', $comment_id, $tweet);

				continue;
			}

			$post_id = $this->process_tweet($tweet, $this->post_author_id);

            update_post_meta($post_id, '_tweet_id', $tweet->id);

			$this->id_to_post_id_map[$tweet->id] = $post_id;

			$this->set_postmeta($tweet, $post_id);
			$this->set_hashtags($tweet, $post_id);
			$this->set_ticker_symbols($tweet, $post_id);
			$this->process_media($tweet, $post_id);
		}

		do_action('birdsite_import_end_of_file', $filename);

		WP_CLI::success('Import Complete - ' . $this->tweets_processed . ' tweets processed, ' . $this->tweets_skipped . 'skipped');
	}

	/**
	 * @param \stdClass $tweet
	 * @param int $post_author
	 * @return int
	 */
	public function process_tweet(\stdClass $tweet, int $post_author) : int {
		$tweet = apply_filters('birdsite_import_tweet', $tweet);

		do_action('birdsite_import_before_each_tweet', $tweet);

		$created_at = \DateTime::createFromFormat('D M d H:i:s O Y', $tweet->created_at)->format('c');

		$tweet_text = $tweet->full_text;

		if (isset($tweet->entities->urls)) {
			foreach($tweet->entities->urls as $url) {
				$link_tag = "<a href=\"{$url->expanded_url}\">{$url->display_url}</a>";
				$tweet_text = str_replace($url->url, $link_tag, $tweet_text);
			}
		}

		$args = [
			'post_name' => $tweet->id,
			'post_author' => $post_author,
			'post_type' => $this->post_type,
			'post_status' => 'publish',
			'post_content' => apply_filters('birdsite_import_post_tweet_text', $tweet_text, $tweet),
			'post_date' => $created_at,
			'post_date_gmt' => $created_at,
		];

		$post = wp_insert_post($args);

		do_action('birdsite_import_after_each_tweet', $post);

		return $post;

	}

	/**
	 * @return array
	 */
	private function get_multipart_tweet_archive_filenames() : array {
		$files[] = $this->data_dir . '/tweets.js';
		$additional_parts = glob($this->data_dir . '/tweets-part*.js');

		return apply_filters('birdsite_import_files', array_merge($files, $additional_parts) );
	}

	/**
	 * @return \stdClass
	 * @throws \JsonException
	 */
	private function get_account_data_from_file() : \stdClass {
		$account_data_as_string = file_get_contents($this->data_dir . '/account.js');

		$account_data_as_string = str_replace('window.YTD.account.part0 = ', '', $account_data_as_string);

		$account_data = json_decode($account_data_as_string, false, 512, JSON_THROW_ON_ERROR);

		if (! is_array($account_data)) {
			WP_CLI::error('Data in account.js is not in the expected format');
		}

		return $account_data[0]->account;
	}

	/**
	 * @param string $filepath
	 * @return array
	 * @throws \JsonException
	 */
	private function get_filtered_result_from_file(string $filepath) : array {
		$data = file_get_contents($filepath);
		$tweets = substr($data, strpos($data, "["));

		try {
			$raw_tweets = json_decode($tweets, false, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			WP_CLI::error("Unable to decode the contents of {$filepath}: " . $e->getMessage());
			die();
		}

		$tweets = [];
		foreach ($raw_tweets as $raw_tweet) {
			$tweet = $raw_tweet;

			// Remove unused entities
			// reduce RAM requirements on large archives
			unset($tweet->tweet->edit_info);
			unset($tweet->tweet->source);
			unset($tweet->tweet->lang);
			unset($tweet->tweet->favorited);
			unset($tweet->tweet->in_reply_to_user_id);
			unset($tweet->tweet->display_text_range);
			unset($tweet->tweet->entities->user_mentions);
			unset($tweet->tweet->in_reply_to_screen_name);

			$tweets[] = $tweet->tweet;
		}

		return $tweets;
	}

	/**
	 * Make the original tweet URL
	 *
	 * @param \stdClass $tweet
	 * @return string
	 */
	private function make_tweet_url(\stdClass $tweet) : string {
		return apply_filters('birdsite_import_tweet_url', 'https://twitter.com/' . $this->account_data->username . '/status/' . $tweet->id, $tweet);
	}

	/**
	 * Set post meta fields for:
	 * - Number of Favorites
	 * - Number of Retweets
	 * - Reply to Tweet ID
	 * - The original Tweet URL
	 *
	 * @param \stdClass $tweet
	 * @param int $post_id
	 * @return void
	 */
	private function set_postmeta(\stdClass $tweet, int $post_id) : void {
		update_post_meta($post_id, '_retweet_count', $tweet->retweet_count );
		update_post_meta($post_id, '_favorite_count', $tweet->favorite_count );

		if (isset($tweet->in_reply_to_status_id_str)) {
			update_post_meta($post_id, '_in_reply_to_status_id_str', $tweet->in_reply_to_status_id_str );
		}

		// Tweets don't seem to have a Tweet URL in the data, so we need to make one.
		update_post_meta($post_id, '_tweet_url', $this->make_tweet_url($tweet));
	}

	/**
	 * Make taxonomy entries from each hashtag
	 *
	 * @param \stdClass $tweet
	 * @param int $post_id
	 * @return void
	 */
	private function set_hashtags(\stdClass $tweet, int $post_id): void
	{
		if (isset($tweet->entities->hashtags)) {
			$hashtags = [];
			foreach($tweet->entities->hashtags as $hashtag) {
				$hashtags[] = $hashtag->text;
			}

			$hashtags = apply_filters('birdsite_import_hashtags', $hashtags, $tweet);

			wp_set_post_terms($post_id, $hashtags, 'birdsite_hashtags', true);
		}
	}

	/**
	 * Make taxonomy entries out of each stock ticker symbol
	 *
	 * @param \stdClass $tweet
	 * @param int $post_id
	 * @return void
	 */
	private function set_ticker_symbols(\stdClass $tweet, int $post_id): void
	{
		if (isset($tweet->entities->symbols)) {
			$ticker_symbols = [];
			foreach($tweet->entities->symbols as $symbol) {
				$ticker_symbols[] = '$' . $symbol->text;
			}

			$ticker_symbols = apply_filters('birdsite_import_ticker_symbols', $ticker_symbols, $tweet);

			wp_set_post_terms($post_id, $ticker_symbols, 'birdsite_hashtags', true);
		}
	}

	/**
	 * Process an image or video contained in the tweet
	 *
	 * @param \stdClass $tweet
	 * @param int $post_id
	 * @return void
	 */
	private function process_media(\stdClass $tweet, int $post_id): void
	{
		if (isset($tweet->entities->media)) {
            $media_index = 0;

            // Get the post and the tweet media filenames we saved on initialisation
            $post = get_post($post_id);
            $tweet_media_filenames = $this->media_files_by_tweet[$tweet->id] ?? [];

            // The tweet contains a single short URL that represents the images
            // We will construct a string of HTML img tags to replace the URL
            $tweet_img_tags = '';

			foreach($tweet->extended_entities->media as $media) {
				$media = apply_filters('birdsite_import_media', $media, $tweet);

                // We will check that that files exists. For speed we cached all
                // the filenames in the media_files_by_tweet array on initialisation.
                // URLs are like http://pbs.twimg.com/media/<media-id>.jpg
                // But the file names in the export are like <tweet-id>-<media-id>.ext
                $media_id = array_slice(explode('/', $media->media_url), -1, 1)[0];
                $media_filename = $tweet->id . '-' . $media_id;

                if (! in_array($media_filename, $tweet_media_filenames)) {
                    WP_CLI::warning('Media file not found for tweet ' . $tweet->id);
                    continue;
                }

                WP_CLI::success('Found Media (' . $media->type . '): ' . $media_filename);
                update_post_meta($post_id, '_tweet_media_' . $media_index, $media_filename);
                update_post_meta($post_id, '_tweet_media_type_' . $media_index, $media->type);

                $post->filter = true;
                $media_url = esc_attr($this->base_upload_folder_url . "/twitter-archive/tweets_media/{$media_filename}");

                // Add to the Tweet image tags
                $tweet_img_tags .= apply_filters('birdsite_import_img_tag', "<img src=\"$media_url\" />", $media, $tweet);

                $media_index++;
			}

            $post->post_content = str_replace($tweet->entities->media[0]->url, apply_filters( 'birdsite_import_img_tags', $tweet_img_tags, $tweet ), $post->post_content);

            wp_update_post($post);

            do_action('birdsite_import_media_imported', $media, $post);
		}
	}

	/**
	 * Checks whether a post or comment was already created from this tweet.
	 * Allows you to run the importer multiple times without creating duplicates.
	 *
	 * @param string $tweet_id
	 * @return bool
	 */
	private function does_tweet_already_exist(string $tweet_id) : bool
	{
		$post = $this->get_post_by_name($tweet_id);

		if ($post) {
			return true;
		}

		$comment_count = get_comments([
			'count' => true,
			'meta_key' => '_tweet_id',
			'meta_value' => $tweet_id,
			'post_type' => $this->post_type,
		]);

		return $comment_count > 0;
	}

	/**
	 * Find a post by the post_name field
	 *
	 * @param string $name
	 * @return bool
	 */
	private function get_post_by_name(string $name): bool
	{
		$query = new WP_Query([
			"post_type" => $this->post_type,
			"name" => $name
		]);

		return $query->have_posts();
	}
}
