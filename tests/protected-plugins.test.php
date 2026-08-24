<?php

define( 'ABSPATH', __DIR__ . '/' );

function wp_normalize_path( $value ) {
	return str_replace( '\\', '/', $value );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

require dirname( __DIR__ ) . '/includes/class-admin-clean-plugin.php';

$reflection = new ReflectionClass( 'AdminClean_Plugin' );
$plugin     = $reflection->newInstanceWithoutConstructor();
$method     = $reflection->getMethod( 'ensure_required_protected_plugins' );

if ( PHP_VERSION_ID < 80100 ) {
	$method->setAccessible( true );
}

$existing = 'cloudflare/cloudflare.php | Cloudflare | cloudflare';
$migrated = $method->invoke( $plugin, $existing );

if ( false === strpos( $migrated, 'git-updater/git-updater.php | Git Updater | git-updater' ) ) {
	throw new RuntimeException( 'Existing AdminClean configurations must add Git Updater.' );
}

$already_configured = $existing . "\n" . 'git-updater/git-updater.php | Custom Label | custom-menu';
$unchanged          = $method->invoke( $plugin, $already_configured );

if ( 1 !== substr_count( $unchanged, 'git-updater/git-updater.php' ) ) {
	throw new RuntimeException( 'Existing Git Updater configuration must not be duplicated.' );
}

echo "Required protected-plugin tests passed.\n";
