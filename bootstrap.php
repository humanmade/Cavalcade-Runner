<?php

namespace HM\Cavalcade\Runner;

function autoload( $class ) {
	if ( strpos( $class, __NAMESPACE__ ) !== 0 ) {
		return;
	}

	$file = str_replace( __NAMESPACE__ . '\\', '', $class );
	$file = str_replace( '\\', DIRECTORY_SEPARATOR, $file );
	include __DIR__ . '/lib/' . $file . '.php';
}

spl_autoload_register( __NAMESPACE__ . '\\autoload' );
