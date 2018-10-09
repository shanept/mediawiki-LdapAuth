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

# Configuration


  [1]: https://www.mediawiki.org/wiki/Manual:LocalSettings.php
