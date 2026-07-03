<?php

// Binder spike DB config (Propel). Points at the `db` compose service.
// Copied to /app/config/config.php by entrypoint if that gitignored file is absent.
return array(
  'all' => array(
    'propel' => array(
      'class' => 'sfPropelDatabase',
      'param' => array(
        'encoding'   => 'utf8',
        'persistent' => true,
        'pooling'    => true,
        'dsn'        => 'mysql:dbname=binder;host=db;port=3306',
        'username'   => 'root',
        'password'   => '',
      ),
    ),
  ),
);
