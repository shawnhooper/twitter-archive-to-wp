=== Archive Twitter to WP ===
Contributors: shooper
Donate link: https://www.paypal.com/paypalme/shawnhooperwp
Tags: twitter, archive, wp-cli, import
Requires at least: 6.0.0
Tested up to: 6.1
Requires PHP: 7.4.0
Stable tag: 1.3.0
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

* tweets.js
* tweets_media/*
* account.js

This plugin doesn't need more than that at the moment.

== Installation ==

1. Install and Activate this plugin
1. Unzip your Twitter Archive
1. Copy the data/ folder from the Twitter archive to the wp-contents/uploads/twitter-archive folder. (read privacy note above)
1. Run `wp import-twitter <post_author>` where <post_author> is your WordPress User ID

== Actions ==

* birdsite_import_start -- Before the import processing starts
* birdsite_import_start_of_file -- When each of the tweets.js, tweets-part0.js, etc. file begins processing
* birdsite_import_before_each_tweet -- Before each tweet is imported
* birdsite_import_before_each_comment -- Before processing a tweet that's part of a thread (will be added as a comment)
* birdsite_import_after_each_tweet -- Before each tweet is imported
* birdsite_import_after_each_comment -- After a comment is added to a tweet (threads)
* birdsite_import_media_imported -- After each media file has been added to the imported tweets
* birdsite_import_end_of_file -- When the end of each of the tweets.js, tweets-part0.js, etc. files are reached
* birdsite_import_end -- The import of all files has completed

== Filters ==

* birdsite_import_tweet -- The contents of each tweet before its processing begins (stdClass object)
* birdsite_import_files -- The list of files containing tweets to be imported
* birdsite_import_post_tweet_text -- The contents to be added to the post_content field of a tweet post
* birdsite_import_comment_tweet_text -- The contents to be added to a comment
* birdsite_import_tweet_url -- The original tweet URL
* birdsite_import_hashtags -- The list of hashtags to be imported for a single tweet
* birdsite_import_ticker_symbols -- The list of hashtags to be imported for a single tweet
* birdsite_import_media -- A single media (video or image) being imported from the archive
* birdsite_import_img_tag -- The <img> tag added the post_content to embed image
*

== Starting Over ==

If you need to start over, the these WP-CLI commadns to delete the data the plugin added:

1. wp term list birdsite_hashtags --field=term_id | xargs wp term delete birdsite_hashtags
1. wp post delete $(wp post list --post_type='birdsite_tweet' --format=ids) --force

== Screenshots ==

1. Tweets created as posts

== Thank you ==

Thank you to:

* Jann Gobble (https://github.com/janngobble) for helping me test the multipart Twitter Archives
* Alex Standiford (https://github.com/alexstandiford) and Tim Lyttle (https://github.com/timnolte) for a late night Zoom call and strategy session and this stuff.
* Ross Wintle (https://github.com/rosswintle) for a large amount of the refactoring into the 2.0.0 release.

== Changelog ==

= 2.0.0 =
* Save tweet into post_content instead of post_title (allows for HTML links)
* Save the original tweet URL as _tweet_url postmeta
* Uses the wp_upload_dir() function to get uploads folder, instead of being hardcoded
* Wrap links with <a> tags.
* Skip tweet if it has already been imported

= 1.3.0 =
* Handle Multipart Archives (tweets.js, tweets-part1.js, tweets-part2.js, etc.)
* Remove unused entities during array processing to reduce RAM requirements
* Import Stock Symbols
* Redisplayed the UI when no tweets imported. Seems to affect hashtag importer.

= 1.2.1 =
* If media is not found, throw a warning instead of an error

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
