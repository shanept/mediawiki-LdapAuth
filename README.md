# MediaWiki LDAP Authentication
This MediaWiki extension allows for an instance to be configured to authenticate against a (one or many) LDAP servers.
The extension is built for MediaWiki v1.27 or greater, as it utilizes the new extension and authentication framework.

# Installation
1. Download the extension and place it in the `extensions/LdapAuth` directory.
2. Add the following to your [LocalSettings.php][1] file:
```php
wfLoadExtension( 'LdapAuth' );
```
3. [Configure](#configuration) as required.

# Quick Configuration
If you can't be bothered reading about how to configure the extension properly, don't worry - here's a quick and easy config you can probably get going with!

```php
$wgLdapAuthDomainNames = 'MY_DOMAIN_HERE';
$wgLdapAuthServers = 'SERVER1,SERVER2,SERVER3';
$wgLdapAuthBindDN = 'MY_BIND_DN_HERE';
$wgLdapAuthBindPass = 'PASSWORD_FOR_BIND_DN';
```

* If you wish to restrict logins to users in a specific OU/DN, see [Base DN Configuration](#wgldapauthbasedn).
* If you wish to map Active Directory groups to MediaWiki groups, see [Group Mapping](#wgldapauthmapgroups).

# Configuration
As this plugin contains support for multiple domains, most of the following settings have two forms - generic cross-domain setting, or individualised per-domain settings, annotated by *PER-DOMAIN*.

### wgLdapAuthDomainNames
Specifies the LDAP domain (CN) to which we are connecting. Domains may be space-delimited, comma-delimited, or an array.

Note that this does not provide per-domain configuration, as that simply wouldn't make sense!

###### REQUIRED

Examples:
```php
$wgLdapAuthDomainNames = 'DOMAIN_1 DOMAIN_2  DOMAIN_3';  // space-delimited
$wgLdapAuthDomainNames = 'DOMAIN_1,DOMAIN_2, DOMAIN_3';  // comma-delimited
$wgLdapAuthDomainNames = [                               // PHP array format
    'DOMAIN_1',
    'DOMAIN_2',
    'DOMAIN_3',
];
```

### wgLdapAuthServers
Specifies a list of servers to authenticate each domain.

###### REQUIRED
###### PER-DOMAIN

Examples:
```php
// space and comma delimited - the following servers will be
// used for ALL domains.
$wgLdapAuthServers = '127.0.0.1 127.0.0.2,127.0.0.3';

// mixed format - the following servers are individual to each
// domain, as specified by the array key.
$wgLdapAuthServers = [
    'DOMAIN_1' => '127.0.0.1 127.0.0.2,127.0.0.3',          // space and comma delimited
    'DOMAIN_2' => ['127.0.0.1', '127.0.0.2', '127.0.0.3'],  // PHP array format
    'DOMAIN_3' => '127.0.0.4',
];
```

### wgLdapAuthBindDN
Specifies the user's distinguished name upon which to perform the bind.

###### DEFAULT: `false`
###### PER-DOMAIN

Examples:
```php
// DN for single domain usage
$wgLdapAuthBindDN = 'CN=Wiki,DC=DOMAIN_1';

// DN for multi-domain usage
$wgLdapAuthBindDN = [
    'DOMAIN_1' => 'CN=Wiki,DC=DOMAIN_1',
    'DOMAIN_2' => 'CN=Wiki,DC=DOMAIN_2',
    'DOMAIN_3' => 'CN=Wiki,DC=DOMAIN_3',
];
```

### wgLdapAuthBindPass
Specifies the password upon which to perform the bind.

###### DEFAULT: `false`
###### PER-DOMAIN

Examples:
```php
$wgLdapAuthBindPass = 'MyPasswordHere';
// or
$wgLdapAuthBindPass = [
    'DOMAIN_1' => 'Domain 1 Password',
    'DOMAIN_2' => 'Domain 2 Password',
    'DOMAIN_3' => 'Domain 3 Password',
];
```

### wgLdapAuthBaseDN
Specifies the DN within which a search is performed.

###### DEFAULT: `false`
###### PER-DOMAIN

Examples:
```php
// DN for single domain usage
$wgLdapAuthBaseDN = 'OU=Users,DC=DOMAIN_1';

// DN for multi-domain usage
$wgLdapAuthBaseDN = [
    'DOMAIN_1' => 'OU=Users,DC=DOMAIN_1',
    'DOMAIN_2' => 'OU=Users,DC=DOMAIN_2',
    'DOMAIN_3' => 'OU=Users,DC=DOMAIN_3',
];
```

### wgLdapAuthSearchTree
Specifies whether or not to perform a recursive search on the BaseDN.

###### DEFAULT: `true`
###### PER-DOMAIN

Examples:
```php
// We will not allow recursive tree searches on any domain
$wgLdapAuthSearchTree = false;

// We will allow recursive searching for only DOMAIN_1
$wgLdapAuthSearchTree = [
    'DOMAIN_1' => true,
    'DOMAIN_2' => false,
    'DOMAIN_3' => false,
];
```

### wgLdapAuthSearchFilter
The filter to be used when performing a search. By default, searches may be performed against first name, last name or username. Disabled accounts are filtered. `%1$s` is used as a placeholder for the username for which we are searching.

###### DEFAULT: `(&(objectCategory=person)(objectClass=user)(!(UserAccountControl:1.2.840.113556.1.4.803:=2))(|(sAMAccountName=%1$s*)(firstName=%1$s*)(lastName=%1$s*)(displayName=%1$s*)))`
###### PER-DOMAIN

Example:
```php
// Overwrite search filter for all domains
$wgLdapAuthSearchFilter = '(&(objectClass=user)(displayName=%1$s))';

// Overwrite search filter for only DOMAIN_1.
// All other domains will inherit the default value.
$wgLdapAuthSearchFilter = [
    'DOMAIN_1' => '(&(objectClass=user)(displayName=%1$s))',
];
```

### wgLdapAuthEncryptionType
The encryption method to use on the connection. Valid values are false, 'ssl', 'tls'.

###### DEFAULT: `false`
###### PER-DOMAIN

Example:
```php
// Set all domains to use TLS encryption
$wgLdapAuthEncryptionType = 'tls';

// Specify that DOMAIN_1 will use TLS, DOMAIN_2 will use SSL
// and DOMAIN_3 will not use encryption.
$wgLdapAuthEncryptionType = [
    'DOMAIN_1' => 'tls',
    'DOMAIN_2' => 'ssl',
    'DOMAIN_3' => false,
];
```

### wgLdapAuthUseLocal
Specifies whether local authentication may be performed against the MediaWiki database.

Note that this does not provide per-domain configuration.

###### DEFAULT: `false`

Example:
```php
// Allow logins to MediaWiki "local" accounts
$wgLdapAuthUseLocal = true;

// Disallow logins to MediaWiki "local" accounts
$wgLdapAuthUseLocal = false;
```

### wgLdapAuthRequireDomain
If there is only one domain to select from, the domain field will be hidden for brevity. We can override this behaviour and force the field to always display.

Note that this does not provide per-domain configuration.

###### DEFAULT: `false`

### wgLdapAuthMapGroups
Maps LDAP groups to equivalent MediaWiki groups.

###### DEFAULT: `array()`
###### PER-DOMAIN

### wgLdapAuthCacheGroupMap
Specifies the period of time for which LDAP grouping should be synced for a user.

###### DEFAULT: `3600`
###### PER-DOMAIN

### wgLdapAuthIsActiveDirectory
Are we connecting to an Active-Directory LDAP server?

###### DEFAULT: `false`
###### PER-DOMAIN

  [1]: https://www.mediawiki.org/wiki/Manual:LocalSettings.php
