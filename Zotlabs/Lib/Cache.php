<?php /** @file */

namespace Zotlabs\Lib;

	/**
	 *  cache api
	 */
	 
class Cache {
    
    /**
     * @brief Returns cached content
     * 
     * @param string $key
     * @param string $age in SQL format, default is '30 DAY'
     * @return string
     */
    
	public static function get($key, $age = '') {

		$hash = hash('whirlpool',$key);

		$r = q("SELECT v FROM cache WHERE k = '%s' AND updated > %s - INTERVAL %s LIMIT 1",
			dbesc($hash),
			db_utcnow(),
<<<<<<< HEAD
			db_quoteinterval(get_config('system','object_cache_days', '30') . ' DAY')
=======
			db_quoteinterval(($age ? $age : get_config('system','object_cache_days', '30') . ' DAY'))
>>>>>>> 85c07d57fe0f468eb8cc84584f3636b590aa929f
		);
			
		if ($r)
			return $r[0]['v'];
		return null;
	}
		
	public static function set($key,$value) {

		$hash = hash('whirlpool',$key);

		$r = q("SELECT * FROM cache WHERE k = '%s' limit 1",
			dbesc($hash)
		);
		if($r) {
			q("UPDATE cache SET v = '%s', updated = '%s' WHERE k = '%s'",
				dbesc($value),
				dbesc(datetime_convert()),
				dbesc($hash));
		}
		else {
			q("INSERT INTO cache ( k, v, updated) VALUES ('%s','%s','%s')",
				dbesc($hash),
				dbesc($value),
				dbesc(datetime_convert()));
		}
	}
}
