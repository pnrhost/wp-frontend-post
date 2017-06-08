<?php
/*
Plugin Name: Frontend Custom Post
Plugin URI: http://wp.pnrhost.com/
Description: Custom frontend plugin for post any custom post
Version: 1.0
Author: pnrhost
Author URI: http://pnrhost.com
*/
//Define consts and bootstrap and dependencies

define( 'FU_VERSION', '1.1' );
define( 'FU_ROOT' , dirname( __FILE__ ) );
define( 'FU_FILE_PATH' , FU_ROOT . '/' . basename( __FILE__ ) );
define( 'FU_URL' , plugins_url( '/', __FILE__ ) );
define( 'FU_NONCE', 'frontend-custom-post-upload-media' );

require_once FU_ROOT . '/lib/php/class-html-helper.php';
require_once FU_ROOT . '/lib/php/settings-api/class.settings-api.php';
require_once FU_ROOT . '/lib/php/functions.php';
require_once FU_ROOT . '/lib/php/frontend-custom-post-settings.php';

class Frontend_Custom_Post {

	public $allowed_mime_types;
	public $html;
	public $settings;
	public $settings_slug = 'frontend_uploader_settings';
	public $is_debug = false;
	/**
	 * Should consist of fields to be proccessed automatically on content submission
	 *
	 * Example field:
	 * array(
	 * 'name' => '{form name}',
	 * 'element' => HTML element,
	 * 'role' => {title|description|content|file|meta|internal} )
	 *
	 * @var array
	 */
	public $form_fields = array();
	protected $manage_permissions = array();

	/**
	 * Here we go
	 *
	 * Instantiating the plugin, adding actions, filters, and shortcodes
	 */
	function __construct() {
		// Init
		add_action( 'init', array( $this, 'action_init' ) );

		// HTML helper to render HTML elements
		$this->html = new Html_Helper;

		// Either use default settings if no setting set, or try to merge defaults with existing settings
		// Needed if new options were added in upgraded version of the plugin
		$this->settings = get_option( $this->settings_slug, $this->settings_defaults() );
		register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
	}

	/**
	 * Load languages and a bit of paranoia
	 */
	function action_init() {

		load_plugin_textdomain( 'frontend-custom-post', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		// Hooking to wp_ajax

		add_action( 'wp_ajax_approve_ugc', array( $this, 'approve_media' ) );
		add_action( 'wp_ajax_approve_ugc_post', array( $this, 'approve_post' ) );
		add_action( 'wp_ajax_delete_ugc', array( $this, 'delete_post' ) );

		add_action( 'wp_ajax_upload_ugc', array( $this, 'upload_content' ) );
		add_action( 'wp_ajax_nopriv_upload_ugc', array( $this, 'upload_content' ) );

		// Adding media submenu
		add_action( 'admin_menu', array( $this, 'add_menu_items' ) );

		// Currently supported shortcodes
		add_shortcode( 'fcp-upload-form', array( $this, 'upload_form' ) );
		add_shortcode( 'fcp-upload-response', array( $this, 'upload_response_shortcode' ) );

		// Since 4.01 we need to explicitly disable texturizing of shortcode's inner content
		add_filter( 'no_texturize_shortcodes', array( $this, 'filter_no_texturize_shortcodes' ) );

		// Static assets
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

		// Unautop the shortcode
		add_filter( 'the_content', 'shortcode_unautop', 100 );
		// Hiding not approved attachments from Media Gallery
		// @since core 3.5-beta-1
		add_filter( 'posts_where', array( $this, 'filter_posts_where' ) );


		$this->allowed_mime_types = $this->_get_mime_types();
		// Configuration filter to change manage permissions
		$this->manage_permissions = apply_filters( 'fu_manage_permissions', 'edit_posts' );
		// Debug mode filter
		$this->is_debug = (bool) apply_filters( 'fu_is_debug', defined( 'WP_DEBUG' ) && WP_DEBUG );

		add_action( 'fu_after_upload', array( $this, '_maybe_insert_images_into_post' ), 10, 3 );

		// Maybe enable Akismet protection
		$this->_enable_akismet_protection();

		// Maybe enable Recaptcha protection
		$this->_enable_recaptcha_protection();
	}

	/**
	 * Slightly convoluted workaround to allow modifying of allowed MIME types for WP < 3.5,
	 * Workaround for IE sometimes setting image/pjepg and image/x-png for JPEGs and PNGs respectively
	 */
	function _get_mime_types() {
		// Use wp_get_mime_types if available, fallback to get_allowed_mime_types()
		$mime_types = function_exists( 'wp_get_mime_types' ) ? wp_get_mime_types() : get_allowed_mime_types() ;
		$fu_mime_types = fu_get_mime_types();
		// Workaround for IE
		$mime_types['jpg|jpe|jpeg|pjpg'] = 'image/pjpeg';
		$mime_types['png|xpng'] = 'image/x-png';
		$enabled = isset( $this->settings['enabled_files'] ) && is_array( $this->settings['enabled_files'] ) ?  $this->settings['enabled_files'] : array();
		// Iterate through default extensions
		foreach ( $fu_mime_types as $extension => $details ) {
			// Skip if it's not in the settings
			if ( !in_array( $extension, $enabled ) )
				continue;

			// Iterate through mime-types for this extension
			foreach ( $details['mimes'] as $ext_mime ) {
				$mime_types[ $extension . '|' . $extension . sanitize_title_with_dashes( $ext_mime ) ] = $ext_mime;
			}
		}

		// Configuration filter: fu_allowed_mime_types should return array of allowed mime types (see readme)
		$mime_types = apply_filters( 'fu_allowed_mime_types', $mime_types );

		foreach ( $mime_types as $ext_key => $mime ) {
			// Check for php just in case
			if ( false !== strpos( $mime, 'php' ) )
				unset( $mime_types[$ext_key] );
		}
		return $mime_types;
	}

	/**
	 * Ensure we're not producing any notices by supplying the defaults to get_option
	 *
	 * @return array $defaults
	 */
	function settings_defaults() {
		$defaults = array();
		$settings = Frontend_Custom_Post_Settings::get_settings_fields();
		foreach ( $settings[$this->settings_slug] as $setting ) {
			$defaults[ $setting['name'] ] = $setting['default'];
		}
		return $defaults;
	}

	/**
	 * Activation hook:
	 *
	 * Bail if version is less than 3.3, set default settings
	 */
	function activate_plugin() {
		global $wp_version;
		if ( version_compare( $wp_version, '3.3', '<' ) ) {
			wp_die( __( 'Frontend Uploader requires WordPress 3.3 or newer. Please upgrade.', 'frontend-custom-post' ) );
		}

		$defaults = $this->settings_defaults();
		$existing_settings = (array) get_option( $this->settings_slug, $this->settings_defaults() );
		update_option( $this->settings_slug, array_merge( $defaults, (array) $existing_settings ) );
	}


	/**
	 * Since 4.01 shortcode contents is texturized by default,
	 * avoid the behavior by explicitly whitelisting our shortcode
	 */
	function filter_no_texturize_shortcodes( $shortcodes ) {
		$shortcodes[] = 'fcp-upload-form';
		return $shortcodes;
	}

	/**
	 * Since WP 3.5-beta-1 WP Media interface shows private attachments as well
	 * We don't want that, so we force WHERE statement to post_status = 'inherit'
	 *
	 * @since 0.3
	 *
	 * @param string $where WHERE statement
	 * @return string WHERE statement
	 */
	function filter_posts_where( $where ) {
		if ( !is_admin() || !function_exists( 'get_current_screen' ) )
			return $where;

		$screen = get_current_screen();
		if ( ! defined( 'DOING_AJAX' ) && $screen && isset( $screen->base ) && $screen->base == 'upload' && ( !isset( $_GET['page'] ) || $_GET['page'] != 'manage_frontend_uploader' ) ) {
			$where = str_replace( "post_status = 'private'", "post_status = 'inherit'", $where );
		}
		return $where;
	}

	/**
	 * Determine if we should autoapprove the submission or not
	 *
	 * @return boolean [description]
	 */
	function _is_public() {
		return ( current_user_can( 'read' ) && 'on' == $this->settings['auto_approve_user_files'] ) || ( 'on' == $this->settings['auto_approve_any_files'] );
	}

	/**
	 * Handle uploading of the files
	 *
	 * @since 0.4
	 *
	 * @uses media_handle_sideload
	 *
	 * @param int  $post_id Parent post id
	 * @return array Combined result of media ids and errors if any
	 */
	function _upload_files( $post_id = 0 ) {
		// Only filter mimes just before the upload
		add_filter( 'upload_mimes', array( $this, '_get_mime_types' ), 999 );


		$media_ids = $errors = array();
		// Bail if there are no files
		if ( empty( $_FILES ) )
			return false;

		// File field name could be user defined, so we just get the first file
		$files = current( $_FILES );

		// There can be multiple files
		// So we need to iterate over each of the files to process
		for ( $i = 0; $i < count( $files['name'] ); $i++ ) {
			$fields = array( 'name', 'type', 'tmp_name', 'error', 'size' );
			foreach ( $fields as $field ) {
				$k[$field] = $files[$field][$i];
			}

			$k['name'] = sanitize_file_name( $k['name'] );

			// Skip to the next file if upload went wrong
			if ( $k['tmp_name'] == "" ) {
				continue;
			}

			$typecheck = wp_check_filetype_and_ext( $k['tmp_name'], $k['name'], false );
			// Add an error message if MIME-type is not allowed
			if ( ! in_array( $typecheck['type'], (array) $this->allowed_mime_types ) ) {
				$errors['fcp-disallowed-mime-type'][] = array( 'name' => $k['name'], 'mime' => $k['type'] );
				continue;
			}

			// Now let's try to catch eval( base64() ) et al
			if ( 0 !== $this->_invoke_paranoia_on_file_contents( file_get_contents( $k['tmp_name'] ) ) ) {
				$errors['fcp-suspicious-file'][] = array( 'name' => $k['name'] );
				continue;
			}

			// Setup some default values
			// However, you can make additional changes on 'fu_after_upload' action
			$caption = '';

			// Try to set post caption if the field is set on request
			// Fallback to post_content if the field is not set
			if ( isset( $_POST['caption'] ) )
				$caption = sanitize_text_field( $_POST['caption'] );
			elseif ( isset( $_POST['post_content'] ) )
				$caption = sanitize_text_field( $_POST['post_content'] );
			// TODO: remove or refactor
			$filename = !empty( $this->settings['default_file_name'] ) ? $this->settings['default_file_name'] : pathinfo( $k['name'], PATHINFO_FILENAME );
			$post_overrides = array(
				'post_status' => $this->_is_public() ? 'publish' : 'private',
				'post_title' => isset( $_POST['post_title'] ) && ! empty( $_POST['post_title'] ) ? sanitize_text_field( $_POST['post_title'] ) : sanitize_text_field( $filename ),
				'post_content' => empty( $caption ) ? __( 'Unnamed', 'frontend-custom-post' ) : $caption,
				'post_excerpt' => empty( $caption ) ? __( 'Unnamed', 'frontend-custom-post' ) : $caption,
			);

			// Obfuscate filename, just in case
			$m = $k;
			$fn = explode( '.', $k['name'] );
			$k['name'] = uniqid() . '.' . end( $fn );

			// Trying to upload the file
			$upload_id = media_handle_sideload( $k, (int) $post_id, $post_overrides['post_title'], $post_overrides );
			$m = $k;

			if ( !is_wp_error( $upload_id ) )
				$media_ids[] = $upload_id;
			else
				$errors['fcp-error-media'][] = $k['name'];
		}

		/**
		 * $success determines the rest of upload flow
		 * Setting this to true if no errors were produced even if there's was no files to upload
		 */
		$success = empty( $errors ) ? true : false;

		if ( $success ) {
			foreach ( $media_ids as $media_id ) {
				$this->_save_post_meta_fields( $media_id );
			}
		}

		// Allow additional setup
		// Pass array of attachment ids
		do_action( 'fu_after_upload', $media_ids, $success, $post_id );
		return array( 'success' => $success, 'media_ids' => $media_ids, 'errors' => $errors );
	}

	/**
	 * A callback to append uploaded images html to post
	 *
	 * @param  array $media_ids [description]
	 * @param  bool  $success   [description]
	 * @param  int   $post_id   [description]
	 * @return mixed            [description]
	 */
	function _maybe_insert_images_into_post( $media_ids, $success, $post_id ) {
		// Bail if request is failed,
		if ( ! $success || !isset( $_POST[ 'append_to_post' ] ) || !$_POST['append_to_post'] )
			return;
		$post = get_post( $post_id );

		$attachments_html = "\n";

		foreach( (array) $media_ids as $media_id ) {
			$attachments_html .= wp_get_attachment_image( $media_id, 'full' );
		}

		// add wp_kses_allowed_html filter just in time before we save post
		add_filter( 'wp_kses_allowed_html', array( $this, 'wp_kses_add_srcset' ), 10, 2 );

		$post->post_content .= $attachments_html;

		return wp_update_post( $post, true );
	}

	/**
	 * Force adding srcset as allowed attr for repsonsive images
	 * @param  [type] $tags    [description]
	 * @param  [type] $context [description]
	 * @return [type]          [description]
	 */
	function wp_kses_add_srcset( $tags, $context ) {
		if ( $context == 'post' )
			$tags['img']['srcset'] = true;

		return $tags;
	}

	/**
	 * Return count of regex matches for common type of upload attack eval(base64($malicious_payload))
	 * @param  string $str [description]
	 * @return int count of matches
	 */
	function _invoke_paranoia_on_file_contents( $str = '' ) {
		// Not a string, bail
		if ( ! is_string( $str ) )
			return 0;

		return preg_match_all( '/<\?php|eval\s*\(|base64_decode|gzinflate|gzuncompress/imsU', $str, $matches );
	}

	/**
	 * Handle post uploads
	 *
	 * @since 0.4
	 */
	function _upload_post() {
		$errors = array();
		$success = true;

		// Sanitize category if present in request
		// Allow to supply comma-separated category ids
		$category = array();
		if ( isset( $_POST['post_category'] ) ) {
			foreach ( explode( ',', $_POST['post_category'] ) as $cat_id ) {
				$category[] = (int) $cat_id;
			}
		}

		$post_title = isset( $_POST['caption'] ) ? sanitize_text_field( $_POST['caption'] ) : sanitize_text_field( $_POST['post_title'] );

		// Construct post array;
		$post_array = array(
			'post_type' => isset( $_POST['post_type'] ) && $this->is_allowed_post_type( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : 'post',
			'post_title' => $post_title ? $post_title : __( 'Untitled post submission', 'frontend-custom-post' ),
			'post_content' => wp_filter_post_kses( $_POST['post_content'] ),
			'post_status' => $this->_is_public() ? 'publish' : 'private',
			'post_category' => $category,
		);

		$author = isset( $_POST['post_author'] ) ? sanitize_text_field( $_POST['post_author'] ) : '';

		if ( $author ) {
			$users = get_users( array(
				'search' => $author,
				'fields' => 'ID'
			) );

			if ( isset( $users[0] ) ) {
				$post_array['post_author'] = (int) $users[0];
			}
		}

		$post_array = apply_filters( 'fu_before_create_post', $post_array );

		$post_id = wp_insert_post( $post_array, true );
		// Something went wrong
		if ( is_wp_error( $post_id ) ) {
			$errors[] = 'fcp-error-post';
			$success = false;
		} else {
			do_action( 'fu_after_create_post', $post_id );

			$this->_save_post_meta_fields( $post_id );
			// If the author name is not in registered users
			// Save the author name if it was filled and post was created successfully
			if ( $author )
				add_post_meta( $post_id, 'author_name', $author );
		}

		return array( 'success' => $success, 'post_id' => $post_id, 'errors' => $errors );
	}

	private function _save_post_meta_fields( $post_id = 0 ) {
		// Post ID not set, bailing
		if ( ! $post_id = (int) $post_id )
			return false;

		// No meta fields in field mapping, bailing
		if ( !isset( $this->form_fields['meta'] ) || empty( $this->form_fields['meta'] ) )
			return false;

		foreach ( $this->form_fields['meta'] as $meta_field ) {
			if ( !isset( $_POST[$meta_field] ) )
				continue;

			$value = $_POST[$meta_field];

			// Sanitize array
			if ( is_array( $value ) ) {
				$value = array_map( array( $this, '_sanitize_array_element_callback' ), $value );
				// Sanitize everything else
			} else {
				$value = sanitize_text_field( $value );
			}
			add_post_meta( $post_id, $meta_field, $value, true );
		}
	}

	/**
	 * Handle post, post+media, or just media files
	 *
	 * @since 0.4
	 */
	function upload_content() {
		$fields = $result = array();

		// Bail if something fishy is going on
		if ( !wp_verify_nonce( $_POST['fu_nonce'], FU_NONCE ) ) {
			wp_safe_redirect( add_query_arg( array( 'response' => 'fcp-error', 'errors' => 'fcp-nonce-failure' ), wp_get_referer() ) );
			exit;
		}

		$form_post_id = isset( $_POST['form_post_id'] ) ? (int) $_POST['form_post_id'] : 0;
		$hash = sanitize_text_field( $_POST['ff'] );
		$this->form_fields = !empty( $this->form_fields ) ? $this->form_fields : $this->_get_fields_for_form( $form_post_id, $hash );

		$layout = isset( $_POST['form_layout'] ) && !empty( $_POST['form_layout'] ) ? $_POST['form_layout'] : 'image';

		/**
		 * Utility hook 'fu_should_process_content_upload': maybe terminate upload early (useful for Akismet integration, etc)
		 * Defaults to true, upload will be terminated if set to false.
		 *
		 * Parameters:
		 * boolean - whether should process
		 * string $layout - which form layout is used
		 */
		if ( false === apply_filters( 'fu_should_process_content_upload', true, $layout ) ) {
			wp_safe_redirect( add_query_arg( array( 'response' => 'fcp-spam' ), wp_get_referer() ) );
			exit;
		}

		switch ( $layout ) {
			// Upload the post
		case 'post':
			$result = $this->_upload_post();
			break;
			// Upload the post first, and then upload media and attach to the post
		case 'post_image':
		case 'post_media';
			$result = $this->_upload_post();
			if ( ! is_wp_error( $result['post_id'] ) ) {
				$media_result = $this->_upload_files( $result['post_id'] );

				// Make sure we don't merge a non-array (_upload_files might return null, false or WP_Error)
				if ( $media_result && is_array( $media_result ) )
					$result = array_merge( $result, $media_result );
			}
			break;
			// Upload media
		case 'image':
		case 'media':
			$pid = isset( $_POST['post_ID'] ) ? (int) $_POST['post_ID'] : 0;
			$result = $this->_upload_files( $pid );
			break;
		}

		/**
		 * Process result with filter
		 *
		 * @param string $layout form layout
		 * @param array  $result assoc array holding $post_id, $media_ids, bool $success, array $errors
		 */
		do_action( 'fu_upload_result', $layout, $result );

		// Notify the admin via email
		$this->_notify_admin( $result );

		// Handle error and success messages, and redirect
		$this->_handle_result( $result );
		exit;
	}

	/**
	 * Notify site administrator by email
	 */
	function _notify_admin( $result = array() ) {
		// Email notifications are disabled, or upload has failed, bailing
		if ( ! ( 'on' == $this->settings['notify_admin'] && $result['success'] ) )
			return;

		// TODO: It'd be nice to add the list of upload files
		$to = !empty( $this->settings['notification_email'] ) && filter_var( $this->settings['notification_email'], FILTER_VALIDATE_EMAIL ) ? $this->settings['notification_email'] : get_option( 'admin_email' );
		$subj = __( 'New content was uploaded on your site', 'frontend-custom-post' );
		wp_mail( $to, $subj, $this->settings['admin_notification_text'] );
	}

	/**
	 * Process response from upload logic
	 *
	 * @since 0.4
	 */
	function _handle_result( $result = array() ) {
		// Redirect to referrer if repsonse is malformed
		if ( empty( $result ) || !is_array( $result ) ) {
			wp_safe_redirect( wp_get_referer() );
			return;
		}

		// Either redirect to success page if it's set and valid
		// Or to referrer
		$url = isset( $_POST['success_page'] ) && filter_var( $_POST['success_page'], FILTER_VALIDATE_URL ) ? $_POST['success_page'] : wp_get_referer();

		// $query_args will hold everything that's needed for displaying notices to user
		$query_args = array();

		// Account for successful uploads
		if ( isset( $result['success'] ) && $result['success'] ) {
			// If it's a post
			if ( isset( $result['post_id'] ) )
				$query_args['response'] = 'fcp-post-sent';
			// If it's media uploads
			if ( isset( $result['media_ids'] ) && !isset( $result['post_id'] ) )
				$query_args['response'] = 'fcp-sent';
		}

		// Something went wrong, let's indicate it
		if ( !empty( $result['errors'] ) ) {
			$query_args['response'] = 'fcp-error';

			$query_args['errors'] = $result['errors'];
		}

		/**
		 * Allow to filter query args before doing the redirect after upload
		 */
		$query_args = apply_filters( 'fcp_upload_result_query_args', $query_args, $result );

		// Perform a safe redirect and exit
		wp_safe_redirect( add_query_arg( $query_args, $url ) );
		exit;
	}

	/**
	 * Render various admin template files
	 *
	 * @param string $view file slug
	 * @since 0.4
	 */
	function render( $view = '' ) {
		if ( empty( $view ) )
			return;

		$this->_set_global_query_for_tables( $view );

		require_once ABSPATH . '/wp-admin/includes/class-wp-list-table.php';
		require_once ABSPATH . '/wp-admin/includes/class-wp-posts-list-table.php';
		require_once ABSPATH . '/wp-admin/includes/class-wp-media-list-table.php';
		require_once FU_ROOT . '/lib/php/class-frontend-custom-post-wp-media-list-table.php';
		require_once FU_ROOT . '/lib/php/class-frontend-custom-post-wp-posts-list-table.php';

		$file = FU_ROOT . "/lib/views/manage-ugc-{$view}.tpl.php";

		if ( 0 === validate_file( $file ) ) {
			include_once $file;
		}
	}

	/**
	 * We need to set global $wp_query in order for list tables to work properly
	 * @param string $type (media|posts)
	 */
	private function _set_global_query_for_tables( $type = 'posts' ) {
		if ( ! in_array( $type, array( 'posts', 'media' ) ) )
			return false;

		$args = array(
			'post_status' => array( 'private' ),
			'posts_per_page' => 20,
			'paged' => isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1,
		);

		// Tweak query arguments (set proper post type and post status)
		switch( $type ) {
			case 'posts':
				$args['post_type'] = isset( $_GET['post_type'] ) && $this->is_allowed_post_type( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : 'post';
			break;
			case 'media':
				$args['post_type'] = 'attachment';
			break;
		}

		query_posts( $args );
	}

	/**
	 * Is post type allowed UGC post type?
	 * @param  string  $post_type to check
	 * @return boolean
	 */
	function is_allowed_post_type( $post_type = 'post' ) {
		return in_array( $post_type, $this->settings['enabled_post_types'], true );
	}

	/**
	 * Display media list table
	 *
	 * @return [type] [description]
	 */
	function admin_list() {
		$this->render( 'media' );
	}

	/**
	 * Display posts/custom post types table
	 *
	 * @return [type] [description]
	 */
	function admin_posts_list() {
		$this->render( 'posts' );
	}

	/**
	 * Add submenu items
	 */
	function add_menu_items() {
		add_media_page( __( 'Manage Library', 'frontend-custom-post' ), __( 'Manage Library', 'frontend-custom-post' ), $this->manage_permissions, 'manage_frontend_custom_post', array( $this, 'admin_list' ) );
		foreach ( (array) $this->settings['enabled_post_types'] as $cpt ) {
			if ( $cpt == 'post' ) {
				add_posts_page( __( 'Manage Submitted Books', 'frontend-custom-post' ), __( 'Manage Library', 'frontend-custom-post' ), $this->manage_permissions, 'manage_frontend_custom_post_posts', array( $this, 'admin_posts_list' ) );
				continue;
			}

			add_submenu_page( "edit.php?post_type={$cpt}", __( 'Manage Submitted Books', 'frontend-custom-post' ), __( 'Manage Library', 'frontend-custom-post' ), $this->manage_permissions, "manage_frontend_custom_post_{$cpt}s", array( $this, 'admin_posts_list' ) );
		}
	}

	/**
	 * Approve a media file
	 *
	 * TODO: refactor in 0.6
	 *
	 * @return [type] [description]
	 */
	function approve_media() {
		// Check permissions, attachment ID, and nonce
		if ( false === $this->_check_perms_and_nonce() || 0 === (int) $_GET['id'] ) {
			wp_safe_redirect( get_admin_url( null, 'upload.php?page=manage_frontend_custom_post&error=id_or_perm' ) );
		}

		$post = get_post( $_GET['id'] );

		if ( is_object( $post ) && $post->post_status == 'private' ) {
			$post->post_status = 'inherit';
			wp_update_post( $post );

			do_action( 'fu_media_approved', $post );

			$this->update_35_gallery_shortcode( $post->post_parent, $post->ID );
			wp_safe_redirect( get_admin_url( null, 'upload.php?page=manage_frontend_custom_post&approved=1' ) );
		}

		wp_safe_redirect( get_admin_url( null, 'upload.php?page=manage_frontend_custom_post' ) );
		exit;
	}

	/**
	 * TODO: refactor in 0.6
	 *
	 * @return [type] [description]
	 */
	function approve_post() {
		// check for permissions and id
		$url = get_admin_url( null, 'edit.php?page=manage_frontend_custom_post__posts&error=id_or_perm' );
		if ( !current_user_can( $this->manage_permissions ) || intval( $_GET['id'] ) === 0 )
			wp_safe_redirect( $url );

		$post = get_post( $_GET['id'] );

		if ( !is_wp_error( $post ) ) {
			$post->post_status = 'publish';
			wp_update_post( $post );

			do_action( 'fu_post_approved', $post );

			// Check if there's any UGC attachments
			$attachments = get_children( 'post_type=attachment&post_parent=' . $post->ID );
			foreach ( (array) $attachments as $image_id => $attachment ) {
				$attachment->post_status = "inherit";
				wp_update_post( $attachment );
			}

			// Override query args
			$qa = array(
				'page' => "manage_frontend_uploader_{$post->post_type}s",
				'approved' => 1,
				'post_type' => $post->post_type != 'post' ? $post->post_type : '',
			);

			$url = add_query_arg( $qa, get_admin_url( null, "edit.php" ) );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Delete post and redirect to referrer
	 *
	 * @return [type] [description]
	 */
	function delete_post() {
		if ( $this->_check_perms_and_nonce() && 0 !== (int) $_GET['id'] ) {
			if ( wp_delete_post( (int) $_GET['id'], true ) )
				$args['deleted'] = 1;
		}

		wp_safe_redirect( add_query_arg( $args, wp_get_referer() ) );
		exit;
	}

	/**
	 * Handles security checks
	 *
	 * @return bool
	 */
	function _check_perms_and_nonce() {
		return current_user_can( $this->manage_permissions ) && wp_verify_nonce( $_REQUEST['fu_nonce'], FU_NONCE );
	}

	/**
	 * Shortcode callback for inner content of [fcp-upload-form] shortcode
	 *
	 * @param array  $atts shortcode attributes
	 * @param unknown $content not used
	 * @param string $tag
	 */
	function shortcode_content_parser( $atts, $content = null, $tag ) {
		$atts = shortcode_atts( array(
				'id' =>  isset( $atts['name'] ) ? 'ugc-input-' . sanitize_key( $atts['name'] ) : '' ,
				'name' => '',
				'description' => '',
				'help' => '',
				'value' => '',
				'type' => '',
				'class' => '',
				'multiple' => false,
				'required' => false,
				'aria-required' => false,
				'values' => '',
				'wysiwyg_enabled' => false,
				'role' => 'meta',
				'minlength' => '',
				'maxlength' => '',
			), $atts );

		extract( $atts );

		$role = in_array( $role, array( 'meta', 'title', 'description', 'author', 'internal', 'content' ) ) ? $role : 'meta';
		$name = sanitize_text_field( $name );
		// Add the field to fields map
		$this->form_fields[$role][] = $name;

		// Render the element if render callback is available
		$callback = array( $this, "_render_{$tag}" );
		if ( is_callable( $callback ) )
			return call_user_func( $callback, $atts );
	}

	/**
	 * Input element callback
	 *
	 * @param array  shortcode attributes
	 * @return string formatted html element
	 */
	function _render_input( $atts ) {
		extract( $atts );

		// Workaround for HTML5 multiple attribute
		if ( (bool) $multiple === false )
			unset( $atts['multiple'] );

		// Allow multiple file upload by default.
		// To do so, we need to add array notation to name field: []
		if ( !strpos( $name, '[]' ) && $type == 'file' )
			$name = 'files' . '[]';

		$input = $this->html->input( $type, $name, $value, $atts );

		// No need for wrappers or labels for hidden input
		if ( $type == 'hidden' )
			return $input;

		$label = $this->html->element( 'label', $description , array( 'for' => $id ), false );

		$help = isset( $help ) && $help ? $this->html->element( 'p', sanitize_text_field( $help ), array( 'class' => 'ugc-help' ) ) : '';

		return $this->html->element( 'div', $label . $input . $help, array( 'class' => 'ugc-input-wrapper' ), false );
	}

	/**
	 * Textarea element callback
	 *
	 * @param array  shortcode attributes
	 * @return string formatted html elemen
	 */
	function _render_textarea( $atts ) {
		extract( $atts );

		$help = isset( $help ) && $help ? $this->html->element( 'p', sanitize_text_field( $help ), array( 'class' => 'ugc-help' ) ) : '';

		$label = $this->html->element( 'label', $description , array( 'for' => $id ), false );

		// Render WYSIWYG textarea
		if ( ( isset( $this->settings['wysiwyg_enabled'] ) && 'on' == $this->settings['wysiwyg_enabled'] ) || $wysiwyg_enabled == true ) {
			ob_start();
			wp_editor( '', $id, array(
					'textarea_name' => $name,
					'media_buttons' => false,
					'teeny' => true,
					'quicktags' => false
				) );
			$tiny = ob_get_clean();

			return $this->html->element( 'div', $label  . $tiny . $help, array( 'class' => 'ugc-input-wrapper' ), false ) ;
		}
		$element = $this->html->element( 'textarea', '', array( 'name' => $name, 'id' => $id, 'class' => $class, 'minlength' => $minlength, 'maxlength' => $maxlength, 'required' => $required ) );
		// Render plain textarea
		$label = $this->html->element( 'label', $description, array( 'for' => $id ), false );

		return $this->html->element( 'div', $label  . $element . $help, array( 'class' => 'ugc-input-wrapper' ), false );
	}

	/**
	 * Checkboxes element callback
	 *
	 * @param array  shortcode attributes
	 * @return [type]  [description]
	 */
	function _render_checkboxes( $atts ) {
		extract( $atts );

		$values = explode( ',', $values );
		$options = '';

		$help = isset( $help ) && $help ? $this->html->element( 'p', sanitize_text_field( $help ), array( 'class' => 'ugc-help' ) ) : '';

		// Making sure we're having array of values for checkboxes
		if ( false === stristr( '[]', $name ) )
			$name = $name . '[]';

		//Build options for the list
		foreach ( $values as $option ) {
			$kv = explode( ":", $option );
			$options .= $this->html->_checkbox( $name, isset( $kv[1] ) ? $kv[1] : $kv[0], $kv[0], $atts, array() );
		}

		$description = $label = $this->html->element( 'label', $description, array(), false );

		// Render select field
		$element = $this->html->element( 'div', $description  . $help . $options, array( 'class' => 'checkbox-wrapper ' . $class ), false );

		return $this->html->element( 'div', $element, array( 'class' => 'ugc-input-wrapper' ), false );
	}

	/**
	 * Radio buttons callback
	 *
	 * @param array  shortcode attributes
	 * @return [type]  [description]
	 */
	function _render_radio( $atts ) {
		extract( $atts );
		$values = explode( ',', $values );
		$options = '';

		$help = isset( $help ) && $help ? $this->html->element( 'p', sanitize_text_field( $help ), array( 'class' => 'ugc-help' ) ) : '';

		//Build options for the list
		foreach ( $values as $option ) {
			$kv = explode( ":", $option );
			$caption = isset( $kv[1] ) ? $kv[1] : $kv[0];
			$options .= $this->html->_radio( $name, isset( $kv[1] ) ? $kv[1] : $kv[0], $kv[0], $atts, array() );
		}

		//Render
		$label = $this->html->element( 'label', $description , array( 'for' => $id ), false );

		return $this->html->element( 'div', $label . $help . $options, array( 'class' => 'ugc-input-wrapper ' . $class ), false );
	}

	/**
	 * Select element callback
	 *
	 * @param array  shortcode attributes
	 * @return [type]  [description]
	 */
	function _render_select( $atts ) {
		extract( $atts );
		$values = explode( ',', $values );
		$options = '';
		$help = isset( $help ) && $help ? $this->html->element( 'p', sanitize_text_field( $help ), array( 'class' => 'ugc-help' ) ) : '';

		//Build options for the list
		foreach ( $values as $option ) {
			$kv = explode( ":", $option );
			$caption = isset( $kv[1] ) ? $kv[1] : $kv[0];

			$options .= $this->html->element( 'option', $caption, array( 'value' => $kv[0] ), false );
		}

		//Render select field
		$label = $this->html->element( 'label', $description , array( 'for' => $id ), false );

		$element =$this->html->element( 'select', $options, array(
			'name' => $name,
			'id' => $id,
			'class' => $class
		), false );

		return $this->html->element( 'div', $label . $help . $element, array( 'class' => 'ugc-input-wrapper' ), false );
	}

	/**
	 * Display the upload post form
	 *
	 * @param array  $atts shortcode attributes
	 * @param string $content content that is enclosed in [fcp-upload-form][/fcp-upload-form]
	 */

    
	function upload_form( $atts, $content = null ) {
		add_shortcode( 'input', array( $this, 'shortcode_content_parser' ) );
		add_shortcode( 'textarea', array( $this, 'shortcode_content_parser' ) );
		add_shortcode( 'select', array( $this, 'shortcode_content_parser' ) );
		add_shortcode( 'checkboxes', array( $this, 'shortcode_content_parser' ) );
		add_shortcode( 'radio', array( $this, 'shortcode_content_parser' ) );
		add_shortcode( 'recaptcha', 'fu_get_recaptcha_markup' );

		// Reset postdata in case it got polluted somewhere
		wp_reset_postdata();
		$form_post_id = get_the_id();

		extract( shortcode_atts( array(
					'description' => '',
					'title' => __( 'Submit a new post', 'frontend-custom-post' ),
					'type' => '',
					'class' => 'validate',
					'success_page' => '',
					'form_layout' => 'image',
					'post_id' => get_the_ID(),
					'post_type' => 'post',
					'category' => '',
					'suppress_default_fields' => false,
					'append_to_post' => false,
				), $atts ) );

		$post_id = (int) $post_id;

		$this->enqueue_scripts();

		$form_layout = in_array( $form_layout, array( 'post', 'image', 'media', 'post_image', 'post_media' ) ) ? $form_layout : 'media';

		ob_start();
?>
	<form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" enctype="multipart/form-data" method="post" id="ugc-media-form" class="<?php echo esc_attr( $class )?> fcp-upload-form">
<input type="hidden" name="action" value="new_book_form" >

<p><label for="cptTitle"><?php _e('Name of book:', 'mytextdomain') ?></label>

<input class='form-control' type="text" name="name" id="cptTitle" maxlength="21" /></p>
<p><label for="cptTitle"><?php _e('Free / Paid:', 'mytextdomain') ?></label>
<select name='free_paid' class='form-control'>
<option class='form-control' value='free'>Free</option>
<option class='form-control' value='paid'>Paid</option>
</select>
<p><label for="cptTitle"><?php _e('Category:', 'mytextdomain') ?></label><br>
<select name='subject_id' class='form-control'>
<?php
foreach($subjects as $subject)
{?>

<option class='form-control' value='<?php echo $subject->id ?>'><?php echo $subject->subject; ?></option>
<?php

}
?>

</select>

<p><label for="cptTitle"><?php _e('Direct purchase link:', 'mytextdomain') ?></label>

<input class='form-control' type="text" name="bookPurchaseLink" id="bookPurchaseLink" /></p>


<p><label for="cptTitle"><?php _e('Cover Page:', 'mytextdomain') ?></label><br>

<input type="file" name="coverPage" id="coverPage" accept="image/*" /></p>


<p> <label for="cptContent"><?php _e('Short Description:', 'mytextdomain') ?></label>

<textarea class='form-control' name="description" style='resize: none;' id="cptContent" rows="4" cols="20"></textarea> </p>
<input type='hidden' name='email' value='<?php echo $email; ?>'/>
<button type="submit" class='btn btn-success' name="save"><?php _e('Submit', 'mytextdomain') ?></button>


<input type="hidden" name="post_type" id="post_type" value="my_custom_post_type" />


<?php wp_nonce_field( 'cpt_nonce_action', 'cpt_nonce_field' ); ?>


</form>
	 <div class="ugc-inner-wrapper">
		 <h2><?php echo esc_html( $title ) ?></h2>
<?php
		if ( !empty( $_GET ) )
			$this->_display_response_notices( $_GET );

		$textarea_desc = __( 'Description', 'frontend-custom-post' );
		$file_desc = __( 'Your Media Files', 'frontend-custom-post' );
		$submit_button = __( 'Submit', 'frontend-custom-post' );

		// Set post type for layouts that include uploading of posts
		// Put it in front of the main form to allow to override it
		if ( in_array( $form_layout, array( "post_media", "post_image", "post" ) ) ) {
			echo $this->shortcode_content_parser( array(
					'type' => 'hidden',
					'role' => 'internal',
					'name' => 'post_type',
					'value' => $post_type
				), null, 'input' );
		}

		echo $this->shortcode_content_parser( array(
				'type' => 'hidden',
				'role' => 'internal',
				'name' => 'post_ID',
				'value' => $post_id
			), null, 'input' );

		if ( isset( $category ) && 0 !== (int) $category ) {
			echo $this->shortcode_content_parser( array(
					'type' => 'hidden',
					'role' => 'internal',
					'name' => 'post_category',
					'value' => $category
				), null, 'input' );
		}

		if ( !( isset( $this->settings['suppress_default_fields'] ) && 'on' == $this->settings['suppress_default_fields'] ) && ( $suppress_default_fields === false ) ) {

			// Display title field
			echo $this->shortcode_content_parser( array(
					'type' => 'text',
					'role' => 'title',
					'name' => 'post_title',
					'id' => 'ug_post_title',
					'class' => 'required',
					'description' => __( 'Title', 'frontend-custom-post' ),
				), null, 'input' );


			/**
			 * Render default fields
			 * Looks gross but somewhat faster than using do_shortcode
			 */
			switch ( $form_layout ) {
			case 'post_image':
			case 'post_media':
			case 'image':
			case 'media':

				// post_content
				echo $this->shortcode_content_parser( array(
						'role' => 'content',
						'name' => 'post_content',
						'id' => 'ug_content',
						'class' => 'required',
						'description' => __( 'Post content or file description', 'frontend-custom-post' ),
					), null, 'textarea' );

				break;

			case 'post':
				// post_content
				echo $this->shortcode_content_parser( array(
						'role' => 'content',
						'name' => 'post_content',
						'id' => 'ug_content',
						'class' => 'required',
						'description' => __( 'Post content', 'frontend-custom-post' ),
					), null, 'textarea' );
				break;
			}
		}

		// Parse nested shortcodes
		if ( $content )
			echo do_shortcode( $content );

		if ( !( isset( $this->settings['suppress_default_fields'] ) && 'on' == $this->settings['suppress_default_fields'] ) && ( $suppress_default_fields === false ) ) {

			if ( in_array( $form_layout, array( 'image', 'media', 'post_image', 'post_media' ) ) ) {
				// Default upload field
				echo $this->shortcode_content_parser( array(
						'type' => 'file',
						'role' => 'file',
						'name' => 'files',
						'id' => 'ug_photo',
						'multiple' => 'multiple',
						'description' => $file_desc,
						'help' => ''
					), null, 'input' );
			}

			if ( $this->settings['enable_recaptcha_protection' ] == 'on' )
				echo fu_get_recaptcha_markup();

			do_action( 'fu_additional_html', $this );

			echo $this->shortcode_content_parser( array(
					'type' => 'submit',
					'role' => 'internal',
					'id' => 'ug_submit_button',
					'class' => 'btn',
					'value' => $submit_button,
				), null, 'input' );
		} else {
			do_action( 'fu_additional_html', $this );
		}

		// wp_ajax_ hook
		echo $this->shortcode_content_parser( array(
				'type' => 'hidden',
				'role' => 'internal',
				'name' => 'action',
				'value' => 'upload_ugc'
			), null, 'input' );

		// Redirect to specified url if valid
		if ( !empty( $success_page ) && filter_var( $success_page, FILTER_VALIDATE_URL ) ) {
			echo $this->shortcode_content_parser( array(
					'type' => 'hidden',
					'role' => 'internal',
					'name' => 'success_page',
					'value' => $success_page
				), null, 'input' );
		}

		// One of supported form layouts
		echo $this->shortcode_content_parser( array(
				'type' => 'hidden',
				'role' => 'internal',
				'name' => 'form_layout',
				'value' => $form_layout
			), null, 'input' );

		if ( in_array( $form_layout, array( 'post_media', 'post_image' ) ) ) {
					// One of supported form layouts
			echo $this->shortcode_content_parser( array(
					'type' => 'hidden',
					'role' => 'internal',
					'name' => 'append_to_post',
					'value' => (bool) $append_to_post
				), null, 'input' );
		}

?>
		<?php wp_nonce_field( FU_NONCE, 'fu_nonce' ); ?>
		<input type="hidden" name="ff" value="<?php echo esc_attr( $this->_get_fields_hash() ) ?>" />
		<input type="hidden" name="form_post_id" value="<?php echo (int) $form_post_id ?>" />
		<div class="clear"></div>
	 </div>
	 </form>
<?php
		$this->maybe_update_fields_map( $form_post_id );
		return ob_get_clean();
	}

	/**
	 * Save field map
	 *
	 * @param integer $form_post_id [description]
	 * @return [type] [description]
	 */
	private function maybe_update_fields_map( $form_post_id = 0 ) {
		$form_post_id = (int) $form_post_id ? (int) $form_post_id : get_the_id();
		$key = 'fu_form:' . $this->_get_fields_hash();

		// See if we already have field map saved as meta
		$fields = get_post_meta( $form_post_id, $key, true );

		// If not, update it
		if ( ! $fields ) {
			update_post_meta( $form_post_id, $key, $this->form_fields );
		}
	}

	/**
	 * Get a key for a form (supposed to be unique to not conflict with multiple forms)
	 *
	 * @return string hash
	 */
	function _get_fields_hash() {
		$hash = md5( serialize( $this->form_fields ) );
		return $hash;
	}

	function _get_fields_for_form( $post_id, $hash ) {
		$fields = get_post_meta( $post_id, "fu_form:{$hash}", true );
		if ( $fields )
			return $fields;

		return false;
	}

	/**
	 * [fcp-upload-response] shortcode callback to render upload results notice
	 *
	 * @param [type] $atts [description]
	 * @return [type]  [description]
	 */
	function upload_response_shortcode( $atts ) {
		$this->enqueue_scripts();
		ob_start();
		$this->_display_response_notices( $_GET );
		return ob_get_clean();
	}

	/**
	 * Returns html chunk of single notice
	 *
	 * @since 0.4
	 *
	 * @param string $message Text of the message
	 * @param string $class  Class of container
	 * @return string [description]
	 */
	function _notice_html( $message, $class ) {
		if ( empty( $message ) || empty( $class ) )
			return;

		return sprintf( '<p class="ugc-notice %1$s">%2$s</p>', esc_attr( $class ), esc_html( $message ) );
	}

	/**
	 * Handle response notices
	 *
	 * @since 0.4
	 *
	 * @param array  $res [description]
	 * @return [type] [description]
	 */
	function _display_response_notices( $res = array() ) {
		if ( empty( $res ) || !is_array( $res ) )
			return;

		array_walk_recursive( $res, 'sanitize_text_field' );

		$output = '';
		$map = array(
			'fcp-sent' => array(
				'text' => __( 'Your file was successfully uploaded!', 'frontend-custom-post' ),
				'class' => 'success',
			),
			'fcp-post-sent' => array(
				'text' => __( 'Your post was successfully submitted!', 'frontend-custom-post' ),
				'class' => 'success',
			),
			'fcp-error' => array(
				'text' => __( 'There was an error with your submission', 'frontend-custom-post' ),
				'class' => 'failure',
			),
			'fcp-spam' => array(
				'text' => __( "Your submission failed spam checks", 'frontend-custom-post' ),
				'class' => 'failure',
			),
		);

		if ( isset( $res['response'] ) && isset( $map[ $res['response'] ] ) )
			$output .= $this->_notice_html( $map[ $res['response'] ]['text'] , $map[ $res['response'] ]['class'] );

		if ( !empty( $res['errors' ] ) )
			$output .= $this->_display_errors( $res['errors' ] );

		echo $output;
	}
	/**
	 * Handle errors
	 *
	 * @since 0.4
	 * @param string $errors [description]
	 * @return string HTML
	 */
	function _display_errors( $errors ) {
		$output = '';
		$map = array(
			'fcp-nonce-failure' => array(
				'text' => __( 'Security check failed!', 'frontend-custom-post' ),
			),
			'fcp-disallowed-mime-type' => array(
				'text' => __( 'This kind of file is not allowed. Please, try again selecting other file.', 'frontend-custom-post' ),
				'format' => $this->is_debug ? '%1$s: <br/> File name: %2$s <br> MIME-TYPE: %3$s' : '%1$s: <br> %2$s',
			),
			'fcp-invalid-post' => array(
				'text' =>__( 'The content you are trying to post is invalid.', 'frontend-custom-post' ),
			),
			'fcp-error-media' => array(
				'text' =>__( "Couldn't upload the file", 'frontend-custom-post' ),
			),
			'fcp-error-post' => array(
				'text' =>__( "Couldn't create the post", 'frontend-custom-post' ),
			),
			'fcp-suspicious-file' => array(
				'text' =>__( "The file you tried to upload looks suspicious. This incedent will be reported.", 'frontend-custom-post' ),
			),
		);

		// Iterate over all the errors that occured for this submission
		// $error is the key of error, $details - additional information about the error
		foreach ( (array) $errors as $error => $details ) {

			// We might have multiple errors of the same type, let's walk through them
			foreach ( (array) $details as $single_error ) {
				if ( isset( $map[ $error ]['format'] ) ) {
					// Prepend the array with error message
					array_unshift( $single_error, $map[ $error ]['text'] );
					$message = vsprintf( $map[ $error ]['format'], $single_error );
				} else {
					$message = $map[ $error ]['text'];
				}

				// Append the error to html to display
				$output .= $this->_notice_html( $message, 'failure' );
			}
		}
		return $output;
	}

	/**
	 * Enqueue our assets
	 */
	function enqueue_scripts() {
		wp_enqueue_style( 'frontend-custom-post', FU_URL . 'lib/css/frontend-custom-post.css' );
		wp_enqueue_script( 'jquery-validate', FU_URL . 'lib/js/validate/jquery.validate.min.js', array( 'jquery', 'underscore' ) );
		wp_enqueue_script( 'jquery-validate-additional', FU_URL . 'lib/js/validate/additional-methods.min.js', array( 'jquery', 'underscore' ) );
		wp_enqueue_script( 'fcp-underscore-string', FU_URL . 'lib/js/underscore.string.min.js', array( 'jquery', 'underscore' ) );
		wp_enqueue_script( 'frontend-custom-post-js', FU_URL . 'lib/js/frontend-custom-post.js', array( 'jquery', 'jquery-validate' ) );
		// Include localization strings for default messages of validation plugin
		// Filter is needed for wordpress.com
		$wplang = apply_filters( 'fu_wplang', defined( 'WPLANG' ) ? WPLANG : '' );
		if ( $wplang ) {
			$lang = explode( '_', $wplang );
			$relative_path = "lib/js/validate/localization/messages_{$lang[0]}.js";
			$url = FU_URL . $relative_path;
			if ( file_exists( FU_ROOT . "/{$relative_path}" ) )
				wp_enqueue_script( 'jquery-validate-messages', $url, array( 'jquery' ) );
		}
	}

	/**
	 * Enqueue scripts for admin
	 */
	function admin_enqueue_scripts() {
		$screen = get_current_screen();
		/**
		 * Don't try to include media script anywhere except "Manage UGC" screen
		 * Otherwise it produces JS errors, potentially breaking some post edit screen features
		 */
		if ( $screen && 'media_page_manage_frontend_uploader' == $screen->base )
			wp_enqueue_script( 'media', array( 'jquery' ) );
	}

	/**
	 * 3.5 brings new Media UI
	 * Unfortunately, we have to specify ids of approved attachments explicitly,
	 * Otherwise, editors have to pick photos after they have already approved them in "Manage UGC"
	 *
	 * This method will search a parent post with a regular expression, and update gallery shortcode with freshly approved attachment ID
	 *
	 * @return post id/wp_error
	 */
	function update_35_gallery_shortcode( $post_id, $attachment_id ) {
		global $wp_version;
		// Bail of wp is older than 3.5
		if ( version_compare( $wp_version, '3.5', '<' ) )
			return;

		$parent = get_post( $post_id );

		/**
		 * Parse the post content:
		 * Before the shortcode,
		 * Before ids,
		 * Ids,
		 * After ids
		 */
		preg_match( '#(?<way_before>.*)(?<before>\[gallery(.*))ids=(\'|")(?<ids>[0-9,]*)(\'|")(?<after>.*)#ims', $parent->post_content, $matches ) ;

		// No gallery shortcode, no problem
		if ( !isset( $matches['ids'] ) )
			return;

		$content = '';
		$if_prepend = apply_filters( 'fu_update_gallery_shortcode_prepend', false );
		// Replace ids element with actual string of ids, adding the new att id
		$matches['ids'] = $if_prepend ? "ids=\"{$attachment_id},{$matches['ids']}\"" : "ids=\"{$matches['ids']},{$attachment_id}\"";
		$deconstructed = array( 'way_before', 'before', 'ids', 'after' );
		// Iterate through match elements and reconstruct the post
		foreach ( $deconstructed as $match_key ) {
			if ( isset( $matches[ $match_key ] ) ) {
				$content .= $matches[ $match_key ];
			}
		}

		// Update the post
		$post_to_update = array(
			'ID' => (int) $post_id,
			'post_content' => $content,
		);
		return wp_update_post( $post_to_update );
	}

	function _sanitize_array_element_callback( $el ) {
		return sanitize_text_field( $el );
	}

	/**
	 * Include Akismet spam protection if enabled in plugin settings
	 */
	function _enable_akismet_protection() {
		// Maybe include Akismet
		if ( isset( $this->settings['enable_akismet_protection'] ) && 'on' == $this->settings['enable_akismet_protection'] ) {
			require_once FU_ROOT . '/lib/php/akismet.php';
		}
	}

	/**
	 * Include recaptcha if configured properly
	 * @return [type] [description]
	 */
	function _enable_recaptcha_protection() {
		$to_check = array( 'recaptcha_site_key', 'recaptcha_secret_key', 'enable_recaptcha_protection' );
		foreach( $to_check as $check ) {
			if ( !isset( $this->settings[ $check ] ) || ! $this->settings[ $check ] || 'off' === $this->settings[ $check ] )
				return false;
		}

		require_once FU_ROOT . '/lib/php/recaptcha.php';
	}
}

$GLOBALS['frontend_custom_post'] = new Frontend_Custom_Post;