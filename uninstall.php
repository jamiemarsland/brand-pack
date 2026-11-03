<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'brand_pack', 'brand_pack_cache', 'brand_pack_dirty', 'brand_pack_history' ) as $option ) {
	delete_option( $option );
}
