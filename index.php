<?php
/**
* Default bootstrap file for ZeroCMS
*/

if ( ! defined( '__DIR__' ) )
	define( '__DIR__', dirname( $_SERVER[ 'SCRIPT_FILENAME' ] ) );

include_once( __DIR__. '/config.php' );
include_once( __DIR__. '/inc/classZeroCms.php' );

$zcms = new ZeroCms();
$zcms->run();
?>