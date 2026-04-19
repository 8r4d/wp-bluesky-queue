This plugin is pretty rough around the edges and I've left some of debugging output on but I got it to work on multiple Wordpress 6.9 sites. 

Zip the files, keeping the structure, upload as a plugin and activate. Voila!

Add and save app credentials from both Bluesky and Mastodon in the settings page to get started.

You can import old posts into a queue that will fire based on a wp-cron to post simultaneously to both social networks as per the settings or set up a randomizer that will randomly post from the queue at a possibility percentage each time the 5 minute cron is activated (ie after 1 hour, a post has a x% chance of being sent at each 5 minute cron increment). 

You can schedule manual posts written using the queue page.

Feel free to use this for non-commercial projects, fork it and improve it. It was mostly vibe-coded using Claude because I didn't have the time and energy to spend too much time making this, so I'm not claiming too much credit or offering any support whatsoever.
