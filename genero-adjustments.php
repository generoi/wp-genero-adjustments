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
defined('ICL_DONT_LOAD_NAVIGATION_CSS') || define('ICL_DONT_LOAD_NAVIGATION_CSS', true);
defined('ICL_DONT_LOAD_LANGUAGE_SELECTOR_CSS') || define('ICL_DONT_LOAD_LANGUAGE_SELECTOR_CSS', true);
defined('ICL_DONT_LOAD_LANGUAGES_JS') || define('ICL_DONT_LOAD_LANGUAGES_JS', true);

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
    $this->init_autoptimize();
    $this->init_admin();
    $this->init_bugfixes();
    $this->init_polylang();
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
    // Set and force some sane default options.
    add_action('wpmu_new_blog', array($this, 'set_default_options'));
    // Disable XML-RPC.
    add_filter('xmlrpc_enabled', '__return_false');
  }

  public function init_autoptimize() {
    // Tell Autoptimizer not to concat inline CSS/JavaScript.
    add_filter('autoptimize_css_include_inline', '__return_false');
    add_filter('autoptimize_js_include_inline', '__return_false');
    // Disable Autoptimize for logged in users.
    add_filter('autoptimize_filter_noptimize', array($this, 'should_disable_cache'));
    // Remove Autoptimize from the admin toolbar.
    add_filter('autoptimize_filter_toolbar_show', '__return_false');
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
      if (get_option('rg_gforms_key') && get_option('gform_pending_installation')) {
        delete_option('gform_pending_installation');
        delete_option('gform_enable_background_updates');
      }
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
    // Hide some columns by default from the Admin UI screen options
    add_filter('default_hidden_columns', array($this, 'default_hidden_columns'), 10, 2);
    add_action('wp_before_admin_bar_render', array($this, 'remove_admin_bar_items'));
    // Remove nagging notices.
    remove_action('admin_notices', 'woothemes_updater_notice');
    remove_action('admin_notices', 'widgetopts_admin_notices');
  }

  /**
   * Fix some contrib plugin bugs.
   */
  public function init_bugfixes() {
    // Fix for clear-cache-for-widgets.
    add_action('ccfm_clear_cache_for_me_before', array($this, 'fix_clear_cache_for_widgets'));
    // Fix for debug-bar-js.dev.js referencing jQuery without depending on it.
    add_action('wp_print_scripts', array($this, 'fix_debug_bar_js'), 999);
  }

  /**
   * Polylang integrations.
   */
  public function init_polylang() {
    add_filter('acf/settings/default_language', function ($language) {
        return function_exists('pll') ? pll_default_language() : $language;
    });
    add_filter('acf/settings/current_language', function ($language) {
        return function_exists('pll') ? pll_current_language() : $language;
    });
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
    add_filter('emoji_svg_url', '__return_false');
    add_filter('tiny_mce_plugins', function ($plugins) {
      if (is_array($plugins)) {
        return array_diff($plugins, array('wpemoji'));
      }
      return array();
    });
    add_filter('wp_resource_hints', function ($hints, $relation_type) {
        if ($relation_type == 'dns-prefetch') {
            $emoji_svg_url = apply_filters('emoji_svg_url', 'https://s.w.org/images/core/emoji/2.2.1/svg/');
            $hints = array_diff($hints, [$emoji_svg_url]);
        }
        return $hints;
    }, 10, 2);
  }

  /**
  * Fix for clear-cache-for-widgets plugin not loading required function
  * definition and causing fatal errors.
  */
  public function fix_clear_cache_for_widgets() {
    include_once WPCACHEHOME . 'wp-cache-phase1.php';
  }

  /**
   * Fix debug-bar-js.dev.js referencing jQuery without depending on it.
   */
  public function fix_debug_bar_js() {
    if (wp_script_is('debug-bar-js', 'enqueued')) {
      wp_dequeue_script('debug-bar-js');
      $suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '.dev' : '';
      wp_enqueue_script('debug-bar-js', plugins_url("js/debug-bar-js$suffix.js", WP_PLUGIN_DIR . '/debug-bar'), ['jquery'], '20111216', true);
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
   * Hide some columns by default from the Admin UI screen options.
   */
  public function default_hidden_columns($hidden, $screen) {
    if (!empty($screen->taxonomy)) {
        $hidden[] = 'description';
    }
    if (!empty($screen->post_type) && $screen->post_type == 'post') {
        $hidden[] = 'tags';
    }
    $hidden[] = 'wpseo-score';
    $hidden[] = 'wpseo-score-readability';
    return $hidden;
  }

  /**
   * Remove items from the admin bar.
   */
  public function remove_admin_bar_items() {
    global $wp_admin_bar;
    // Yoast
    $wp_admin_bar->remove_menu('wpseo-menu');
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
   * Return if caching should be disabled.
   */
  public function should_disable_cache() {
    return WP_CACHE ? is_user_logged_in() : true;
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
      'mailer' => 'GENERO_MAILER',
      'mailserver_url' => 'GENERO_MAILSERVER_URL',
      'mailserver_login' => 'GENERO_MAILSERVER_LOGIN',
      'mailserver_pass' => 'GENERO_MAILSERVER_PASS',
      'mailserver_port' => 'GENERO_MAILSERVER_PORT',
      'smtp_host' => 'GENERO_SMTP_HOST',
      'smtp_user' => 'GENERO_SMTP_USER',
      'smtp_auth' => 'GENERO_SMTP_AUTH',
      'smtp_ssl' => 'GENERO_SMTP_SSL',
      'smtp_pass' => 'GENERO_SMTP_PASS',
      'smtp_port' => 'GENERO_SMTP_PORT',
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
