<?php
/**
 * Copyright 2014 Facebook, Inc.
 *
 * You are hereby granted a non-exclusive, worldwide, royalty-free license to
 * use, copy, modify, and distribute this software in source code or binary
 * form for use in connection with the web services and APIs provided by
 * Facebook.
 *
 * As with any software that integrates with the Facebook platform, your use
 * of this software is subject to the Facebook Developer Principles and
 * Policies [http://developers.facebook.com/policy/]. This copyright notice
 * shall be included in all copies or substantial portions of the software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 *
 */
namespace Facebook\Helpers;

use Facebook\Facebook;
use Facebook\Entities\AccessToken;
use Facebook\Entities\FacebookApp;
use Facebook\Url\UrlInterface;
use Facebook\Url\FacebookUrlManipulator;
use Facebook\Exceptions\FacebookSDKException;
use Facebook\FacebookClient;

/**
 * Class FacebookRedirectLoginHelper
 * @package Facebook
 * @author Fosco Marotto <fjm@fb.com>
 * @author David Poll <depoll@fb.com>
 */
class FacebookRedirectLoginHelper
{

  /**
   * @var FacebookApp The FacebookApp entity.
   */
  protected $app;

  /**
   * @var UrlInterface The URL handler.
   */
  protected $urlHandler;

  /**
   * @var FacebookUrlManipulator The URL manipulator.
   */
  protected $urlManipulator;

  /**
   * @var string Prefix to use for session variables.
   */
  protected $sessionPrefix = 'FBRLH_';

  /**
   * @var boolean Toggle for PHP session status check.
   */
  protected $checkForSessionStatus = true;

  /**
   * Constructs a RedirectLoginHelper for a given appId.
   *
   * @param FacebookApp $app The FacebookApp entity.
   * @param UrlInterface $urlHandler The URL handler.
   * @param FacebookUrlManipulator $urlManipulator The URL manipulator.
   */
  public function __construct(FacebookApp $app,
                              UrlInterface $urlHandler,
                              FacebookUrlManipulator $urlManipulator)
  {
    $this->app = $app;
    $this->urlHandler = $urlHandler;
    $this->urlManipulator = $urlManipulator;
  }

  /**
   * Stores CSRF state and returns a URL to which the user should be sent to
   *   in order to continue the login process with Facebook.  The
   *   provided redirectUrl should invoke the handleRedirect method.
   *   If a previous request to certain permission(s) was declined
   *   by the user, rerequest should be set to true or the permission(s)
   *   will not be re-asked.
   *
   * @param string $redirectUrl The URL Facebook should redirect users to
   *                            after login.
   * @param array $scope List of permissions to request during login.
   * @param boolean $rerequest Toggle for this authentication to be a rerequest.
   * @param string $version Optional Graph API version if not default (v2.0).
   * @param string $separator The separator to use in http_build_query().
   *
   * @return string
   */
  public function getLoginUrl($redirectUrl,
                              array $scope = [],
                              $rerequest = false,
                              $version = null,
                              $separator = '&')
  {
    $version = $version ?: Facebook::DEFAULT_GRAPH_VERSION;
    $state = $this->generateState();
    $this->storeState($state);
    $params = [
      'client_id' => $this->app->getId(),
      'redirect_uri' => $redirectUrl,
      'state' => $state,
      'sdk' => 'php-sdk-' . Facebook::VERSION,
      'scope' => implode(',', $scope)
    ];

    if ($rerequest) {
      $params['auth_type'] = 'rerequest';
    }

    return 'https://www.facebook.com/' . $version . '/dialog/oauth?' .
      http_build_query($params, null, $separator);
  }

  /**
   * Returns the URL to send the user in order to log out of Facebook.
   *
   * @param AccessToken|string $accessToken The access token that will be logged out.
   * @param string $next The url Facebook should redirect the user to after
   *                          a successful logout.
   * @param string $separator The separator to use in http_build_query().
   *
   * @return string
   */
  public function getLogoutUrl($accessToken, $next, $separator = '&')
  {
    $params = [
      'next' => $next,
      'access_token' => (string) $accessToken,
    ];
    return 'https://www.facebook.com/logout.php?' . http_build_query($params, null, $separator);
  }

  /**
   * Takes a valid code from a login redirect, and returns an AccessToken entity.
   *
   * @param FacebookClient $client The Facebook client.
   * @param string|null $redirectUrl The redirect URL.
   *
   * @return AccessToken|null
   *
   * @throws FacebookSDKException
   */
  public function getAccessToken(FacebookClient $client, $redirectUrl = null)
  {
    if ($this->isValidRedirect()) {
      $code = $this->getCode();
      $redirectUrl = $redirectUrl ?: $this->urlHandler->getCurrentUrl();

      $paramsToFilter = [
        'state',
        'code',
        'error',
        'error_reason',
        'error_description',
        'error_code',
        ];
      $redirectUrl = $this->urlManipulator->removeParamsFromUrl($redirectUrl, $paramsToFilter);

      return AccessToken::getAccessTokenFromCode($code, $this->app, $client, $redirectUrl);
    }
    return null;
  }

  /**
   * Check if a redirect has a valid state.
   *
   * @return bool
   */
  protected function isValidRedirect()
  {
    return $this->getCode() && isset($_GET['state'])
        && $_GET['state'] == $this->loadState();
  }

  /**
   * Return the code.
   *
   * @return string|null
   */
  protected function getCode()
  {
    return isset($_GET['code']) ? $_GET['code'] : null;
  }

  /**
   * Generate a state string for CSRF protection.
   *
   * @return string
   */
  protected function generateState()
  {
    return $this->random(16);
  }

  /**
   * Stores a state string in session storage for CSRF protection.
   * Developers should subclass and override this method if they want to store
   *   this state in a different location.
   *
   * @param string $state
   *
   * @throws FacebookSDKException
   */
  protected function storeState($state)
  {
    if ($this->checkForSessionStatus === true
      && session_status() !== PHP_SESSION_ACTIVE) {
      throw new FacebookSDKException(
        'Session not active, could not store state.', 720
      );
    }
    $_SESSION[$this->sessionPrefix . 'state'] = $state;
  }

  /**
   * Loads a state string from session storage for CSRF validation.  May return
   *   null if no object exists.  Developers should subclass and override this
   *   method if they want to load the state from a different location.
   *
   * @return string|null
   *
   * @throws FacebookSDKException
   */
  protected function loadState()
  {
    if ($this->checkForSessionStatus === true
      && session_status() !== PHP_SESSION_ACTIVE) {
      throw new FacebookSDKException(
        'Session not active, could not load state.', 721
      );
    }
    if (isset($_SESSION[$this->sessionPrefix . 'state'])) {
      return $_SESSION[$this->sessionPrefix . 'state'];
    }
    return null;
  }

  /**
   * Generate a cryptographically secure pseudrandom number.
   * 
   * @param int $bytes Number of bytes to return.
   * 
   * @return string
   * 
   * @throws FacebookSDKException
   * 
   * @TODO Add support for Windows platforms.
   */
  private function random($bytes)
  {
    if (!is_numeric($bytes)) {
      throw new FacebookSDKException(
        "random() expects an integer"
      );
    }
    if ($bytes < 1) {
      throw new FacebookSDKException(
        "random() expects an integer greater than zero"
      );
    }
    $buf = '';
    // http://sockpuppet.org/blog/2014/02/25/safely-generate-random-numbers/
    if (!ini_get('open_basedir')
      && is_readable('/dev/urandom')) {
      $fp = fopen('/dev/urandom', 'rb');
      if ($fp !== FALSE) {
        $buf = fread($fp, $bytes);
        fclose($fp);
        if($buf !== FALSE) {
          return bin2hex($buf);
        }
      }
    }

    if (function_exists('mcrypt_create_iv')) {
        $buf = mcrypt_create_iv($bytes, MCRYPT_DEV_URANDOM);
        if ($buf !== FALSE) {
          return bin2hex($buf);
        }
    }
    
    while (strlen($buf) < $bytes) {
      $buf .= md5(uniqid(mt_rand(), true), true); 
      // We are appending raw binary
    }
    return bin2hex(substr($buf, 0, $bytes));
  }

  /**
   * Disables the session_status() check when using $_SESSION.
   */
  public function disableSessionStatusCheck()
  {
    $this->checkForSessionStatus = false;
  }

}
