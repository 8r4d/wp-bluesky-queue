# Bluedon Queue Social Autoposter 

Contributors: bradsalomons

Tested up to: 7.1

Stable tag: 1.8.0

License: GNU General Public License v2 or later http://www.gnu.org/licenses/gpl-2.0.html

Bluesky & Mastodon posting buffer and feed auto-queue plugin for Wordpress.

This plugin remains pretty rough around the edges, but I think it might be useful for others.  I've left some of debugging output in the settings page, on but I've got it to work on multiple Wordpress 7.1 sites. Note: It is not a certified nor an official plugin. Use at your own risk.

**How to Install:**

Zip the files, keeping the file/folder structure, upload as a plugin and activate. Voila!

Add and save app credentials from both Bluesky and Mastodon in the settings page to get started. (Remember to save before testing!)

**What It Can Do:**

Import old posts into a queue that will fire based on a wp-cron to post simultaneously to both social networks as per the settings or set up a randomizer that will randomly post from the queue at a possibility percentage each time the 5 minute cron is activated (ie after 1 hour, a post has a x% chance of being sent at each 5 minute cron increment). 

Format posts, include hashtags, and automatically generate hashtags from metadata in the posts. You can define multiple post templates that then will be used randomly to format the auto-generation of posts for the queue.

Schedule manually written posts written using the queue page that include text, a link, a published post, or an image to be embedded into the post and delievered at a scheduled date and time. Ie. Use it like a posting buffer/scheduler for general posting to your feed.

Randomly revive old posts based on variables, date-windows, and frequencies, adding older content to the posting queue.

Set an the archive window for retaining posting history (default 30 days).

**Other Info:**

This is free to use for non-commercial projects. Fork it and improve it if you'd like. 

It was mostly vibe-coded using Claude because I didn't have the time and energy to spend too much time making this, so I'm not claiming too much credit or offering any support whatsoever.
