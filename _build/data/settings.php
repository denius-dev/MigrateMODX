<?php
return array(
    'migratemodx_target_modx' => array('textfield', 'auto'),
    'migratemodx_target_php' => array('textfield', 'auto'),
    'migratemodx_store_path' => array('textfield', ''),
    'migratemodx_require_staging' => array('combo-boolean', '1'),
    'migratemodx_repo_check' => array('combo-boolean', '1'),
    'migratemodx_repo_modx_url' => array('textfield', 'https://rest.modx.com/extras/'),
    'migratemodx_repo_modstore_url' => array('textfield', 'https://modstore.pro/extras/'),
    'migratemodx_repo_timeout' => array('numberfield', '8'),
    'migratemodx_repo_cache_ttl' => array('numberfield', '86400'),
);
