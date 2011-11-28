<?php

// internal [DO NOT CHANGE]
define( 'ZC_DIR', __DIR__ );
define( 'ZC_CONTENTS', ZC_DIR . '/content' );

// realtive dir. Change, if not in docroot!
define( 'ZC_RELDIR', substr( __DIR__, strlen( $_SERVER[ 'DOCUMENT_ROOT' ] ) ). '/' );

// textile modifcation
define( 'ZC_CLEAR_EMPTY_PARAGRAPHS', true );

// admin credentials
define( 'ZC_ADMIN_LOGIN', 'admin' );
define( 'ZC_ADMIN_PASSWORD', 'password' );

// default toc title
define( 'ZC_TOC_DEFAULT_TITLE', 'Table of content' );

// theme
define( 'ZC_THEME', 'ugly' );

// charset (for header)
define( 'ZC_CHARSET_OUT', 'UTF-8' );

// either "apc" or "file" or "none"
define( 'ZC_CACHE', 'none' );

// either "TextileAlike", "Textile" or "Markdown"
define( 'ZC_PARSER', 'TextileAlike' );

// only required if ZC_CACHE is set to file
define( 'ZC_CACHE_DIR', ZC_DIR. '/cache' );

// only required if ZC_CACHE is set to apc, useful for multiple installations
// using the same APC
define( 'ZC_CACHE_PREFIX', 'zerocms-' );

// debug & benchmark
ini_set( 'display_errors', in_array( $_SERVER[ 'SERVER_NAME' ], array( 'localhost', 'zerocms' ) ) );
define( 'ZC_PRINT_RENDER_TIME', true );
define( 'ZC_DEBUG', false );

?>