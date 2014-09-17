<?php

/**
 * ownCloud - roundcube mail plugin
 *
 * @author Martin Reinhardt and David Jaedke
 * @copyright 2012 Martin Reinhardt contact@martinreinhardt-online.de
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * This class manages the roundcube app. It enables the db integration and
 * connects to the roundcube installation via the roundcube API
 */
class OC_RoundCube_App {

	const SESSION_ATTR_RCPRIVKEY = 'OC\\ROUNDCUBE\\privateKey';

	private $path='';

	/**
	 * @brief write basic information for the user in the app configu
	 * @param user object $meUser
	 * @returns true/false
	 *
	 * This function creates a simple personal entry for each user to distinguish them later
	 *
	 * It also chekcs the login data
	 */
	public static function writeBasicData($meUser) {
		OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->writeBasicData(): Writing basic data for ' . $meUser, OCP\Util::DEBUG);
		$stmt = OCP\DB::prepare("INSERT INTO *PREFIX*roundcube (oc_user) VALUES (?)");
		$result = $stmt -> execute(array($meUser));
		return self::checkLoginData($meUser, 1);
	}

	/**
	 * @brief chek the login parameters
	 * @param user object $ocUser
	 * @param write the basic user data to db
	 * @returns the login data
	 *
	 * This function tries to load the configured login data for roundcube and return it.
	 */
	public static function checkLoginData($ocUser, $written = 0) {
		OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->checkLoginData(): Checking login data for ' . $ocUser, OCP\Util::DEBUG);
		$stmt = OCP\DB::prepare('SELECT * FROM *PREFIX*roundcube WHERE oc_user=?');
		$result = $stmt -> execute(array($ocUser));
		$mailEntries = $result -> fetchAll();
		if (count($mailEntries) > 0) {
			OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->checkLoginData(): Found login data for ' . $ocUser, OCP\Util::DEBUG);
			return $mailEntries;
		} elseif ($written == 0) {
			OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->checkLoginData(): Did not found login data for ' . $ocUser, OCP\Util::DEBUG);
			return self::writeBasicData($ocUser);
		}
	}

	/**
	 * Generate a private/public key pair.
	 * @param  User ID$user.
	 * @param  Passphrase to $passphrase
	 *
	 * @return array('privateKey', 'publicKey')
	 */
	public static function generateKeyPair($user, $passphrase)
	{
		/* Create the private and public key */
		$res = openssl_pkey_new();
		/* Extract the private key from $res to $privKey */
		if (!openssl_pkey_export($res, $privKey, $passphrase)) {
			return false;
		}
		/* Extract the public key from $res to $pubKey */
		$pubKey = openssl_pkey_get_details($res);
		if ($pubKey === false) {
			return false;
		}
		$pubKey = $pubKey['key'];
		// We now store the public key unencrypted in the user preferences.
		// The private key already is encrypted with the user's password,
		// so there is no need to encrypt it again.
		\OCP\Config::setUserValue($user, 'roundcube', 'publicSSLKey', $pubKey);
		\OCP\Config::setUserValue($user, 'roundcube', 'privateSSLKey', $privKey);
		return array('privateKey' => $privKey, 'publicKey' => $pubKey);
	}


	/**
	 * Get users public key
	 * @param  user $user
	 * @return public key
	 */
	public static function getPublicKey($user)
	{
		$pubKey = \OCP\Config::getUserValue($user, 'roundcube', 'publicSSLKey', false);
		return $pubKey;
	}

	/**
	 * Get private key for user
	 * @param user $user
	 * @param passphrase to use $passphrase
	 * @return private key|boolean
	 */
	public static function getPrivateKey($user, $passphrase)
	{
		$privKey = \OCP\Config::getUserValue($user, 'roundcube', 'privateSSLKey', $passphrase);
		// need to create key pair
		if ($privKey === false) {
			$result = self::generateKeyPair($user, $passphrase);
			$privKey = $result['privateKey'];
		}
		// save key attribute in session
		$_SESSION[self::SESSION_ATTR_RCPRIVKEY] = $privKey;
		return $privKey;
	}

	/**
	 * encrypt data ssl
	 * @param object to encrypt $entry
	 * @param public key $pubKey
	 * @return boolean|unknown
	 */
	public static function cryptMyEntry($entry, $pubKey) {
		if (openssl_public_encrypt($entry, $entry, $pubKey) === false) {
			return false;
		}
		$entry = base64_encode($entry);
		return $entry;
	}

	/**
	 * decrypt ssl-encrypted data
	 * @param data to encrypt $data
	 * @param private key $privKey
	 * @return void|unknown
	 */
	public static function decryptMyEntry($data, $privKey) {
		$data = base64_decode($data);
		if (openssl_private_decrypt($data, $data, $privKey) === false) {
			return;
		}
		return $data;
	}

	/**
	 * Use the pulic key of the respective user to encrypt the given
	 * email identity and store it in the data-base.
	 * @param The OwnCloud user id $ocUser
	 * @param The IMAP account Id $emailUser
	 * @param unknown $emailPassword
	 * @return The IMAP credentials.|unknown
	 */
	public static function cryptEmailIdentity($ocUser, $emailUser, $emailPassword){
		OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->cryptEmailIdentity(): Updating roundcube profile for '. $ocUser, OCP\Util::DEBUG);
		$mail_userdata_entries = self::checkLoginData($ocUser);
		$pubKey = self::getPublicKey($ocUser);
		$mail_userdata = $mail_userdata_entries[0];
		if ($mail_userdata_entries === false || $pubKey === false) {
			return false;
		}
		$mail_username =self::cryptMyEntry($emailUser, $pubKey);
		$mail_password =self::cryptMyEntry($emailPassword, $pubKey);
		if ($mail_username === false || $mail_password === false) {
			return false;
		}
		$stmt = OCP\DB::prepare("UPDATE *PREFIX*roundcube SET mail_user = ?, mail_password = ? WHERE oc_user = ?");
		$result = $stmt -> execute(array($mail_username, $mail_password, $ocUser));
		return $result;
	}

	/**
	 * Logs the current user out from roundcube
	 * @param roundcube server address $rcHost
	 * @param roundcube server port $rcPort
	 * @param path to roundcube installation, Note: The first parameter is the URL-path of the RC inst
	 * NOT the file-system path http://host.com/path/to/roundcube/ --> "/path/to/roundcube" $maildir
	 * @param roundcube usernam $user
	 */
	public static function logout($rcHost, $rcPort, $maildir, $user) {
		$enableDebug = OCP\Config::getAppValue('roundcube', 'enableDebug', 'true');
		$rcl = new OC_RoundCube_Login($rcHost, $rcPort, $maildir, $enableDebug);
		if ($rcl -> logout()) {
			OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->logout(): '. $user.' successfully logged off from roundcube ', OCP\Util::INFO);
		} else {
			OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->logout(): Failed to log-off '.$user.' from roundcube ', OCP\Util::ERROR);
		}
	}

	/**
	 * Login to roundcube host
	 *
	 * @param roundcube host to use $rcHost
	 * @param port of the roundcube server $rcPort
	 * @param context path of roundcube $maildir
	 * @param login to be used $pLogin
	 * @param password to be used $pPassword
	 */
	public static function login($rcHost, $rcPort, $maildir, $pLogin, $pPassword) {
		// Create RC login object.
		$enableDebug = OCP\Config::getAppValue('roundcube', 'enableDebug', 'true');
		$rcl = new OC_RoundCube_Login($rcHost, $rcPort, $maildir, $enableDebug);
		// Try to login
		$rcl -> login($pLogin, $pPassword);
		OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->login(): Trying to log into roundcube webinterface under ' . $maildir . ' as user ' . $pLogin, OCP\Util::DEBUG);
		if ($rcl -> isLoggedIn()) {
			OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->login(): '.$pLogin.' already logged into roundcube ', OCP\Util::DEBUG);
			return true;
		} else {
			$rcl -> login($pLogin, $pPassword);
			if ($rcl -> isLoggedIn()) {
				OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->login(): '.$pLogin.' successfully logged into roundcube ', OCP\Util::DEBUG);
				return true;
			} else {
				// If the login fails, display an error message in the loggs
				OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->login(): '.$pLogin.': RoundCube can\'t login to roundcube due to a login error to roundcube', OCP\Util::ERROR);
				return false;
			}
		}
	}

	/**
	 * Try to refresh roundcube session
	 * @param roundcube host to use $rcHost
	 * @param port of the roundcube server $rcPort
	 * @param context path of roundcube $maildir
	 * @return true if session refresh was successfull, otherwise false
	 */
	public static function refresh($rcHost, $rcPort, $maildir){
		$ocUser = OCP\User::getUser();
		// Create RC login object.
		$enableDebug = OCP\Config::getAppValue('roundcube', 'enableDebug', 'true');
		$rcl = new OC_RoundCube_Login($rcHost, $rcPort, $maildir, $enableDebug);
		// Try to refresh
		OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->refresh(): Trying to refresh RoundCube session under ' . $maildir, OCP\Util::DEBUG);
		if ($rcl -> isLoggedIn()) {
			OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->refresh(): Successfully refreshed the RC session.', OCP\Util::INFO);
			return true;
		} else {
			// login expired, we are
			if (isset($_SESSION[self::SESSION_ATTR_RCPRIVKEY])) {
				OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->refresh(): Login seems expired. Trying a new login.', OCP\Util::INFO);
				$privKey = $_SESSION[self::SESSION_ATTR_RCPRIVKEY];
				$mail_userdata_entries = self::checkLoginData($ocUser,1);
				// TODO create dropdown list
				$mail_userdata = $mail_userdata_entries[0];
				$rcLogin = self::decryptMyEntry($mail_userdata['mail_user'], $privKey);
				$rcPassword = self::decryptMyEntry($mail_userdata['mail_password'], $privKey);
				OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->refresh(): key is: '.$privKey, OCP\Util::DEBUG);
				OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->refresh(): user is: '.$rcLogin, OCP\Util::DEBUG);
				return self::login($rcHost, $rcPort, $maildir, $rcLogin, $rcPassword);
				OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->refresh(): New login done.', OCP\Util::INFO);
			} else{
				OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->refresh(): Failed to refresh the RC session.', OCP\Util::ERROR);
				return false;
			}
		}
	}

	/**
	 *
	 * @brief showing up roundcube iFrame
	 * @param roundcube host $rcHost
	 * @param roundcube port $rcPort
	 * @param path to roundcube installation, Note: The first parameter is the URL-path of the RC inst
	 * NOT the file-system path http://host.com/path/to/roundcube/ --> "/path/to/roundcube" $maildir
	 *
	 */
	public static function showMailFrame($rcHost, $rcPort, $maildir) {
		$ocUser = OCP\User::getUser();
		$privKey = $_SESSION[self::SESSION_ATTR_RCPRIVKEY];
		$returnObject = new OC_Mail_Object();
		$enableDebug = OCP\Config::getAppValue('roundcube', 'enableDebug', true);
		$enableAutologin = OCP\Config::getAppValue('roundcube', 'autoLogin', false);
		$mail_userdata_entries = self::checkLoginData($ocUser,1);
		// TODO create dropdown list
		$mail_userdata = $mail_userdata_entries[0];

		$rcLogin = self::decryptMyEntry($mail_userdata['mail_user'], $privKey);
		$rcPassword = self::decryptMyEntry($mail_userdata['mail_password'], $privKey);

		try {
			$loggedIn = self::login($rcHost, $rcPort, $maildir, $rcLogin, $rcPassword);
			if (!$loggedIn) {
				// If the login fails, display an error message in the logs
				OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->showMailFrame(): Not logged in.', OCP\Util::ERROR);
				OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->showMailFrame(): Trying to refresh session.', OCP\Util::INFO);
				if(!self::refresh($rcHost, $rcPort, $maildir)){
					throw new OC_Mail_LoginException("Unable to login to roundcube");
				}
			}
			OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->showMailFrame(): Preparing iFrame for roundcube.', OCP\Util::DEBUG);
			// loader image
			$loader_image = OCP\Util::imagePath('roundcube', 'loader.gif');
			$disable_header_nav = OCP\Config::getAppValue('roundcube', 'removeHeaderNav', 'false');
			$disable_control_nav = OCP\Config::getAppValue('roundcube', 'removeControlNav', 'false');

			$returnObject -> setDisplayName($rcLogin);
			// create iFrame begin
			$returnObject -> appendHtmlOutput('<img src="' . $loader_image . '" id="roundcubeLoader">');
			$returnObject -> appendHtmlOutput('<iframe src="' . self::getRedirectPath($rcHost, $rcPort, $maildir) . '" id="roundcubeFrame"  name="roundcube" width="100%" style="display:none;">  </iframe>');
			$returnObject -> appendHtmlOutput('<input type="hidden" id="disable_header_nav" value="' . $disable_header_nav . '"/>');
			$returnObject -> appendHtmlOutput('<input type="hidden" id="disable_control_nav" value="' . $disable_control_nav . '"/>');
			// create iFrame end
		} catch (OC_Mail_NetworkingException $ex_net) {
			$returnObject -> setErrorOccurred(true);
			$returnObject -> setErrorCode(OC_Mail_Object::ERROR_CODE_NETWORK);
			$returnObject -> setHtmlOutput('');
			$returnObject -> setErrorDetails("ERROR: Technical problem during trying to connect to roundcube server, " . $ex_net -> getMessage());
			OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->showMailFrame(): RoundCube can not login to roundcube due to a network connection exception to roundcube', OCP\Util::ERROR);
		} catch (OC_Mail_LoginException $ex_login) {
			$returnObject -> setErrorOccurred(true);
			if($enableAutologin){
				OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->showMailFrame(): Autologin is enabled. Seems that the owncloud and roundcube login details do not match', OCP\Util::ERROR);
				$returnObject -> setErrorCode(OC_Mail_Object::ERROR_CODE_AUTOLOGIN);
			} else {
				$returnObject -> setErrorCode(OC_Mail_Object::ERROR_CODE_LOGIN);
			}
			$returnObject -> setHtmlOutput('');
			$returnObject -> setErrorDetails("ERROR: Technical problem, " . $ex_login -> getMessage());
			OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->showMailFrame(): RoundCube can not login to roundcube due to a login exception to roundcube', OCP\Util::ERROR);
		} catch (OC_Mail_RC_InstallNotFoundException $ex_login) {
			$returnObject -> setErrorOccurred(true);
			$returnObject -> setErrorCode(OC_Mail_Object::ERROR_CODE_RC_NOT_FOUND);
			$returnObject -> setHtmlOutput('');
			$returnObject -> setErrorDetails("ERROR: Technical problem, " . $ex_login -> getMessage());
			OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->showMailFrame(): RoundCube can nott be found on the given path.', OCP\Util::ERROR);
		} catch (Exception $ex_login) {
			$returnObject -> setErrorOccurred(true);
			$returnObject -> setErrorCode(OC_Mail_Object::ERROR_CODE_GENERAL);
			$returnObject -> setHtmlOutput('');
			$returnObject -> setErrorDetails("ERROR: Technical problem, " . $ex_login -> getMessage());
			OCP\Util::writeLog('roundcube', 'OC_RoundCube_App.class.php->showMailFrame(): RoundCube can not login to roundcube due to a unkown exception to roundcube', OCP\Util::ERROR);
		}
		return $returnObject;
	}

	public static function getRedirectPath($pRcHost, $pRcPort, $pRcPath) {
		# Use a relative protocol in case we/roundcube are behind an SSL proxy (see
		# http://tools.ietf.org/html/rfc3986#section-4.2).
		$protocol = '//';
		if(strlen($pRcPort) > 1){
			$path = $protocol . rtrim($pRcHost, "/") . ":" . $pRcPort. "/"  . ltrim($pRcPath, "/");
		} else {
			$path = $protocol . rtrim($pRcHost, "/") . "/" . ltrim($pRcPath, "/");
		}
		return $path;
	}

}
