<?php
/**
 * @file include/opengraph.php
 * @brief Add Opengraph metadata and related functions.
 */
 
 
 /**
 * @brief Adds Opengraph meta tags into HTML head
 *
 * @param array $item
 * @param array $profile
 *
 */
 
 function opengraph_add_meta($item, $profile) {

	if(! empty($item)) {

		if(! empty($item['title']))
			$ogtitle = $item['title'];

		// find first image if exist
		if(preg_match("/\[[zi]mg(=[0-9]+x[0-9]+)?\]([^\[]+)/is", $item['body'], $matches))
			$ogimage = $matches[2];

		// use summary as description if exist
		$ogdesc = (empty($item['summary']) ? $item['body'] : $item['summary'] );
		
		$ogdesc = str_replace("#^[", "[", $ogdesc);
	
		$ogdesc = bbcode($ogdesc, [ 'tryoembed' => false ]);
		$ogdesc = trim(html2plain($ogdesc, 0, true));
		$ogdesc = html_entity_decode($ogdesc, ENT_QUOTES, 'UTF-8');
		
		// remove all URLs
		$ogdesc = preg_replace("/https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,\@]+/", "", $ogdesc);
		
		// shorten description
		$ogdesc = substr($ogdesc, 0, 300);
		$ogdesc = str_replace("\n", " ", $ogdesc);
		while (strpos($ogdesc, "  ") !== false)
			$ogdesc = str_replace("  ", " ", $ogdesc);
		$ogdesc = rtrim(substr($ogdesc, 0, strrpos($ogdesc, " ")), "?.,:;!-") . "...";
		
		$ogtype = "article";
	}

	$channel = channelx_by_n($profile['profile_uid']);

	if(! isset($ogdesc)) {
		if($profile['about'] && perm_is_allowed($channel['channel_id'],get_observer_hash(),'view_profile')) {
			$ogdesc = $profile['about'];
		}
		else {
			$ogdesc = sprintf( t('This is the home page of %s.'), $channel['channel_name']);
		}
	}
	
	if(! isset($ogimage))
		$ogimage = $channel['xchan_photo_l'];

	App::$page['htmlhead'] .= '<meta property="og:title" content="' . htmlspecialchars((isset($ogtitle) ? $ogtitle : $channel['channel_name'])) . '">' . "\r\n";
	App::$page['htmlhead'] .= '<meta property="og:image" content="' . $ogimage . '">' . "\r\n";
	App::$page['htmlhead'] .= '<meta property="og:image:type" content="' . guess_image_type($ogimage) . '">' . "\r\n";
	App::$page['htmlhead'] .= '<meta property="og:description" content="' . htmlspecialchars($ogdesc) . '">' . 	"\r\n";
	App::$page['htmlhead'] .= '<meta property="og:type" content="' . (isset($ogtype) ? $ogtype : "profile") . '">' . "\r\n";

	return true;
 }
 