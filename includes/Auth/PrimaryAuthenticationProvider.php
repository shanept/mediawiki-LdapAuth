<?php

namespace Shanept\LdapAuth\Auth;

use Shanept\LdapAuth\Exceptions\i18nException;
use Shanept\LdapAuth\Exceptions\ConnectionException as LdapConnectionException;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Ldap\Adapter\QueryInterface;
use Symfony\Component\Ldap\Exception\ExceptionInterface as SymException;

use Message;
use StatusValue;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AbstractPrimaryAuthenticationProvider;
use MediaWiki\Logger\LoggerFactory;

class PrimaryAuthenticationProvider extends AbstractPrimaryAuthenticationProvider
{
    /**
     * @inheritDoc
     *
     * Of the requests returned by this method, exactly one should have
     * {@link AuthenticationRequest::$required} set to REQUIRED.
     */
    public function getAuthenticationRequests($action, array $options)
    {
        $domains = $this->config->get('LdapAuthDomainNames');
        return [new LdapAuthenticationRequest($domains)];
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
    public function beginPrimaryAuthentication(array $reqs)
    {
        $req = LdapAuthenticationRequest::getRequestByClass($reqs, LdapAuthenticationRequest::class);
        if (false === $req) {
            return AuthenticationResponse::newAbstain();
        }

        return $this->beginPrimaryLdapAuthentication($req);
    }

    public function beginPrimaryLdapAuthentication(LdapAuthenticationRequest $req)
    {
        // Let's set up an error message. Upon error, this should be
        // overwritten, so if we hard-fail we can provide an accurate
        // reason as to why.
        $message = 'error-unknown';
        $params = [];

        try {
            // We attempt to connect to an LDAP server for the domain
            // specified by the request. This will bind to the BindDN
            $ldap = $this->connect($req);

            // We must authenticate the specified user against LDAP.
            $this->authenticate($ldap, $req);

            // And now we ensure they are in the search base.
            $search = $this->search($ldap, $req)->toArray();

            // If we don't have results, we will just use an exception to
            // jump to the end of the function.
            if (!count($search)) {
                // The user was outside the SearchBase - equivalent to a
                // forbidden login - however the user has already been
                // authenticated with their password, so we must not
                // error with an 'Incorrect Credentials' message.
                throw new LdapConnectionException('', 'password-login-forbidden');
            }

            // Test & assign groups

            $username = $search[0]->getAttribute('sAMAccountName')[0];
            return AuthenticationResponse::newPass($username);
        } catch (i18nException $e) {
            $message = $e->getTranslationKey();
            $params = $e->getTranslationParams();
        } catch (SymException $e) {
            $message = $e->getMessage();
        }

        // We should have passed by now...
        if ($this->config->get('LdapAuthUseLocal')) {
            return AuthenticationResponse::newAbstain();
        } else {
            return AuthenticationResponse::newFail(new Message($message, $params));
        }
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
    public function testUserExists($username, $flags = User::READ_NORMAL)
    {
        throw new \BadMethodCallException('Not yet implemented');
    }

    /**
     * Test whether the named user can authenticate with this provider
     *
     * Should return true if the provider has any data for this user which can be used to
     * authenticate it, even if the user is temporarily prevented from authentication somehow.
     *
     * @param string $username MediaWiki username
     * @return bool
     */
    public function testUserCanAuthenticate($username)
    {
        if (!$this->testUserExists($username)) return false;
        // Test user exists and isn't disabled
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
    public function providerRevokeAccessForUser($username)
    {
        throw new \BadMethodCallException('Not yet implemented');
        return;
    }

    /**
     * Determine whether a property can change
     * @see AuthManager::allowsPropertyChange()
     * @param string $property
     * @return bool
     */
    public function providerAllowsPropertyChange($property)
    {
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
        AuthenticationRequest $req, $checkData = true
    ) {
        return StatusValue::newFatal('Authentication Data Change not supported.');
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
     */
    public function providerChangeAuthenticationData(AuthenticationRequest $req)
    {
        throw new \BadMethodCallException('Not yet implemented');
    }

    /**
     * Fetch the account-creation type
     * @return string One of the TYPE_* constants
     */
    public function accountCreationType()
    {
        return self::TYPE_NONE; // self::TYPE_LINK;
    }

    /**
     * Determine whether an account creation may begin
     *
     * Called from AuthManager::beginAccountCreation()
     *
     * @note No need to test if the account exists, AuthManager checks that
     * @param User $user User being created (not added to the database yet).
     *   This may become a "UserValue" in the future, or User may be refactored
     *   into such.
     * @param User $creator User doing the creation. This may become a
     *   "UserValue" in the future, or User may be refactored into such.
     * @param AuthenticationRequest[] $reqs
     * @return StatusValue
     */
    public function testForAccountCreation($user, $creator, array $reqs)
    {
        return StatusValue::newFatal('Account Creation not supported.');
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
    public function beginPrimaryAccountCreation($user, $creator, array $reqs)
    {
        return AuthenticationResponse::newFail();
    }

    private function connect(LdapAuthenticationRequest $req)
    {
        $dn = $this->config->get('LdapAuthBindDN')[$req->domain];
        $pass = $this->config->get('LdapAuthBindPass')[$req->domain];
        $servers = $this->config->get('LdapAuthServers')[$req->domain];
        $encryption = $this->config->get('LdapAuthEncryptionType')[$req->domain];

        if (false === $dn) {
            $msgkey = 'ldapauth-attempt-bind-search';
            $bind_with = [null, null];
        } else {
            $msgkey = 'ldapauth-attempt-bind-dn-search';
            $bind_with = [$dn, $pass];
        }

        $message = new Message($msgkey, [
            'dn' => "{$dn}@{$req->domain}",
        ]);
        $this->logger->info($message->text());

        foreach ($servers as $server) {
            if (false === $server)
                continue;

            $ldap = Ldap::create('ext_ldap', [
                'host' => $server,
                'encryption' => $encryption
            ]);

            // Attempt bind - on failure, throw an exception
            try {
                call_user_func_array([$ldap, 'bind'], $bind_with);

                // log successful bind
                $msgkey = 'ldapauth-bind-success';
                $message = wfMessage($msgkey)->text();
                $this->logger->info($message);

                return $ldap;
            } catch (SymException $e) {
                if (false === $dn) {
                    $msgkey = 'ldapauth-no-bind-search';
                } else {
                    $msgkey = 'ldapauth-no-bind-dn-search';
                }

                $message = new Message($msgkey, [
                    'dn' => "{$dn}@{$req->domain}",
                ]);
                $message = $message->text();

                $this->logger->info($message);
                $this->logger->debug($e->getMessage());
            }
        }

        // We should have returned an LDAP resource by now...
        // We couldn't successfully connect.
        $msgkey = 'ldapauth-no-connect';
        $message = new Message($msgkey);

        // If we are permitting local authentication, we can continue on to
        // try it - therefore this is not a *hard* error.
        $fn = $this->config->get('LdapAuthUseLocal') ?
                [$this->logger, 'warning'] :
                [$this->logger, 'error'];

        call_user_func($fn, $message->text());

        throw new LdapConnectionException($message, $msgkey);
    }

    private function authenticate($ldap, LdapAuthenticationRequest $req)
    {
        $dn = $this->config->get('LdapAuthBindDN')[$req->domain];
        $encryption = $this->config->get('LdapAuthEncryptionType')[$req->domain];

        // We will go through and try every server until one succeeds
        $username = "{$req->username}@{$req->domain}";
        $msg_bind_params = [
            'server' => $server,
            'enc' => ($encryption === 'none') ? 'ldap' : $encryption,
            'username' => $username
        ];

        try {
            $message = new Message("ldapauth-bind-dn", $msg_bind_params);
            $ldap->bind($username, $req->password);

            return $ldap;
        } catch (SymException $e) {
            // Generate log then try next connection
            $msgkey = 'wrongpassword';
            $message = new Message($msgkey, $msg_bind_params);

            // If we are permitting local authentication, we can continue on to
            // try it - therefore this is not a *hard* error.
            $fn = $this->config->get('LdapAuthUseLocal') ?
                    [$this->logger, 'warning'] :
                    [$this->logger, 'error'];

            call_user_func($fn, $message->text());
            $this->logger->debug($e->getMessage());

            throw new LdapConnectionException($message, $msgkey);
        }
    }

    private function search($ldap, LdapAuthenticationRequest $req)
    {
        $base = $this->config->get('LdapAuthBaseDN')[$req->domain];
        $filter = $this->config->get('LdapAuthSearchFilter')[$req->domain];
        $search_tree = $this->config->get('LdapAuthSearchTree')[$req->domain];

        if (false === $base) {
            // log && throw
            $msgkey = 'ldapauth-no-base';
            $params = ['domain' => $req->domain];

            $message = new Message($msgkey, $params);
            $message = $message->text();

            $this->logger->error($message);
            throw new LdapConnectionException($message, $msgkey, $params);
        }

        $ldap_query_options = [
            'scope' => $search_tree ? QueryInterface::SCOPE_SUB : QueryInterface::SCOPE_ONE,
            'filter' => [
                'sAMAccountName',
                'givenName',    // first name
                'sn',           // last name
                'displayName',
            ],
        ];

        $filter = sprintf($filter, $req->username);

        $query = $ldap->query($base, $filter, $ldap_query_options);

        return $query->execute();
    }
}
