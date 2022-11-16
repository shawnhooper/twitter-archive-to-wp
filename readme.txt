=== Archive Twitter to WP ===
Contributors: shooper
Donate link: https://www.paypal.com/paypalme/shawnhooperwp
Tags: twitter, archive, wp-cli, import
Requires at least: 6.0.0
Tested up to: 6.1
Requires PHP: 7.4.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Imports a Twitter archive into WordPress using WP-CLI.

== Description ==

This plugin contains a custom WP-CLI command to import a Twitter data
archive into WordPress.

* Imports each of your tweets into a new Custom Post Type
* Creates a new taxonomy for hashtags, populated from each tweet
* Expands t.co short URLs to their full version

== Privacy Note ==

Your Twitter archive contains some data you might not want accessible by someone who knows
you've uploaded it your site.  It is highly recommended before uploading the data folder that
you delete all files except for:

* tweets.json
* tweets_media/*

This plugin doesn't need more than that at the moment.

== Installation ==

1. Install and Activate this plugin
1. Unzip your Twitter Archive
1. Copy the data/ folder from the Twitter archive to the wp-contents/uploads/twitter-archive folder. (read privacy note above)
1. Run `wp import-twitter --author=<post_author>` where <post_author> is your WordPress User ID

== Starting Over ==

If you need to start over, the these WP-CLI commadns to delete the data the plugin added:

1. wp term list birdsite_hashtag --field=term_id | xargs wp term delete birdsite_hashtag
1. wp post delete $(wp post list --post_type='birdsite_tweet' --format=ids) --force

== Screenshots ==

1. Tweets created as posts

== Changelog ==

= 1.2.0 =
* Import threads as post comments

= 1.1.1 =
* Fix: import tweets in the order they were tweeted

= 1.1.0 =
* Ability to skip your replies to others from import using --slip-replies
* Moved WP-CLI command into it's own class
* Hide UI for Tweets if no import has been done

= 1.0.0 =
* Creates custom post type and taxonomy
* Imports tweet and sets hashtags
* Expands t.co URLs
