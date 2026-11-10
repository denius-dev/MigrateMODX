<?php
/**
 * PHP 7.4 → 8+ detection / autofix rules.
 * safety: high = may autofix; low = report only.
 */
return array(
    array(
        'id' => 'curly_offset',
        'severity' => 'blocker',
        'safety' => 'high',
        'detect' => '/(\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(?:\[[^\]]+\])*)\{([\'"]?)([^}]+)\2\}/',
        'message' => 'Curly-brace array/string offset is removed in PHP 8.0',
        'autofix' => true,
    ),
    array(
        'id' => 'each',
        'severity' => 'blocker',
        'safety' => 'low',
        'detect' => '/\\beach\\s*\\(/',
        'message' => 'each() was removed in PHP 8.0',
        'autofix' => false,
    ),
    array(
        'id' => 'create_function',
        'severity' => 'blocker',
        'safety' => 'low',
        'detect' => '/\\bcreate_function\\s*\\(/',
        'message' => 'create_function() was removed in PHP 8.0',
        'autofix' => false,
    ),
    array(
        'id' => 'money_format',
        'severity' => 'blocker',
        'safety' => 'low',
        'detect' => '/\\bmoney_format\\s*\\(/',
        'message' => 'money_format() was removed in PHP 8.0',
        'autofix' => false,
    ),
    array(
        'id' => 'dollar_brace_interp',
        'severity' => 'blocker',
        'safety' => 'high',
        'detect' => '/\\$\\{([a-zA-Z_][a-zA-Z0-9_]*)\\}/',
        'message' => '${var} string interpolation is deprecated/removed',
        'autofix' => true,
    ),
    array(
        'id' => 'split',
        'severity' => 'blocker',
        'safety' => 'low',
        'detect' => '/\\bsplit\\s*\\(/',
        'message' => 'split() was removed long ago; use preg_split/explode',
        'autofix' => false,
    ),
    array(
        'id' => 'mysql_',
        'severity' => 'blocker',
        'safety' => 'low',
        'detect' => '/\\bmysql_(connect|query|fetch|close|select_db|real_escape_string|error)\\s*\\(/',
        'message' => 'mysql_* extension was removed in PHP 7+',
        'autofix' => false,
    ),
    array(
        'id' => 'ereg',
        'severity' => 'blocker',
        'safety' => 'low',
        'detect' => '/\\bereg(i)?(_replace)?\\s*\\(/',
        'message' => 'ereg* functions were removed',
        'autofix' => false,
    ),
    array(
        'id' => 'libxml_disable_entity_loader',
        'severity' => 'warning',
        'safety' => 'low',
        'detect' => '/\\blibxml_disable_entity_loader\\s*\\(/',
        'message' => 'libxml_disable_entity_loader() is deprecated in PHP 8',
        'autofix' => false,
    ),
    array(
        'id' => 'implode_reverse',
        'severity' => 'warning',
        'safety' => 'low',
        'detect' => '/\\bimplode\\s*\\(\\s*\\$/',
        'message' => 'Possible implode($array, $glue) argument order (legacy)',
        'autofix' => false,
    ),
);
