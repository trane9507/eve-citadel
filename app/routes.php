<?php

use Slim\Http\Request;
use Slim\Http\Response;

use Dflydev\FigCookies\Cookie;
use Dflydev\FigCookies\SetCookie;
use Dflydev\FigCookies\FigRequestCookies;
use Dflydev\FigCookies\FigResponseCookies;
use RestCord\DiscordClient;

//$mc = new Memcached();
//$mc->addServer("localhost", 11211);

require_once(__DIR__ . '/../lib/cURL.php');
require_once(__DIR__ . '/../lib/other.php');
require_once(__DIR__ . '/../lib/db.php');
require_once(__DIR__ . '/../lib/esi.php');
require_once(__DIR__ . '/../lib/ts3.php');

// Routes
$app->get('/', function (Request $request, Response $response) use ($config) {
    // Sample log message
    //$this->logger->info("Slim-Skeleton '/' route");

	$response = $this->renderer->render($response, 'header.phtml');
	$response = $this->renderer->render($response, 'index.phtml');
	$response = $this->renderer->render($response, 'footer.phtml');

    return $response;
});

$app->get('/login', function (Request $request, Response $response) use ($config) {

	$_SESSION['state'] = uniqid();
	
	//$ssoURL = "https://login.eveonline.com/oauth/authorize?response_type=code&redirect_uri=" . $config['sso']['callbackURL'] . "&client_id=" . $config['sso']['clientID'] . "&scope=publicData" . "&state=" . $state;
	
	$ssoURL = "https://login.eveonline.com/oauth/authorize?response_type=code&redirect_uri=" . $config['sso']['callbackURL'] . "&client_id=" . $config['sso']['clientID'] . "&state=" . $_SESSION['state'];
	
	$response = $this->renderer->render($response, 'header.phtml');
	$response = $this->renderer->render($response, 'login.phtml', [
		'ssoURL' => $ssoURL,
	]);
	$response = $this->renderer->render($response, 'footer.phtml');

    return $response;
});

$app->get('/login/contacts', function (Request $request, Response $response) use ($config) {

	if (citadeldb_users_check_admin($_SESSION['user_id'])) {
		$_SESSION['state'] = uniqid();
		
		$ssoURL = "https://login.eveonline.com/oauth/authorize?response_type=code&redirect_uri=" . $config['sso']['callbackURL'] . "&client_id=" . $config['sso']['clientID'] . "&scope=esi-alliances.read_contacts.v1" . "&state=" . $_SESSION['state'] . 'contacts';
		
		$response = $this->renderer->render($response, 'header.phtml');
		$response = $this->renderer->render($response, 'login.phtml', [
			'ssoURL' => $ssoURL,
		]);
		$response = $this->renderer->render($response, 'footer.phtml');

		return $response;
	} else {
		return $response->withRedirect('/dashboard');
	}
});

$app->get('/logout', function (Request $request, Response $response, $args) use ($config) {

	$cookie = FigRequestCookies::get($request, 'session_key');
	$session_key = $cookie->getValue();

	$response = FigResponseCookies::expire($response, 'session_key');
	citadeldb_session_delete($session_key);
	session_unset();

    return $response->withRedirect('/');
});

$app->get('/dashboard', function (Request $request, Response $response, $args) use ($config) {

	$cookie = FigRequestCookies::get($request, 'session_key');
	$session_key = $cookie->getValue();

	if (isset($session_key)) {
		if (citadeldb_session_check_key($session_key)) {

			if (!isset($_SESSION['user_id'])) {
				$_SESSION['user_id'] = citadeldb_session_get_id($session_key);
			}
			if (!isset($_SESSION['character_id'])) {
				$_SESSION['character_id'] = citadeldb_users_select_id($_SESSION['user_id']);
			}
			if (!isset($_SESSION['discord_id'])) {
				$_SESSION['discord_id'] = discord_users_select($_SESSION['user_id']);
			}
			if (!isset($_SESSION['teamspeak_data'])) {
				$_SESSION['teamspeak_data'] = teamspeak_users_select($_SESSION['user_id']);
			}

			$discord_auth_state = "no";
			if ($_SESSION['discord_id'] == null) {
				$_SESSION['state'] = uniqid();
				$discord_url = "https://discordapp.com/api/oauth2/authorize?client_id=" . $config["discord"]["clientID"] . "&redirect_uri=" . $config['discord']['callbackURL'] . "&response_type=code" . "&scope=identify guilds.join" . "&state=" . $_SESSION['state'];
			} else {
				$discord_auth_state = "yes";
				$discord_url = "https://discordapp.com/";
			}
			
			if (!isset($_SESSION['char_data'])) {
				$_SESSION['char_data'] = esi_character_get_details($_SESSION['character_id']);
			}
			if (!isset($_SESSION['corp_data'])) {
				$_SESSION['corp_data'] = esi_corporation_get_details($_SESSION['char_data']['corporation_id']);
			}
			if (!isset($_SESSION['alliance_name'])) {
				if (!isset($_SESSION['char_data']['alliance_id'])) {
					$_SESSION['alliance_name'] = "Your are not in alliance";
				} else {
					$_SESSION['alliance_data'] = esi_alliance_get_details($_SESSION['corp_data']['alliance_id']);
					$_SESSION['alliance_name'] = $_SESSION['alliance_data']['name'];
				}
			}
			if (!isset($_SESSION['is_admin'])) {
				$_SESSION['is_admin'] = citadeldb_users_check_admin($_SESSION['user_id']);
			}
			
			if ($config['auth']['nameEnforce']) {
				$teamspeak_nick = $_SESSION['char_data']['name'];
				if ($config['auth']['corpTicker']) {
					$teamspeak_nick = "[".$_SESSION['corp_data']['ticker']."] ".$teamspeak_nick;
				}
			}
			
			$response = $this->renderer->render($response, 'header.phtml');
			$response = $this->renderer->render($response, 'dashboard.phtml', [
				'character_id' => $_SESSION['character_id'],
				'discord_auth_state' => $discord_auth_state,
				'discord_url' => $discord_url,
				'teamspeak_url' => $config['ts3_url'],
				'teamspeak_nick' => $teamspeak_nick,
				'teamspeak_token' => $_SESSION['teamspeak_data']['teamspeak_token'],
				'char_name' => $_SESSION['char_data']['name'],
				'corp_name' => $_SESSION['corp_data']['name'],
				'alliance_name' => $_SESSION['alliance_name'],
				'is_admin' => $_SESSION['is_admin'],
			]);
			$response = $this->renderer->render($response, 'footer.phtml');

			return $response;
		} else {
			citadeldb_session_delete($session_key);
			return $response->withRedirect('/login');
		}
	} else {
		return $response->withRedirect('/login');
	}
})->setName('dashboard');

$app->get('/dashboard/refresh', function (Request $request, Response $response) use ($config) {

	session_unset();

    return $response->withRedirect('/dashboard');
});

//$app->get('/eveonline/callback', function (Request $request, Response $response, $args) use ($config) {
$app->get('/callback-sso', function (Request $request, Response $response, $args) use ($config) {

	$state = $request->getParam("state");
	$code = $request->getParam("code");

	$base64 = base64_encode($config["sso"]["clientID"] . ":" . $config["sso"]["secretKey"]);
	$tokenURL = "https://login.eveonline.com/oauth/token";
	$verifyURL = "https://login.eveonline.com/oauth/verify";
	$data = json_decode(sendData($tokenURL, array(
		"grant_type" => "authorization_code",
		"code" => $code
	), array("Authorization: Basic {$base64}")));

	$accessToken = $data->access_token;
	$refreshToken = $data->refresh_token;

	$data = json_decode(sendData($verifyURL, array(), array("Authorization: Bearer {$accessToken}")));
	$character_id = $data->CharacterID;

	if ($state == $_SESSION['state']) {
		if (isset($character_id)) {
			$user = citadeldb_users_select($character_id);

			if (!isset($user['character_id'])) {
				$user = array();
				$user['character_id'] = $data->CharacterID;
				foreach ($config['auth']['default_admins'] as $admin_id) {
					if ($user['character_id'] == $admin_id) {
						citadeldb_users_add_admin($character_id);
					} else {
						citadeldb_users_add($character_id);
					}
				}
				$user = citadeldb_users_select($character_id);
			}

			$citadel_session = citadeldb_session_get($user['id']);
			if (isset($citadel_session['session_key'])) {
				if (strtotime($citadel_session['expire']) <= time()) {
					citadeldb_session_delete($citadel_session['session_key']);
				}
			}

			$session_key = uniqidReal(40);
			$expire =  time()+60*60*24;
			$expire_timestamp = date("Y-m-d H:i:s", $expire);
			$response = FigResponseCookies::set($response, SetCookie::create('session_key')
				->withValue($session_key)
				->withExpires($expire)
				//->withMaxAge($expire)
				//->rememberForever()
			);

			citadeldb_session_add($user['id'], $session_key, $expire_timestamp);

			unset($_SESSION['state']);
			
			$_SESSION['session_key'] = $session_key;

			//$url = $this->router->pathFor('dashboard',[], $user);
			//return $response->withRedirect($url);
			return $response->withRedirect('/dashboard');
		} else {
			return $response->withRedirect('/login');
		}
	} elseif ($state == ($_SESSION['state'].'contacts')) {
		$scope = 'esi-alliances.read_contacts.v1';
		$expire_date =  time()+19*60;
		$expire_date = date("Y-m-d H:i:s", $expire_date);
		$contacts_token = citadeldb_custom_get('contacts_token');
		if (!isset($contacts_token)) {
			$token_data = citadeldb_token_get($_SESSION['user_id'], $scope);
			if ($token_data == null) {
				citadeldb_token_add($_SESSION['user_id'], $accessToken, $refreshToken, $scope, $expire_date);
				citadeldb_custom_add('contacts_token', $_SESSION['user_id']);
			} else {
				citadeldb_token_updatefull($_SESSION['user_id'], $accessToken, $refreshToken, $scope, $expire_date);
			}
		}
		return $response->withRedirect('/dashboard');
	} else {
		return $response->withRedirect('/login');
	}
});

$app->get('/discord/deactivate', function (Request $request, Response $response) use ($config) {

    $this->logger->info("Slim-Skeleton '/discord/deactivate' route");

	$discord_client = new DiscordClient([
		'token' => $config['discord']['token']
	]);
	$discord_client->guild->removeGuildMember([
		'guild.id' => (int)$config['discord']['guildID'],
		'user.id' => (int)$_SESSION['discord_id']
	]);
	discord_users_delete($_SESSION['user_id']);
	
	unset($_SESSION['discord_id']);

    return $response->withRedirect('/dashboard');
});

$app->get('/discord/callback', function (Request $request, Response $response) use ($config) {

	$code = $request->getParam("code");
	$state = $request->getParam("state");

	$discordOAuthProvider = new \Discord\OAuth\Discord([
		'clientId' => $config["discord"]["clientID"],
		'clientSecret' => $config["discord"]["secretKey"],
		'redirectUri' => $config["discord"]["callbackURL"]
	]);

	$token = $discordOAuthProvider->getAccessToken('authorization_code', [
		'code' => $code,
	]);

	$user = $discordOAuthProvider->getResourceOwner($token);
	$discordID = $user->id;

	$discord_client = new DiscordClient([
		'token' => $config['discord']['token']
	]);
	$guild = $discord_client->guild->getGuild([
		'guild.id' => (int)$config['discord']['guildID']
	]);
	$roles = $discord_client->guild->getGuildRoles([
		'guild.id' => (int)$config['discord']['guildID']
	]);

	if (isset($_SESSION['char_data']['alliance_id'])) {
		foreach ($config['auth']['groups'] as $group) {
			if ($_SESSION['char_data']['alliance_id'] == $group['id']) {
				$invite = $user->acceptInvite($config["discord"]["inviteLink"]);
				discord_users_add($_SESSION['user_id'], $discordID);
				if ($group['setCorpRole']) {
					$corp_role_name = "".$_SESSION['corp_data']['ticker']." Corporation";
					$corp_role = null;
				}
				foreach ($roles as $role) {
					if ($role->name == $group['role']) {
						$discord_client->guild->addGuildMemberRole([
							'guild.id' => (int)$config['discord']['guildID'],
							'user.id' => (int)$discordID,
							'role.id' => (int)$role->id
						]);
					}
					if ($group['setCorpRole']) {
						if ($role->name == $corp_role_name) {
							$corp_role = $role;
						}
					}
				}
				if ($group['setCorpRole']) {
					if ($corp_role == null) {
						$corp_role = $discord_client->guild->createGuildRole([
							'guild.id' => (int)$config['discord']['guildID'],
							'name' => $corp_role_name,
							'hoist' => true,
							'color' => (int)$group['corpColour']
						]);
					}
					$discord_client->guild->addGuildMemberRole([
						'guild.id' => (int)$config['discord']['guildID'],
						'user.id' => (int)$discordID,
						'role.id' => (int)$corp_role->id
					]);
				}
				break;
			}
		}
	} else {
		return $response->withRedirect('/dashboard');
	}
	
	if ($config['auth']['nameEnforce']) {
		$discord_nick = $_SESSION['char_data']['name'];
		if ($config['auth']['corpTicker']) {
			$discord_nick = "[".$_SESSION['corp_data']['ticker']."] ".$discord_nick;
		}
		$discord_client->guild->modifyGuildMember(['guild.id' => (int)$config['discord']['guildID'], 'user.id' => (int)$discordID, 'nick' => $discord_nick]);
	}

	return $response->withRedirect('/dashboard');
});

$app->get('/teamspeak/activate', function (Request $request, Response $response) use ($config) {

	if (!isset($_SESSION['character_id'])) {
		return $response->withRedirect('/dashboard');
	}
	$char_info = esi_character_get_details($_SESSION['character_id']);
	$corp_info = esi_corporation_get_details($char_info['corporation_id']);
	foreach ($config['auth']['groups'] as $group) {
		if ($corp_info['alliance_id'] == $group['id']) {
			$role = $group['role'];
			break;
		}
	}
	$group_id = TSGroupGetByName($role);
	if ($group_id == null) {
		$group_id = TSGroupAdd($role);
	}
	$ts_user = TSAddUser($_SESSION['character_id'], $char_info['name'], $group_id);
	teamspeak_users_add($_SESSION['user_id'], $ts_user['token']);

    return $response->withRedirect('/dashboard');
});

$app->get('/teamspeak/deactivate', function (Request $request, Response $response) use ($config) {

	if (!isset($_SESSION['character_id'])) {
		return $response->withRedirect('/dashboard');
	}

	$ts_user = teamspeak_users_select($config["db"]["url"], $config["db"]["user"], $config["db"]["pass"], $config["db"]["dbname"], $_SESSION['user_id']);
    TSDelUser($_SESSION['character_id'], $ts_user['teamspeak_token']);
	teamspeak_users_delete($_SESSION['user_id']);
	unset($_SESSION['teamspeak_data']);

    return $response->withRedirect('/dashboard');
});

$app->get('/phpbb3/activate', function (Request $request, Response $response) use ($config) {

	if (!isset($_SESSION['character_id'])) {
		return $response->withRedirect('/dashboard');
	}
	
	include($phpbb_root_path . 'includes/functions_user.' . $phpEx);

	$user_row = array(
		'username'              => $username,
		'user_password'         => phpbb_hash($password),
		'user_email'            => $email_address,
		'group_id'              => (int) $group_id,
		'user_timezone'         => (float) $timezone,
		'user_dst'              => $is_dst,
		'user_lang'             => $language,
		'user_type'             => $user_type,
		'user_actkey'           => $user_actkey,
		'user_ip'               => $user_ip,
		'user_regdate'          => $registration_time,
		'user_inactive_reason'  => $user_inactive_reason,
		'user_inactive_time'    => $user_inactive_time,
	);

	// all the information has been compiled, add the user
	// tables affected: users table, profile_fields_data table, groups table, and config table.
	$user_id = user_add($user_row);

    return $response->withRedirect('/dashboard');
});

$app->get('/phpbb3/deactivate', function (Request $request, Response $response) use ($config) {

	if (!isset($_SESSION['character_id'])) {
		return $response->withRedirect('/dashboard');
	}

    return $response->withRedirect('/dashboard');
});




$app->get('/test', function (Request $request, Response $response) {
    // Sample log message
    $this->logger->info("Slim-Skeleton '/test' route");

    // Render index view
    return $this->renderer->render($response, 'test/index.phtml');
});

$app->get('/testdh', function (Request $request, Response $response) {
    // Sample log message
    $this->logger->info("Slim-Skeleton '/test' route");

    // Render index view
    return $this->renderer->render($response, 'test/dashboard.phtml');
});