<?php /** @file */

/**
 * API Login via basic-auth or OAuth
 */

function api_login(&$a){

	$record = null;
	$remote_auth = false;
	$sigblock = null;

	require_once('include/oauth.php');


	if(array_key_exists('REDIRECT_REMOTE_USER',$_SERVER) && (! array_key_exists('HTTP_AUTHORIZATION',$_SERVER))) {
		$_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_REMOTE_USER'];
	}

	// login with oauth

	try {
		// OAuth 2.0
		$storage = new \Zotlabs\Identity\OAuth2Storage(\DBA::$dba->db);
		$server = new \Zotlabs\Identity\OAuth2Server($storage);
		$request = \OAuth2\Request::createFromGlobals();
		if ($server->verifyResourceRequest($request)) {
			$token = $server->getAccessTokenData($request);
			$uid = $token['user_id'];
			$r = q("SELECT * FROM channel WHERE channel_id = %d LIMIT 1", 
				intval($uid)
			);
			if (count($r)) {
				$record = $r[0];
			} else {
				header('HTTP/1.0 401 Unauthorized');
				echo('This api requires login');
				killme();
			}

			$_SESSION['uid'] = $record['channel_id'];
			$_SESSION['addr'] = $_SERVER['REMOTE_ADDR'];

			$x = q("select * from account where account_id = %d LIMIT 1", 
				intval($record['channel_account_id'])
			);
			if ($x) {
				require_once('include/security.php');
				authenticate_success($x[0], null, true, false, true, true);
				$_SESSION['allow_api'] = true;
				call_hooks('logged_in', App::$user);
				return;
			}
		} else {
			// OAuth 1.0
			$oauth = new ZotOAuth1();
			$req = OAuth1Request::from_request();

			list($consumer, $token) = $oauth->verify_request($req);

			if (!is_null($token)) {
				$oauth->loginUser($token->uid);

				App::set_oauth_key($consumer->key);

				call_hooks('logged_in', App::$user);
				return;
			}
			killme();
		}
	} catch (Exception $e) {
		logger($e->getMessage());
	}


	if(array_key_exists('HTTP_AUTHORIZATION',$_SERVER)) {

		/* Basic authentication */

		if (substr(trim($_SERVER['HTTP_AUTHORIZATION']),0,5) === 'Basic') {
			$userpass = @base64_decode(substr(trim($_SERVER['HTTP_AUTHORIZATION']),6)) ;
			if(strlen($userpass)) {
				list($name, $password) = explode(':', $userpass);
				$_SERVER['PHP_AUTH_USER'] = $name;
				$_SERVER['PHP_AUTH_PW']   = $password;
			}
		}

		/* OpenWebAuth */

		if(substr(trim($_SERVER['HTTP_AUTHORIZATION']),0,9) === 'Signature') {

			$record = null;

			$sigblock = \Zotlabs\Web\HTTPSig::parse_sigheader($_SERVER['HTTP_AUTHORIZATION']);
			if($sigblock) {
				$keyId = str_replace('acct:','',$sigblock['keyId']);
				if($keyId) {
					$r = q("select * from hubloc where ( hubloc_addr = '%s' or hubloc_id_url = '%s' ) limit 1",
						dbesc($keyId),
						dbesc($keyId)
					);
					if($r) {
						$c = channelx_by_hash($r[0]['hubloc_hash']);
						if (! $c) {
							$c = channelx_by_portid($r[0]['hubloc_hash']);
						}
						if($c) {
							$a = q("select * from account where account_id = %d limit 1",
								intval($c['channel_account_id'])
							);
							if($a) {
								$record = [ 'channel' => $c, 'account' => $a[0] ];
								$channel_login = $c['channel_id'];
							}
						}
					}

					if($record) {					
						$verified = \Zotlabs\Web\HTTPSig::verify('',$record['channel']['channel_pubkey']);
						if(! ($verified && $verified['header_signed'] && $verified['header_valid'])) {
							$record = null;
						}
					}
				}
			}
		}
	}

	require_once('include/auth.php');
	require_once('include/security.php');

	// process normal login request

	if(isset($_SERVER['PHP_AUTH_USER']) && (! $record)) {
		$channel_login = 0;
		$record = account_verify_password($_SERVER['PHP_AUTH_USER'],$_SERVER['PHP_AUTH_PW']);
		if($record && $record['channel']) {
			$channel_login = $record['channel']['channel_id'];
		}
	}

	if($record['account']) {
		authenticate_success($record['account']);

		if($channel_login)
			change_channel($channel_login);

		$_SESSION['allow_api'] = true;
		return true;
	}
	else {
		$_SERVER['PHP_AUTH_PW'] = '*****';
		logger('API_login failure: ' . print_r($_SERVER,true), LOGGER_DEBUG);
		log_failed_login('API login failure');
		retry_basic_auth();
	}

}


function retry_basic_auth($method = 'Basic') {
	header('WWW-Authenticate: ' . $method . ' realm="Hubzilla"');
	header('HTTP/1.0 401 Unauthorized');
	echo('This api requires login');
	killme();
}