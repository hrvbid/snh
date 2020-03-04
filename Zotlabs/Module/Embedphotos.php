<?php

namespace Zotlabs\Module;

/**
 * @brief Embedphoto endpoint.
 *
 * Provide an AJAX endpoint to fill the embedPhotoModal with folders and photos
 * selection.
 */
class Embedphotos extends \Zotlabs\Web\Controller {

	function get() {

	}

	/**
	 * @brief This is the POST destination for the embedphotos button.
	 *
	 * @return string A JSON string.
	 */
	public function post() {
		if (argc() > 1 && argv(1) === 'album') {
			// API: /embedphotos/album
			$name = (x($_POST, 'name') ? $_POST['name'] : null );
			if (!$name) {
				json_return_and_die(array('errormsg' => 'Error retrieving album', 'status' => false));
			}
			$album = $this->embedphotos_widget_album(array('channel' => \App::get_channel(), 'album' => $name));
			json_return_and_die(array('status' => true, 'content' => $album));
		}
		if (argc() > 1 && argv(1) === 'albumlist') {
			// API: /embedphotos/albumlist
			$album_list = $this->embedphotos_album_list();
			json_return_and_die(array('status' => true, 'albumlist' => $album_list));
		}
		if (argc() > 1 && argv(1) === 'photolink') {
			// API: /embedphotos/photolink
			$href = (x($_POST, 'href') ? $_POST['href'] : null );
			if (!$href) {
				json_return_and_die(array('errormsg' => 'Error retrieving link ' . $href, 'status' => false));
			}
			$resource_id = array_pop(explode('/', $href));
			$x = self::photolink($resource_id);
			if($x) 
				json_return_and_die(array('status' => true, 'photolink' => $x, 'resource_id' => $resource_id));
			json_return_and_die(array('errormsg' => 'Error retrieving resource ' . $resource_id, 'status' => false));
		}
	}


	protected static function photolink($resource) {
		$channel = \App::get_channel();
		$output = EMPTY_STR;
		if($channel) {
			$resolution = ((feature_enabled($channel['channel_id'],'large_photos')) ? 1 : 2);
			$r = q("select mimetype, height, width from photo where resource_id = '%s' and $resolution = %d and uid = %d limit 1",
				dbesc($resource),
				intval($resolution),
				intval($channel['channel_id'])
			);
			if(! $r)
				return $output;

			if($r[0]['mimetype'] === 'image/jpeg')
				$ext = '.jpg';
			elseif($r[0]['mimetype'] === 'image/png')
				$ext = '.png';
			elseif($r[0]['mimetype'] === 'image/gif')
				$ext = '.gif';
			elseif($r[0]['mimetype'] === 'image/webp')
				$exp = '.webp';
			else
				$ext = EMPTY_STR;

			$output = '[zrl=' . z_root() . '/photos/' . $channel['channel_address'] . '/image/' . $resource . ']' .
				'[zmg=' . $r[0]['width'] . 'x' . $r[0]['height'] . ']' . z_root() . '/photo/' . $resource . '-' . $resolution .  $ext . '[/zmg][/zrl]';

			return $output;
		}
	}


	/**
	 * @brief Get photos from an album.
	 *
	 * @see \\Zotlabs\\Widget\\Album::widget()
	 *
	 * @param array $args associative array with
	 *  * \e array \b channel
	 *  * \e string \b album
	 * @return string with HTML code from 'photo_album.tpl'
	 */
	protected function embedphotos_widget_album($args) {
		$channel_id = 0;

		if (array_key_exists('channel', $args)) {
			$channel = $args['channel'];
			$channel_id = intval($channel['channel_id']);
		}
		if (! $channel_id)
			$channel_id = \App::$profile_uid;
		if (! $channel_id)
			return '';

		require_once('include/security.php');
		$sql_extra = permissions_sql($channel_id);

		if (! perm_is_allowed($channel_id, get_observer_hash(), 'view_storage'))
			return '';

		if (isset($args['album']))
			$album = (($args['album'] === '/') ? '' : $args['album']);
		if (isset($args['title']))
			$title = $args['title'];

		/**
		 * @note This may return incorrect permissions if you have multiple directories of the same name.
		 * It is a limitation of the photo table using a name for a photo album instead of a folder hash
		 */
		if ($album) {
			require_once('include/attach.php');
			$x = q("select hash from attach where filename = '%s' and uid = %d limit 1",
				dbesc($album),
				intval($channel_id)
			);
			if ($x) {
				$y = attach_can_view_folder($channel_id, get_observer_hash(), $x[0]['hash']);
				if (! $y)
					return '';
			}
		}

		$order = 'DESC';

		$r = q("SELECT p.resource_id, p.id, p.filename, p.mimetype, p.imgscale, p.description, p.created FROM photo p INNER JOIN
				(SELECT resource_id, max(imgscale) imgscale FROM photo WHERE uid = %d AND album = '%s' AND imgscale <= 4 AND photo_usage IN ( %d, %d ) $sql_extra GROUP BY resource_id) ph
				ON (p.resource_id = ph.resource_id AND p.imgscale = ph.imgscale)
				ORDER BY created $order",
				intval($channel_id),
				dbesc($album),
				intval(PHOTO_NORMAL),
				intval(PHOTO_PROFILE)
		);

		$photos = [];
		if (count($r)) {
			$twist = 'rotright';
			foreach ($r as $rr) {
				if ($twist == 'rotright')
					$twist = 'rotleft';
				else
					$twist = 'rotright';

				$ph = photo_factory('');
				$phototypes = $ph->supportedTypes();

				$ext = $phototypes[$rr['mimetype']];

				$imgalt_e = $rr['filename'];
				$desc_e = $rr['description'];

				$imagelink = (z_root() . '/photos/' . $channel['channel_address'] . '/image/' . $rr['resource_id']
					. (($_GET['order'] === 'posted') ? '?f=&order=posted' : ''));

				$photos[] = [
						'id' => $rr['id'],
						'twist' => ' ' . $twist . rand(2,4),
						'link' => $imagelink,
						'title' => t('View Photo'),
						'src' => z_root() . '/photo/' . $rr['resource_id'] . '-' . $rr['imgscale'] . '.' .$ext,
						'alt' => $imgalt_e,
						'desc'=> $desc_e,
						'ext' => $ext,
						'hash'=> $rr['resource_id'],
						'unknown' => t('Unknown'),
				];
			}
		}

		$tpl = get_markup_template('photo_album.tpl');
		$o = replace_macros($tpl, [
			'$photos' => $photos,
			'$album' => (($title) ? $title : $album),
			'$album_id' => rand(),
			'$album_edit' => array(t('Edit Album'), false),
			'$can_post' => false,
			'$upload' => array(t('Upload'), z_root() . '/photos/' . \App::$profile['channel_address'] . '/upload/' . bin2hex($album)),
			'$order' => false,
			'$upload_form' => '',
			'$no_fullscreen_btn' => true,
		]);

		return $o;
	}

	/**
	 * @brief Get albums observer is allowed to see.
	 *
	 * @see photos_albums_list()
	 *
	 * @return NULL|array
	 */
	protected function embedphotos_album_list() {
		require_once('include/photos.php');
		$p = photos_albums_list(\App::get_channel(), \App::get_observer());

		if ($p['success']) {
			return $p['albums'];
		}

		return null;
	}

}
