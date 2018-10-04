--
-- extension LdapAuth SQL schema
--
CREATE TABLE /*$wgDBprefix*/user_ldapauth_user (
    user_id int(10) unsigned NOT NULL,
    user_domain tinyblob NOT NULL,
    KEY(user_id)
) /*$wgDBTableOptions*/;
