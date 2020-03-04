<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;
use Zotlabs\Lib\Enotify;
use Zotlabs\Lib\XConfig;

class Sse extends Controller {

	public static $uid;
	public static $ob_hash;
	public static $sse_id;
	public static $vnotify;

	function init() {

		if((observer_prohibited(true))) {
			killme();
		}

		if(! intval(get_config('system','open_pubstream',1))) {
			if(! get_observer_hash()) {
				killme();
			}
		}

		// this is important!
		session_write_close();

		self::$uid = local_channel();
		self::$ob_hash = get_observer_hash();
		self::$sse_id = false;

		if(! self::$ob_hash) {
			if(session_id()) {
				self::$sse_id = true;
				self::$ob_hash = 'sse_id.' . session_id();
			}
			else {
				return;
			}
		}

		self::$vnotify = get_pconfig(self::$uid, 'system', 'vnotify');

		$sys = get_sys_channel();
		$sleep_seconds = 3;

		header("Content-Type: text/event-stream");
		header("Cache-Control: no-cache");
		header("Connection: keep-alive");
		header("X-Accel-Buffering: no");

		while(true) {

			/**
			 * Update chat presence indication (if applicable)
			 */

			if(! self::$sse_id) {
				$r = q("select cp_id, cp_room from chatpresence where cp_xchan = '%s' and cp_client = '%s' and cp_room = 0 limit 1",
					dbesc(self::$ob_hash),
					dbesc($_SERVER['REMOTE_ADDR'])
				);
				$basic_presence = false;
				if($r) {
					$basic_presence = true;
					q("update chatpresence set cp_last = '%s' where cp_id = %d",
						dbesc(datetime_convert()),
						intval($r[0]['cp_id'])
					);
				}
				if(! $basic_presence) {
					q("insert into chatpresence ( cp_xchan, cp_last, cp_status, cp_client)
						values( '%s', '%s', '%s', '%s' ) ",
						dbesc(self::$ob_hash),
						dbesc(datetime_convert()),
						dbesc('online'),
						dbesc($_SERVER['REMOTE_ADDR'])
					);
				}
			}

			XConfig::Load(self::$ob_hash);

			$result = XConfig::Get(self::$ob_hash, 'sse', 'notifications', []);
			$lock = XConfig::Get(self::$ob_hash, 'sse', 'lock');

			if($result && !$lock) {
				echo "event: notifications\n";
				echo 'data: ' . json_encode($result);
				echo "\n\n";

				XConfig::Set(self::$ob_hash, 'sse', 'notifications', []);
				unset($result);
			}

			// always send heartbeat to detect disconnected clients
			echo "event: heartbeat\n";
			echo 'data: {}';
			echo "\n\n";

			ob_end_flush();
			flush();

			if(connection_status() != CONNECTION_NORMAL || connection_aborted()) {
				//TODO: this does not seem to be triggered
				XConfig::Set(self::$ob_hash, 'sse', 'timestamp', NULL_DATE);
				break;
			}

			sleep($sleep_seconds);

		}

	}

}
