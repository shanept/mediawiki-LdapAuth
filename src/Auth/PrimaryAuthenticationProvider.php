<?php

namespace Shanept\LdapAuth\Auth;

use Shanept\LdapAuth\Groups\LdapGroupSync;
use Shanept\LdapAuth\Exceptions\I18nException;
use Shanept\LdapAuth\Exceptions\ConnectionException as LdapConnectionException;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Ldap\Adapter\QueryInterface;
use Symfony\Component\Ldap\Exception\ExceptionInterface as SymException;

use User;
use Config;
use Message;
use RawMessage;
use StatusValue;
use MediaWiki\Auth\AuthManager;
use MediaWiki\MediaWikiServices;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AbstractPrimaryAuthenticationProvider;

class PrimaryAuthenticationProvider extends AbstractPrimaryAuthenticationProvider {
	/**
	 * The IP of the connected LDAP server
	 *
	 * @var string
	 **/
	protected $server;

	/**
	 * The encryption method used to connect to the LDAP server
	 *
	 * @var string
	 **/
	protected $encryption;

	/**
	 * The LDAP connection
	 *
	 * @var Ldap
	 */
	protected $ldap;

	/**
	 * @inheritDoc
	 *
	 * We are not handed the correct config, let us override it.
	 */
	public function setConfig( Config $config ) {
		$this->config = MediaWikiServices::getInstance()
			->getConfigFactory()->makeConfig( 'LdapAuth' );
	}

	/**
	 * @inheritDoc
	 *
	 * Of the requests returned by this method, exactly one should have
	 * {@link AuthenticationRequest::$required} set to REQUIRED.
	 */
	public function getAuthenticationRequests( $action, array $options ) {
		$domains = $this->config->get( 'DomainNames' );
		return [ new LdapAuthenticationRequest( $domains ) ];
	}

	/**
	 * Start an authentication flow
	 *
	 * @param AuthenticationRequest[] $reqs
	 * @return AuthenticationResponse Expected responses:
	 *  - PASS: The user is authenticated. Secondary providers will now run.
	 *  - FAIL: The user is not authenticated. Fail the authentication process.
	 *  - ABSTAIN: These $reqs are not handled. Some other primary provider may handle it.
	 *  - UI: The $reqs are accepted, no other primary provider will run.
	 *    Additional AuthenticationRequests are needed to complete the process.
	 *  - REDIRECT: The $reqs are accepted, no other primary provider will run.
	 *    Redirection to a third party is needed to complete the process.
	 */
	public function beginPrimaryAuthentication( array $reqs ) {
		$req = LdapAuthenticationRequest::getRequestByClass(
			$reqs,
			LdapAuthenticationRequest::class
		);

		if ( false === $req ) {
			return AuthenticationResponse::newAbstain();
		}

		return $this->beginPrimaryLdapAuthentication( $req );
	}

	/**
	 * Called by beginPrimaryAuthentication when we are sure we should actually
	 * be attempting LDAP authentication. Performs the actual authentication,
	 * returning the value for beginPrimaryAuthentication to return.
	 *
	 * @param LdapAuthenticationRequest $req The single request to validate
	 *
	 * @return AuthenticationResponse on success or failure
	 */
	public function beginPrimaryLdapAuthentication( LdapAuthenticationRequest $req ) {
		// Let's set up an error message. Upon error, this should be
		// overwritten, so if we hard-fail we can provide an accurate
		// reason as to why.
		$message = 'error-unknown';
		$params = [];

		try {
			// We attempt to connect to an LDAP server for the domain
			// specified by the request. This will bind to the BindDN
			$this->ldap = $this->connect( $req );

			// We must authenticate the specified user against LDAP.
			$this->authenticate( $req );

			// And now we ensure they are in the search base.
			$search = $this->search( $req )->toArray();

			// If we don't have results, we will just use an exception to
			// jump to the end of the function.
			if ( !count( $search ) ) {
				// The user was outside the SearchBase - equivalent to a
				// forbidden login - however the user has already been
				// authenticated with their password, so we must not
				// error with an 'Incorrect Credentials' message.
				throw new LdapConnectionException( '', 'password-login-forbidden' );
			}

			// Make things shorter...
			$s = $search[0];
			$setSession = [ $this->manager, 'setAuthenticationSessionData' ];

			$setSession( 'LdapAuthUsername', $s->getAttribute( 'sAMAccountName' )[0] );
			$setSession( 'LdapAuthDisplayName', $s->getAttribute( 'displayName' )[0] );
			$setSession( 'LdapAuthFirstName', $s->getAttribute( 'givenName' )[0] );
			$setSession( 'LdapAuthLastName', $s->getAttribute( 'sn' )[0] );
			$setSession( 'LdapAuthEmail', $s->getAttribute( 'mail' )[0] );
			$setSession( 'LdapAuthDomain', $req->domain );

			return AuthenticationResponse::newPass( $s->getAttribute( 'sAMAccountName' )[0] );
		} catch ( I18nException $e ) {
			$message = $e->getTranslationKey();
			$params = $e->getTranslationParams();
		} catch ( SymException $e ) {
			$message = new RawMessage( '$1', $e->getMessage() );
		}

		if ( !is_a( $message, Message::class ) ) {
			$message = new Message( $message, $params );
		}

		// We should have passed by now...
		if ( $this->config->get( 'UseLocal' ) ) {
			return AuthenticationResponse::newAbstain();
		} else {
			return AuthenticationResponse::newFail( $message );
		}
	}

	/**
	 * Post-login callback
	 *
	 * This will be called at the end of any login attempt, regardless of whether this provider was
	 * the one that handled it. It will not be called for unfinished login attempts that fail by
	 * the session timing out.
	 *
	 * @param User|null $user User that was attempted to be logged in, if known.
	 *   This may become a "UserValue" in the future, or User may be refactored
	 *   into such.
	 * @param AuthenticationResponse $response Authentication response that will be returned
	 *   (PASS or FAIL)
	 */
	public function postAuthentication( $user, AuthenticationResponse $response ) {
		$user->setRealName( $this->manager->getAuthenticationSessionData( 'LdapAuthDisplayName' ) );
		$user->setEmail( $this->manager->getAuthenticationSessionData( 'LdapAuthEmail' ) );
		// Every time the email is set, it is invalidated. Don't invalidate it.
		$user->confirmEmail();
		$user->saveSettings();

		// Map Groups
		$sync = new LdapGroupSync( $user, $this->ldap );
		$sync->setLogger( $this->logger );
		$sync->setConfig( $this->config );

		$sync->map();
	}

	/**
	 * Test whether the named user exists
	 *
	 * Single-sign-on providers can use this to reserve a username for autocreation.
	 *
	 * @param string $username MediaWiki username
	 * @param int $flags Bitfield of User:READ_* constants
	 * @return bool
	 */
	public function testUserExists( $username, $flags = User::READ_NORMAL ) {
		return false;
	}

	/**
	 * Revoke the user's credentials
	 *
	 * This may cause the user to no longer exist for the provider, or the user
	 * may continue to exist in a "disabled" state.
	 *
	 * The intention is that the named account will never again be usable for
	 * normal login (i.e. there is no way to undo the revocation of access).
	 *
	 * @param string $username
	 */
	public function providerRevokeAccessForUser( $username ) {
		throw new \BadMethodCallException(
			'This should never be thrown. If it has been thrown, please ' .
			'create a bug report.'
		);
	}

	/**
	 * Determine whether a property can change
	 * @see AuthManager::allowsPropertyChange()
	 * @param string $property
	 * @return bool
	 */
	public function providerAllowsPropertyChange( $property ) {
		return false;
	}

	/**
	 * Validate a change of authentication data (e.g. passwords)
	 *
	 * Return StatusValue::newGood( 'ignored' ) if you don't support this
	 * AuthenticationRequest type.
	 *
	 * @param AuthenticationRequest $req
	 * @param bool $checkData If false, $req hasn't been loaded from the
	 *  submission so checks on user-submitted fields should be skipped.
	 *  $req->username is considered user-submitted for this purpose, even
	 *  if it cannot be changed via $req->loadFromSubmission.
	 * @return StatusValue
	 */
	public function providerAllowsAuthenticationDataChange(
		AuthenticationRequest $req,
		$checkData = true
	) {
		return StatusValue::newFatal( 'Authentication Data Change not supported.' );
	}

	/**
	 * Change or remove authentication data (e.g. passwords)
	 *
	 * If $req was returned for AuthManager::ACTION_CHANGE, the corresponding
	 * credentials should result in a successful login in the future.
	 *
	 * If $req was returned for AuthManager::ACTION_REMOVE, the corresponding
	 * credentials should no longer result in a successful login.
	 *
	 * It can be assumed that providerAllowsAuthenticationDataChange with $checkData === true
	 * was called before this, and passed. This method should never fail (other than throwing an
	 * exception).
	 *
	 * @param AuthenticationRequest $req
	 *
	 * @return bool Whether or not the database was updated correctly
	 */
	public function providerChangeAuthenticationData( AuthenticationRequest $req ) {
		if ( !is_a( $req, LdapAuthenticationRequest::class ) ) {
			return;
		}

		if ( $req->action !== AuthManager::ACTION_REMOVE ) {
			throw new \BadMethodCallException( 'Authentication Data Change not supported.' );
		}

		$db = wfGetDB( DB_MASTER );

		$user = User::newFromName( $req->username );

		return (bool)$db->delete(
			'user_ldapauth_domain',
			[
				'user_id' => $user->getId()
			],
			__METHOD__
		);
	}

	/**
	 * Fetch the account-creation type
	 * @return string One of the TYPE_* constants
	 */
	public function accountCreationType() {
		return self::TYPE_CREATE;
	}

	/**
	 * Start an account creation flow
	 * @param User $user User being created (not added to the database yet).
	 *   This may become a "UserValue" in the future, or User may be refactored
	 *   into such.
	 * @param User $creator User doing the creation. This may become a
	 *   "UserValue" in the future, or User may be refactored into such.
	 * @param AuthenticationRequest[] $reqs
	 * @return AuthenticationResponse Expected responses:
	 *  - PASS: The user may be created. Secondary providers will now run.
	 *  - FAIL: The user may not be created. Fail the creation process.
	 *  - ABSTAIN: These $reqs are not handled. Some other primary provider may handle it.
	 *  - UI: The $reqs are accepted, no other primary provider will run.
	 *    Additional AuthenticationRequests are needed to complete the process.
	 *  - REDIRECT: The $reqs are accepted, no other primary provider will run.
	 *    Redirection to a third party is needed to complete the process.
	 */
	public function beginPrimaryAccountCreation( $user, $creator, array $reqs ) {
		throw new \BadMethodCallException(
			'This should never be thrown. If it has been thrown, please ' .
			'create a bug report.'
		);

		// return AuthenticationResponse::newFail();
	}

	/**
	 * Determine whether an account may be created
	 *
	 * @param User $user User being created (not added to the database yet).
	 *   This may become a "UserValue" in the future, or User may be refactored
	 *   into such.
	 * @param bool|string $autocreate False if this is not an auto-creation, or
	 *  the source of the auto-creation passed to AuthManager::autoCreateUser().
	 * @param array $options
	 *  - flags: (int) Bitfield of User:READ_* constants, default User::READ_NORMAL
	 *  - creating: (bool) If false (or missing), this call is only testing if
	 *    a user could be created. If set, this (non-autocreation) is for
	 *    actually creating an account and will be followed by a call to
	 *    testForAccountCreation(). In this case, the provider might return
	 *    StatusValue::newGood() here and let the later call to
	 *    testForAccountCreation() do a more thorough test.
	 * @return StatusValue
	 */
	public function testUserForCreation( $user, $autocreate, array $options = [] ) {
		if ( !$autocreate ) {
			return StatusValue::newFatal( 'Account can not be created.' );
		}

		$domains = $this->config->get( 'DomainNames' );
		$req = new LdapAuthenticationRequest( $domains );
		$req->username = $user->mName;

		foreach ( $domains as $domain ) {
			$req->domain = $domain;

			$ldap = $this->connect( $req );
			$results = $this->search( $ldap, $req )->toArray();

			if ( count( $results ) > 0 ) {
				return StatusValue::newGood();
			}
		}

		return StatusValue::newFatal( 'Account can not be created.' );
	}

	/**
	 * Post-auto-creation callback
	 * @param User $user User being created (has been added to the database now).
	 *   This may become a "UserValue" in the future, or User may be refactored
	 *   into such.
	 * @param string $source The source of the auto-creation passed to
	 *  AuthManager::autoCreateUser().
	 */
	public function autoCreatedAccount( $user, $source ) {
		$domain = $this->manager->getAuthenticationSessionData( 'LdapAuthDomain' );
		$user->setOption( 'domain', $domain );
		$user->confirmEmail();
		$user->saveSettings();

		$db = wfGetDB( DB_MASTER );

		$db->insert(
			'user_ldapauth_domain',
			[
				'user_id' => $user->getId(),
				'user_domain' => $domain
			]
		);
	}

	private function connect( LdapAuthenticationRequest $req ) {
		$dn = $this->config->get( 'BindDN' )[$req->domain];
		$pass = $this->config->get( 'BindPass' )[$req->domain];
		$servers = $this->config->get( 'Servers' )[$req->domain];
		$encryption = $this->config->get( 'EncryptionType' )[$req->domain];

		if ( false === $dn ) {
			$msgkey = 'ldapauth-attempt-bind-search';
			$bind_with = [ null, null ];
		} else {
			$msgkey = 'ldapauth-attempt-bind-dn-search';
			$bind_with = [ $dn, $pass ];
		}

		$message = new Message( $msgkey, [
			'dn' => "{$dn}@{$req->domain}",
		] );
		$this->logger->info( $message->text() );

		foreach ( $servers as $server ) {
			if ( false === $server ) {
				continue;
			}

			$ldap = Ldap::create( 'ext_ldap', [
				'host' => $server,
				'encryption' => $encryption
			] );

			// Attempt bind - on failure, throw an exception
			try {
				call_user_func_array( [ $ldap, 'bind' ], $bind_with );

				$this->server = $server;
				$this->encryption = $encryption;

				// log successful bind
				$msgkey = 'ldapauth-bind-success';
				$message = wfMessage( $msgkey )->text();
				$this->logger->info( $message );

				return $ldap;
			} catch ( SymException $e ) {
				if ( false === $dn ) {
					$msgkey = 'ldapauth-no-bind-search';
				} else {
					$msgkey = 'ldapauth-no-bind-dn-search';
				}

				$message = new Message( $msgkey, [
					'dn' => "{$dn}@{$req->domain}",
				] );
				$message = $message->text();

				$this->logger->info( $message );
				$this->logger->debug( $e->getMessage() );
			}
		}

		// We should have returned an LDAP resource by now...
		// We couldn't successfully connect.
		$msgkey = 'ldapauth-no-connect';
		$message = new Message( $msgkey );

		// If we are permitting local authentication, we can continue on to
		// try it - therefore this is not a *hard* error.
		$fn = $this->config->get( 'UseLocal' ) ?
				[ $this->logger, 'warning' ] :
				[ $this->logger, 'error' ];

		call_user_func( $fn, $message->text() );

		throw new LdapConnectionException( $message, $msgkey );
	}

	private function authenticate( LdapAuthenticationRequest $req ) {
		$dn = $this->config->get( 'BindDN' )[$req->domain];
		$encryption = $this->config->get( 'EncryptionType' )[$req->domain];

		// We will go through and try every server until one succeeds
		$username = "{$req->username}@{$req->domain}";
		$msg_bind_params = [
			'server' => $this->server,
			'enc' => ( $this->encryption === 'none' ) ? 'ldap' : $this->encryption,
			'username' => $username
		];

		try {
			$message = new Message( "ldapauth-bind-dn", $msg_bind_params );
			$this->ldap->bind( $username, $req->password );

			return $this->ldap;
		} catch ( SymException $e ) {
			// Generate log then try next connection
			$msgkey = 'wrongpassword';
			$message = new Message( $msgkey, $msg_bind_params );

			// If we are permitting local authentication, we can continue on to
			// try it - therefore this is not a *hard* error.
			$fn = $this->config->get( 'UseLocal' ) ?
					[ $this->logger, 'warning' ] :
					[ $this->logger, 'error' ];

			call_user_func( $fn, $message->text() );
			$this->logger->debug( $e->getMessage() );

			throw new LdapConnectionException( $message, $msgkey );
		}
	}

	private function search( LdapAuthenticationRequest $req ) {
		$base = $this->config->get( 'BaseDN' )[ $req->domain ];
		$filter = $this->config->get( 'SearchFilter' )[ $req->domain ];
		$search_tree = $this->config->get( 'SearchTree' )[ $req->domain ];

		if ( false === $base ) {
			// log && throw
			$msgkey = 'ldapauth-no-base';
			$params = [ 'domain' => $req->domain ];

			$message = new Message( $msgkey, $params );
			$message = $message->text();

			$this->logger->error( $message );
			throw new LdapConnectionException( $message, $msgkey, $params );
		}

		$ldap_query_options = [
			'scope' => $search_tree ? QueryInterface::SCOPE_SUB : QueryInterface::SCOPE_ONE,
			'filter' => [
				'sAMAccountName',

				// First Name:
				'givenName',

				// Last Name:
				'sn',
				'displayName',

				// Email Address:
				'mail',
			],
		];

		$filter = sprintf( $filter, $req->username );

		$query = $this->ldap->query( $base, $filter, $ldap_query_options );

		return $query->execute();
	}
}
