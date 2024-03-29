<?php

/*
Plugin Name: VIP Local Development Helper
Description: Helps you test your <a href="http://vip.wordpress.com/hosting/">WordPress.com VIP</a> theme in your local development environment by defining some functions that are always loaded on WordPress.com
Plugin URI:  http://lobby.vip.wordpress.com/getting-started/development-environment/
Author:      Automattic
Author URI:  http://vip.wordpress.com/

For help with this plugin, please see http://wp.me/PPtWC-2T or contact VIP support at vip-support@wordpress.com

This plugin is enabled automatically on WordPress.com for VIPs.
*/


/**
 * Loads a plugin out of our shared plugins directory.
 *
 * @link http://lobby.vip.wordpress.com/plugins/ VIP Shared Plugins
 * @param string $plugin Optional. Plugin folder name (and filename) of the plugin
 * @param string $folder Optional. Folder to include from; defaults to "plugins". Useful for when you have multiple themes and your own shared plugins folder.
 * @return bool True if the include was successful
 */
function wpcom_vip_load_plugin( $plugin = false, $folder = 'plugins', $load_release_candidate = false ) {

	// Force release candidate loading if the site has the correct sticker
	if ( ( defined( 'WPCOM_IS_VIP_ENV' ) && true === WPCOM_IS_VIP_ENV ) && has_blog_sticker( 'vip-plugins-ui-rc-plugins' ) ) {
		$load_release_candidate = true;
	}

	// Make sure there's a plugin to load
	if ( empty($plugin) ) {
		// On WordPress.com, use an internal function to message VIP about a bad call to this function
		if ( function_exists( 'wpcom_is_vip' ) ) {
			if ( function_exists( 'send_vip_team_debug_message' ) ) {
				// Use an expiring cache value to avoid spamming messages
				if ( ! wp_cache_get( 'noplugin', 'wpcom_vip_load_plugin' ) ) {
					send_vip_team_debug_message( 'WARNING: wpcom_vip_load_plugin() is being called without a $plugin parameter', 1 );
					wp_cache_set( 'noplugin', 1, 'wpcom_vip_load_plugin', 3600 );
				}
			}
			return false;
		}
		// die() in non-WordPress.com environments so you know you made a mistake
		else {
			die( 'wpcom_vip_load_plugin() was called without a first parameter!' );
		}
	}

	// Make sure $plugin and $folder are valid
	$plugin = _wpcom_vip_load_plugin_sanitizer( $plugin );
	if ( 'plugins' !== $folder )
		$folder = _wpcom_vip_load_plugin_sanitizer( $folder );

	// Shared plugins are located at /wp-content/themes/vip/plugins/example-plugin/
	// You should keep your local copies of the plugins in the same location

	$includepath 					= WP_CONTENT_DIR . "/themes/vip/$folder/$plugin/$plugin.php";
	$release_candidate_includepath 	= WP_CONTENT_DIR . "/themes/vip/$folder/release-candidates/$plugin/$plugin.php";

	if( true === $load_release_candidate && file_exists( $release_candidate_includepath ) ) {
		$includepath = $release_candidate_includepath;
	}

	if ( file_exists( $includepath ) ) {

		wpcom_vip_add_loaded_plugin( "$folder/$plugin" );

		// Since we're going to be include()'ing inside of a function,
		// we need to do some hackery to get the variable scope we want.
		// See http://www.php.net/manual/en/language.variables.scope.php#91982

		// Start by marking down the currently defined variables (so we can exclude them later)
		$pre_include_variables = get_defined_vars();

		// Now include
		include_once( $includepath );

		// If there's a wpcom-helper file for the plugin, load that too
		$helper_path = WP_CONTENT_DIR . "/themes/vip/$folder/$plugin/wpcom-helper.php";
		if ( file_exists( $helper_path ) )
			require_once( $helper_path );

		// Blacklist out some variables
		$blacklist = array( 'blacklist' => 0, 'pre_include_variables' => 0, 'new_variables' => 0 );

		// Let's find out what's new by comparing the current variables to the previous ones
		$new_variables = array_diff_key( get_defined_vars(), $GLOBALS, $blacklist, $pre_include_variables );

		// global each new variable
		foreach ( $new_variables as $new_variable => $devnull )
			global $$new_variable;

		// Set the values again on those new globals
		extract( $new_variables );

		return true;
	} else {
		// On WordPress.com, use an internal function to message VIP about the bad call to this function
		if ( function_exists( 'wpcom_is_vip' ) ) {
			if ( function_exists( 'send_vip_team_debug_message' ) ) {
				// Use an expiring cache value to avoid spamming messages
				$cachekey = md5( $folder . '|' . $plugin );
				if ( ! wp_cache_get( "notfound_$cachekey", 'wpcom_vip_load_plugin' ) ) {
					send_vip_team_debug_message( "WARNING: wpcom_vip_load_plugin() is trying to load a non-existent file ( /$folder/$plugin/$plugin.php )", 1 );
					wp_cache_set( "notfound_$cachekey", 1, 'wpcom_vip_load_plugin', 3600 );
				}
			}
			return false;

		// die() in non-WordPress.com environments so you know you made a mistake
		} else {
			die( "Unable to load $plugin ({$folder}) using wpcom_vip_load_plugin()!" );
		}
	}
}

/**
 * Helper function for wpcom_vip_load_plugin(); sanitizes plugin folder name.
 *
 * You shouldn't use this function.
 *
 * @param string $folder Folder name
 * @return string Sanitized folder name
 */
function _wpcom_vip_load_plugin_sanitizer( $folder ) {
	$folder = preg_replace( '#([^a-zA-Z0-9-_.]+)#', '', $folder );
	$folder = str_replace( '..', '', $folder ); // To prevent going up directories

	return $folder;
}

/**
 * Require a library in the VIP shared code library.
 *
 * @param string $slug 
 */
function wpcom_vip_require_lib( $slug ) {
	if ( !preg_match( '|^[a-z0-9/_.-]+$|i', $slug ) ) {
		trigger_error( "Cannot load a library with invalid slug $slug.", E_USER_ERROR );
		return;
	}
	$basename = basename( $slug );
	$lib_dir = WP_CONTENT_DIR . '/themes/vip/plugins/lib';
	$choices = array(
		"$lib_dir/$slug.php",
		"$lib_dir/$slug/0-load.php",
		"$lib_dir/$slug/$basename.php",
	);
	foreach( $choices as $file_name ) {
		if ( is_readable( $file_name ) ) {
			require_once $file_name;
			return;
		}
	}
	trigger_error( "Cannot find a library with slug $slug.", E_USER_ERROR );
}

/**
 * Loads the shared VIP helper file which defines some helpful functions.
 *
 * @link http://vip.wordpress.com/documentation/development-environment/ Setting up your Development Environment
 */
function wpcom_vip_load_helper() {
	$includepath = WP_CONTENT_DIR . '/themes/vip/plugins/vip-helper.php';

	if ( file_exists( $includepath ) ) {
		require_once( $includepath );
	} else {
		die( "Unable to load vip-helper.php using wpcom_vip_load_helper(). The file doesn't exist!" );
	}
}


/**
 * Loads the WordPress.com-only VIP helper file which defines some helpful functions.
 *
 * @link http://vip.wordpress.com/documentation/development-environment/ Setting up your Development Environment
 */
function wpcom_vip_load_helper_wpcom() {
	$includepath = WP_CONTENT_DIR . '/themes/vip/plugins/vip-helper-wpcom.php';
	require_once( $includepath );
}

/**
 * Loads the WordPress.com-only VIP helper file for stats which defines some helpful stats-related functions.
 */
function wpcom_vip_load_helper_stats() {
	$includepath = WP_CONTENT_DIR . '/themes/vip/plugins/vip-helper-stats-wpcom.php';
	require_once( $includepath );
}

/**
 * Store the name of a VIP plugin that will be loaded
 *
 * @param string $plugin Plugin name and folder
 * @see wpcom_vip_load_plugin()
 */
function wpcom_vip_add_loaded_plugin( $plugin ) {
	global $vip_loaded_plugins;

	if ( ! isset( $vip_loaded_plugins ) )
		$vip_loaded_plugins = array();

	array_push( $vip_loaded_plugins, $plugin );
}

/**
 * Get the names of VIP plugins that have been loaded
 *
 * @return array
 */
function wpcom_vip_get_loaded_plugins() {
	global $vip_loaded_plugins;

	if ( ! isset( $vip_loaded_plugins ) )
		$vip_loaded_plugins = array();

	return $vip_loaded_plugins;
}

/**
 * Returns the raw path to the VIP themes dir.
 *
 * @return string
 */
function wpcom_vip_themes_root() {
	return WP_CONTENT_DIR . '/themes/vip';
}

/**
 * Returns the non-CDN URI to the VIP themes dir.
 *
 * Sometimes enqueuing/inserting resources can trigger cross-domain errors when
 * using the CDN, so this function allows bypassing the CDN to eradicate those
 * unwanted errors.
 *
 * @return string The URI
 */
function wpcom_vip_themes_root_uri() {
	if ( ! is_admin() ) {
		return home_url( '/wp-content/themes/vip' );
	} else {
		return content_url( '/themes/vip' );
	}
}

/**
 * Returns the non-CDN'd URI to the specified path.
 *
 * @param string $path Must be a full path, e.g. dirname( __FILE__ )
 * @return string
 */
function wpcom_vip_noncdn_uri( $path ) {
	// Be gentle on Windows, borrowed from core, see plugin_basename
	$path = str_replace( '\\','/', $path ); // sanitize for Win32 installs
	$path = preg_replace( '|/+|','/', $path ); // remove any duplicate slash

	return sprintf( '%s%s', wpcom_vip_themes_root_uri(), str_replace( wpcom_vip_themes_root(), '', $path ) );
}

/**
 * Filter plugins_url() so that it works for plugins inside the shared VIP plugins directory or a theme directory.
 *
 * Props to the GigaOm dev team for coming up with this method.
 *
 * @param string $url Optional. Absolute URL to the plugins directory.
 * @param string $path Optional. Path relative to the plugins URL.
 * @param string $plugin Optional. The plugin file that you want the URL to be relative to.
 * @return string
 */
function wpcom_vip_plugins_url( $url = '', $path = '', $plugin = '' ) {
	static $content_dir, $vip_dir, $vip_url;

	if ( ! isset( $content_dir ) ) {
		// Be gentle on Windows, borrowed from core, see plugin_basename
		$content_dir = str_replace( '\\','/', WP_CONTENT_DIR ); // sanitize for Win32 installs
		$content_dir = preg_replace( '|/+|','/', $content_dir ); // remove any duplicate slash
	}

	if ( ! isset( $vip_dir ) ) {
		$vip_dir = $content_dir . '/themes/vip';
	}

	if ( ! isset( $vip_url ) ) {
		$vip_url = content_url( '/themes/vip' );
	}

	// Don't bother with non-VIP or non-path URLs
	if ( ! $plugin || 0 !== strpos( $plugin, $vip_dir ) ) {
		return $url;
	}

	if( 0 === strpos( $plugin, $vip_dir ) )
		$url_override = str_replace( $vip_dir, $vip_url, dirname( $plugin ) );
	elseif  ( 0 === strpos( $plugin, get_stylesheet_directory() ) )
		$url_override = str_replace(get_stylesheet_directory(), get_stylesheet_directory_uri(), dirname( $plugin ) );

	if ( isset( $url_override ) )
		$url = trailingslashit( $url_override ) . $path;

	return $url;
}
add_filter( 'plugins_url', 'wpcom_vip_plugins_url', 10, 3 );

/**
 * Return a URL for given VIP theme and path. Does not work with VIP shared plugins.
 *
 * @param string $path Optional. Path to suffix to the theme URL.
 * @param string $theme Optional. Name of the theme folder.
 * @return string|bool URL for the specified theme and path. If path doesn't exist, returns false.
 */
function wpcom_vip_theme_url( $path = '', $theme = '' ) {
	if ( empty( $theme ) )
		$theme = str_replace( 'vip/', '', get_stylesheet() );

	// We need to reference a file in the specified theme; style.css will almost always be there.
	$theme_folder = sprintf( '%s/themes/vip/%s', WP_CONTENT_DIR, $theme );
	$theme_file = $theme_folder . '/style.css';

	// For local environments where the theme isn't under /themes/vip/themename/
	$theme_folder_alt = sprintf( '%s/themes/%s', WP_CONTENT_DIR, $theme );
	$theme_file_alt = $theme_folder_alt . '/style.css';

	$path = ltrim( $path, '/' );

	// We pass in a dummy file to plugins_url even if it doesn't exist, otherwise we get a URL relative to the parent of the theme folder (i.e. /themes/vip/)
	if ( is_dir( $theme_folder ) ) {
		return plugins_url( $path, $theme_file );
	} elseif ( is_dir( $theme_folder_alt ) ) {
		return plugins_url( $path, $theme_file_alt );
	}

	return false;
}

/**
 * Return the directory path for a given VIP theme
 *
 * @link http://vip.wordpress.com/documentation/mobile-theme/ Developing for Mobile Phones and Tablets
 * @param string $theme Optional. Name of the theme folder
 * @return string Path for the specified theme
 */
function wpcom_vip_theme_dir( $theme = '' ) {
	if ( empty( $theme ) )
		$theme = get_stylesheet();

	// Simple sanity check, in case we get passed a lame path
	$theme = ltrim( $theme, '/' );
	$theme = str_replace( 'vip/', '', $theme );

	return sprintf( '%s/themes/vip/%s', WP_CONTENT_DIR, $theme );
}


/**
 * VIPs and other themes can declare the permastruct, tag and category bases in their themes.
 * This is done by filtering the option.
 *
 * To ensure we're using the freshest values, and that the option value is available earlier
 * than when the theme is loaded, we need to get each option, save it again, and then
 * reinitialize wp_rewrite.
 *
 * On WordPress.com this happens auto-magically when theme updates are deployed
 */
function wpcom_vip_local_development_refresh_wp_rewrite() {
	// No-op on WordPress.com
	if ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV )
		return;

	global $wp_rewrite;

	// Permastructs available in the options table and their core defaults
	$permastructs = array(
			'permalink_structure',
			'category_base',
			'tag_base',
		);

	$needs_flushing = false;

	foreach( $permastructs as $option_key ) {
		$filter = 'pre_option_' . $option_key;
		$callback = '_wpcom_vip_filter_' . $option_key;

		$option_value = get_option( $option_key );
		$filtered = has_filter( $filter, $callback );
		if ( $filtered ) {
			remove_filter( $filter, $callback, 99 );
			$raw_option_value = get_option( $option_key );
			add_filter( $filter, $callback, 99 );

			// Are we overriding this value in the theme?
			if ( $option_value != $raw_option_value ) {
				$needs_flushing = true;
				update_option( $option_key, $option_value );
			}
		}

	}

	// If the options are different from the theme let's fix it.
	if ( $needs_flushing ) {
		// Reconstruct WP_Rewrite and make sure we persist any custom endpoints, etc.
		$old_values = array();
		$custom_rules = array(
				'extra_rules',
				'non_wp_rules',
				'endpoints',
			);
		foreach( $custom_rules as $key ) {
			$old_values[$key] = $wp_rewrite->$key;
		}
		$wp_rewrite->init();
		foreach( $custom_rules as $key ) {
			$wp_rewrite->$key = array_merge( $old_values[$key], $wp_rewrite->$key );
		}
	
		flush_rewrite_rules( false );
	}
}
if ( defined( 'WPCOM_IS_VIP_ENV' ) && ! WPCOM_IS_VIP_ENV ) {
	add_action( 'init', 'wpcom_vip_local_development_refresh_wp_rewrite', 9999 );
}


/**
 * If you don't want people (de)activating plugins via this UI
 * and only want to enable plugins via wpcom_vip_load_plugin()
 * calls in your theme's functions.php file, then call this
 * function to disable this plugin's (de)activation links.
 */
function wpcom_vip_plugins_ui_disable_activation() {
	//The Class is not loaded on local environments
	if ( class_exists( "WPcom_VIP_Plugins_UI" )){
		WPcom_VIP_Plugins_UI()->activation_disabled = true;
	}
}

/** 
 * Return the language code. 
 *
 * Internal wpcom function that's used by the wpcom-sitemap plugin
 *
 * Note: Not overrideable in production - this function exists solely for dev environment
 * compatibility. To set blog language, use the Dashboard UI.
 * 
 * @return string 
 */
if ( ! function_exists( 'get_blog_lang_code' ) ) {
	function get_blog_lang_code() { 
		return 'en'; 
	}
}
