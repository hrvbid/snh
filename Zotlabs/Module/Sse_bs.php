<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;
use Zotlabs\Lib\Enotify;

class Sse_bs extends Controller {

	public static $uid;
	public static $ob_hash;
	public static $vnotify;
	public static $evdays;
	public static $limit;
	public static $offset;
	public static $xchans;

	function init() {

		self::$uid = local_channel();
		self::$ob_hash = get_observer_hash();
		self::$vnotify = get_pconfig(self::$uid, 'system', 'vnotify');
		self::$evdays = intval(get_pconfig(self::$uid, 'system', 'evdays'));
		self::$limit = 100;
		self::$offset = 0;
		self::$xchans = '';

		if(!empty($_GET['nquery']) && $_GET['nquery'] !== '%') {
			$nquery = $_GET['nquery'];

			$x = q("SELECT xchan_hash FROM xchan WHERE xchan_name LIKE '%s' OR xchan_addr LIKE '%s'",
				dbesc($nquery . '%'),
				dbesc($nquery . '%')
			);

			self::$xchans = ids_to_querystr($x, 'xchan_hash', true);
		}

		if(intval(argv(2)) > 0)
			self::$offset = argv(2);
		else
			$_SESSION['sse_loadtime'] = datetime_convert();

		$network = false;
		$home = false;
		$pubs = false;
		$f = '';

		switch (argv(1)) {
			case 'network':
				$network = true;
				$f = 'bs_network';
				break;
			case 'home':
				$home = true;
				$f = 'bs_home';
				break;
			case 'pubs':
				$pubs = true;
				$f = 'bs_pubs';
				break;
			default:
		}

		//hz_syslog('init: ' . argv(1));
		//hz_syslog('offset: ' . argv(2));

		if(self::$offset && $f) {
			$result = self::$f(true);
			json_return_and_die($result);
		}

		$result = array_merge(
			self::bs_network($network),
			self::bs_home($home),
			self::bs_notify(),
			self::bs_intros(),
			self::bs_forums(),
			self::bs_pubs($pubs),
			self::bs_files(),
			self::bs_mail(),
			self::bs_all_events(),
			self::bs_register()
		);

		json_return_and_die($result);
	}

	function bs_network($notifications) {

		$result['network']['notifications'] = [];
		$result['network']['count'] = 0;

		if(! self::$uid)
			return $result;

		$limit = intval(self::$limit);
		$offset = self::$offset;

		$sql_extra = '';
		if(! (self::$vnotify & VNOTIFY_LIKE))
			$sql_extra = " AND verb NOT IN ('" . dbesc(ACTIVITY_LIKE) . "', '" . dbesc(ACTIVITY_DISLIKE) . "') ";

		$sql_extra2 = '';
		if(self::$xchans)
			$sql_extra2 = " AND (author_xchan IN (" . self::$xchans . ") OR owner_xchan IN (" . self::$xchans . ")) ";

		$item_normal = item_normal();

		if ($notifications) {
			$items = q("SELECT * FROM item 
				WHERE uid = %d
				AND created <= '%s'
				AND item_unseen = 1 AND item_wall = 0 
				AND author_xchan != '%s'
				$item_normal
				$sql_extra
				$sql_extra2
				ORDER BY created DESC LIMIT $limit OFFSET $offset",
				intval(self::$uid),
				dbescdate($_SESSION['sse_loadtime']),
				dbesc(self::$ob_hash)
			);

			if($items) {
				$result['network']['offset'] = ((count($items) == $limit) ? intval($offset + $limit) : -1);
				xchan_query($items);
				foreach($items as $item) {
					$result['network']['notifications'][] = Enotify::format($item);
				}
			}
			else {
				$result['network']['offset'] = -1;
			}

		}

		$r = q("SELECT count(id) as total FROM item 
			WHERE uid = %d and item_unseen = 1 AND item_wall = 0 
			$item_normal
			$sql_extra
			AND author_xchan != '%s'",
			intval(self::$uid),
			dbesc(self::$ob_hash)
		);

		if($r)
			$result['network']['count'] = intval($r[0]['total']);

		return $result;
	}

	function bs_home($notifications) {

		$result['home']['notifications'] = [];
		$result['home']['count'] = 0;

		if(! self::$uid)
			return $result;

		$limit = intval(self::$limit);
		$offset = self::$offset;

		$sql_extra = '';
		if(! (self::$vnotify & VNOTIFY_LIKE))
			$sql_extra = " AND verb NOT IN ('" . dbesc(ACTIVITY_LIKE) . "', '" . dbesc(ACTIVITY_DISLIKE) . "') ";

		$sql_extra2 = '';
		if(self::$xchans)
			$sql_extra2 = " AND (author_xchan IN (" . self::$xchans . ") OR owner_xchan IN (" . self::$xchans . ")) ";


		$item_normal = item_normal();

		if ($notifications) {
			$items = q("SELECT * FROM item 
				WHERE uid = %d
				AND created <= '%s'
				AND item_unseen = 1 AND item_wall = 1 
				AND author_xchan != '%s'
				$item_normal
				$sql_extra
				$sql_extra2
				ORDER BY created DESC LIMIT $limit OFFSET $offset",
				intval(self::$uid),
				dbescdate($_SESSION['sse_loadtime']),
				dbesc(self::$ob_hash)
			);

			if($items) {
				$result['home']['offset'] = ((count($items) == $limit) ? intval($offset + $limit) : -1);
				xchan_query($items);
				foreach($items as $item) {
					$result['home']['notifications'][] = Enotify::format($item);
				}
			}
			else {
				$result['home']['offset'] = -1;
			}

		}

		$r = q("SELECT count(id) as total FROM item 
			WHERE uid = %d and item_unseen = 1 AND item_wall = 1 
			$item_normal
			$sql_extra
			AND author_xchan != '%s'",
			intval(self::$uid),
			dbesc(self::$ob_hash)
		);

		if($r)
			$result['home']['count'] = intval($r[0]['total']);

		return $result;
	}

	function bs_pubs($notifications) {

		$result['pubs']['notifications'] = [];
		$result['pubs']['count'] = 0;

		if(! isset($_SESSION['static_loadtime']))
			$_SESSION['static_loadtime'] = datetime_convert();

		$limit = intval(self::$limit);
		$offset = self::$offset;

		$sys = get_sys_channel();
		$sql_extra = '';
		if(! (self::$vnotify & VNOTIFY_LIKE))
			$sql_extra = " AND verb NOT IN ('" . dbesc(ACTIVITY_LIKE) . "', '" . dbesc(ACTIVITY_DISLIKE) . "') ";

		$sql_extra2 = '';
		if(self::$xchans)
			$sql_extra2 = " AND (author_xchan IN (" . self::$xchans . ") OR owner_xchan IN (" . self::$xchans . ")) ";

		$item_normal = item_normal();

		if ($notifications) {
			$items = q("SELECT * FROM item 
				WHERE uid = %d
				AND created <= '%s'
				AND item_unseen = 1
				AND author_xchan != '%s'
				AND created > '%s'
				$item_normal
				$sql_extra
				$sql_extra2
				ORDER BY created DESC LIMIT $limit OFFSET $offset",
				intval($sys['channel_id']),
				dbescdate($_SESSION['sse_loadtime']),
				dbesc(self::$ob_hash),
				dbescdate($_SESSION['static_loadtime'])
			);

			if($items) {
				$result['pubs']['offset'] = ((count($items) == $limit) ? intval($offset + $limit) : -1);
				xchan_query($items);
				foreach($items as $item) {
					$result['pubs']['notifications'][] = Enotify::format($item);
				}
			}
			else {
				$result['pubs']['offset'] = -1;
			}


		}

		$r = q("SELECT count(id) as total FROM item 
			WHERE uid = %d AND item_unseen = 1
			AND created > '%s'
			$item_normal
			$sql_extra
			AND author_xchan != '%s'",
			intval($sys['channel_id']),
			dbescdate($_SESSION['static_loadtime']),
			dbesc(self::$ob_hash)
		);

		if($r)
			$result['pubs']['count'] = intval($r[0]['total']);

		return $result;
	}


	function bs_notify() {

		$result['notify']['notifications'] = [];
		$result['notify']['count'] = 0;
		$result['notify']['offset'] = -1;

		if(! self::$uid)
			return $result;

		$r = q("SELECT * FROM notify WHERE uid = %d AND seen = 0 ORDER BY created DESC",
			intval(self::$uid)
		);
		if($r) {
			foreach($r as $rr) {
				$result['notify']['notifications'][] = Enotify::format_notify($rr);
			}
			$result['notify']['count'] = count($r);
		}

		return $result;

	}

	function bs_intros() {

		$result['intros']['notifications'] = [];
		$result['intros']['count'] = 0;
		$result['intros']['offset'] = -1;

		if(! self::$uid)
			return $result;

		$r = q("SELECT * FROM abook left join xchan on abook.abook_xchan = xchan.xchan_hash where abook_channel = %d and abook_pending = 1 and abook_self = 0 and abook_ignored = 0 and xchan_deleted = 0 and xchan_orphan = 0 ORDER BY abook_created DESC LIMIT 50",
			intval(self::$uid)
		);

		if($r) {
			foreach($r as $rr) {
				$result['intros']['notifications'][] = Enotify::format_intros($rr);
			}
			$result['intros']['count'] = count($r);
		}

		return $result;

	}

	function bs_forums() {

		$result['forums']['notifications'] = [];
		$result['forums']['count'] = 0;
		$result['forums']['offset'] = -1;

		if(! self::$uid)
			return $result;

		$forums = get_forum_channels(self::$uid);

		if($forums) {
			$item_normal = item_normal();

			$sql_extra = '';
			if(! (self::$vnotify & VNOTIFY_LIKE))
				$sql_extra = " AND verb NOT IN ('" . dbesc(ACTIVITY_LIKE) . "', '" . dbesc(ACTIVITY_DISLIKE) . "') ";

			$fcount = count($forums);
			$i = 0;

			for($x = 0; $x < $fcount; $x ++) {
				$p = q("SELECT oid AS parent FROM term WHERE uid = %d AND ttype = %d AND term = '%s'",
					intval(self::$uid),
					intval(TERM_FORUM),
					dbesc($forums[$x]['xchan_name'])
				);

				$p_str = ids_to_querystr($p, 'parent');
				$p_sql = (($p_str) ? "OR parent IN ( $p_str )" : '');

				$r = q("select count(id) as unseen from item 
					where uid = %d and ( owner_xchan = '%s' OR author_xchan = '%s' $p_sql ) and item_unseen = 1 $sql_extra $item_normal",
					intval(self::$uid),
					dbesc($forums[$x]['xchan_hash']),
					dbesc($forums[$x]['xchan_hash'])
				);

				if($r[0]['unseen']) {
					$forums[$x]['notify_link'] = (($forums[$x]['private_forum']) ? $forums[$x]['xchan_url'] : z_root() . '/network/?f=&pf=1&unseen=1&cid=' . $forums[$x]['abook_id']);
					$forums[$x]['name'] = $forums[$x]['xchan_name'];
					$forums[$x]['addr'] = $forums[$x]['xchan_addr'];
					$forums[$x]['url'] = $forums[$x]['xchan_url'];
					$forums[$x]['photo'] = $forums[$x]['xchan_photo_s'];
					$forums[$x]['unseen'] = $r[0]['unseen'];
					$forums[$x]['private_forum'] = (($forums[$x]['private_forum']) ? 'lock' : '');
					$forums[$x]['message'] = (($forums[$x]['private_forum']) ? t('Private forum') : t('Public forum'));

					unset($forums[$x]['abook_id']);
					unset($forums[$x]['xchan_hash']);
					unset($forums[$x]['xchan_name']);
					unset($forums[$x]['xchan_url']);
					unset($forums[$x]['xchan_photo_s']);

					$i = $i + $r[0]['unseen'];

				}
				else {
					unset($forums[$x]);
				}
			}

			$result['forums']['count'] = $i;
			$result['forums']['notifications'] = array_values($forums);

		}

		return $result;

	}

	function bs_files() {

		$result['files']['notifications'] = [];
		$result['files']['count'] = 0;
		$result['files']['offset'] = -1;

		if(! self::$uid)
			return $result;

		$r = q("SELECT * FROM item 
			WHERE verb = '%s'
			AND obj_type = '%s'
			AND uid = %d
			AND owner_xchan != '%s'
			AND item_unseen = 1",
			dbesc(ACTIVITY_POST),
			dbesc(ACTIVITY_OBJ_FILE),
			intval(self::$uid),
			dbesc(self::$ob_hash)
		);
		if($r) {
			xchan_query($r);
			foreach($r as $rr) {
				$result['files']['notifications'][] = Enotify::format_files($rr);
			}
			$result['files']['count'] = count($r);
		}

		return $result;

	}

	function bs_mail() {

		$result['mail']['notifications'] = [];
		$result['mail']['count'] = 0;
		$result['mail']['offset'] = -1;

		if(! self::$uid)
			return $result;

		$r = q("select mail.*, xchan.* from mail left join xchan on xchan_hash = from_xchan
			where channel_id = %d and mail_seen = 0 and mail_deleted = 0
			and from_xchan != '%s' order by created desc",
			intval(self::$uid),
			dbesc(self::$ob_hash)
		);

		if($r) {
			foreach($r as $rr) {
				$result['mail']['notifications'][] = Enotify::format_mail($rr);
			}
			$result['mail']['count'] = count($r);
		}

		return $result;

	}

	function bs_all_events() {

		$result['all_events']['notifications'] = [];
		$result['all_events']['count'] = 0;
		$result['all_events']['offset'] = -1;

		if(! self::$uid)
			return $result;

		$r = q("SELECT * FROM event left join xchan on event_xchan = xchan_hash
			WHERE event.uid = %d AND dtstart < '%s' AND dtstart > '%s' and dismissed = 0
			and etype in ( 'event', 'birthday' )
			ORDER BY dtstart DESC",
			intval(self::$uid),
			dbesc(datetime_convert('UTC', date_default_timezone_get(), 'now + ' . intval(self::$evdays) . ' days')),
			dbesc(datetime_convert('UTC', date_default_timezone_get(), 'now - 1 days'))
		);

		if($r) {
			foreach($r as $rr) {
				$result['all_events']['notifications'][] = Enotify::format_all_events($rr);
			}
			$result['all_events']['count'] = count($r);
		}

		return $result;
	}

	function bs_register() {

		$result['register']['notifications'] = [];
		$result['register']['count'] = 0;
		$result['register']['offset'] = -1;

		if(! self::$uid && ! is_site_admin())
			return $result;

		$r = q("SELECT account_email, account_created from account where (account_flags & %d) > 0",
			intval(ACCOUNT_PENDING)
		);
		if($r) {
			foreach($r as $rr) {
				$result['register']['notifications'][] = Enotify::format_register($rr);
			}
			$result['register']['count'] = count($r);
		}

		return $result;

	}

}
