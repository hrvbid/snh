<?php

namespace Zotlabs\Daemon;

if(array_search( __file__ , get_included_files()) === 0) {
	require_once('include/cli_startup.php');
	array_shift($argv);
	$argc = count($argv);

	if($argc)
		Master::Release($argc,$argv);
	return;
}



class Master {

	static public function Summon($arr) {
		$hookinfo = [
			'argv'=>$arr
		];

		call_hooks ('daemon_master_summon',$hookinfo);

		$arr = $hookinfo['argv'];
		$argc = count($arr);

		if ((!is_array($arr) || (count($arr) < 1))) {
			logger("Summon handled by hook.",LOGGER_DEBUG);
			return;
		}

		$phpbin = get_config('system','phpbin','php');
		proc_run($phpbin,'Zotlabs/Daemon/Master.php',$arr);
	}

	static public function Release($argc,$argv) {
		cli_startup();

		$hookinfo = [
			'argv'=>$argv
		];

		call_hooks ('daemon_master_release',$hookinfo);

		$argv = $hookinfo['argv'];
		$argc = count($argv);

		if ((!is_array($argv) || (count($argv) < 1))) {
			logger("Release handled by hook.",LOGGER_DEBUG);
			return;
		}

		logger('Master: release: ' . json_encode($argv), LOGGER_ALL,LOG_DEBUG);
                $cls = '\\Zotlabs\\Daemon\\' . $argv[0];
                $cls::run($argc,$argv);
	}
}
