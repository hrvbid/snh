<?php

namespace Zotlabs\Lib;

use Zotlabs\Access\PermissionLimits;
use Zotlabs\Daemon\Master;
use Zotlabs\Web\HTTPSig;

require_once('include/event.php');
require_once('include/html2plain.php');

class Activity {

	static function encode_object($x) {


		if(($x) && (! is_array($x)) && (substr(trim($x),0,1)) === '{' ) {
			$x = json_decode($x,true);
		}

		if(is_array($x)) {

			if(array_key_exists('asld',$x)) {
				return $x['asld'];
			}

			if($x['type'] === ACTIVITY_OBJ_PERSON) {
				return self::fetch_person($x); 
			}
			if($x['type'] === ACTIVITY_OBJ_PROFILE) {
				return self::fetch_profile($x); 
			}
			if(in_array($x['type'], [ ACTIVITY_OBJ_NOTE, ACTIVITY_OBJ_ARTICLE ] )) {
				return self::fetch_item($x); 
			}
			if($x['type'] === ACTIVITY_OBJ_THING) {
				return self::fetch_thing($x); 
			}
			if($x['type'] === ACTIVITY_OBJ_EVENT) {
				return self::fetch_event($x); 
			}
			if($x['type'] === ACTIVITY_OBJ_PHOTO) {
				return self::fetch_image($x); 
			}

			call_hooks('encode_object',$x);
		}

		return $x;

	}

	static function fetch($url,$channel = null) {
		$redirects = 0;
		if(! check_siteallowed($url)) {
			logger('blacklisted: ' . $url);
			return null;
		}
		if(! $channel) {
			$channel = get_sys_channel();
		}

		logger('fetch: ' . $url, LOGGER_DEBUG);

		if(strpos($url,'x-zot:') === 0) {
			$x = ZotURL::fetch($url,$channel);
		}
		else {
			$m = parse_url($url);

			// handle bearcaps
			if ($m['scheme'] === 'bear') {
				$params = explode('&',$m['query']);
				if ($params) {
					foreach ($params as $p) {
						if (substr($p,0,2) === 'u=') {
							$url = substr($p,2);
						}
						if (substr($p,0,2) === 't=') {
							$token = substr($p,2);
						}
					}
					$m = parse_url($url);
				}
			}

			$headers = [
				'Accept'           => 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
				'Host'             => $m['host'],
				'Date'             => datetime_convert('UTC','UTC', 'now', 'D, d M Y H:i:s \\G\\M\\T'),
				'(request-target)' => 'get ' . get_request_string($url)
			];
			if (isset($token)) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}
			$h = HTTPSig::create_sig($headers,$channel['channel_prvkey'],channel_url($channel),false);
			$x = z_fetch_url($url, true, $redirects, [ 'headers' => $h ] );
		}

		if($x['success']) {
			$y = json_decode($x['body'],true);
			logger('returned: ' . json_encode($y,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOGGER_DEBUG);
			return json_decode($x['body'], true);
		}
		else {
			logger('fetch failed: ' . $url);
		}
		return null;
	}




	static function fetch_person($x) {
		return self::fetch_profile($x);
	}

	static function fetch_profile($x) {
		$r = q("select * from xchan where xchan_url like '%s' limit 1",
			dbesc($x['id'] . '/%')
		);
		if(! $r) {
			$r = q("select * from xchan where xchan_hash = '%s' limit 1",
				dbesc($x['id'])
			);

		} 
		if(! $r)
			return [];

		return self::encode_person($r[0]);

	}

	static function fetch_thing($x) {

		$r = q("select * from obj where obj_type = %d and obj_obj = '%s' limit 1",
			intval(TERM_OBJ_THING),
			dbesc($x['id'])
		);

		if(! $r)
			return [];

		$x = [
			'type' => 'Object',
			'id'   => z_root() . '/thing/' . $r[0]['obj_obj'],
			'name' => $r[0]['obj_term']
		];

		if($r[0]['obj_image'])
			$x['image'] = $r[0]['obj_image'];

		return $x;

	}

	static function fetch_item($x) {

		if (array_key_exists('source',$x)) {
			// This item is already processed and encoded
			return $x;
		}

		$r = q("select * from item where mid = '%s' limit 1",
			dbesc($x['id'])
		);
		if($r) {
			xchan_query($r,true);
			$r = fetch_post_tags($r,true);
			return self::encode_item($r[0]);
		}
	}


	static function fetch_image($x) {

		$ret = [
			'type' => 'Image',
			'id' => $x['id'],
			'name' => $x['title'],
			'content' => bbcode($x['body'], [ 'cache' => true ]),
			'source' => [ 'mediaType' => 'text/bbcode', 'content' => $x['body'] ],
			'published' => datetime_convert('UTC','UTC',$x['created'],ATOM_TIME), 
			'updated' => datetime_convert('UTC','UTC', $x['edited'],ATOM_TIME),
			'url' => [
					'type'      => 'Link',
					'mediaType' => $x['link'][0]['type'], 
					'href'      => $x['link'][0]['href'],
					'width'     => $x['link'][0]['width'],
		  			'height'    => $x['link'][0]['height']
			]
		];
		return $ret;
	}

	static function fetch_event($x) {

		// convert old Zot event objects to ActivityStreams Event objects

		if (array_key_exists('content',$x) && array_key_exists('dtstart',$x)) {
			$ev = bbtoevent($x['content']);
			if($ev) {


				if (! $ev['timezone']) {
					$ev['timezone'] = 'UTC';
				}

				$actor = null;
				if(array_key_exists('author',$x) && array_key_exists('link',$x['author'])) {
					$actor = $x['author']['link'][0]['href'];
				}
				$y = [ 
					'type'      => 'Event',
					'id'        => z_root() . '/event/' . $ev['event_hash'],
					'name'      => $ev['summary'],
//					'summary'   => bbcode($ev['summary'], [ 'cache' => true ]),
					// RFC3339 Section 4.3
					'startTime' => (($ev['adjust']) ? datetime_convert($ev['timezone'],'UTC',$ev['dtstart'], ATOM_TIME) : datetime_convert('UTC','UTC',$ev['dtstart'],'Y-m-d\\TH:i:s-00:00')),
					'content'   => bbcode($ev['description'], [ 'cache' => true ]),
					'location'  => [ 'type' => 'Place', 'content' => bbcode($ev['location'], [ 'cache' => true ]) ],
					'source'    => [ 'content' => format_event_bbcode($ev), 'mediaType' => 'text/bbcode' ],
					'actor'     => $actor,
				];
				if(! $ev['nofinish']) {
					$y['endTime'] = (($ev['adjust']) ? datetime_convert($ev['timezone'],'UTC',$ev['dtend'], ATOM_TIME) : datetime_convert('UTC','UTC',$ev['dtend'],'Y-m-d\\TH:i:s-00:00'));
				}
				
				// copy attachments from the passed object - these are already formatted for ActivityStreams

				if($x['attachment']) {
					$y['attachment'] = $x['attachment'];
				}

				if($actor) {
					return $y;
				}
			}
		}

		return $x;

	}


	static function encode_item_collection($items,$id,$type,$extra = null) {

		$ret = [
			'id' => z_root() . '/' . $id,
			'type' => $type,
			'totalItems' => count($items),
		];
		if($extra)
			$ret = array_merge($ret,$extra);

		if($items) {
			$x = [];
			foreach($items as $i) {
				$t = self::encode_activity($i);
				if($t)
					$x[] = $t;
			}
			if($type === 'OrderedCollection')
				$ret['orderedItems'] = $x;
			else
				$ret['items'] = $x;
		}

		return $ret;
	}

	static function encode_follow_collection($items,$id,$type,$extra = null) {

		$ret = [
			'id' => z_root() . '/' . $id,
			'type' => $type,
			'totalItems' => count($items),
		];
		if($extra)
			$ret = array_merge($ret,$extra);

		if($items) {
			$x = [];
			foreach($items as $i) {
				if($i['xchan_url']) {
					$x[] = $i['xchan_url'];
				}
			}

			if($type === 'OrderedCollection')
				$ret['orderedItems'] = $x;
			else
				$ret['items'] = $x;
		}

		return $ret;
	}




	static function encode_item($i) {

		$ret = [];

		if($i['verb'] === ACTIVITY_FRIEND) {
			// Hubzilla 'make-friend' activity, no direct mapping from AS1 to AS2 - make it a note
			$objtype = 'Note';
		}
		else {		
			$objtype = self::activity_obj_mapper($i['obj_type']);
		}
		
		if(intval($i['item_deleted'])) {
			$ret['type'] = 'Tombstone';
			$ret['formerType'] = $objtype;
			$ret['id'] = ((strpos($i['mid'],'http') === 0) ? $i['mid'] : z_root() . '/item/' . urlencode($i['mid']));
			return $ret;
		}

		$ret['type'] = $objtype;

		$ret['id']   = ((strpos($i['mid'],'http') === 0) ? $i['mid'] : z_root() . '/item/' . urlencode($i['mid']));

		if($i['title'])
			$ret['name'] = $i['title'];

		$ret['published'] = datetime_convert('UTC','UTC',$i['created'],ATOM_TIME);
		if($i['created'] !== $i['edited'])
			$ret['updated'] = datetime_convert('UTC','UTC',$i['edited'],ATOM_TIME);
		if ($i['expires'] <= NULL_DATE) {
			$ret['expires'] = datetime_convert('UTC','UTC',$i['expires'],ATOM_TIME);
		}

		if($i['app']) {
			$ret['generator'] = [ 'type' => 'Application', 'name' => $i['app'] ];
		}
		if($i['location'] || $i['coord']) {
			$ret['location'] = [ 'type' => 'Place' ];
			if($i['location']) {
				$ret['location']['name'] = $i['location'];
			}
			if($i['coord']) {
				$l = explode(' ',$i['coord']);
				$ret['location']['latitude'] = $l[0];
				$ret['location']['longitude'] = $l[1];
			}
		}

		if (intval($i['item_wall']) && $i['mid'] === $i['parent_mid']) {
			$ret['commentPolicy'] = map_scope(PermissionLimits::Get($i['uid'],'post_comments'));
		}

		if (intval($i['item_private']) === 2) {
			$ret['directMessage'] = true;
		}

		if (array_key_exists('comments_closed',$i) && $i['comments_closed'] !== EMPTY_STR && $i['comments_closed'] !== NULL_DATE) {
			if($ret['commentPolicy']) {
				$ret['commentPolicy'] .= ' ';
			}
			$ret['commentPolicy'] .= 'until=' . datetime_convert('UTC','UTC',$i['comments_closed'],ATOM_TIME);
		}

		$ret['attributedTo'] = $i['author']['xchan_url'];

		if($i['id'] != $i['parent']) {
			$ret['inReplyTo'] = ((strpos($i['thr_parent'],'http') === 0) ? $i['thr_parent'] : z_root() . '/item/' . urlencode($i['thr_parent']));
		}

		if($i['mimetype'] === 'text/bbcode') {
			if($i['title'])
				$ret['name'] = bbcode($i['title'], [ 'cache' => true ]);
			if($i['summary'])
				$ret['summary'] = bbcode($i['summary'], [ 'cache' => true ]);
			$ret['content'] = bbcode($i['body'], [ 'cache' => true ]);
			$ret['source'] = [ 'content' => $i['body'], 'mediaType' => 'text/bbcode' ];
		}

		$actor = self::encode_person($i['author'],false);
		if($actor)
			$ret['actor'] = $actor;
		else
			return [];

		$t = self::encode_taxonomy($i);
		if($t) {
			$ret['tag']       = $t;
		}

		$a = self::encode_attachment($i);
		if($a) {
			$ret['attachment'] = $a;
		}

		return $ret;
	}

	static function decode_taxonomy($item) {

		$ret = [];

		if ($item['tag'] && is_array($item['tag'])) {
			$ptr = $item['tag'];
			if (! array_key_exists(0,$ptr)) {
				$ptr = [ $ptr ];
			}
			foreach ($ptr as $t) {
				if (! array_key_exists('type',$t))
					$t['type'] = 'Hashtag';

				switch($t['type']) {
					case 'Hashtag':
						$ret[] = [ 'ttype' => TERM_HASHTAG, 'url' => $t['href'], 'term' => escape_tags((substr($t['name'],0,1) === '#') ? substr($t['name'],1) : $t['name']) ];
						break;

					case 'Mention':
						$mention_type = substr($t['name'],0,1);
						if ($mention_type === '!') {
							$ret[] = [ 'ttype' => TERM_FORUM, 'url' => $t['href'], 'term' => escape_tags(substr($t['name'],1)) ];
						}
						else {
							$ret[] = [ 'ttype' => TERM_MENTION, 'url' => $t['href'], 'term' => escape_tags((substr($t['name'],0,1) === '@') ? substr($t['name'],1) : $t['name']) ];
						}
						break;

					default:
						break;
				}
			}
		}

		return $ret;
	}



	static function encode_taxonomy($item) {

		$ret = [];

		if($item['term']) {
			foreach($item['term'] as $t) {
				switch($t['ttype']) {
					case TERM_HASHTAG:
						// href is required so if we don't have a url in the taxonomy, ignore it and keep going.
						if($t['url']) {
							$ret[] = [ 'type' => 'Hashtag', 'href' => $t['url'], 'name' => '#' . $t['term'] ];
						}
						break;

					case TERM_FORUM:
						$ret[] = [ 'type' => 'Mention', 'href' => $t['url'], 'name' => '!' . $t['term'] ];
						break;

					case TERM_MENTION:
						$ret[] = [ 'type' => 'Mention', 'href' => $t['url'], 'name' => '@' . $t['term'] ];
						break;
	
					default:
						break;
				}
			}
		}

		return $ret;
	}

	static function encode_attachment($item) {

		$ret = [];

		if($item['attach']) {
			$atts = ((is_array($item['attach'])) ? $item['attach'] : json_decode($item['attach'],true));
			if($atts) {
				foreach($atts as $att) {
					if(strpos($att['type'],'image')) {
						$ret[] = [ 'type' => 'Image', 'url' => $att['href'] ];
					}
					else {
						$ret[] = [ 'type' => 'Link', 'mediaType' => $att['type'], 'href' => $att['href'] ];
					}
				}
			}
		}
		
		return $ret;
	}


	static function decode_attachment($item) {

		$ret = [];

		if($item['attachment']) {
			foreach($item['attachment'] as $att) {
				$entry = [];
				if($att['href'])
					$entry['href'] = $att['href'];
				elseif($att['url'])
					$entry['href'] = $att['url'];
				if($att['mediaType'])
					$entry['type'] = $att['mediaType'];
				elseif($att['type'] === 'Image')
					$entry['type'] = 'image/jpeg';
				if($entry)
					$ret[] = $entry;
			}
		}

		return $ret;
	}



	static function encode_activity($i) {

		$ret   = [];
		$reply = false;


		if($i['verb'] === ACTIVITY_FRIEND) {
			// Hubzilla 'make-friend' activity, no direct mapping from AS1 to AS2 - make it a note
			$ret['obj'] = [];
		}

		if(intval($i['item_deleted'])) {
			$ret['type'] = 'Tombstone';
			$ret['formerType'] = self::activity_obj_mapper($i['obj_type']);
			$ret['id'] = ((strpos($i['mid'],'http') === 0) ? $i['mid'] : z_root() . '/item/' . urlencode($i['mid']));
			$actor = self::encode_person($i['author'],false);
			if($actor)
				$ret['actor'] = $actor;
			else
				return []; 
			return $ret;
		}


		$ret['type'] = self::activity_mapper($i['verb']);

		if($ret['type'] === 'emojiReaction') {
			// There may not be an object for these items for legacy reasons - it should be the conversation parent.
			$p = q("select * from item where mid = '%s' and uid = %d",
				dbesc($i['parent_mid']),
				intval($i['uid'])
			);
			if($p) {
				xchan_query($p,true);
				$p = fetch_post_tags($p,true);
				$i['obj'] = self::encode_item($p[0]);

				// convert to zot6 emoji reaction encoding which uses the target object to indicate the
				// specific emoji instead of overloading the verb or type.
				
				$im = explode('#',$i['verb']);
				if($im && count($im) > 1)
					$emoji = $im[1];
				if(preg_match("/\[img(.*?)\](.*?)\[\/img\]/ism", $i['body'], $match)) {
					$ln = $match[2];
				}

				$i['tgt_type'] = 'Image';
			
				$i['target'] = [
					'type' => 'Image',
					'name' => $emoji,
					'url'  => (($ln) ? $ln : z_root() . '/images/emoji/' . $emoji . '.png')
				];
				
			}
		}


		$ret['id']   = ((strpos($i['mid'],'http') === 0) ? $i['mid'] : z_root() . '/activity/' . urlencode($i['mid']));

		if($i['title'])
			$ret['name'] = html2plain(bbcode($i['title'], [ 'cache' => true ]));

		if($i['summary'])
			$ret['summary'] = bbcode($i['summary'], [ 'cache' => true ]);

		if($ret['type'] === 'Announce') {
			$tmp = preg_replace('/\[share(.*?)\[\/share\]/ism',EMPTY_STR, $i['body']);
			$ret['content'] = bbcode($tmp, [ 'cache' => true ]);
			$ret['source'] = [
				'content' => $i['body'],
				'mediaType' => 'text/bbcode'
			];
		}

		$ret['published'] = datetime_convert('UTC','UTC',$i['created'],ATOM_TIME);
		if($i['created'] !== $i['edited'])
			$ret['updated'] = datetime_convert('UTC','UTC',$i['edited'],ATOM_TIME);
		if($i['app']) {
			$ret['generator'] = [ 'type' => 'Application', 'name' => $i['app'] ];
		}
		if($i['location'] || $i['coord']) {
			$ret['location'] = [ 'type' => 'Place' ];
			if($i['location']) {
				$ret['location']['name'] = $i['location'];
			}
			if($i['coord']) {
				$l = explode(' ',$i['coord']);
				$ret['location']['latitude'] = $l[0];
				$ret['location']['longitude'] = $l[1];
			}
		}

		if($i['id'] != $i['parent']) {
			$ret['inReplyTo'] = ((strpos($i['thr_parent'],'http') === 0) ? $i['thr_parent'] : z_root() . '/item/' . urlencode($i['thr_parent']));
			$reply = true;

			if($i['item_private']) {
				$d = q("select xchan_url, xchan_addr, xchan_name from item left join xchan on xchan_hash = author_xchan where id = %d limit 1",
					intval($i['parent'])
				);
				if($d) {
					$is_directmessage = false;
					$recips = get_iconfig($i['parent'], 'activitypub', 'recips');

					if(in_array($i['author']['xchan_url'], $recips['to'])) {
						$reply_url = $d[0]['xchan_url'];
						$is_directmessage = true;
					}
					else {
						$reply_url = z_root() . '/followers/' . substr($i['author']['xchan_addr'],0,strpos($i['author']['xchan_addr'],'@'));
					}

					$reply_addr = (($d[0]['xchan_addr']) ? $d[0]['xchan_addr'] : $d[0]['xchan_name']);
				}
			}

		}

		$actor = self::encode_person($i['author'],false);
		if($actor)
			$ret['actor'] = $actor;
		else
			return []; 

		if(strpos($i['body'],'[/share]') !== false) {
			$i['obj'] = null;
		}

		if($i['obj']) {
			if(! is_array($i['obj'])) {
				$i['obj'] = json_decode($i['obj'],true);
			}
			if($i['obj']['type'] === ACTIVITY_OBJ_PHOTO) {
				$i['obj']['id'] = $i['mid'];
			}

			$obj = self::encode_object($i['obj']);
			if($obj)
				$ret['object'] = $obj;
			else
				return [];
		}
		else {
			$obj = self::encode_item($i);
			if($obj)
				$ret['object'] = $obj;
			else
				return [];
		}


		if($i['target']) {
			if(! is_array($i['target'])) {
				$i['target'] = json_decode($i['target'],true);
			}
			$tgt = self::encode_object($i['target']);
			if($tgt)
				$ret['target'] = $tgt;
			else
				return [];
		}

		return $ret;
	}

	static function map_mentions($i) {
		if(! $i['term']) {
			return [];
		}

		$list = [];

		foreach ($i['term'] as $t) {
			if($t['ttype'] == TERM_MENTION) {
				$list[] = $t['url'];
			}
		}

		return $list;
	}

	static function map_acl($i,$mentions = false) {

		$private = false;
		$list = [];
		$x = collect_recipients($i,$private);
		if($x) {
			stringify_array_elms($x);
			if(! $x)
				return;

			$strict = (($mentions) ? true : get_config('activitypub','compliance'));

			$sql_extra = (($strict) ? " and xchan_network = 'activitypub' " : '');

			$details = q("select xchan_url, xchan_addr, xchan_name from xchan where xchan_hash in (" . implode(',',$x) . ") $sql_extra");

			if($details) {
				foreach($details as $d) {
					if($mentions) {
						$list[] = [ 'type' => 'Mention', 'href' => $d['xchan_url'], 'name' => '@' . (($d['xchan_addr']) ? $d['xchan_addr'] : $d['xchan_name']) ];
					}
					else { 
						$list[] = $d['xchan_url'];
					}
				}
			}
		}

		return $list;

	}


	static function encode_person($p, $extended = true) {

		if(! $p['xchan_url'])
			return [];

		if(! $extended) {
			return $p['xchan_url'];
		}
		$ret = [];

		$c = ((array_key_exists('channel_id',$p)) ? $p : channelx_by_hash($p['xchan_hash']));

		$ret['type']  = 'Person';

		if ($c) {
			$role = get_pconfig($c['channel_id'],'system','permissions_role');
			if (strpos($role,'forum') !== false) {
				$ret['type'] = 'Group';
			}
		}

		if ($c) {
			$ret['id'] = channel_url($c);
		}
		else {
			$ret['id'] = ((strpos($p['xchan_hash'],'http') === 0) ? $p['xchan_hash'] : $p['xchan_url']);
		}

		if($p['xchan_addr'] && strpos($p['xchan_addr'],'@'))
			$ret['preferredUsername'] = substr($p['xchan_addr'],0,strpos($p['xchan_addr'],'@'));
		$ret['name']  = $p['xchan_name'];
		$ret['updated'] = datetime_convert('UTC','UTC',$p['xchan_name_date'],ATOM_TIME);
		$ret['icon']  = [
			'type'      => 'Image',
			'mediaType' => (($p['xchan_photo_mimetype']) ? $p['xchan_photo_mimetype'] : 'image/png' ),
			'updated'   => datetime_convert('UTC','UTC',$p['xchan_photo_date'],ATOM_TIME),
			'url'       => $p['xchan_photo_l'],
			'height'    => 300,
			'width'     => 300,
		];
		$ret['url'] = [
			[ 
				'type'      => 'Link',
				'mediaType' => 'text/html',
				'href'      => $p['xchan_url']
			],
			[
				'type'      => 'Link',
				'mediaType' => 'text/x-zot+json',
				'href'      => $p['xchan_url']
			]
		];

		$arr = [ 'xchan' => $p, 'encoded' => $ret ];
		call_hooks('encode_person', $arr);
		$ret = $arr['encoded'];


		return $ret;
	}








	static function activity_mapper($verb) {

		if(strpos($verb,'/') === false) {
			return $verb;
		}

		$acts = [
			'http://activitystrea.ms/schema/1.0/post'      => 'Create',
			'http://activitystrea.ms/schema/1.0/share'     => 'Announce',
			'http://activitystrea.ms/schema/1.0/update'    => 'Update',
			'http://activitystrea.ms/schema/1.0/like'      => 'Like',
			'http://activitystrea.ms/schema/1.0/favorite'  => 'Like',
			'http://purl.org/zot/activity/dislike'         => 'Dislike',
			'http://activitystrea.ms/schema/1.0/tag'       => 'Add',
			'http://activitystrea.ms/schema/1.0/follow'    => 'Follow',
			'http://activitystrea.ms/schema/1.0/unfollow'  => 'Unfollow',
			'http://purl.org/zot/activity/attendyes'       => 'Accept',
			'http://purl.org/zot/activity/attendno'        => 'Reject',
			'http://purl.org/zot/activity/attendmaybe'     => 'TentativeAccept'
		];

		call_hooks('activity_mapper',$acts);

		if(array_key_exists($verb,$acts) && $acts[$verb]) {
			return $acts[$verb];
		}

		// Reactions will just map to normal activities

		if(strpos($verb,ACTIVITY_REACT) !== false)
			return 'emojiReaction';
		if(strpos($verb,ACTIVITY_MOOD) !== false)
			return 'Create';

		if(strpos($verb,ACTIVITY_FRIEND) !== false)
			return 'Create';

		if(strpos($verb,ACTIVITY_POKE) !== false)
			return 'Activity';

		// We should return false, however this will trigger an uncaught execption  and crash 
		// the delivery system if encountered by the JSON-LDSignature library
 
		logger('Unmapped activity: ' . $verb);
		return 'Create';
	//	return false;
}



	static function activity_decode_mapper($verb) {

		$acts = [
			'http://activitystrea.ms/schema/1.0/post'      => 'Create',
			'http://activitystrea.ms/schema/1.0/share'     => 'Announce',
			'http://activitystrea.ms/schema/1.0/update'    => 'Update',
			'http://activitystrea.ms/schema/1.0/like'      => 'Like',
			'http://activitystrea.ms/schema/1.0/favorite'  => 'Like',
			'http://purl.org/zot/activity/dislike'         => 'Dislike',
			'http://activitystrea.ms/schema/1.0/tag'       => 'Add',
			'http://activitystrea.ms/schema/1.0/follow'    => 'Follow',
			'http://activitystrea.ms/schema/1.0/unfollow'  => 'Unfollow',
			'http://purl.org/zot/activity/attendyes'       => 'Accept',
			'http://purl.org/zot/activity/attendno'        => 'Reject',
			'http://purl.org/zot/activity/attendmaybe'     => 'TentativeAccept'
		];

		call_hooks('activity_decode_mapper',$acts);

		foreach($acts as $k => $v) {
			if($verb === $v) {
				return $k;
			}
		}

		logger('Unmapped activity: ' . $verb);
		return 'Create';

	}

	static function activity_obj_decode_mapper($obj) {

		$objs = [
			'http://activitystrea.ms/schema/1.0/note'           => 'Note',
			'http://activitystrea.ms/schema/1.0/note'           => 'Article',
			'http://activitystrea.ms/schema/1.0/comment'        => 'Note',
			'http://activitystrea.ms/schema/1.0/person'         => 'Person',
			'http://purl.org/zot/activity/profile'              => 'Profile',
			'http://activitystrea.ms/schema/1.0/photo'          => 'Image',
			'http://activitystrea.ms/schema/1.0/profile-photo'  => 'Icon',
			'http://activitystrea.ms/schema/1.0/event'          => 'Event',
			'http://activitystrea.ms/schema/1.0/wiki'           => 'Document',
			'http://purl.org/zot/activity/location'             => 'Place',
			'http://purl.org/zot/activity/chessgame'            => 'Game',
			'http://purl.org/zot/activity/tagterm'              => 'zot:Tag',
			'http://purl.org/zot/activity/thing'                => 'Object',
			'http://purl.org/zot/activity/file'                 => 'zot:File',
			'http://purl.org/zot/activity/mood'                 => 'zot:Mood',
		
		];

		call_hooks('activity_obj_decode_mapper',$objs);

		foreach($objs as $k => $v) {
			if($obj === $v) {
				return $k;
			}
		}

		logger('Unmapped activity object: ' . $obj);
		return 'Note';
	}




	static function activity_obj_mapper($obj) {

		if(strpos($obj,'/') === false) {
			return $obj;
		}

		$objs = [
			'http://activitystrea.ms/schema/1.0/note'           => 'Note',
			'http://activitystrea.ms/schema/1.0/comment'        => 'Note',
			'http://activitystrea.ms/schema/1.0/person'         => 'Person',
			'http://purl.org/zot/activity/profile'              => 'Profile',
			'http://activitystrea.ms/schema/1.0/photo'          => 'Image',
			'http://activitystrea.ms/schema/1.0/profile-photo'  => 'Icon',
			'http://activitystrea.ms/schema/1.0/event'          => 'Event',
			'http://activitystrea.ms/schema/1.0/wiki'           => 'Document',
			'http://purl.org/zot/activity/location'             => 'Place',
			'http://purl.org/zot/activity/chessgame'            => 'Game',
			'http://purl.org/zot/activity/tagterm'              => 'zot:Tag',
			'http://purl.org/zot/activity/thing'                => 'Object',
			'http://purl.org/zot/activity/file'                 => 'zot:File',
			'http://purl.org/zot/activity/mood'                 => 'zot:Mood',
		
		];

		call_hooks('activity_obj_mapper',$objs);

		if(array_key_exists($obj,$objs)) {
			return $objs[$obj];
		}

		logger('Unmapped activity object: ' . $obj);
		return 'Note';

		//	return false;

	}


	static function follow($channel,$act) {

		$contact = null;
		$their_follow_id = null;

		/*
		 * 
		 * if $act->type === 'Follow', actor is now following $channel 
		 * if $act->type === 'Accept', actor has approved a follow request from $channel 
		 *	 
		 */

		$person_obj = $act->actor;

		if($act->type === 'Follow') {
			$their_follow_id  = $act->id;
		}
		elseif($act->type === 'Accept') {
			$my_follow_id = z_root() . '/follow/' . $contact['id'];
		}
	
		if(is_array($person_obj)) {

			// store their xchan and hubloc

			self::actor_store($person_obj['id'],$person_obj);

			// Find any existing abook record 

			$r = q("select * from abook left join xchan on abook_xchan = xchan_hash where abook_xchan = '%s' and abook_channel = %d limit 1",
				dbesc($person_obj['id']),
				intval($channel['channel_id'])
			);
			if($r) {
				$contact = $r[0];
			}
		}

		$x = \Zotlabs\Access\PermissionRoles::role_perms('social');
		$p = \Zotlabs\Access\Permissions::FilledPerms($x['perms_connect']);
		$their_perms = \Zotlabs\Access\Permissions::serialise($p);

		if($contact && $contact['abook_id']) {

			// A relationship of some form already exists on this site. 

			switch($act->type) {

				case 'Follow':

					// A second Follow request, but we haven't approved the first one

					if($contact['abook_pending']) {
						return;
					}

					// We've already approved them or followed them first
					// Send an Accept back to them

					set_abconfig($channel['channel_id'],$person_obj['id'],'pubcrawl','their_follow_id', $their_follow_id);
					Master::Summon([ 'Notifier', 'permissions_accept', $contact['abook_id'] ]);
					return;

				case 'Accept':

					// They accepted our Follow request - set default permissions
	
					set_abconfig($channel['channel_id'],$contact['abook_xchan'],'system','their_perms',$their_perms);

					$abook_instance = $contact['abook_instance'];
	
					if(strpos($abook_instance,z_root()) === false) {
						if($abook_instance) 
							$abook_instance .= ',';
						$abook_instance .= z_root();

						$r = q("update abook set abook_instance = '%s', abook_not_here = 0 
							where abook_id = %d and abook_channel = %d",
							dbesc($abook_instance),
							intval($contact['abook_id']),
							intval($channel['channel_id'])
						);
					}
		
					return;
				default:
					return;
	
			}
		}

		// No previous relationship exists.

		if($act->type === 'Accept') {
			// This should not happen unless we deleted the connection before it was accepted.
			return;
		}

		// From here on out we assume a Follow activity to somebody we have no existing relationship with

		set_abconfig($channel['channel_id'],$person_obj['id'],'pubcrawl','their_follow_id', $their_follow_id);

		// The xchan should have been created by actor_store() above

		$r = q("select * from xchan where xchan_hash = '%s' and xchan_network = 'activitypub' limit 1",
			dbesc($person_obj['id'])
		);

		if(! $r) {
			logger('xchan not found for ' . $person_obj['id']);
			return;
		}
		$ret = $r[0];

		$p = \Zotlabs\Access\Permissions::connect_perms($channel['channel_id']);
		$my_perms  = \Zotlabs\Access\Permissions::serialise($p['perms']);
		$automatic = $p['automatic'];

		$closeness = get_pconfig($channel['channel_id'],'system','new_abook_closeness',80);

		$r = abook_store_lowlevel(
			[
				'abook_account'   => intval($channel['channel_account_id']),
				'abook_channel'   => intval($channel['channel_id']),
				'abook_xchan'     => $ret['xchan_hash'],
				'abook_closeness' => intval($closeness),
				'abook_created'   => datetime_convert(),
				'abook_updated'   => datetime_convert(),
				'abook_connected' => datetime_convert(),
				'abook_dob'       => NULL_DATE,
				'abook_pending'   => intval(($automatic) ? 0 : 1),
				'abook_instance'  => z_root()
			]
		);
		
		if($my_perms)
			set_abconfig($channel['channel_id'],$ret['xchan_hash'],'system','my_perms',$my_perms);

		if($their_perms)
			set_abconfig($channel['channel_id'],$ret['xchan_hash'],'system','their_perms',$their_perms);


		if($r) {
			logger("New ActivityPub follower for {$channel['channel_name']}");

			$new_connection = q("select * from abook left join xchan on abook_xchan = xchan_hash left join hubloc on hubloc_hash = xchan_hash where abook_channel = %d and abook_xchan = '%s' order by abook_created desc limit 1",
				intval($channel['channel_id']),
				dbesc($ret['xchan_hash'])
			);
			if($new_connection) {
				\Zotlabs\Lib\Enotify::submit(
					[
						'type'	       => NOTIFY_INTRO,
						'from_xchan'   => $ret['xchan_hash'],
						'to_xchan'     => $channel['channel_hash'],
						'link'         => z_root() . '/connedit/' . $new_connection[0]['abook_id'],
					]
				);

				if($my_perms && $automatic) {
					// send an Accept for this Follow activity
					Master::Summon([ 'Notifier', 'permissions_accept', $new_connection[0]['abook_id'] ]);
					// Send back a Follow notification to them
					Master::Summon([ 'Notifier', 'permissions_create', $new_connection[0]['abook_id'] ]);
				}

				$clone = array();
				foreach($new_connection[0] as $k => $v) {
					if(strpos($k,'abook_') === 0) {
						$clone[$k] = $v;
					}
				}
				unset($clone['abook_id']);
				unset($clone['abook_account']);
				unset($clone['abook_channel']);
		
				$abconfig = load_abconfig($channel['channel_id'],$clone['abook_xchan']);

				if($abconfig)
					$clone['abconfig'] = $abconfig;

				Libsync::build_sync_packet($channel['channel_id'], [ 'abook' => array($clone) ] );
			}
		}


		/* If there is a default group for this channel and permissions are automatic, add this member to it */

		if($channel['channel_default_group'] && $automatic) {
			$g = Group::rec_byhash($channel['channel_id'],$channel['channel_default_group']);
			if($g)
				Group::member_add($channel['channel_id'],'',$ret['xchan_hash'],$g['id']);
		}


		return;

	}


	static function unfollow($channel,$act) {

		$contact = null;

		/* @FIXME This really needs to be a signed request. */

		/* actor is unfollowing $channel */

		$person_obj = $act->actor;

		if(is_array($person_obj)) {

			$r = q("select * from abook left join xchan on abook_xchan = xchan_hash where abook_xchan = '%s' and abook_channel = %d limit 1",
				dbesc($person_obj['id']),
				intval($channel['channel_id'])
			);
			if($r) {
				// remove all permissions they provided
				del_abconfig($channel['channel_id'],$r[0]['xchan_hash'],'system','their_perms',EMPTY_STR);
			}
		}

		return;
	}




	static function actor_store($url,$person_obj) {

		if(! is_array($person_obj))
			return;

		$name = $person_obj['name'];
		if(! $name)
			$name = $person_obj['preferredUsername'];
		if(! $name)
			$name = t('Unknown');

		if($person_obj['icon']) {
			if(is_array($person_obj['icon'])) {
				if(array_key_exists('url',$person_obj['icon']))
					$icon = $person_obj['icon']['url'];
				else
					$icon = $person_obj['icon'][0]['url'];
			}
			else
				$icon = $person_obj['icon'];
		}

		if(is_array($person_obj['url']) && array_key_exists('href', $person_obj['url']))
			$profile = $person_obj['url']['href'];
		else
			$profile = $url;


		$inbox = $person_obj['inbox'];

		$collections = [];

		if($inbox) {
			$collections['inbox'] = $inbox;
			if($person_obj['outbox'])
				$collections['outbox'] = $person_obj['outbox'];
			if($person_obj['followers'])
				$collections['followers'] = $person_obj['followers'];
			if($person_obj['following'])
				$collections['following'] = $person_obj['following'];
			if($person_obj['endpoints'] && $person_obj['endpoints']['sharedInbox'])
				$collections['sharedInbox'] = $person_obj['endpoints']['sharedInbox'];
		}

		if(array_key_exists('publicKey',$person_obj) && array_key_exists('publicKeyPem',$person_obj['publicKey'])) {
			if($person_obj['id'] === $person_obj['publicKey']['owner']) {
				$pubkey = $person_obj['publicKey']['publicKeyPem'];
				if(strstr($pubkey,'RSA ')) {
					$pubkey = rsatopem($pubkey);
				}
			}
		}

		$r = q("select * from xchan where xchan_hash = '%s' limit 1",
			dbesc($url)
		);
		if(! $r) {
			// create a new record
			$r = xchan_store_lowlevel(
				[
					'xchan_hash'         => $url,
					'xchan_guid'         => $url,
					'xchan_pubkey'       => $pubkey,
					'xchan_addr'         => '',
					'xchan_url'          => $profile,
					'xchan_name'         => $name,
					'xchan_name_date'    => datetime_convert(),
					'xchan_network'      => 'activitypub'
				]
			);
		}
		else {

			// Record exists. Cache existing records for one week at most
			// then refetch to catch updated profile photos, names, etc. 

			$d = datetime_convert('UTC','UTC','now - 1 week');
			if($r[0]['xchan_name_date'] > $d)
				return;

			// update existing record
			$r = q("update xchan set xchan_name = '%s', xchan_pubkey = '%s', xchan_network = '%s', xchan_name_date = '%s' where xchan_hash = '%s'",
				dbesc($name),
				dbesc($pubkey),
				dbesc('activitypub'),
				dbesc(datetime_convert()),
				dbesc($url)
			);
		}

		if($collections) {
			set_xconfig($url,'activitypub','collections',$collections);
		}

		$r = q("select * from hubloc where hubloc_hash = '%s' limit 1",
			dbesc($url)
		);


		$m = parse_url($url);
		if($m) {
			$hostname = $m['host'];
			$baseurl = $m['scheme'] . '://' . $m['host'] . (($m['port']) ? ':' . $m['port'] : '');
		}

		if(! $r) {
			$r = hubloc_store_lowlevel(
				[
					'hubloc_guid'     => $url,
					'hubloc_hash'     => $url,
					'hubloc_addr'     => '',
					'hubloc_network'  => 'activitypub',
					'hubloc_url'      => $baseurl,
					'hubloc_host'     => $hostname,
					'hubloc_callback' => $inbox,
					'hubloc_updated'  => datetime_convert(),
					'hubloc_primary'  => 1
				]
			);
		}

		if(! $icon)
			$icon = z_root() . '/' . get_default_profile_photo(300);

		$photos = import_xchan_photo($icon,$url);
		$r = q("update xchan set xchan_photo_date = '%s', xchan_photo_l = '%s', xchan_photo_m = '%s', xchan_photo_s = '%s', xchan_photo_mimetype = '%s' where xchan_hash = '%s'",
			dbescdate(datetime_convert('UTC','UTC',$photos[5])),
			dbesc($photos[0]),
			dbesc($photos[1]),
			dbesc($photos[2]),
			dbesc($photos[3]),
			dbesc($url)
		);

	}


	static function create_action($channel,$observer_hash,$act) {

		if(in_array($act->obj['type'], [ 'Note', 'Article', 'Video' ])) {
			self::create_note($channel,$observer_hash,$act);
		}


	}

	static function announce_action($channel,$observer_hash,$act) {

		if(in_array($act->type, [ 'Announce' ])) {
			self::announce_note($channel,$observer_hash,$act);
		}

	}


	static function like_action($channel,$observer_hash,$act) {

		if(in_array($act->obj['type'], [ 'Note', 'Article', 'Video' ])) {
			self::like_note($channel,$observer_hash,$act);
		}


	}

	// sort function width decreasing

	static function as_vid_sort($a,$b) {
		if($a['width'] === $b['width'])
			return 0;
		return (($a['width'] > $b['width']) ? -1 : 1);
	}

	static function create_note($channel,$observer_hash,$act) {

		$s = [];

		// Mastodon only allows visibility in public timelines if the public inbox is listed in the 'to' field.
		// They are hidden in the public timeline if the public inbox is listed in the 'cc' field.
		// This is not part of the activitypub protocol - we might change this to show all public posts in pubstream at some point.
		$pubstream = ((is_array($act->obj) && array_key_exists('to', $act->obj) && in_array(ACTIVITY_PUBLIC_INBOX, $act->obj['to'])) ? true : false);
		$is_sys_channel = is_sys_channel($channel['channel_id']);

		$parent = ((array_key_exists('inReplyTo',$act->obj)) ? urldecode($act->obj['inReplyTo']) : '');
		if($parent) {

			$r = q("select * from item where uid = %d and ( mid = '%s' or  mid = '%s' ) limit 1",
				intval($channel['channel_id']),
				dbesc($parent),
				dbesc(basename($parent))
			);

			if(! $r) {
				logger('parent not found.');
				return;
			}

			if($r[0]['owner_xchan'] === $channel['channel_hash']) {
				if(! perm_is_allowed($channel['channel_id'],$observer_hash,'send_stream') && ! ($is_sys_channel && $pubstream)) {
					logger('no comment permission.');
					return;
				}
			}

			$s['parent_mid'] = $r[0]['mid'];
			$s['owner_xchan'] = $r[0]['owner_xchan'];
			$s['author_xchan'] = $observer_hash;

		}
		else {
			if(! perm_is_allowed($channel['channel_id'],$observer_hash,'send_stream') && ! ($is_sys_channel && $pubstream)) {
				logger('no permission');
				return;
			}
			$s['owner_xchan'] = $s['author_xchan'] = $observer_hash;
		}
	
		$abook = q("select * from abook where abook_xchan = '%s' and abook_channel = %d limit 1",
			dbesc($observer_hash),
			intval($channel['channel_id'])
		);
	
		$content = self::get_content($act->obj);

		if(! $content) {
			logger('no content');
			return;
		}

		$s['aid'] = $channel['channel_account_id'];
		$s['uid'] = $channel['channel_id'];
		$s['mid'] = urldecode($act->obj['id']);
		$s['plink'] = urldecode($act->obj['id']);


		if($act->data['published']) {
			$s['created'] = datetime_convert('UTC','UTC',$act->data['published']);
		}
		elseif($act->obj['published']) {
			$s['created'] = datetime_convert('UTC','UTC',$act->obj['published']);
		}
		if($act->data['updated']) {
			$s['edited'] = datetime_convert('UTC','UTC',$act->data['updated']);
		}
		elseif($act->obj['updated']) {
			$s['edited'] = datetime_convert('UTC','UTC',$act->obj['updated']);
		}
		if ($act->data['expires']) {
			$s['expires'] = datetime_convert('UTC','UTC',$act->data['expires']);
		}
		elseif ($act->obj['expires']) {
			$s['expires'] = datetime_convert('UTC','UTC',$act->obj['expires']);
		}

		if(! $s['created'])
			$s['created'] = datetime_convert();

		if(! $s['edited'])
			$s['edited'] = $s['created'];


		if(! $s['parent_mid'])
			$s['parent_mid'] = $s['mid'];

	
		$s['title']    = self::bb_content($content,'name');
		$s['summary']  = self::bb_content($content,'summary'); 
		$s['body']     = self::bb_content($content,'content');
		$s['verb']     = ACTIVITY_POST;
		$s['obj_type'] = ACTIVITY_OBJ_NOTE;

		$generator = $act->get_property_obj('generator');
		if(! $generator)
			$generator = $act->get_property_obj('generator',$act->obj);

		if($generator && array_key_exists('type',$generator) 
			&& in_array($generator['type'], [ 'Application','Service' ] ) && array_key_exists('name',$generator)) {
			$s['app'] = escape_tags($generator['name']);
		}

		if($channel['channel_system']) {
			if(! \Zotlabs\Lib\MessageFilter::evaluate($s,get_config('system','pubstream_incl'),get_config('system','pubstream_excl'))) {
				logger('post is filtered');
				return;
			}
		}


		if($abook) {
			if(! post_is_importable($s,$abook[0])) {
				logger('post is filtered');
				return;
			}
		}

		if($act->obj['conversation']) {
			set_iconfig($s,'ostatus','conversation',$act->obj['conversation'],1);
		}

		$a = self::decode_taxonomy($act->obj);
		if($a) {
			$s['term'] = $a;
		}

		$a = self::decode_attachment($act->obj);
		if($a) {
			$s['attach'] = $a;
		}

		if($act->obj['type'] === 'Note' && $s['attach']) {
			$s['body'] .= self::bb_attach($s['attach'],$s['body']);
		}

		// we will need a hook here to extract magnet links e.g. peertube
		// right now just link to the largest mp4 we find that will fit in our
		// standard content region

		if($act->obj['type'] === 'Video') {

			$vtypes = [
				'video/mp4',
				'video/ogg',
				'video/webm'
			];

			$mps = [];
			if(array_key_exists('url',$act->obj) && is_array($act->obj['url'])) {
				foreach($act->obj['url'] as $vurl) {
					if(in_array($vurl['mimeType'], $vtypes)) {
						if(! array_key_exists('width',$vurl)) {
							$vurl['width'] = 0;
						}
						$mps[] = $vurl;
					}
				}
			}
			if($mps) {
				usort($mps,'as_vid_sort');
				foreach($mps as $m) {
					if(intval($m['width']) < 500) {
						$s['body'] .= "\n\n" . '[video]' . $m['href'] . '[/video]';
						break;
					}
				}
			}
		}

		if($act->recips && (! in_array(ACTIVITY_PUBLIC_INBOX,$act->recips)))
			$s['item_private'] = 1;


		if (array_key_exists('directMessage',$act->obj) && intval($act->obj['directMessage'])) {
			$s['item_private'] = 2;
		}

		set_iconfig($s,'activitypub','recips',$act->raw_recips);
		if($parent) {
			set_iconfig($s,'activitypub','rawmsg',$act->raw,1);
		}

		$x = null;

		$r = q("select created, edited from item where mid = '%s' and uid = %d limit 1",
			dbesc($s['mid']),
			intval($s['uid'])
		);
		if($r) {
			if($s['edited'] > $r[0]['edited']) {
				$x = item_store_update($s);
			}
			else {
				return;
			}
		}
		else {
			$x = item_store($s);
		}

		if(is_array($x) && $x['item_id']) {
			if($parent) {
				if($s['owner_xchan'] === $channel['channel_hash']) {
					// We are the owner of this conversation, so send all received comments back downstream
					Master::Summon(array('Notifier','comment-import',$x['item_id']));
				}
				$r = q("select * from item where id = %d limit 1",
					intval($x['item_id'])
				);
				if($r) {
					send_status_notifications($x['item_id'],$r[0]);
				}
			}
			sync_an_item($channel['channel_id'],$x['item_id']);
		}

	}

	static function get_actor_bbmention($id) {

		$x = q("select * from hubloc left join xchan on hubloc_hash = xchan_hash where hubloc_hash = '%s' or hubloc_id_url = '%s' limit 1",
			dbesc($id),
			dbesc($id)
		);

		if($x) {
			return sprintf('@[zrl=%s]%s[/zrl]',$x[0]['xchan_url'],$x[0]['xchan_name']);		
		}
		return '@{' . $id . '}';

	}


	static function decode_note($act) {

		$response_activity = false;

		$s = [];

		if(is_array($act->obj)) {
			$content = self::get_content($act->obj);
		}
			
		$s['owner_xchan']  = $act->actor['id'];
		$s['author_xchan'] = $act->actor['id'];

		// ensure we store the original actor
		self::actor_store($act->actor['id'],$act->actor);

		$s['mid']        = $act->obj['id'];
		$s['parent_mid'] = $act->parent_id;

		if($act->data['published']) {
			$s['created'] = datetime_convert('UTC','UTC',$act->data['published']);
		}
		elseif($act->obj['published']) {
			$s['created'] = datetime_convert('UTC','UTC',$act->obj['published']);
		}
		if($act->data['updated']) {
			$s['edited'] = datetime_convert('UTC','UTC',$act->data['updated']);
		}
		elseif($act->obj['updated']) {
			$s['edited'] = datetime_convert('UTC','UTC',$act->obj['updated']);
		}
		if ($act->data['expires']) {
			$s['expires'] = datetime_convert('UTC','UTC',$act->data['expires']);
		}
		elseif ($act->obj['expires']) {
			$s['expires'] = datetime_convert('UTC','UTC',$act->obj['expires']);
		}


		if(in_array($act->type, [ 'Like', 'Dislike', 'Flag', 'Block', 'Announce', 'Accept', 'Reject', 'TentativeAccept', 'emojiReaction' ])) {

			$response_activity = true;

			$s['mid'] = $act->id;
			$s['parent_mid'] = $act->obj['id'];

			// over-ride the object timestamp with the activity

			if($act->data['published']) {
				$s['created'] = datetime_convert('UTC','UTC',$act->data['published']);
			}

			if($act->data['updated']) {
				$s['edited'] = datetime_convert('UTC','UTC',$act->data['updated']);
			}

			$obj_actor = ((isset($act->obj['actor'])) ? $act->obj['actor'] : $act->get_actor('attributedTo', $act->obj));
			// ensure we store the original actor
			self::actor_store($obj_actor['id'],$obj_actor);

			$mention = self::get_actor_bbmention($obj_actor['id']);

			if($act->type === 'Like') {
				$content['content'] = sprintf( t('Likes %1$s\'s %2$s'),$mention,$act->obj['type']) . "\n\n" . $content['content'];
			}
			if($act->type === 'Dislike') {
				$content['content'] = sprintf( t('Doesn\'t like %1$s\'s %2$s'),$mention,$act->obj['type']) . "\n\n" . $content['content'];
			}
			if($act->type === 'Accept' && $act->obj['type'] === 'Event' ) {
				$content['content'] = sprintf( t('Will attend %1$s\'s %2$s'),$mention,$act->obj['type']) . "\n\n" . $content['content'];
			}
			if($act->type === 'Reject' && $act->obj['type'] === 'Event' ) {
				$content['content'] = sprintf( t('Will not attend %1$s\'s %2$s'),$mention,$act->obj['type']) . "\n\n" . $content['content'];
			}
			if($act->type === 'TentativeAccept' && $act->obj['type'] === 'Event' ) {
				$content['content'] = sprintf( t('May attend %1$s\'s %2$s'),$mention,$act->obj['type']) . "\n\n" . $content['content'];
			}
			if($act->type === 'Announce') {
				$content['content'] = sprintf( t('&#x1f501; Repeated %1$s\'s %2$s'), $mention, $act->obj['type']);
			}
			if ($act->type === 'emojiReaction') {
				$content['content'] = (($act->tgt && $act->tgt['type'] === 'Image') ? '[img=32x32]' . $act->tgt['url'] . '[/img]' : '&#x' . $act->tgt['name'] . ';');
			}			
		}

		if(! $s['created'])
			$s['created'] = datetime_convert();

		if(! $s['edited'])
			$s['edited'] = $s['created'];

		$s['title']    = self::bb_content($content,'name');
		$s['summary']  = self::bb_content($content,'summary');
		$s['body']     = ((self::bb_content($content,'bbcode') && (! $response_activity)) ? self::bb_content($content,'bbcode') : self::bb_content($content,'content'));

		$s['verb']     = self::activity_decode_mapper($act->type);


		if($act->type === 'Tombstone' || $act->type === 'Delete' || ($act->type === 'Create' && $act->obj['type'] === 'Tombstone')) {
			$s['item_deleted'] = 1;
		}

		$s['obj_type'] = self::activity_obj_decode_mapper($act->obj['type']);
		if($s['obj_type'] === ACTIVITY_OBJ_NOTE && $s['mid'] !== $s['parent_mid']) {
			$s['obj_type'] = ACTIVITY_OBJ_COMMENT;
		}

		if($act->obj['type'] === 'Event') {

			$s['obj'] = [];
			$s['obj']['asld'] = $act->obj;
			$s['obj']['type'] = ACTIVITY_OBJ_EVENT;
			$s['obj']['id'] = $act->obj['id'];
			$s['obj']['title'] = $act->obj['name'];

			if(strpos($act->obj['startTime'],'Z'))
				$s['obj']['adjust'] = true;
			else
				$s['obj']['adjust'] = false;

			$s['obj']['dtstart'] = datetime_convert('UTC','UTC',$act->obj['startTime']);
			if($act->obj['endTime']) 
				$s['obj']['dtend'] = datetime_convert('UTC','UTC',$act->obj['endTime']);
			else
				$s['obj']['nofinish'] = true;
			$s['obj']['description'] = $act->obj['content'];

			if(array_path_exists('location/content',$act->obj))
				$s['obj']['location'] = $act->obj['location']['content'];

		}
		else {
			$s['obj']      = $act->obj;
		}

		$generator = $act->get_property_obj('generator');
		if((! $generator) && (! $response_activity)) {
			$generator = $act->get_property_obj('generator',$act->obj);
		}

		if($generator && array_key_exists('type',$generator) 
			&& in_array($generator['type'], [ 'Application', 'Service' ] ) && array_key_exists('name',$generator)) {
			$s['app'] = escape_tags($generator['name']);
		}


		if(! $response_activity) {
			$a = self::decode_taxonomy($act->obj);
			if($a) {
				$s['term'] = $a;
				foreach($a as $b) {
					if($b['ttype'] === TERM_EMOJI) {
						$s['title'] = str_replace($b['term'],'[img=16x16]' . $b['url'] . '[/img]',$s['title']);
						$s['summary'] = str_replace($b['term'],'[img=16x16]' . $b['url'] . '[/img]',$s['summary']);
						$s['body'] = str_replace($b['term'],'[img=16x16]' . $b['url'] . '[/img]',$s['body']);
					}
				}
			}

			$a = self::decode_attachment($act->obj);
			if($a) {
				$s['attach'] = $a;
			}
		}

		if($act->obj['type'] === 'Note' && $s['attach']) {
			$s['body'] .= self::bb_attach($s['attach'],$s['body']);
		}


		// we will need a hook here to extract magnet links e.g. peertube
		// right now just link to the largest mp4 we find that will fit in our
		// standard content region

		if(! $response_activity) {
			if($act->obj['type'] === 'Video') {

				$vtypes = [
					'video/mp4',
					'video/ogg',
					'video/webm'
				];

				$mps = [];
				$ptr = null;

				if(array_key_exists('url',$act->obj)) {
					if(is_array($act->obj['url'])) {
						if(array_key_exists(0,$act->obj['url'])) {				
							$ptr = $act->obj['url'];
						}
						else {
							$ptr = [ $act->obj['url'] ];
						}
						foreach($ptr as $vurl) {
							// peertube uses the non-standard element name 'mimeType' here
							if(array_key_exists('mimeType',$vurl)) {
								if(in_array($vurl['mimeType'], $vtypes)) {
									if(! array_key_exists('width',$vurl)) {
										$vurl['width'] = 0;
									}
									$mps[] = $vurl;
								}
							}
							elseif(array_key_exists('mediaType',$vurl)) {
								if(in_array($vurl['mediaType'], $vtypes)) {
									if(! array_key_exists('width',$vurl)) {
										$vurl['width'] = 0;
									}
									$mps[] = $vurl;
								}
							}
						}
					}
					if($mps) {
						usort($mps,[ __CLASS__, 'vid_sort' ]);
						foreach($mps as $m) {
							if(intval($m['width']) < 500 && self::media_not_in_body($m['href'],$s['body'])) {
								$s['body'] .= "\n\n" . '[video]' . $m['href'] . '[/video]';
								break;
							}
						}
					}
					elseif(is_string($act->obj['url']) && self::media_not_in_body($act->obj['url'],$s['body'])) {
						$s['body'] .= "\n\n" . '[video]' . $act->obj['url'] . '[/video]';
					}
				}
			}

			if($act->obj['type'] === 'Audio') {

				$atypes = [
					'audio/mpeg',
					'audio/ogg',
					'audio/wav'
				];

				$ptr = null;

				if(array_key_exists('url',$act->obj)) {
					if(is_array($act->obj['url'])) {
						if(array_key_exists(0,$act->obj['url'])) {				
							$ptr = $act->obj['url'];
						}
						else {
							$ptr = [ $act->obj['url'] ];
						}
						foreach($ptr as $vurl) {
							if(in_array($vurl['mediaType'], $atypes) && self::media_not_in_body($vurl['href'],$s['body'])) {
								$s['body'] .= "\n\n" . '[audio]' . $vurl['href'] . '[/audio]';
								break;
							}
						}
					}
					elseif(is_string($act->obj['url']) && self::media_not_in_body($act->obj['url'],$s['body'])) {
						$s['body'] .= "\n\n" . '[audio]' . $act->obj['url'] . '[/audio]';
					}
				}

			}

			// avoid double images from hubzilla to zap/osada

			if($act->obj['type'] === 'Image' && strpos($s['body'],'zrl=') === false) {

				$ptr = null;

				if(array_key_exists('url',$act->obj)) {
					if(is_array($act->obj['url'])) {
						if(array_key_exists(0,$act->obj['url'])) {				
							$ptr = $act->obj['url'];
						}
						else {
							$ptr = [ $act->obj['url'] ];
						}
						foreach($ptr as $vurl) {
							if(strpos($s['body'],$vurl['href']) === false) {
								$s['body'] .= '[zmg]' . $vurl['href'] . '[/zmg]' . "\n\n" . $s['body'];
								break;
							}
						}
					}
					elseif(is_string($act->obj['url'])) {
						if(strpos($s['body'],$act->obj['url']) === false) {
							$s['body'] .= '[zmg]' . $act->obj['url'] . '[/zmg]' . "\n\n" . $s['body'];
						}
					}
				}
			}


			if($act->obj['type'] === 'Page' && ! $s['body'])  {

				$ptr  = null;
				$purl = EMPTY_STR;

				if(array_key_exists('url',$act->obj)) {
					if(is_array($act->obj['url'])) {
						if(array_key_exists(0,$act->obj['url'])) {				
							$ptr = $act->obj['url'];
						}
						else {
							$ptr = [ $act->obj['url'] ];
						}
						foreach($ptr as $vurl) {
							if(array_key_exists('mediaType',$vurl) && $vurl['mediaType'] === 'text/html') {
								$purl = $vurl['href'];
								break;
							}
							elseif(array_key_exists('mimeType',$vurl) && $vurl['mimeType'] === 'text/html') {
								$purl = $vurl['href'];
								break;
							}
						}
					}
					elseif(is_string($act->obj['url'])) {
						$purl = $act->obj['url'];
					}
					if($purl) {
						$li = z_fetch_url(z_root() . '/linkinfo?binurl=' . bin2hex($purl));
						if($li['success'] && $li['body']) {
							$s['body'] .= "\n" . $li['body'];
						}
						else {
							$s['body'] .= "\n\n" . $purl;
						}
					}
				}
			}
		}



		if(in_array($act->obj['type'],[ 'Note','Article','Page' ])) {
			$ptr = null;

			if(array_key_exists('url',$act->obj)) {
				if(is_array($act->obj['url'])) {
					if(array_key_exists(0,$act->obj['url'])) {				
						$ptr = $act->obj['url'];
					}
					else {
						$ptr = [ $act->obj['url'] ];
					}
					foreach($ptr as $vurl) {
						if(array_key_exists('mediaType',$vurl) && $vurl['mediaType'] === 'text/html') {
							$s['plink'] = $vurl['href'];
							break;
						}
					}
				}
				elseif(is_string($act->obj['url'])) {
					$s['plink'] = $act->obj['url'];
				}
			}
		}

		if(! $s['plink']) {
			$s['plink'] = $s['mid'];
		}

		if($act->recips && (! in_array(ACTIVITY_PUBLIC_INBOX,$act->recips)))
			$s['item_private'] = 1;

		set_iconfig($s,'activitypub','recips',$act->raw_recips);

		$parent = (($s['parent_mid'] && $s['parent_mid'] === $s['mid']) ? true : false);
		if($parent) {
			set_iconfig($s,'activitypub','rawmsg',$act->raw,1);
		}

		$hookinfo = [
			'act' => $act,
			's' => $s
		];

		call_hooks('decode_note',$hookinfo);

		$s = $hookinfo['s'];

		return $s;

	}

	static function store($channel,$observer_hash,$act,$item,$fetch_parents = true) {

		$is_sys_channel = is_sys_channel($channel['channel_id']);

		// Mastodon only allows visibility in public timelines if the public inbox is listed in the 'to' field.
		// They are hidden in the public timeline if the public inbox is listed in the 'cc' field.
		// This is not part of the activitypub protocol - we might change this to show all public posts in pubstream at some point.

		$pubstream = ((is_array($act->obj) && array_key_exists('to', $act->obj) && in_array(ACTIVITY_PUBLIC_INBOX, $act->obj['to'])) ? true : false);
		$is_parent = (($item['parent_mid'] && $item['parent_mid'] === $item['mid']) ? true : false);

		if($is_parent && (! perm_is_allowed($channel['channel_id'],$observer_hash,'send_stream') && ! ($is_sys_channel && $pubstream))) {
			logger('no permission');
			return;
		}

		if(is_array($act->obj)) {
			$content = self::get_content($act->obj);
		}
		if(! $content) {
			logger('no content');
			return;
		}

		$item['aid'] = $channel['channel_account_id'];
		$item['uid'] = $channel['channel_id'];
		$s['uuid'] = '';

		// Friendica sends the diaspora guid in a nonstandard field via AP
		if($act->obj['diaspora:guid'])
			$s['uuid'] = $act->obj['diaspora:guid'];

		if(! ( $item['author_xchan'] && $item['owner_xchan'])) {
			logger('owner or author missing.');
			return;
		}

		if($channel['channel_system']) {
			if(! MessageFilter::evaluate($item,get_config('system','pubstream_incl'),get_config('system','pubstream_excl'))) {
				logger('post is filtered');
				return;
			}
		}

		$abook = q("select * from abook where abook_xchan = '%s' and abook_channel = %d limit 1",
			dbesc($observer_hash),
			intval($channel['channel_id'])
		);

		if($abook) {
			if(! post_is_importable($item,$abook[0])) {
				logger('post is filtered');
				return;
			}
		}


		if($act->obj['conversation']) {
			set_iconfig($item,'ostatus','conversation',$act->obj['conversation'],1);
		}

		// This isn't perfect but the best we can do for now.

		$item['comment_policy'] = 'authenticated';

		set_iconfig($item,'activitypub','recips',$act->raw_recips);

		if(! $is_parent) {
			$p = q("select parent_mid from item where mid = '%s' and uid = %d limit 1",
				dbesc($item['parent_mid']),
				intval($item['uid'])
			);
			if(! $p) {
				$a = (($fetch_parents) ? self::fetch_and_store_parents($channel,$act,$item) : false);
				if($a) {
					$p = q("select parent_mid from item where mid = '%s' and uid = %d limit 1",
						dbesc($item['parent_mid']),
						intval($item['uid'])
					);
				}
				else {
					logger('could not fetch parents');
					return;

					// @TODO we maybe could accept these is we formatted the body correctly with share_bb()
					// or at least provided a link to the object
					// if(in_array($act->type,[ 'Like','Dislike' ])) {
					//	return;
					// }

					// @TODO do we actually want that?
					// if no parent was fetched, turn into a top-level post

					// turn into a top level post
					// $s['parent_mid'] = $s['mid'];
					// $s['thr_parent'] = $s['mid'];
				}
			}
			if($p[0]['parent_mid'] !== $item['parent_mid']) {
				$item['thr_parent'] = $item['parent_mid'];
			}
			else {
				$item['thr_parent'] = $p[0]['parent_mid'];
			}
			$item['parent_mid'] = $p[0]['parent_mid'];
		}

		$r = q("select id, created, edited from item where mid = '%s' and uid = %d limit 1",
			dbesc($item['mid']),
			intval($item['uid'])
		);
		if($r) {
			if($item['edited'] > $r[0]['edited']) {
				$item['id'] = $r[0]['id'];
				$x = item_store_update($item);
			}
			else {
				return;
			}
		}
		else {
			$x = item_store($item);
		}

		if(is_array($x) && $x['item_id']) {
			if($is_parent) {
				if($item['owner_xchan'] === $channel['channel_hash']) {
					// We are the owner of this conversation, so send all received comments back downstream
					Master::Summon(array('Notifier','comment-import',$x['item_id']));
				}
				$r = q("select * from item where id = %d limit 1",
					intval($x['item_id'])
				);
				if($r) {
					send_status_notifications($x['item_id'],$r[0]);
				}
			}
			sync_an_item($channel['channel_id'],$x['item_id']);
		}

	}

	static public function fetch_and_store_parents($channel,$act,$item) {

		logger('fetching parents');

		$p = [];

		$current_act = $act;
		$current_item = $item;

		while($current_item['parent_mid'] !== $current_item['mid']) {
			$n = ActivityStreams::fetch($current_item['parent_mid'], $channel);
			if(! $n) {
				break;
			}
			$a = new ActivityStreams($n);

			//logger($a->debug());

			if(! $a->is_valid()) {
				break;
			}

			$replies = null;
			if(isset($a->obj['replies']['first']['items'])) {
				$replies = $a->obj['replies']['first']['items'];
				// we already have this one
				array_diff($replies, [$current_item['mid']]);
			}

			$item = null;

			switch($a->type) {
				case 'Create':
				case 'Update':
				case 'Like':
				case 'Dislike':
				case 'Announce':
					$item = self::decode_note($a);
					break;
				default:
					break;

			}

			$hookinfo = [
				'a' => $a,
				'item' => $item
			];

			call_hooks('fetch_and_store',$hookinfo);

			$item = $hookinfo['item'];

			if($item) {

				array_unshift($p,[ $a, $item, $replies]);
	
				if($item['parent_mid'] === $item['mid'] || count($p) > 20) {
					break;
				}

			}
			$current_act = $a;
			$current_item = $item;
		}

		if($p) {
			foreach($p as $pv) {
				self::store($channel,$pv[0]->actor['id'],$pv[0],$pv[1],false);
				if($pv[2])
					self::fetch_and_store_replies($channel, $pv[2]);
			}
			return true;
		}

		return false;
	}

	static public function fetch_and_store_replies($channel, $arr) {

		logger('fetching replies');

		$p = [];

		foreach($arr as $url) {

			$n = ActivityStreams::fetch($url, $channel);
			if(! $n) {
				break;
			}

			$a = new ActivityStreams($n);

			if(! $a->is_valid()) {
				break;
			}

			$item = null;

			switch($a->type) {
				case 'Create':
				case 'Update':
				case 'Like':
				case 'Dislike':
				case 'Announce':
					$item = self::decode_note($a);
					break;
				default:
					break;
			}

			$hookinfo = [
				'a' => $a,
				'item' => $item
			];

			call_hooks('fetch_and_store',$hookinfo);

			$item = $hookinfo['item'];

			if($item) {
				array_unshift($p,[ $a, $item ]);
			}

		}

		if($p) {
			foreach($p as $pv) {
				self::store($channel,$pv[0]->actor['id'],$pv[0],$pv[1],false);
			}
		}

	}

	static function announce_note($channel,$observer_hash,$act) {

		$s = [];

		$is_sys_channel = is_sys_channel($channel['channel_id']);

		// Mastodon only allows visibility in public timelines if the public inbox is listed in the 'to' field.
		// They are hidden in the public timeline if the public inbox is listed in the 'cc' field.
		// This is not part of the activitypub protocol - we might change this to show all public posts in pubstream at some point.
		$pubstream = ((is_array($act->obj) && array_key_exists('to', $act->obj) && in_array(ACTIVITY_PUBLIC_INBOX, $act->obj['to'])) ? true : false);

		if(! perm_is_allowed($channel['channel_id'],$observer_hash,'send_stream') && ! ($is_sys_channel && $pubstream)) {
			logger('no permission');
			return;
		}

		$content = self::get_content($act->obj);

		if(! $content) {
			logger('no content');
			return;
		}

		$s['owner_xchan'] = $s['author_xchan'] = $observer_hash;

		$s['aid'] = $channel['channel_account_id'];
		$s['uid'] = $channel['channel_id'];
		$s['mid'] = urldecode($act->obj['id']);
		$s['plink'] = urldecode($act->obj['id']);

		if(! $s['created'])
			$s['created'] = datetime_convert();

		if(! $s['edited'])
			$s['edited'] = $s['created'];


		$s['parent_mid'] = $s['mid'];

		$s['verb']     = ACTIVITY_POST;
		$s['obj_type'] = ACTIVITY_OBJ_NOTE;
		$s['app']      = t('ActivityPub');

		if($channel['channel_system']) {
			if(! \Zotlabs\Lib\MessageFilter::evaluate($s,get_config('system','pubstream_incl'),get_config('system','pubstream_excl'))) {
				logger('post is filtered');
				return;
			}
		}

		$abook = q("select * from abook where abook_xchan = '%s' and abook_channel = %d limit 1",
			dbesc($observer_hash),
			intval($channel['channel_id'])
		);

		if($abook) {
			if(! post_is_importable($s,$abook[0])) {
				logger('post is filtered');
				return;
			}
		}

		if($act->obj['conversation']) {
			set_iconfig($s,'ostatus','conversation',$act->obj['conversation'],1);
		}

		$a = self::decode_taxonomy($act->obj);
		if($a) {
			$s['term'] = $a;
		}

		$a = self::decode_attachment($act->obj);
		if($a) {
			$s['attach'] = $a;
		}

		$body = "[share author='" . urlencode($act->sharee['name']) . 
			"' profile='" . $act->sharee['url'] . 
			"' avatar='" . $act->sharee['photo_s'] . 
			"' link='" . ((is_array($act->obj['url'])) ? $act->obj['url']['href'] : $act->obj['url']) . 
			"' auth='" . ((is_matrix_url($act->obj['url'])) ? 'true' : 'false' ) . 
			"' posted='" . $act->obj['published'] . 
			"' message_id='" . $act->obj['id'] . 
		"']";

		if($content['name'])
			$body .= self::bb_content($content,'name') . "\r\n";

		$body .= self::bb_content($content,'content');

		if($act->obj['type'] === 'Note' && $s['attach']) {
			$body .= self::bb_attach($s['attach'],$body);
		}

		$body .= "[/share]";

		$s['title']    = self::bb_content($content,'name');
		$s['body']     = $body;

		if($act->recips && (! in_array(ACTIVITY_PUBLIC_INBOX,$act->recips)))
			$s['item_private'] = 1;

		set_iconfig($s,'activitypub','recips',$act->raw_recips);

		$r = q("select created, edited from item where mid = '%s' and uid = %d limit 1",
			dbesc($s['mid']),
			intval($s['uid'])
		);
		if($r) {
			if($s['edited'] > $r[0]['edited']) {
				$x = item_store_update($s);
			}
			else {
				return;
			}
		}
		else {
			$x = item_store($s);
		}

		if(is_array($x) && $x['item_id']) {
			if($s['owner_xchan'] === $channel['channel_hash']) {
				// We are the owner of this conversation, so send all received comments back downstream
				Master::Summon(array('Notifier','comment-import',$x['item_id']));
			}
			$r = q("select * from item where id = %d limit 1",
				intval($x['item_id'])
			);
			if($r) {
				send_status_notifications($x['item_id'],$r[0]);
			}

			sync_an_item($channel['channel_id'],$x['item_id']);
		}

	}

	static function like_note($channel,$observer_hash,$act) {

		$s = [];

		$parent = $act->obj['id'];
	
		if($act->type === 'Like')
			$s['verb'] = ACTIVITY_LIKE;
		if($act->type === 'Dislike')
			$s['verb'] = ACTIVITY_DISLIKE;

		if(! $parent)
			return;

		$r = q("select * from item where uid = %d and ( mid = '%s' or  mid = '%s' ) limit 1",
			intval($channel['channel_id']),
			dbesc($parent),
			dbesc(urldecode(basename($parent)))
		);

		if(! $r) {
			logger('parent not found.');
			return;
		}

		xchan_query($r);
		$parent_item = $r[0];

		if($parent_item['owner_xchan'] === $channel['channel_hash']) {
			if(! perm_is_allowed($channel['channel_id'],$observer_hash,'post_comments')) {
				logger('no comment permission.');
				return;
			}
		}

		if($parent_item['mid'] === $parent_item['parent_mid']) {
			$s['parent_mid'] = $parent_item['mid'];
		}
		else {
			$s['thr_parent'] = $parent_item['mid'];
			$s['parent_mid'] = $parent_item['parent_mid'];
		}

		$s['owner_xchan'] = $parent_item['owner_xchan'];
		$s['author_xchan'] = $observer_hash;
	
		$s['aid'] = $channel['channel_account_id'];
		$s['uid'] = $channel['channel_id'];
		$s['mid'] = $act->id;

		if(! $s['parent_mid'])
			$s['parent_mid'] = $s['mid'];
	

		$post_type = (($parent_item['resource_type'] === 'photo') ? t('photo') : t('status'));

		$links = array(array('rel' => 'alternate','type' => 'text/html', 'href' => $parent_item['plink']));
		$objtype = (($parent_item['resource_type'] === 'photo') ? ACTIVITY_OBJ_PHOTO : ACTIVITY_OBJ_NOTE );

		$body = $parent_item['body'];

		$z = q("select * from xchan where xchan_hash = '%s' limit 1",
			dbesc($parent_item['author_xchan'])
		);
		if($z)
			$item_author = $z[0];		

		$object = json_encode(array(
			'type'    => $post_type,
			'id'      => $parent_item['mid'],
			'parent'  => (($parent_item['thr_parent']) ? $parent_item['thr_parent'] : $parent_item['parent_mid']),
			'link'    => $links,
			'title'   => $parent_item['title'],
			'content' => $parent_item['body'],
			'created' => $parent_item['created'],
			'edited'  => $parent_item['edited'],
			'author'  => array(
				'name'     => $item_author['xchan_name'],
				'address'  => $item_author['xchan_addr'],
				'guid'     => $item_author['xchan_guid'],
				'guid_sig' => $item_author['xchan_guid_sig'],
				'link'     => array(
					array('rel' => 'alternate', 'type' => 'text/html', 'href' => $item_author['xchan_url']),
					array('rel' => 'photo', 'type' => $item_author['xchan_photo_mimetype'], 'href' => $item_author['xchan_photo_m'])),
				),
			), JSON_UNESCAPED_SLASHES
		);

		if($act->type === 'Like')
			$bodyverb = t('%1$s likes %2$s\'s %3$s');
		if($act->type === 'Dislike')
			$bodyverb = t('%1$s doesn\'t like %2$s\'s %3$s');

		$ulink = '[url=' . $item_author['xchan_url'] . ']' . $item_author['xchan_name'] . '[/url]';
		$alink = '[url=' . $parent_item['author']['xchan_url'] . ']' . $parent_item['author']['xchan_name'] . '[/url]';
		$plink = '[url='. z_root() . '/display/' . urlencode($act->id) . ']' . $post_type . '[/url]';
		$s['body'] =  sprintf( $bodyverb, $ulink, $alink, $plink );

		$s['app']  = t('ActivityPub');

		// set the route to that of the parent so downstream hubs won't reject it.

		$s['route'] = $parent_item['route'];
		$s['item_private'] = $parent_item['item_private'];
		$s['obj_type'] = $objtype;
		$s['obj'] = $object;

		if($act->obj['conversation']) {
			set_iconfig($s,'ostatus','conversation',$act->obj['conversation'],1);
		}

		if($act->recips && (! in_array(ACTIVITY_PUBLIC_INBOX,$act->recips)))
			$s['item_private'] = 1;

		set_iconfig($s,'activitypub','recips',$act->raw_recips);

		$result = item_store($s);

		if($result['success']) {
			// if the message isn't already being relayed, notify others
			if(intval($parent_item['item_origin']))
					Master::Summon(array('Notifier','comment-import',$result['item_id']));
				sync_an_item($channel['channel_id'],$result['item_id']);
		}

		return;
	}



	static function bb_attach($attach,$body) {

		$ret = false;

		foreach($attach as $a) {
			if(strpos($a['type'],'image') !== false) {
				if(self::media_not_in_body($a['href'],$body)) {
					$ret .= "\n\n" . '[img]' . $a['href'] . '[/img]';
				}
			}
			if(array_key_exists('type',$a) && strpos($a['type'], 'video') === 0) {
				if(self::media_not_in_body($a['href'],$body)) {
					$ret .= "\n\n" . '[video]' . $a['href'] . '[/video]';
				}
			}
			if(array_key_exists('type',$a) && strpos($a['type'], 'audio') === 0) {
				if(self::media_not_in_body($a['href'],$body)) {
					$ret .= "\n\n" . '[audio]' . $a['href'] . '[/audio]';
				}
			}
		}

		return $ret;
	}


	// check for the existence of existing media link in body

	static function media_not_in_body($s,$body) {
		
		if((strpos($body,']' . $s . '[/img]') === false) && 
			(strpos($body,']' . $s . '[/zmg]') === false) && 
			(strpos($body,']' . $s . '[/video]') === false) && 
			(strpos($body,']' . $s . '[/audio]') === false)) {
			return true;
		}
		return false;
	}


	static function bb_content($content,$field) {

		require_once('include/html2bbcode.php');
		require_once('include/event.php');
		$ret = false;

		if(is_array($content[$field])) {
			foreach($content[$field] as $k => $v) {
				$ret .= html2bbcode($v);
				// save this for auto-translate or dynamic filtering
				// $ret .= '[language=' . $k . ']' . html2bbcode($v) . '[/language]';
			}
		}
		else {
			if($field === 'bbcode' && array_key_exists('bbcode',$content)) {
				$ret = $content[$field];
			}
			else {
				$ret = html2bbcode($content[$field]);
			}
		}
		if($field === 'content' && $content['event'] && (! strpos($ret,'[event'))) {
			$ret .= format_event_bbcode($content['event']);
		}

		return $ret;
	}


	static function get_content($act) {

		$content = [];
		$event = null;

		if ((! $act) || (! is_array($act))) {
			return $content;
		}

		if($act['type'] === 'Event') {
			$adjust = false;                                                                                                                              
			$event = [];                                                                                                                                  
			$event['event_hash'] = $act['id'];                                                                                                            
			if(array_key_exists('startTime',$act) && strpos($act['startTime'],-1,1) === 'Z') {                                                            
				$adjust = true;                                                                                                                           
				$event['adjust'] = 1;                                                                                                                     
				$event['dtstart'] = datetime_convert('UTC','UTC',$event['startTime'] . (($adjust) ? '' : 'Z'));                                           
			}                                                                                                                                             
			if(array_key_exists('endTime',$act)) {                                                                                                        
				$event['dtend'] = datetime_convert('UTC','UTC',$event['endTime'] . (($adjust) ? '' : 'Z'));                                               
			}                                                                                                                                             
			else {                                                                                                                                        
				$event['nofinish'] = true;                                                                                                                
			}                                                                                                                                             
		}                         

		foreach ([ 'name', 'summary', 'content' ] as $a) {
			if (($x = self::get_textfield($act,$a)) !== false) {
				$content[$a] = $x;
			}
		}

		if($event) {
			$event['summary'] = $content['name'];
			if(! $event['summary']) {
				if($content['summary']) {
					$event['summary'] = html2plain($content['summary']);
				}
			}
			$event['description'] = html2bbcode($content['content']);
			if($event['summary'] && $event['dtstart']) {
				$content['event'] = $event;
			}
		}

		if (array_key_exists('source',$act) && array_key_exists('mediaType',$act['source'])) {
			if ($act['source']['mediaType'] === 'text/bbcode') {
				$content['bbcode'] = purify_html($act['source']['content']);
			}
		}




		return $content;
	}


	static function get_textfield($act,$field) {
	
		$content = false;

		if(array_key_exists($field,$act) && $act[$field])
			$content = purify_html($act[$field]);
		elseif(array_key_exists($field . 'Map',$act) && $act[$field . 'Map']) {
			foreach($act[$field . 'Map'] as $k => $v) {
				$content[escape_tags($k)] = purify_html($v);
			}
		}
		return $content;
	}


}
