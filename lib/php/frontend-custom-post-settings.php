<?php
/**
 * Frontend Custom Post Settings
 */
class Frontend_Custom_Post_Settings {

	private $settings_api, $public_post_types = array();

	function __construct() {
		$this->settings_api = new WeDevs_Settings_API;

		add_action( 'current_screen', array( $this, 'action_current_screen' ) );
		add_action( 'admin_menu', array( $this, 'action_admin_menu' ) );
	}

	/**
	 * Only run if current screen is plugin settings or options.php
	 * @return [type] [description]
	 */
	function action_current_screen() {
		$screen = get_current_screen();
		if ( in_array( $screen->base, array( 'settings_page_fu_settings', 'options' ) ) ) {
			$this->settings_api->set_sections( $this->get_settings_sections() );
			$this->settings_api->set_fields( $this->get_settings_fields() );
			// Initialize settings
			$this->settings_api->admin_init();
		}
	}

	/**
	 * Get post types for checkbox option
	 * @return array of slug => label for registered post types
	 */
	static function get_post_types() {
		$fu_public_post_types = get_post_types( array( 'public' => true ), 'objects' );
		foreach( $fu_public_post_types as $slug => $post_object ) {
			if ( $slug == 'attachment' ) {
				unset( $fu_public_post_types[$slug] );
				continue;
			}
			$fu_public_post_types[$slug] = $post_object->labels->name;
		}
		return $fu_public_post_types;
	}

	function action_admin_menu() {
		add_options_page( __( 'Frontend Custom Post Settings', 'frontend-custom-post' ) , __( 'Frontend Custom Post Settings', 'frontend-custom-post' ), 'manage_options', 'fu_settings', array( $this, 'plugin_page' ) );
	}

	function get_settings_sections() {
		$sections = array(
			array(
				'id' => 'frontend_uploader_settings',
				'title' => __( 'Basic Settings', 'frontend-custom-post' ),
			),
		);
		return $sections;
	}

	/**
	 * Returns all the settings fields
	 *
	 * @return array settings fields
	 */
	static function get_settings_fields() {
		$default_post_type = array( 'post' => 'Posts' );
		$settings_fields = array(
			'frontend_uploader_settings' => array(
				array(
					'name' => 'enable_akismet_protection',
					'label' => __( 'Enable Akismet spam protection', 'frontend-custom-post' ),
					'desc' => __( 'Yes (Akismet must be enabled and configured)', 'frontend-custom-post' ),
					'type' => 'checkbox',
					'default' => '',
				),
				array(
					'name' => 'enable_recaptcha_protection',
					'label' => __( 'Enable Recaptcha spam protection', 'frontend-custom-post' ),
					'desc' => __( 'Yes (Fill in Recaptcha credentials below)', 'frontend-custom-post' ),
					'type' => 'checkbox',
					'default' => '',
				),
				array(
					'name' => 'recaptcha_site_key',
					'label' => __( 'Recaptcha Site Key', 'frontend-custom-post' ) . " get at https://www.google.com/recaptcha/admin",
					'desc' => __( '', 'frontend-uploader' ),
					'type' => 'text',
					'default' => '',
				),
				array(
					'name' => 'recaptcha_secret_key',
					'label' => __( 'Recaptcha Site Secret', 'frontend-custom-post' ) . " get at https://www.google.com/recaptcha/admin",
					'desc' => __( '', 'frontend-custom-post' ),
					'type' => 'text',
					'default' => '',
				),
				array(
					'name' => 'notify_admin',
					'label' => __( 'Notify site admins', 'frontend-custom-post' ),
					'desc' => __( 'Yes', 'frontend-custom-post' ),
					'type' => 'checkbox',
					'default' => '',
				),
				array(
					'name' => 'admin_notification_text',
					'label' => __( 'Admin Notification', 'frontend-custom-post' ),
					'desc' => __( 'Message that admin will get on new file upload', 'frontend-custom-post' ),
					'type' => 'textarea',
					'default' => 'Someone uploaded a new Book and cover page, please moderate at: ' . admin_url( 'upload.php?page=manage_frontend_custom_post' ),
					'sanitize_callback' => 'wp_kses_post'
				),
				array(
					'name' => 'notification_email',
					'label' => __( 'Notification email', 'frontend-custom-post' ),
					'desc' => __( 'Leave blank to use site admin email', 'frontend-custom-post' ),
					'type' => 'text',
					'default' => '',
					'sanitize_callback' => 'sanitize_email',
				),
				array(
					'name' => 'show_author',
					'label' => __( 'Show author field', 'frontend-custom-post' ),
					'desc' => __( 'Yes', 'frontend-custom-post' ),
					'type' => 'checkbox',
					'default' => '',
				),
				array(
					'name' => 'enabled_post_types',
					'label' => __( 'Enable Frontend Custom Post for the following post types', 'frontend-custom-post' ),
					'desc' => '',
					'type' => 'multicheck',
					'default' => $default_post_type,
					'options' => self::get_post_types(),
				),
				array(
					'name' => 'wysiwyg_enabled',
					'label' => __( 'Enable visual editor for textareas', 'frontend-custom-post' ),
					'desc' => __( 'Yes', 'frontend-custom-post' ),
					'type' => 'checkbox',
					'default' => '',
				),
				array(
					'name' => 'enabled_files',
					'label' => __( 'Also allow to upload these files (in addition to the ones that WP allows by default)', 'frontend-custom-post' ),
					'desc' => '',
					'type' => 'multicheck',
					'default' => array(),
					'options' => fu_get_exts_descs(),
				),
				array(
					'name' => 'auto_approve_user_files',
					'label' => __( 'Auto-approve registered users files', 'frontend-custom-post' ),
					'desc' => __( 'Yes', 'frontend-uploader' ),
					'type' => 'checkbox',
					'default' => '',
				),
				array(
					'name' => 'auto_approve_any_files',
					'label' => __( 'Auto-approve any files', 'frontend-custom-post' ),
					'desc' => __( 'Yes', 'frontend-custom-post' ),
					'type' => 'checkbox',
					'default' => '',
				),
				array(
					'name' => 'default_file_name',
					'label' => __( 'Default file name', 'frontend-custom-post' ),
					'desc' => __( 'Leave blank to use original file name', 'frontend-custom-post' ),
					'type' => 'text',
					'default' => 'Unnamed',
					/* No need to set a sanitize callback. It is handled automagically. */
				),
				array(
					'name' => 'suppress_default_fields',
					'label' => __('Suppress default fields', 'frontend-custom-post' ),
					'desc' => __( 'Yes', 'frontend-custom-post' ),
					'type' => 'checkbox',
					'default' => '',
				),
			),
		);

		return $settings_fields;
	}

	/**
	 * Render the UI
	 */
	function plugin_page() {
		echo '<div class="wrap">';
		$this->settings_api->show_navigation();
		$this->settings_api->show_forms();
		echo '</div>';
	}
}

// Instantiate
$frontend_custom_post_settings = new Frontend_Custom_Post_Settings;