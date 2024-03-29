<?php

namespace Automattic\Syndication\Clients\XML_Push;

use Automattic\Syndication;
use Automattic\Syndication\Pusher;
use Automattic\Syndication\Types;

include_once( ABSPATH . 'wp-includes/class-IXR.php' );
include_once( ABSPATH . 'wp-includes/class-wp-http-ixr-client.php' );

/**
 * Syndication Client: XML Push
 *
 * Create 'syndication sites' to push site content to an external
 * WordPress install via XML-RPC. Includes XPath mapping to map incoming
 * XML data to specific post data.
 *
 * @package Automattic\Syndication\Clients\XML
 */
include_once( ABSPATH . 'wp-includes/class-IXR.php' );
include_once( ABSPATH . 'wp-includes/class-wp-http-ixr-client.php' );

class Push_Client extends \WP_HTTP_IXR_Client {

	private $username;
	private $password;

	private $site_ID;


	function __construct() {}

	public function init( $site_ID = 0 ) {
		global $settings_manager;

		$this->username = get_post_meta( $site_ID, 'syn_site_username', true );
		$this->password = $settings_manager->syndicate_decrypt( get_post_meta( $site_ID, 'syn_site_password', true ) );
		$this->site_ID  = $site_ID;

		$server = untrailingslashit( get_post_meta( $site_ID, 'syn_site_url', true ) );

		/**
		 * Set up the callbacks for attachments.
		 */
		/**
		 * Filter whether the XML push client should push thumbnails.
		 *
		 * Return false to skip sending thumbnails.
		 *
		 * @param bool     $push_thumbnails Whether to push thumbnails. Default is true.
		 * @param int      $site_ID         The id of the site being pushed to.
		 * @param XML_Push $this            The push client instance.
		 */
		if ( true === apply_filters( 'syn_xmlrpc_push_send_thumbnail', true, $site_ID, $this ) ) {
			add_action( 'syn_xmlrpc_push_new_post_success', array( $this, 'post_push_send_thumbnail' ), 10, 6 );
			add_action( 'syn_xmlrpc_push_edit_post_success', array( $this, 'post_push_send_thumbnail' ), 10, 6 );
			// TODO: on delete post, delete thumbnail
		}

		/**
		 * Bail on connection test if we don't have a server URL.
		 */
		if ( '' === $server ) {
			return false;
		}

		if ( false === strpos( $server, 'xmlrpc.php' ) ) {
			$server = esc_url_raw( trailingslashit( $server ) . 'xmlrpc.php' );
		} else {
			$server = esc_url_raw( $server );
		}

		parent::__construct( $server );
	}

	public function get_posts( $site_ID = 0 ) {
	}

	private function get_thumbnail_meta_keys( $post_id ) {
		// Support for non-core images, like from the Multiple Post Thumbnail plugin
		/** Filter is documented in includes/clients/rest-push/class-push-client.php */
		return apply_filters( 'syn_xmlrpc_push_thumbnail_metas', array( '_thumbnail_id' ), $post_id );
	}

	/**
	 * Push thumbnail along with the post.
	 */
	function post_push_send_thumbnail( $remote_post_id, $post_id ) {

		$thumbnail_meta_keys = $this->get_thumbnail_meta_keys( $post_id );

		foreach ( $thumbnail_meta_keys as $thumbnail_meta ) {
			$thumbnail_id = get_post_meta( $post_id, $thumbnail_meta, true );
			$syn_local_meta_key = '_syn_push_thumb_' . $thumbnail_meta;
			$syndicated_thumbnails_by_site = get_post_meta( $post_id, $syn_local_meta_key, true );

			if ( ! is_array( $syndicated_thumbnails_by_site ) ) {
				$syndicated_thumbnails_by_site = array();
			}

			$syndicated_thumbnail_id = isset( $syndicated_thumbnails_by_site[ $this->site_ID ] ) ? $syndicated_thumbnails_by_site[ $this->site_ID ] : false;

			if ( ! $thumbnail_id ) {
				if ( $syndicated_thumbnail_id ) {
					$result = $this->query(
						'syndication.deleteThumbnail',
						'1',
						$this->username,
						$this->password,
						$remote_post_id,
						$thumbnail_meta
					);

					unset( $syndicated_thumbnails_by_site[ $this->site_ID ] );
					update_post_meta( $post_id, $syn_local_meta_key, $syndicated_thumbnails_by_site );

				}
				continue;
			}

			if ( $syndicated_thumbnail_id == $thumbnail_id ) {
				continue;
			}

			list( $thumbnail_url ) = wp_get_attachment_image_src( $thumbnail_id, 'full' );
			//pass thumbnail data and meta into the addThumnail to sync caption, description and alt-text
			//has to be this way since mw_newMediaObject doesn't allow to pass description and caption along
			$thumbnail_post_data = get_post( $thumbnail_id );
			$thumbnail_alt_text = get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );

			$result = $this->query(
				'syndication.addThumbnail',
				'1',
				$this->username,
				$this->password,
				$remote_post_id,
				$thumbnail_url,
				$thumbnail_meta,
				$thumbnail_post_data,
				$thumbnail_alt_text
			);

			if ( $result ) {
				$syndicated_thumbnails_by_site[ $this->site_ID ] = $thumbnail_id;
				update_post_meta( $post_id, $syn_local_meta_key, $syndicated_thumbnails_by_site );
			}
		}
	}

	public static function get_client_data() {
		return array( 'id' => 'WP_XMLRPC', 'modes' => array( 'push' ), 'name' => 'WordPress XMLRPC' );
	}

	/**
	 * Push a new post to the remote.
	 */
	public function new_post( $post_ID ) {

		$post = (array) get_post( $post_ID );

		/**
		* Filter the post used by the XML push client when pushing a new post.
		*
		* This filter can be used to exclude or alter posts a post push. Return false
		* to short circuit the post push.
		*
		* @param WP_Post $post    The post the be pushed.
		* @param int     $post_ID The id of the post originating this request.
		*/
		$post = apply_filters( 'syn_xmlrpc_push_filter_new_post', $post, $post_ID );
		if ( false === $post ) {
			return true;
		}

		//Uploads all gallery images to the remote site and replaces [gallery] tags with new IDs
		$post['post_content'] = $this->syndicate_gallery_images( $post['post_content'] );

		// rearranging arguments
		$args = array();
		$args['post_title']	   = $post['post_title'];
		$args['post_content']  = $post['post_content'];
		$args['post_excerpt']  = $post['post_excerpt'];
		$args['post_status']   = $post['post_status'];
		$args['post_type']     = $post['post_type'];
		$args['wp_password']   = $post['post_password'];
		$args['post_date_gmt'] = $this->convert_date_gmt( $post['post_date_gmt'], $post['post_date'] );
		$args['terms_names']   = $this->_get_post_terms( $post_ID );
		$args['custom_fields'] = $this->_get_custom_fields( $post_ID );

		/**
		 * Filter the args used for the XML Push client XML request when creating a new post.
		 * @param array $args Array of args to use.
		 */
		$args = apply_filters( 'syn_xmlrpc_push_new_post_args', $args, $post );

		$result = $this->query(
			'wp.newPost',
			'1',
			$this->username,
			$this->password,
			$args
		);

		if ( ! $result ) {
			return new \WP_Error( $this->getErrorCode(), $this->getErrorMessage() );
		}

		$remote_post_id = (int) $this->getResponse();

		do_action( 'syn_xmlrpc_push_new_post_success', $remote_post_id, $post_ID );

		return $remote_post_id;

	}

	/**
	 * Update an existing post on the remote.
	 */
	public function edit_post( $post_ID, $remote_post_id ) {

		$args = array();

		$post = (array)get_post( $post_ID );

		/**
		* Filter the post used by the XML push client when pushing an update.
		*
		* This filter can be used to exclude or alter posts during a content update to an
		* existing post on the remote. Return false to short circuit the post push update.
		*
		* @param WP_Post $post    The post the be pushed.
		* @param int     $post_ID The id of the post originating this request.
		*/
		$post = apply_filters( 'syn_xmlrpc_push_filter_edit_post', $post, $post_ID );
		if ( false === $post ) {
			return true;
		}

		$remote_post = $this->get_remote_post( $remote_post_id );

		if ( ! $remote_post ) {
			return new \WP_Error( 'syn-remote-post-not-found', __( 'Remote post doesn\'t exist.', 'syndication' ) );
		}

		// Delete existing metadata to avoid duplicates
		$args['custom_fields'] = array();
		foreach ( $remote_post['custom_fields'] as $custom_field ) {
			$args['custom_fields'][] = array(
				'id' => $custom_field['id'],
				'meta_key_lookup' => $custom_field['key'],
			);
		}

		$thumbnail_meta_keys = $this->get_thumbnail_meta_keys( $post_ID );

		foreach ( $thumbnail_meta_keys as $thumbnail_meta_key ) {
			$thumbnail_id = get_post_meta( $post_ID, $thumbnail_meta_key, true );
			$syn_local_meta_key = '_syn_push_thumb_' . $thumbnail_meta_key;
			$syndicated_thumbnails_by_site = get_post_meta( $post_ID, $syn_local_meta_key, true );

			if ( ! is_array( $syndicated_thumbnails_by_site ) ) {
				$syndicated_thumbnails_by_site = array();
			}

			$syndicated_thumbnail_id = isset( $syndicated_thumbnails_by_site[ $this->site_ID ] ) ? $syndicated_thumbnails_by_site[ $this->site_ID ] : false;

			if ( $syndicated_thumbnail_id == $thumbnail_id ) {
				//need to preserve old meta custom_type_thumbnail_id if it wasn't changed during update
				//hence we remove ID from the custom_fileds list in order to avoid its deletion
				foreach ( $args['custom_fields'] as $index => $value ) {
					if ( $value['meta_key_lookup'] == $thumbnail_meta_key ) {
						unset( $args['custom_fields'][$index] );
					}
				}
			}
		}

		//Uploads all gallery images to the remote site and replaces [gallery] tags with new IDs
		$post['post_content'] = $this->syndicate_gallery_images( $post['post_content'] );

		// rearranging arguments
		$args['post_title']    = $post['post_title'];
		$args['post_content']  = $post['post_content'];
		$args['post_excerpt']  = $post['post_excerpt'];
		$args['post_status']   = $post['post_status'];
		$args['post_type']     = $post['post_type'];
		$args['wp_password']   = $post['post_password'];
		$args['post_date_gmt'] = $this->convert_date_gmt( $post['post_date_gmt'], $post['post_date'] );
		$args['terms_names']   = $this->_get_post_terms( $post_ID );
		$args['custom_fields'] = array_merge( $args['custom_fields'], $this->_get_custom_fields( $post_ID ) );

		/**
		 * Filter the args used for the XML Push client XML request when updating a post.
		 * @param array $args Array of args to use.
		 */
		$args = apply_filters( 'syn_xmlrpc_push_edit_post_args', $args, $post );

		$result = $this->query(
			'wp.editPost',
			'1',
			$this->username,
			$this->password,
			$remote_post_id,
			$args
		);

		if ( ! $result ) {
			return new \WP_Error( $this->getErrorCode(), $this->getErrorMessage() );
		}

		do_action( 'syn_xmlrpc_push_edit_post_success', $remote_post_id, $post_ID );

		return $remote_post_id;
	}

	/**
	 * Utility method to Syndicate [gallery] shortcode images
	 * It needs to upload images and inject new IDs into the post_content
	 * @access private
	 * @uses $shortcode_tags global variable
	 * @param string $post_content - post to be syndicated
	 * @return string $post_content - post content with replaced gallery shortcodes
	 */
	private function syndicate_gallery_images( $post_content ) {
		$attachment_ids = array();
		global $shortcode_tags;
		//overwrite global shortcodes for gallery only and then revert back to original
		$temp = $shortcode_tags;
		$shortcode_tags = array( 'gallery' => 'gallery_shortcode' );
		$pattern = get_shortcode_regex();
		$shortcode_tags = $temp;

		$image_ids = array();
		$new_image_ids = array();

		if ( preg_match_all( '/' . $pattern . '/s', $post_content, $matches ) ) {
			$count = count( $matches[3] );
			for ( $i = 0; $i < $count; $i++ ) {
				$atts = shortcode_parse_atts( $matches[3][$i] );
				if ( isset( $atts['ids'] ) ) {
					$attachment_ids = explode( ',', $atts['ids'] );
					$image_ids[$i] = $attachment_ids;
				}
			}
		}

		if ( ! empty( $image_ids ) ) {
			foreach ( $image_ids as $key => $gallery_ids ) {
				foreach ( $gallery_ids as $index => $id ) {
					//do upload, get new ID back
					list( $thumbnail_url ) = wp_get_attachment_image_src( $id, 'full' );
					$thumbnail_post_data = get_post( $id );
					$thumbnail_alt_text = trim( get_post_meta( $id, '_wp_attachment_image_alt', true ) );

					$result = $this->query(
						'syndication.postGalleryImage',
						'1',
						$this->username,
						$this->password,
						$thumbnail_url,
						$thumbnail_post_data,
						$thumbnail_alt_text
					);

					if ( ! $result ) {
						return new \WP_Error( $this->getErrorCode(), $this->getErrorMessage() );
					}
					$new_image_ids[$key][$index] = (int) $this->getResponse();
				}
			}
		}

		//new IDs needs to be injected into the post content
		//replace old gallery code with a new one
		$lenght = count( $matches[0] );
		for ( $i = 0; $i < $lenght; $i++ ) {
			$shortcode = $matches[0][$i];
			//WP regex matches attribute with leading space, required here
			$attribute     = ' ids="' . implode( ',', $image_ids[$i] ) . '"';
			$new_attribute = ' ids="' . implode( ',', $new_image_ids[$i] ) . '"';
			$new_shortcode = str_replace( $attribute, $new_attribute, $shortcode );
			$post_content  = str_replace( $shortcode, $new_shortcode, $post_content );
		}

		return $post_content;
	}

	/**
	 * When we delete a local post, delete the remote as well.
	 */
	public function delete_post( $remote_post_id ) {

		$result = $this->query(
			'wp.deletePost',
			'1',
			$this->username,
			$this->password,
			$remote_post_id
		);

		if ( ! $result ) {
			return new \WP_Error( $this->getErrorCode(), $this->getErrorMessage() );
		}

		return true;
	}

	private function _get_custom_fields( $post_id ) {
		$post = get_post( $post_id );

		$custom_fields = array();
		$all_post_meta = get_post_custom( $post_id );

		$blacklisted_meta = $this->_get_meta_blacklist( $post_id );
		foreach ( (array) $all_post_meta as $post_meta_key => $post_meta_values ) {

			if ( in_array( $post_meta_key, $blacklisted_meta ) || preg_match( '/^_?syn/i', $post_meta_key ) )
				continue;

			foreach ( $post_meta_values as $post_meta_value ) {
				$post_meta_value = maybe_unserialize( $post_meta_value ); // get_post_custom returns serialized data

				$custom_fields[] = array(
					'key' => $post_meta_key,
					'value' => $post_meta_value,
				);
			}
		}

		$custom_fields[] = array(
			'key' => 'syn_source_url',
			'value' => $post->guid,
		);
		return $custom_fields;
	}

	private function _get_meta_blacklist( $post_id ) {
		$blacklist = array( '_edit_last', '_edit_lock' /** TODO: add more **/ );
		$thumbnail_meta_keys = $this->get_thumbnail_meta_keys( $post_id );

		$blacklist = array_merge( $blacklist, $thumbnail_meta_keys );
		/**
		 * Filter the list of ignored or blacklisted meta fields.
		 *
		 * @param array $blacklist The array of meta fields to ignore. Default is [ '_edit_last', '_edit_lock' ].
		 */
		return apply_filters( 'syn_ignored_meta_fields', $blacklist, $post_id );
	}

	private function _get_post_terms( $post_id ) {
		$terms_names = array();

		$post = get_post( $post_id );

		if ( is_object_in_taxonomy( $post->post_type, 'category' ) )
			$terms_names['category'] = wp_get_object_terms( $post_id, 'category', array( 'fields' => 'names' ) );

		if ( is_object_in_taxonomy( $post->post_type, 'post_tag' )  )
			$terms_names['post_tag'] = wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );

		// TODO: custom taxonomy

		return $terms_names;
	}

	/**
	 * Retrieve a remote post by ID.
	 */
	function get_remote_post( $remote_post_id ) {

		$result = $this->query(
			'wp.getPost',
			'1',
			$this->username,
			$this->password,
			$remote_post_id
		);

		if ( ! $result )
			return false;

		return $this->getResponse();
	}

	/**
	 * Check to see if a remote post exists.
	 */
	public function is_post_exists( $remote_post_id ) {
		$remote_post = $this->get_remote_post( $remote_post_id );

		if ( ! $remote_post || $remote_post_id != $remote_post['post_id'] ) {
			return false;
		}

		return true;

	}

	protected function convert_date_gmt( $date_gmt, $date ) {
		if ( $date !== '0000-00-00 00:00:00' && $date_gmt === '0000-00-00 00:00:00' ) {
			return new IXR_Date( get_gmt_from_date( mysql2date( 'Y-m-d H:i:s', $date, false ), 'Ymd\TH:i:s' ) );
		}
		return $this->convert_date( $date_gmt );
	}

	protected function convert_date( $date ) {
		if ( $date === '0000-00-00 00:00:00' ) {
			return new IXR_Date( '00000000T00:00:00Z' );
		}
		return new \IXR_Date( mysql2date( 'Ymd\TH:i:s', $date, false ) );
	}

	/**
	 * Test the connection to the remote server.
	 *
	 * @return bool
	 */
	public function test_connection( $site_ID ) {
		$this->init( $site_ID );

		$result = $this->query(
			'wp.getPostTypes', // @TODO find a better suitable function
			'1',
			$this->username,
			$this->password
		);

		if ( ! $result ) {

			$error_code = absint( $this->getErrorCode() );

			switch ( $error_code ) {
				case 32301:
					add_filter( 'redirect_post_location', create_function( '$location', 'return add_query_arg("message", 305, $location);' ) );
					break;
				case 401:
					add_filter( 'redirect_post_location', create_function( '$location', 'return add_query_arg("message", 302, $location);' ) );
					break;
				case 403:
					add_filter( 'redirect_post_location', create_function( '$location', 'return add_query_arg("message", 303, $location);' ) );
					break;
				case 405:
					add_filter( 'redirect_post_location', create_function( '$location', 'return add_query_arg("message", 304, $location);' ) );
					break;
				default:
					add_filter( 'redirect_post_location', create_function( '$location', 'return add_query_arg("message", 306, $location);' ) );
					break;
			}

			return false;

		}

		return true;

	}

}
class Syndication_WP_XMLRPC_Client_Extensions {

	public static function init() {
		add_filter( 'xmlrpc_methods' , array( __CLASS__, 'push_syndicate_methods' ) );
	}

	public static function push_syndicate_methods( $methods ) {
		$methods['syndication.addThumbnail']     = array( __CLASS__, 'xmlrpc_add_thumbnail' );
		$methods['syndication.deleteThumbnail']  = array( __CLASS__, 'xmlrpc_delete_thumbnail' );
		$methods['syndication.postGalleryImage'] = array( __CLASS__, 'xmlrpc_post_gallery_images' );

		return $methods;
	}

	public static function xmlrpc_add_thumbnail( $args ) {
		global $wp_xmlrpc_server, $wpdb;

		$wp_xmlrpc_server->escape( $args );

		$blog_id             = (int) $args[0];
		$username            = $args[1];
		$password            = $args[2];
		$post_ID             = (int) $args[3];
		$thumbnail_url       = esc_url_raw( $args[4] );
		$meta_key            = ! empty( $args[5] ) ? sanitize_text_field( $args[5] ) : '_thumbnail_id';
		$thumbnail_post_data = $args[6];
		$thumbnail_alt_text  = $args[7];

		if ( ! $post_ID )
			return new IXR_Error( 500, __( 'Please specify a valid post_ID.', 'syndication' ) );

		$thumbnail_raw = wp_remote_retrieve_body( wp_remote_get( $thumbnail_url ) );
		if ( ! $thumbnail_raw )
			return new IXR_Error( 500, __( 'Sorry, the image URL provided was incorrect.', 'syndication' ) );

		$thumbnail_filename = basename( $thumbnail_url );
		$thumbnail_type = wp_check_filetype( $thumbnail_filename );

		$args = array(
			$blog_id,
			$username,
			$password,
			array(
				'name' => $thumbnail_filename,
				'type' => $thumbnail_type['type'],
				'bits' => $thumbnail_raw,
				'overwrite' => false,
			),
		);

		// Note: Leting mw_newMediaObject handle our auth and cap checks
		$image = $wp_xmlrpc_server->mw_newMediaObject( $args );

		if ( ! is_array( $image ) || empty( $image['url'] ) )
			return $image;

		$thumbnail_id = (int) $image['id'];
		if ( empty( $thumbnail_id ) )
			return new IXR_Error( 500, __( 'Sorry, looks like the image upload failed.', 'syndication' ) );

		if ( '_thumbnail_id' == $meta_key )
			$thumbnail_set = set_post_thumbnail( $post_ID, $thumbnail_id );
		else
			$thumbnail_set = update_post_meta( $post_ID, $meta_key, $thumbnail_id );

		if ( ! $thumbnail_set )
			return new IXR_Error( 403, __( 'Could not attach post thumbnail.' ) );

		$args = array(
			$blog_id,
			$username,
			$password,
			$thumbnail_id,
			array(
				'post_title' => $thumbnail_post_data['post_title'],
				'post_content' => $thumbnail_post_data['post_content'],
				'post_excerpt' => $thumbnail_post_data['post_excerpt'],
			),
		);
		//update caption and description of the image
		$result = $wp_xmlrpc_server->wp_editPost( $args );
		if ( $result !== true ) {
			//failed to update atatchment post details
			//handle it th way you want it (log it, message it)
		}
		//update alt text of the image
		update_post_meta( $thumbnail_id, '_wp_attachment_image_alt', $thumbnail_alt_text );

		return $thumbnail_id;
	}

	public static function xmlrpc_delete_thumbnail( $args ) {

		global $wp_xmlrpc_server;
		$wp_xmlrpc_server->escape( $args );

		$blog_id  = (int) $args[0];
		$username = $args[1];
		$password = $args[2];
		$post_ID  = (int) $args[3];
		$meta_key = ! empty( $args[4] ) ? sanitize_text_field( $args[4] ) : '_thumbnail_id';

		if ( ! $user = $wp_xmlrpc_server->login( $username, $password ) )
			return $wp_xmlrpc_server->error;

		if ( ! current_user_can( 'edit_post', $post_ID ) )
			return new IXR_Error( 401, __( 'Sorry, you are not allowed to post on this site.' ) );

		if ( '_thumbnail_id' == $meta_key )
			$result = delete_post_thumbnail( $post_ID );
		else
			$result = delete_post_meta( $post_ID, $meta_key );

		if ( ! $result )
			return new IXR_Error( 403, __( 'Could not remove post thumbnail.' ) );

		return true;

	}

	/**
	 * Upload Image for the gallery shortcode
	 * @uses $wp_xmlrpc_server
	 * @param array $args Contains necessary parameters for XMLRCP call: user, paasword, image data
	 * @return integer $thumbnail_id New ID of the newly uploaded image to the remote site
	 */
	public static function xmlrpc_post_gallery_images( $args ) {
		global $wp_xmlrpc_server;

		$wp_xmlrpc_server->escape( $args );

		$blog_id             = (int) $args[0];
		$username            = $args[1];
		$password            = $args[2];
		$thumbnail_url       = esc_url_raw( $args[3] );
		$thumbnail_post_data = $args[4];
		$thumbnail_alt_text  = $args[5];

		$thumbnail_raw = wp_remote_retrieve_body( wp_remote_get( $thumbnail_url ) );
		if ( ! $thumbnail_raw ) {
			return new IXR_Error( 500, __( 'Sorry, the image URL provided was incorrect.', 'syndication' ) );
		}

		$thumbnail_filename = basename( $thumbnail_url );
		$thumbnail_type = wp_check_filetype( $thumbnail_filename );

		$args = array(
			$blog_id,
			$username,
			$password,
			array(
				'name' => $thumbnail_filename,
				'type' => $thumbnail_type['type'],
				'bits' => $thumbnail_raw,
				'overwrite' => false,
			),
		);

		// Note: Letting mw_newMediaObject handle our auth and cap checks
		$image = $wp_xmlrpc_server->mw_newMediaObject( $args );
		if ( ! is_array( $image ) || empty($image['url'] ) ) {
			return $image;
		}

		$thumbnail_id = (int) $image['id'];
		if ( empty( $thumbnail_id ) ) {
			return new IXR_Error( 500, __( 'Sorry, looks like the image upload failed.', 'syndication' ) );
		}

		$args = array(
			$blog_id,
			$username,
			$password,
			$thumbnail_id,
			array(
				'post_title' => $thumbnail_post_data['post_title'],
				'post_content' => $thumbnail_post_data['post_content'],
				'post_excerpt' => $thumbnail_post_data['post_excerpt'],
			),
		);

		//update caption and description of the image
		$result = $wp_xmlrpc_server->wp_editPost( $args );
		if ( $result !== true ) {
			//failed to update atatchment post details
			//handle it th way you want it (log it, message it)
		}
		//update alt text of the image
		update_post_meta( $thumbnail_id, '_wp_attachment_image_alt', $thumbnail_alt_text );

		return $thumbnail_id;
	} //end of xmlrpc_post_gallery_images()

}

Syndication_WP_XMLRPC_Client_Extensions::init();
