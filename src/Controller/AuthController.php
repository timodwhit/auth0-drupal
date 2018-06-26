<?php

namespace Drupal\auth0\Controller;

/**
 * @file
 * Contains \Drupal\auth0\Controller\AuthController.
 */

// Create a variable to store the path to this module and load vendor
// files if they exist.
define('AUTH0_PATH', drupal_get_path('module', 'auth0'));

if (file_exists(AUTH0_PATH . '/vendor/autoload.php')) {
  require_once AUTH0_PATH . '/vendor/autoload.php';
}

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\user\PrivateTempStoreFactory;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\auth0\Event\Auth0UserSigninEvent;
use Drupal\auth0\Event\Auth0UserSignupEvent;
use Drupal\auth0\Exception\EmailNotSetException;
use Drupal\auth0\Exception\EmailNotVerifiedException;
use Drupal\auth0\Util\AuthHelper;

use Auth0\SDK\JWTVerifier;
use Auth0\SDK\Auth0;
use Auth0\SDK\API\Authentication;
use Auth0\SDK\API\Management;
use Auth0\SDK\Exception\CoreException;
use Auth0\SDK\API\Helpers\State\SessionStateHandler;
use Auth0\SDK\Store\SessionStore;

/**
 * Controller routines for auth0 authentication.
 */
class AuthController extends ControllerBase {
  const SESSION = 'auth0';
  const STATE = 'state';
  const AUTH0_LOGGER = 'auth0_controller';
  const AUTH0_DOMAIN = 'auth0_domain';
  const AUTH0_CLIENT_ID = 'auth0_client_id';
  const AUTH0_CLIENT_SECRET = 'auth0_client_secret';
  const AUTH0_REDIRECT_FOR_SSO = 'auth0_redirect_for_sso';
  const AUTH0_JWT_SIGNING_ALGORITHM = 'auth0_jwt_signature_alg';
  const AUTH0_SECRET_ENCODED = 'auth0_secret_base64_encoded';
  const AUTH0_OFFLINE_ACCESS = 'auth0_allow_offline_access';

  protected $eventDispatcher;
  protected $tempStore;
  protected $sessionManager;
  protected $logger;
  protected $config;
  protected $domain;
  protected $clientId;
  protected $clientSecret;
  protected $redirectForSso;
  protected $auth0JwtSignatureAlg;
  protected $secretBase64Encoded;
  protected $offlineAccess;
  protected $helper;
  protected $auth0;

  /**
   * Initialize the controller.
   *
   * @param \Drupal\user\PrivateTempStoreFactory $tempStoreFactory
   * @param \Drupal\Core\Session\SessionManagerInterface $sessionManager
   */
  public function __construct(PrivateTempStoreFactory $tempStoreFactory, SessionManagerInterface $sessionManager) {
    // Ensure the pages this controller servers never gets cached.
    \Drupal::service('page_cache_kill_switch')->trigger();

    $this->helper = new AuthHelper();

    $this->eventDispatcher = \Drupal::service('event_dispatcher');
    $this->tempStore = $tempStoreFactory->get(AuthController::SESSION);
    $this->sessionManager = $sessionManager;
    $this->logger = \Drupal::logger(AuthController::AUTH0_LOGGER);
    $this->config = \Drupal::service('config.factory')->get('auth0.settings');
    $this->domain = $this->config->get(AuthController::AUTH0_DOMAIN);
    $this->clientId = $this->config->get(AuthController::AUTH0_CLIENT_ID);
    $this->clientSecret = $this->config->get(AuthController::AUTH0_CLIENT_SECRET);
    $this->redirectForSso = $this->config->get(AuthController::AUTH0_REDIRECT_FOR_SSO);
    $this->auth0JwtSignatureAlg = $this->config->get(AuthController::AUTH0_JWT_SIGNING_ALGORITHM);
    $this->secretBase64Encoded = FALSE || $this->config->get(AuthController::AUTH0_SECRET_ENCODED);
    $this->offlineAccess = FALSE || $this->config->get(AuthController::AUTH0_OFFLINE_ACCESS);

    $this->auth0 = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
        $container->get('user.private_tempstore'),
        $container->get('session_manager')
    );
  }

  /**
   * Handles the login page override.
   */
  public function login(Request $request) {
    global $base_root;

    $config = \Drupal::service('config.factory')->get('auth0.settings');

    $lockExtraSettings = $config->get('auth0_lock_extra_settings');

    if (trim($lockExtraSettings) == "") {
      $lockExtraSettings = "{}";
    }

    $returnTo = NULL;
    if ($request->request->has('returnTo')) {
      $returnTo = $request->request->get('returnTo');
    }
    elseif ($request->query->has('returnTo')) {
      $returnTo = $request->query->get('returnTo');
    }

    // If supporting SSO, redirect to the hosted login page for authorization.
    if ($this->redirectForSso == TRUE) {
      $prompt = 'none';
      return new TrustedRedirectResponse($this->buildAuthorizeUrl($prompt, $returnTo));
    }

    /* Not doing SSO, so show login page */
    return [
      '#theme' => 'auth0_login',
      '#domain' => $config->get('auth0_domain'),
      '#clientID' => $config->get('auth0_client_id'),
      '#state' => $this->getNonce($returnTo),
      '#showSignup' => $config->get('auth0_allow_signup'),
      '#offlineAccess' => $this->offlineAccess,
      '#widgetCdn' => $config->get('auth0_widget_cdn'),
      '#loginCSS' => $config->get('auth0_login_css'),
      '#lockExtraSettings' => $lockExtraSettings,
      '#callbackURL' => "$base_root/auth0/callback",
      '#scopes' => AUTH0_DEFAULT_SCOPES,
    ];
  }

  /**
   * Handles the login page override.
   */
  public function logout() {
    global $base_root;

    $auth0Api = new Authentication($this->domain, $this->clientId);

    user_logout();

    // If we are using SSO, we need to logout completely from Auth0, otherwise
    // they will just logout of their client.
    return new TrustedRedirectResponse($auth0Api->get_logout_link(
        $base_root,
        $this->redirectForSso ? NULL : $this->clientId)
    );
  }

  /**
   * Create a new nonce in session and return it.
   *
   * @param $returnTo
   *
   * @return string
   */
  protected function getNonce($returnTo) {
    // Have to start the session after putting something into the session,
    // or we don't actually start it!
    if (!$this->sessionManager->isStarted() && !isset($_SESSION['auth0_is_session_started'])) {
      $_SESSION['auth0_is_session_started'] = 'yes';
      $this->sessionManager->start();
    }

    $sessionStateHandler = new SessionStateHandler(new SessionStore());
    $states = $this->tempStore->get(AuthController::STATE);
    if (!is_array($states)) {
      $states = [];
    }
    $nonce = $sessionStateHandler->issue();
    $states[$nonce] = $returnTo === NULL ? '' : $returnTo;
    $this->tempStore->set(AuthController::STATE, $states);

    return $nonce;
  }

  /**
   * Build the Authorize url.
   *
   * @param $prompt none|login if prompt=none should be passed, false if not
   * @param $returnTo local path|null if null, use default of /user
   * @return string the URL to redirect to for authorization
   */
  protected function buildAuthorizeUrl($prompt, $returnTo = NULL) {
    global $base_root;

    $auth0Api = new Authentication($this->domain, $this->clientId);

    $response_type = 'code';
    $redirect_uri = "$base_root/auth0/callback";
    $connection = NULL;
    $state = $this->getNonce($returnTo);
    $additional_params = [];
    $additional_params['scope'] = AUTH0_DEFAULT_SCOPES;

    if ($this->offlineAccess) {
      $additional_params['scope'] .= ' offline_access';
    }

    if ($prompt) {
      $additional_params['prompt'] = $prompt;
    }

    return $auth0Api->get_authorize_link($response_type, $redirect_uri, $connection, $state, $additional_params);
  }

  /**
   *
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @param $returnTo
   * @return \Drupal\Core\Routing\TrustedRedirectResponse|null
   */
  private function checkForError(Request $request, $returnTo) {
    // Check for errors.
    // Check in query.
    if ($request->query->has('error') && $request->query->get('error') == 'login_required') {
      return new TrustedRedirectResponse($this->buildAuthorizeUrl(FALSE, $returnTo));
    }
    // Check in post.
    if ($request->request->has('error') && $request->request->get('error') == 'login_required') {
      return new TrustedRedirectResponse($this->buildAuthorizeUrl(FALSE, $returnTo));
    }

    return NULL;
  }

  /**
   * Handles the callback for the oauth transaction.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse|null|\Symfony\Component\HttpFoundation\RedirectResponse
   *
   * @throws \Auth0\SDK\Exception\CoreException
   */
  public function callback(Request $request) {
    global $base_root;
    $problem_logging_in_msg = t('There was a problem logging you in, sorry for the inconvenience.');

    $returnTo = NULL;
    $response = $this->checkForError($request, $returnTo);

    if ($response !== NULL) {
      return $response;
    }

    $this->auth0 = new Auth0([
      'domain'        => $this->domain,
      'client_id'     => $this->clientId,
      'client_secret' => $this->clientSecret,
      'redirect_uri'  => "$base_root/auth0/callback",
      'store' => NULL, // Set to null so that the store is set to SessionStore.
      'persist_id_token' => FALSE,
      'persist_user' => FALSE,
      'persist_access_token' => FALSE,
      'persist_refresh_token' => FALSE,
    ]);

    $userInfo = NULL;
    $refreshToken = NULL;

    // Exchange the code for the tokens (happens behind the scenes in the SDK).
    try {
      $userInfo = $this->auth0->getUser();
      $idToken = $this->auth0->getIdToken();
    }
    catch (\Exception $e) {
      return $this->failLogin(
        $problem_logging_in_msg,
        t('Failed to exchange code for tokens: @exception', ['@exception' => $e->getMessage()])
      );
    }

    if ($this->offlineAccess) {
      try {
        $refreshToken = $this->auth0->getRefreshToken();
      }
      catch (\Exception $e) {
        // Do NOT fail here, just log the error.
        \Drupal::logger('auth0')->warning(t('Failed getting refresh token: ', ['@exception' => $e->getMessage()]));
      }
    }

    try {
      $user = $this->helper->validateIdToken($idToken);
    }
    catch (\Exception $e) {
      return $this->failLogin($problem_logging_in_msg, t('Failed to validate JWT: @exception', ['@exception' => $e->getMessage()]));
    }

    if ($userInfo) {
      if (empty($userInfo['sub']) && !empty($userInfo['user_id'])) {
        $userInfo['sub'] = $userInfo['user_id'];
      }
      elseif (empty($userInfo['user_id']) && !empty($userInfo['sub'])) {
        $userInfo['user_id'] = $userInfo['sub'];
      }

      if ($userInfo['sub'] != $user->sub) {
        return $this->failLogin($problem_logging_in_msg, t('Failed to verify JWT sub'));
      }

      \Drupal::logger('auth0')->notice('Good Login');

      return $this->processUserLogin($request, $userInfo, $idToken, $refreshToken, $user->exp, $returnTo);
    }
    else {
      return $this->failLogin($problem_logging_in_msg, 'No userinfo found');
    }
  }

  /**
   * Checks if the email is valid.
   *
   * @param $userInfo
   * @throws \Drupal\auth0\Exception\EmailNotSetException
   * @throws \Drupal\auth0\Exception\EmailNotVerifiedException
   */
  protected function validateUserEmail($userInfo) {
    $config = \Drupal::service('config.factory')->get('auth0.settings');
    $requires_email = $config->get('auth0_requires_verified_email');

    if ($requires_email) {
      if (!isset($userInfo['email']) || empty($userInfo['email'])) {
        throw new EmailNotSetException();
      }
      if (!$userInfo['email_verified']) {
        throw new EmailNotVerifiedException();
      }
    }
  }

  /**
   * Process the Auth0 user profile and sign in or sign the user up
   *
   * @param Request $request
   * @param array $userInfo - array of user data from an ID token or /userinfo endpoint
   * @param string $idToken - ID token received during code exchange
   * @param string $refreshToken - refresh token
   * @param int $expiresAt - token expiration
   * @param string $returnTo - return URL
   *
   * @return RedirectResponse
   */
  protected function processUserLogin(Request $request, array $userInfo, $idToken, $refreshToken, $expiresAt, $returnTo) {
    \Drupal::logger('auth0')->notice('process user login');

    try {
      $this->validateUserEmail($userInfo);
    }
    catch (EmailNotSetException $e) {
      return $this->failLogin(t('This account does not have an email associated. Please login with a different provider.'), 'No Email Found');
    }
    catch (EmailNotVerifiedException $e) {
      return $this->auth0FailWithVerifyEmail($idToken);
    }

    // See if there is a user in the auth0_user table with the user
    // info client ID.
    \Drupal::logger('auth0')->notice($userInfo['user_id'] . ' looking up drupal user by auth0 user_id');
    $user = $this->findAuth0User($userInfo['user_id']);

    if ($user) {
      \Drupal::logger('auth0')->notice('uid of existing drupal user found');

      // User exists, update the auth0_user with the new userInfo object.
      $this->updateAuth0User($userInfo);

      // Update field and role mappings.
      $this->auth0_update_fields_and_roles($userInfo, $user);

      $event = new Auth0UserSigninEvent($user, $userInfo, $refreshToken, $expiresAt);
      $this->eventDispatcher->dispatch(Auth0UserSigninEvent::NAME, $event);
    }
    else {
      \Drupal::logger('auth0')->notice('existing drupal user NOT found');

      try {
        $user = $this->signupUser($userInfo);
      }
      catch (EmailNotVerifiedException $e) {
        return $this->auth0FailWithVerifyEmail($idToken);
      }

      $this->insertAuth0User($userInfo, $user->id());

      $event = new Auth0UserSignupEvent($user, $userInfo);
      $this->eventDispatcher->dispatch(Auth0UserSignupEvent::NAME, $event);
    }

    user_login_finalize($user);

    if ($returnTo !== NULL && strlen($returnTo) > 0 && $returnTo[0] === '/') {
      return new RedirectResponse($returnTo);
    }
    elseif ($request->request->has('destination')) {
      return new RedirectResponse($request->request->get('destination'));
    }

    return $this->redirect('entity.user.canonical', ['user' => $user->id()]);
  }

  /**
   *
   *
   * @param $message
   * @param $logMessage
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  protected function failLogin($message, $logMessage) {
    $this->logger->error($logMessage);
    drupal_set_message($message, 'error');
    if ($this->auth0) {
      $this->auth0->logout();
    }
    return new RedirectResponse('/');
  }

  /**
   * Create or link a new user based on the auth0 profile.
   *
   * @param array $userInfo - userinfo from an ID token or the /userinfo endpoint
   * @param string $idToken - ID token returned during login
   *
   * @return mixed
   *
   * @throws EmailNotVerifiedException
   */
  protected function signupUser(array $userInfo, $idToken = '') {
    // If the user doesn't exist we need to either create a new one,
    // or assign him to an existing one.
    $isDatabaseUser = FALSE;

    $user_sub_arr = explode('|', $userInfo['user_id']);
    $provider = $user_sub_arr[0];

    if ('auth0' === $provider) {
      $isDatabaseUser = TRUE;
    }

    $joinUser = FALSE;

    $config = \Drupal::service('config.factory')->get('auth0.settings');
    $user_name_claim = $config->get('auth0_username_claim');
    if ($user_name_claim == '') {
      $user_name_claim = 'nickname';
    }

    // Drupal usernames do not allow pipe characters.
    $user_name_used = !empty($userInfo[$user_name_claim])
      ? $userInfo[$user_name_claim]
      : str_replace('|', '_', $userInfo['user_id']);

    if ($config->get('auth0_join_user_by_mail_enabled') && !empty($userInfo['email'])) {
      \Drupal::logger('auth0')->notice($userInfo['email'] . 'join user by mail is enabled, looking up user by email');
      // If the user has a verified email or is a database user try to see if
      // there is a user to join with. The isDatabase is because we don't want
      // to allow database user creation if there is an existing one with no
      // verified email.
      if ($userInfo['email_verified'] || $isDatabaseUser) {
        $joinUser = user_load_by_mail($userInfo['email']);
      }
    } else {
      \Drupal::logger('auth0')->notice($user_name_used . ' join user by username');

   	  if (!empty($userInfo['email_verified']) || $isDatabaseUser) {
   	    $joinUser = user_load_by_name($user_name_used);
      }
    }

    if ($joinUser) {
      \Drupal::logger('auth0')->notice($joinUser->id() . ' drupal user found by email with uid');

      // If we are here, we have a potential join user.
      // Don't allow creation or assignation of user if the email is not verified,
      // that would be hijacking.
      if (!$userInfo['email_verified']) {
        throw new EmailNotVerifiedException();
      }
      $user = $joinUser;
    }
    else {
      \Drupal::logger('auth0')->notice($user_name_used . ' creating new drupal user from auth0 user');

      // If we are here, we need to create the user.
      $user = $this->createDrupalUser($userInfo);

      // Update field and role mappings
      $this->auth0_update_fields_and_roles($userInfo, $user);
    }

    return $user;
  }

  /**
   * Email not verified error message.
   */
  protected function auth0FailWithVerifyEmail($idToken) {

    $url = Url::fromRoute('auth0.verify_email', [], []);
    $formText = "<form style='display:none' name='auth0VerifyEmail' action=@url method='post'><input type='hidden' value=@token name='idToken'/></form>";
    $linkText = "<a href='javascript:null' onClick='document.forms[\"auth0VerifyEmail\"].submit();'>here</a>";

    return $this->failLogin(
      t($formText."Please verify your email and log in again. Click $linkText to Resend verification email.",
        [
          '@url' => $url->toString(),
          '@token' => $idToken
        ]
    ), 'Email not verified');
  }

  /**
   * Get the auth0 user profile.
   */
  protected function findAuth0User($id) {
    $auth0_user = db_select('auth0_user', 'a')
      ->fields('a', ['drupal_id'])
      ->condition('auth0_id', $id, '=')
      ->execute()
      ->fetchAssoc();

    return empty($auth0_user) ? FALSE : User::load($auth0_user['drupal_id']);
  }

  /**
   * Update the auth0 user profile.
   */
  protected function updateAuth0User($userInfo) {
    db_update('auth0_user')
      ->fields([
        'auth0_object' => serialize($userInfo),
      ])
      ->condition('auth0_id', $userInfo['user_id'], '=')
      ->execute();
  }

  /**
   *
   *
   * @param $userInfo
   * @param $user
   */
  protected function auth0_update_fields_and_roles($userInfo, $user) {

    $edit = [];
    $this->auth0_update_fields($userInfo, $user, $edit);
    $this->auth0_update_roles($userInfo, $user, $edit);

    $user->save();
  }

  /**
   * Update the $user profile attributes of a user based on the auth0 field mappings
   *
   * @param $user_info
   * @param $user
   * @param $edit
   */
  protected function auth0_update_fields($user_info, $user, &$edit) {
    $config = \Drupal::service('config.factory')->get('auth0.settings');
    $auth0_claim_mapping = $config->get('auth0_claim_mapping');

    if (isset($auth0_claim_mapping) && !empty($auth0_claim_mapping)) {
      // For each claim mapping, lookup the value, otherwise set to blank
      $mappings = $this->auth0_pipeListToArray($auth0_claim_mapping);

      // Remove mappings handled automatically by the module.
      $skip_mappings = [
        'uid',
        'name',
        'mail',
        'init',
        'is_new',
        'status',
        'pass',
      ];

      foreach ($mappings as $mapping) {
        \Drupal::logger('auth0')->notice('mapping ' . $mapping);

        $key = $mapping[1];
        if (in_array($key, $skip_mappings)) {
          \Drupal::logger('auth0')->notice('skipping mapping handled already by auth0 module ' . $mapping);
        } else {
          $value = isset($user_info[$mapping[0]]) ? $user_info[$mapping[0]] : '';
          $current_value = $user->get($key)->value;
          if ($current_value === $value) {
            \Drupal::logger('auth0')->notice('value is unchanged ' . $key);
          } else {
            \Drupal::logger('auth0')->notice('value changed ' . $key . ' from [' . $current_value . '] to [' . $value . ']');
            $edit[$key] = $value;
            $user->set($key, $value);
          }
        }
      }
    }
  }

  /**
   * Updates the $user->roles of a user based on the auth0 role mappings.
   *
   * @param $user_info
   * @param $user
   * @param $edit
   */
  protected function auth0_update_roles($user_info, $user, &$edit) {
    \Drupal::logger('auth0')->notice("Mapping Roles");
    $config = \Drupal::service('config.factory')->get('auth0.settings');
    $auth0_claim_to_use_for_role = $config->get('auth0_claim_to_use_for_role');

    if (isset($auth0_claim_to_use_for_role) && !empty($auth0_claim_to_use_for_role)) {
      $claim_value = isset($user_info[$auth0_claim_to_use_for_role]) ? $user_info[$auth0_claim_to_use_for_role] : '';
      \Drupal::logger('auth0')->notice('claim_value '.$claim_value);

      $claim_values = [];
      if (is_array($claim_value)) {
        $claim_values = $claim_value;
      } else {
        $claim_values[] = $claim_value;
      }

      $auth0_role_mapping = $config->get('auth0_role_mapping');
      $mappings = $this->auth0_pipeListToArray($auth0_role_mapping);

      $roles_granted = [];
      $roles_managed_by_mapping = [];

      foreach ($mappings as $mapping) {
        \Drupal::logger('auth0')->notice('mapping ' . $mapping);
        $roles_managed_by_mapping[] = $mapping[1];

        if (in_array($mapping[0], $claim_values)) {
          $roles_granted[] = $mapping[1];
        }
      }

      $roles_granted = array_unique($roles_granted);
      $roles_managed_by_mapping = array_unique($roles_managed_by_mapping);

      $not_granted = array_diff($roles_managed_by_mapping, $roles_granted);

      $user_roles = $user->getRoles();

      $new_user_roles = array_merge(array_diff($user_roles, $not_granted), $roles_granted);

      $tmp = array_diff($new_user_roles, $user_roles);
      if (empty($tmp)) {
        \Drupal::logger('auth0')->notice('no changes to roles detected');
      } else {
        \Drupal::logger('auth0')->notice('changes to roles detected');
        $edit['roles'] = $new_user_roles;
        foreach (array_diff($new_user_roles, $user_roles) as $new_role) {
          $user->addRole($new_role);
        }
        foreach (array_diff($user_roles, $new_user_roles) as $remove_role) {
          $user->removeRole($remove_role);
        }
      }
    }
  }

  /**
   *
   *
   * @param $mappings
   * @return string
   */
  protected function auth0_mappingsToPipeList($mappings) {
    $result_text = "";
    foreach ($mappings as $map) {
      $result_text .= $map['from'] . '|' . $map['user_entered'] . "\n";
    }
    return $result_text;
  }

  /**
   *
   *
   * @param $mapping_list_txt
   * @param bool $make_item0_lowercase
   * @return array
   */
  protected function auth0_pipeListToArray($mapping_list_txt, $make_item0_lowercase = FALSE) {
    $result_array = [];
    $mappings = preg_split('/[\n\r]+/', $mapping_list_txt);
    foreach ($mappings as $line) {
      if (count($mapping = explode('|', trim($line))) == 2) {
        $item_0 = ($make_item0_lowercase) ? drupal_strtolower(trim($mapping[0])) : trim($mapping[0]);
        $result_array[] = [$item_0, trim($mapping[1])];
      }
    }
    return $result_array;
  }

  /**
   * Insert the auth0 user.
   *
   * @param $userInfo
   * @param $uid
   */
  protected function insertAuth0User($userInfo, $uid) {

    db_insert('auth0_user')->fields([
      'auth0_id' => $userInfo['user_id'],
      'drupal_id' => $uid,
      'auth0_object' => json_encode($userInfo),
    ])->execute();

  }

  /**
   *
   *
   * @param int $nbBytes
   * @return string
   * @throws \Exception
   */
  private function getRandomBytes($nbBytes = 32) {
    $bytes = openssl_random_pseudo_bytes($nbBytes, $strong);
    if (FALSE !== $bytes && TRUE === $strong) {
      return $bytes;
    }
    else {
      throw new \Exception("Unable to generate secure token from OpenSSL.");
    }
  }

  /**
   *
   *
   * @param $length
   * @return bool|string
   * @throws \Exception
   */
  private function generatePassword($length) {
    return substr(preg_replace("/[^a-zA-Z0-9]\+\//", "", base64_encode($this->getRandomBytes($length + 1))), 0, $length);
  }

  /**
   * Create the Drupal user based on the Auth0 user profile.
   *
   * @param $userInfo
   * @return mixed
   * @throws \Exception
   */
  protected function createDrupalUser($userInfo) {
    $config = \Drupal::service('config.factory')->get('auth0.settings');
    $user_name_claim = $config->get('auth0_username_claim');
    if ($user_name_claim == '') {
      $user_name_claim = 'nickname';
    }

    $user = User::create();

    $user->setPassword($this->generatePassword(16));
    $user->enforceIsNew();

    if (!empty($userInfo['email'])) {
      $user->setEmail($userInfo['email']);
    }
    else {
      $user->setEmail("change_this_email@" . uniqid() . ".com");
    }

    // If the username already exists, create a new random one.
    $username = !empty($userInfo[$user_name_claim])
      ? $userInfo[$user_name_claim]
      : $userInfo['user_id'];

    if (user_load_by_name($username)) {
      $username .= time();
    }

    $user->setUsername($username);
    $user->activate();
    $user->save();

    return $user;
  }

  /**
   * Send the verification email.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   * @throws \Auth0\SDK\Exception\CoreException
   */
  public function verify_email(Request $request) {
    $idToken = $request->get('idToken');

    // Validate the ID Token.
    $auth0_domain = 'https://' . $this->domain . '/';
    $auth0_settings = [];
    $auth0_settings['authorized_iss'] = [$auth0_domain];
    $auth0_settings['supported_algs'] = [$this->auth0JwtSignatureAlg];
    $auth0_settings['valid_audiences'] = [$this->clientId];
    $auth0_settings['client_secret'] = $this->clientSecret;
    $auth0_settings['secret_base64_encoded'] = $this->secretBase64Encoded;
    $jwt_verifier = new JWTVerifier($auth0_settings);

    try {
      $user = $jwt_verifier->verifyAndDecode($idToken);
    }
    catch (\Exception $e) {
      return $this->failLogin(t('There was a problem resending the verification email, sorry for the inconvenience.'),
        "Failed to verify and decode the JWT ($idToken) for the verify email page: " . $e->getMessage());
    }

    try {
      $userId = $user->sub;
      $url = "https://$this->domain/api/users/$userId/send_verification_email";

      $client = \Drupal::httpClient();

      $client->request('POST', $url, [
          "headers" => [
            "Authorization" => "Bearer $idToken",
          ],
        ]
      );

      drupal_set_message(t('An Authorization email was sent to your account'));
    }
    catch (\UnexpectedValueException $e) {
      drupal_set_message(t('Your session has expired.'), 'error');
    }
    catch (\Exception $e) {
      drupal_set_message(t('Sorry, we couldnt send the email'), 'error');
    }

    return new RedirectResponse('/');
  }

}
