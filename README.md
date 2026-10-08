# Bluedon Queue Social Autoposter 

Contributors: bradsalomons

Tested up to: 7.1

Stable tag: 1.11.0

License: GNU General Public License v2 or later http://www.gnu.org/licenses/gpl-2.0.html

Bluesky & Mastodon posting buffer and feed auto-queue plugin for Wordpress, with an optional hand-off to Buffer.com for everything else (X/Twitter, LinkedIn, Instagram, Threads, etc.)

Use at your own risk.

**How to Install:**

Zip the files, keeping the file/folder structure, upload as a plugin and activate. Voila!

Add and save app credentials from both Bluesky, Mastodon & Buffer in the settings page to get started. (Remember to save before testing!)

**What It Can Do:**

Supports direct API connection to Bluesky & Mastadon for direct posting from your own site.

Supports connection to Buffer for third party queuing to multiple other platforms.

Auto-queues posts on publish or scheduled publish. Or you can manually import old posts into the same editable queue. The queue will fire/post based on a wp-cron to post simultaneously to authenticated social networks (as per the settings.)

You can set up a randomizer that will randomly post from the queue at a possibility percentage each time the 5 minute cron is activated (ie after 1 hour, a post has a x% chance of being sent at each 5 minute cron increment). 

Randomly revive old posts based on variables, date-windows, and frequencies, adding older content to the posting queue. Individual posts can be flagged "do not revive."

Automatically format posts, include hashtags, and generate hashtags from metadata (tags/categories) in the posts. 

Define multiple post templates that then will be used randomly to format the auto-generation of posts for the queue, using {title}, {excerpt}, {blurb}, and {url}. Add queue-specific metadata "blurbs" are an extra text field available to each post for generating social-friendly descriptors.

Schedule any other kind of free-form manually written social media posts (written using the queue page editor) that can include text, a link, a published post, or an image to be embedded into the post and delievered at a scheduled date and time. Ie. You can also use it like a posting buffer/scheduler for general posting to your feed.

Bulk import posts into the queue from a CSV or JSON file (or pasted text) with columns for post_text, link_url, image_url, scheduled_at, and blog_post_id. Preview and validate every row before importing; rows with just a blog_post_id get their text from your post templates, and duplicates of already-queued posts are skipped.

Includes a handy metadata checklist report page to see which of your posts are optimized for social sharing.

Stores a robust activity log and you can set an the archive window for retaining posting history (default 30 days).

**Other Info:**

This is free to use for non-commercial projects. Fork it and improve it if you'd like. 

