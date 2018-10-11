<?php

namespace Shanept\LdapAuth\Groups;

use Shanept\LdapAuth\Exceptions\MappingException;
use Symfony\Component\Ldap\Adapter\QueryInterface;

use User;
use Config;
use Message;
use Psr\Log\LoggerInterface;

/**
 * Heavily based upon extension:LdapGroups
 **/
class LdapGroupSync {
	/**
	 * The Logger object
	 *
	 * @var Psr\Log\LoggerInterface
	 */
	protected $logger;

	/**
	 * The config object
	 *
	 * @var \Config
	 */
	protected $config;

	/**
	 * The LDAP object
	 *
	 * @var Symfony\Component\Ldap\Ldap
	 */
	protected $ldap;

	/**
	 * @param \User $user
	 * @param Symfony\Component\Ldap\Ldap $ldap
	 */
	public function __construct( User $user, $ldap ) {
		$this->user = $user;
		$this->ldap = $ldap;
	}

	/**
	 * Sets the Logger for this class
	 *
	 * @param Psr\Logger\LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Sets the Config for this class
	 *
	 * @param \Config $config
	 */
	public function setConfig( Config $config ) {
		$this->config = $config;
	}

	public function map() {
		$this->populateGroups();
		$data = $this->fetchData();
		$this->mapGroups( $data );
	}

	protected function populateGroups() {
		$domain = $this->user->getOption( 'domain' );
		$map = $this->config->get( 'MapGroups' )[$domain];

		foreach ( $map as $group => $DNs ) {
			$DNs = array_map( 'strtolower', $DNs );

			foreach ( $DNs as $DN ) {
				$this->groupMap[$group][] = $DN;
				$this->ldapGroupMap[$DN] = $group;
			}
		}

		$this->setRestrictions( $map );
	}

	/**
	 * Restrict what can be done with these groups on Special:UserRights
	 *
	 * @param array $map The group map
	 */
	protected function setRestrictions( array $map ) {
		global $wgGroupPermissions, $wgAddGroups, $wgRemoveGroups;

		// Setup all new groups as 'user'. This allows new groups to be
		// created for linking the AD groups to.
		foreach ( $map as $group => $DNs ) {
			if ( !isset( $wgGroupPermissions[$group] ) ) {
				$wgGroupPermissions[$group] = $wgGroupPermissions['user'];
			}
		}

		$LdapGroups = array_keys( $map );
		$WikiGroups = array_diff( array_keys( $wgGroupPermissions ), $LdapGroups );

		// Restrict the ability of users to change these rights
		foreach ( array_unique( array_keys( $wgGroupPermissions ) ) as $group ) {
			if ( !isset( $wgGroupPermissions[$group]['userrights'] ) ) {
				continue;
			}

			if ( !$wgGroupPermissions[$group]['userrights'] ) {
				continue;
			}

			$wgGroupPermissions[$group]['userrights'] = false;

			if ( !isset( $wgAddGroups[$group] ) ) {
				$wgAddGroups[$group] = $WikiGroups;
			}

			if ( !isset( $wgRemoveGroups[$group] ) ) {
				$wgRemoveGroups[$group] = $WikiGroups;
			}
		}
	}

	protected function fetchData() {
		$email = $this->user->getEmail();

		if ( !$email ) {
			$msgkey = 'noemail';
			$params = [ 'user' => "$this->user" ];

			$message = new Message( $msgkey, $params );
			$this->logger->warning( $message->text() );

			throw new MappingException( "No email found for \"{$this->user}\".", $msgkey, $params );
		}

		$domain = $this->user->getOption( 'domain' );

		if ( !$domain ) {
			$msgkey = 'ldapauth-nodomain';
			$params = [ 'user' => "$this->user" ];

			$message = new Message( $msgkey, $params );
			$this->logger->warning( $message->text() );

			throw new MappingException( "No domain found for \"{$this->user}\".", $msgkey, $params );
		}

		$isActiveDirectory = $this->config->get( 'IsActiveDirectory' )[$domain];

		$msgkey = 'ldapauth-fetch-data';
		$params = [ 'user' => "$this->user" ];

		$message = new Message( $msgkey, $params );
		$this->logger->info( $message->text() );

		$entry = $this->doSearch( "mail={$email}" );

		if ( !$entry ) {
			$msgkey = 'ldapauth-no-user-by-email';
			$params = [ 'email' => $email ];

			$message = new Message( $msgkey, $params );
			$this->logger->warning( $message->text() );

			throw new MappingException( "No user found by email \"{$email}\".", $msgkey, $params );
		}

		$data = $entry[0];

		if ( $isActiveDirectory ) {
			$data = $this->doGroupMapUsingChain( $data );
		}

		return $data;
	}

	/**
	 * Performs an LDAP query against the query parameters in $match
	 *
	 * @param string $match The query parameters for which to search
	 *
	 * @return Symfony\Component\Ldap\Entry The first entry in the result list
	 */
	protected function doSearch( $match ) {
		$domain = $this->user->getOption( 'domain' );
		$base = $this->config->get( 'BaseDN' )[$domain];
		$search_tree = $this->config->get( 'SearchTree' )[$domain];
		$refresh_sync = $this->config->get( 'CacheGroupMap' )[$domain];

		$runtime = -microtime( true );
		$key = wfMemcKey( 'ldapauth-groups', $match );
		$cache = wfGetMainCache();
		$entry = $cache->get( $key );

		if ( $entry === false ) {
			$ldap_query_options = [
				'scope' => $search_tree ? QueryInterface::SCOPE_SUB : QueryInterface::SCOPE_ONE,
				'filter' => [
					'*',
				],
			];

			$query = $this->ldap->query( $base, $match, $ldap_query_options );
			$entry = $query->execute()->toArray();

			$cache->set( $key, $entry, $refresh_sync );
		}

		$runtime += microtime( true );
		$msgkey = 'ldapauth-ran-search';
		$params = [ 'search' => $match, 'runtime' => $runtime ];

		$message = new Message( $msgkey, $params );
		$this->logger->debug( $message->text() );

		return $entry;
	}

	/**
	 * Determines which LDAP groups should be mapped to which MediaWiki groups
	 * and adds the user to the associated MediaWiki group
	 *
	 * @param Symfony\Component\Ldap\Entry $data LDAP query results for user
	 */
	protected function mapGroups( $data ) {
		$user_groups = $this->user->getGroups();

		// Create a list of LDAP groups this person is a member of
		$memberOf = array_map( 'strtolower', $data->getAttribute( 'memberOf' ) );
		$memberOf = array_flip( $memberOf );

		$this->logger->debug( sprintf( 'memberOf: "%s"', implode( '", "', $memberOf ) ) );
		$this->logger->debug( sprintf( 'In groups: "%s"', implode( '", ', $user_groups ) ) );

		// List of LDAP groups that map to MediaWiki groups that we already have
		$existing = array_intersect( $this->ldapGroupMap, $user_groups );

		// List of LDAP groups that map to MediaWiki groups we do NOT already have
		$missing = array_diff( $this->ldapGroupMap, $user_groups );

		// LDAP-mapped MediaWiki groups that should be added because they
		// aren't in the user's list
		$add = array_keys( array_flip( array_intersect_key( $missing, $memberOf ) ) );

		// MediaWiki groups that should be removed - user doesn't have any LDAP groups
		foreach ( array_keys( $this->groupMap ) as $group ) {
			$matched = array_intersect( $this->groupMap[$group], array_flip( $memberOf ) );

			if ( count( $matched ) === 0 ) {
				$msgkey = 'ldapauth-delete-from-group';
				$params = [ 'user' => "{$this->user}", 'group' => $group ];

				$message = new Message( $msgkey, $params );
				$this->logger->debug( $message->text() );

				$this->user->removeGroup( $group );
			}
		}

		foreach ( $add as $group ) {
			$msgkey = 'ldapauth-add-to-group';
			$params = [ 'user' => "{$this->user}", 'group' => $group ];

			$message = new Message( $msgkey, $params );
			$this->logger->debug( $message->text() );

			$this->user->addGroup( $group );
		}

		$this->user->saveSettings();
	}

	/**
	 * Set up a group map for the user using chained groups.
	 * See http://ldapwiki.com/wiki/1.2.840.113556.1.4.1941
	 *
	 * @param array $data Ldap query results for the user
	 */
	protected function doGroupMapUsingChain( $data ) {
		throw new \BadMethodCallException( 'Not yet implemented.' );
	}
}
