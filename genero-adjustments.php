<?php
/*
Plugin Name:        Genero Adjustments
Plugin URI:         http://genero.fi
Description:        A collection of minor fixes across all sites.
Version:            0.0.1
Author:             Genero
Author URI:         http://genero.fi/
License:            MIT License
License URI:        http://opensource.org/licenses/MIT
*/

namespace Genero;

if (!is_blog_installed()) {
  return;
}

/** Remove WPML assets. */
define('ICL_DONT_LOAD_NAVIGATION_CSS', true);
define('ICL_DONT_LOAD_LANGUAGE_SELECTOR_CSS', true);
define('ICL_DONT_LOAD_LANGUAGES_JS', true);

class Adjustments {

  /** Singleton instance */
  private static $instance = null;

  /** Additional role capabilities */
  public static $role_capabilities = array(
    'editor' => array('edit_theme_options'),
  );

  /**
   * Singleton
   */
  public static function get_instance() {
    if (null === self::$instance) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Initialize all actions and filters.
   */
  public function init() {
    $this->init_cleanup();
    $this->init_shortcodes();
    $this->init_gravityform();
    $this->init_admin();
    $this->init_bugfixes();
  }

  /**
   * Add custom shortcodes.
   */
  public function init_shortcodes() {
    // Add [shy] shortcuit.
    add_shortcode('shy', array($this, 'shortcode_shy'));
    // Allow shy entities in TinyMCE.
    add_filter('tiny_mce_before_init', array($this, 'tinymce_allow_shy'));
    // Allow shortcodes in text widgets.
    add_filter('widget_text', 'do_shortcode');
  }

  /**
   * Basic cleanup for performance, security and general sanity.
   */
  public function init_cleanup() {
    // Remove all traces of emojicons.
    add_action('init', array($this, 'remove_emojicons'));
    // Tell Autoptimizer not to concat inline CSS/JavaScript.
    add_filter('autoptimize_css_include_inline', '__retun_false');
    add_filter('autoptimize_js_include_inline', '__return_false');
    // Activate Soil features.
    add_action('after_setup_theme', array($this, 'setup_soil'));
    // Set and force some sane default options.
    add_action('wpmu_new_blog', array($this, 'set_default_options'));
    // Disable XML-RPC.
    add_filter('xmlrpc_enabled', '__return_false');
  }

  /**
   * Setup some defaults for gravityforms.
   */
  public function init_gravityform() {
    if (class_exists('GFForms')) {
      // Allow hiding Graviy Form field labels so that fields can use
      // placeholders only.
      add_filter('gform_enable_field_label_visibility_settings', '__return_true');

      // Gravityform forces these.
      delete_option('gform_pending_installation');
      delete_option('gform_enable_background_updates');
    }
  }

  /**
   * Setup the administration interface.
   */
  public function init_admin() {
    // Add an instagram contact method to user profiles.
    add_filter('user_contactmethods', array($this, 'modify_user_contact_methods'));
    // Hide some unnecessary admin menu items.
    add_action('admin_head', array($this, 'modify_admin_pages'));
    // Set better role capabilities, primarily for editors.
    add_action('plugins_loaded', array($this, 'modify_role_capabilities'));
    // As with above action, grant permissions in customizer as well.
    add_action('customize_register', array($this, 'modify_customizer_capabilities'), 1000);
    // Allow users to edit their profile meta data through the customizer.
    add_action('customize_update_user_meta', array($this, 'customizer_update_user_meta'), 10, 2);
    // Action for saving author_meta settings used in above action.
    add_action('customize_register', array($this, 'customizer_add_author_meta'), 100);
  }

  /**
   * Fix some contrib plugin bugs.
   */
  public function init_bugfixes() {
    // Fix for clear-cache-for-widgets.
    add_action('ccfm_clear_cache_for_me_before', array($this, 'fix_clear_cache_for_widgets'));
    // Fix for wp-xhprof-profiler.
    add_action('plugins_loaded', array($this, 'fix_xhprof_profiler'), 9);
  }

  /**
   * Remove Emojicons.
   */
  public function remove_emojicons() {
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    add_filter('tiny_mce_plugins', function ($plugins) {
      if (is_array($plugins)) {
        return array_diff($plugins, array('wpemoji'));
      }
      return array();
    });
  }

  /**
   * Activate soil features in all front-end themes.
   */
  public function setup_soil() {
    if (!is_admin()) {
      add_theme_support('soil-clean-up');
      add_theme_support('soil-nice-search');
      add_theme_support('soil-jquery-cdn');
      add_theme_support('soil-relative-urls');
    }
  }

  /**
  * Fix for clear-cache-for-widgets plugin not loading required function
  * definition and causing fatal errors.
  */
  public function fix_clear_cache_for_widgets() {
    include_once WPCACHEHOME . 'wp-cache-phase1.php';
  }

  /**
   * Fix for wp-xhprof-profiler which corrupts AJAX/REST output.
   */
  public function fix_xhprof_profiler() {
    $is_ajax = defined('DOING_AJAX') && DOING_AJAX;
    $is_customizer = is_customize_preview();

    if ($is_ajax || $is_customizer) {
      remove_action('plugins_loaded', 'berriart_xhprof_profiler_muplugins_loaded');
      remove_action('shutdown', 'berriart_xhprof_profiler_shutdown');
    }
  }

  /**
   * Add additional capabilities to the editor role.
   */
  public function modify_role_capabilities() {
    foreach (self::$role_capabilities as $role_name => $capabilities) {
      $role = get_role($role_name);
      foreach ($capabilities as $cap) {
        if (!$role->has_cap($cap)) {
          $role->add_cap($cap);
        }
      }
    }
  }

  /**
   * Alter some admin pages.
   */
  public function modify_admin_pages() {
    // Only display themes page for users who can switch the theme.
    if (!current_user_can('switch_themes')) {
      remove_submenu_page('themes.php', 'themes.php');
    }
    // Hide update notices for people who can't update.
    if (!current_user_can('update_core')) {
      remove_action('admin_notices', 'update_nag', 3);
    }
    // Remove tools page for all.
    remove_menu_page('tools.php');
  }

  /**
   * Grant editors ability to edit all customizer options except for some
   * blacklisted options.
   */
  public function modify_customizer_capabilities($wp_customize) {
    $settings = $wp_customize->settings();

    $blacklist = array(
      'active_theme',
    );
    foreach ($settings as $id => $setting) {
      if (in_array($id, $blacklist)) {
        continue;
      }
      $setting->capability = 'edit_theme_options';
    }
  }

  /**
   * Add an Instagram field to user profiles.
   */
  public function modify_user_contact_methods($user_contact) {
    $user_contact['instagram']  = __('Instagram');
    return $user_contact;
  }

  /**
   * Add author meta data to customizer.
   */
  public function customizer_add_author_meta($wp_customize) {
    // Trigger WPSEO user_contactmethods filter.
    if (function_exists('wpseo_admin_init')) {
      wpseo_admin_init();
    }
    $author = wp_get_current_user();

    $fields = array(
      'first_name' => __('First Name'),
      'last_name' => __('Last Name'),
      'nickname' => __('Nickname'),
      'user_url' => __('Website'),
    );
    $fields += wp_get_user_contact_methods($author);

    $wp_customize->add_section('author', array(
      'title' => sprintf(__('Author: %s'), $author->user_login),
      'priority' => 20,
    ));

    foreach ($fields as $key => $title) {
      $wp_customize->add_setting($key, array(
        'default' => get_user_meta($author->ID, $key, true),
        'type' => 'user_meta',
        'capability' => 'manage_options',
      ));
      $wp_customize->add_control($key, array(
        'label' => $title,
        'section' => 'author',
      ));
    }
  }

  /**
   * Save author metadata set through Customizer.
   */
  public function customizer_update_user_meta($value, $WP_Customize_Setting) {
    $author = wp_get_current_user();
    update_user_meta($author->ID, $WP_Customize_Setting->id, $value);
  }

  /**
   * Allow &shy; in TinyMCE
   * @see http://stackoverflow.com/a/29261339/319855
   */
  public function tinymce_allow_shy($initArray) {
    $initArray['entities'] = 'shy';
    return $initArray;
  }

  /**
  * Add a [shy] shortcode.
  */
  public function shortcode_shy($atts) {
    return '&shy;';
  }

  /**
  * Set some hard-coded defaults.
  */
  public function set_default_options($blog_id = null) {
    if (isset($blog_id)) {
      switch_to_blog($blog_id);
    }

    // Disable avatars as they add unnecssary assets to the front-end.
    update_option('show_avatars', 0);
    // Other sane defaults.
    update_option('uploads_use_yearmonth_folders', 0);
    update_option('permalink_structure', '/%postname%/');
    update_option('gmt_offset', '+3');
    update_option('default_comment_status', 'closed');
    update_option('time_format', 'g:i');
    update_option('autoptimize_html', 'on');
    update_option('autoptimize_js', 'on');
    update_option('autoptimize_css', 'on');

    // Set some optinally sitewide-defined options.
    foreach (array(
      'mailserver_url' => 'GENERO_MAILSERVER_URL',
      'mailserver_login' => 'GENERO_MAILSERVER_LOGIN',
      'mailserver_pass' => 'GENERO_MAILSERVER_PASS',
      'mailserver_port' => 'GENERO_MAILSERVER_PORT',
    ) as $option => $constant) {
      if (defined($constant)) {
        update_option($option, constant($constant));
      }
    }

    // Preconfigure Gravityforms (always set these in case the plugin is
    // activated later).
    if (defined('GENERO_GFORM_KEY')) {
      update_option('rg_gforms_key', GENERO_GFORM_KEY);
    }
    update_option('gform_enable_noconflict', 0);
    update_option('rg_gforms_enable_akismet', 1);
    update_option('rg_gforms_enable_html5', 1);
    update_option('rg_gforms_currency', 'EUR');

    if (isset($blog_id)) {
      restore_current_blog();
    }
  }

  /**
   * Install hook.
   */
  public function install() {
    $this->set_default_options(null);
  }
}

register_activation_hook(__FILE__, array(Adjustments::get_instance(), 'install'));
Adjustments::get_instance()->init();
