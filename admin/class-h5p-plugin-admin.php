<?php
/**
 * H5P Plugin.
 *
 * @package   H5P
 * @author    Joubel <contact@joubel.com>
 * @license   MIT
 * @link      http://joubel.com
 * @copyright 2014 Joubel
 */

/**
 * Plugin admin class.
 *
 * TODO: Add development mode
 * TODO: Move results stuff to seperate class
 *
 * @package H5P_Plugin_Admin
 * @author Joubel <contact@joubel.com>
 */
class H5P_Plugin_Admin {

  /**
   * Instance of this class.
   *
   * @since 1.0.0
   * @var \H5P_Plugin_Admin
   */
  protected static $instance = NULL;

  /**
   * @since 1.1.0
   */
  private $plugin_slug = NULL;

  /**
   * Keep track of the current content.
   *
   * @since 1.0.0
   */
  private $content = NULL;

  /**
   * Keep track of the current library.
   *
   * @since 1.1.0
   */
  private $library = NULL;

  /**
   * Initialize the plugin by loading admin scripts & styles and adding a
   * settings page and menu.
   *
   * @since 1.0.0
   */
  private function __construct() {
    $plugin = H5P_Plugin::get_instance();
    $this->plugin_slug = $plugin->get_plugin_slug();

    // Prepare admin pages / sections
    $this->content = new H5PContentAdmin($this->plugin_slug);
    $this->library = new H5PLibraryAdmin($this->plugin_slug);

    // Load admin style sheet and JavaScript.
    add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles_and_scripts'));

    // Add the options page and menu item.
    add_action('admin_menu', array($this, 'add_plugin_admin_menu'));

    // Allow altering of page titles for different page actions.
    add_filter('admin_title', array($this, 'alter_title'), 10, 2);

    // Custom media button for inserting H5Ps.
    add_action('media_buttons_context', array($this->content, 'add_insert_button'));
    add_action('admin_footer', array($this->content, 'print_insert_content_scripts'));
    add_action('wp_ajax_h5p_insert_content', array($this->content, 'ajax_insert_content'));

    // Editor ajax
    add_action('wp_ajax_h5p_libraries', array($this->content, 'ajax_libraries'));
    add_action('wp_ajax_h5p_files', array($this->content, 'ajax_files'));

    // AJAX for rebuilding all content caches
    add_action('wp_ajax_h5p_rebuild_cache', array($this->library, 'ajax_rebuild_cache'));

    // AJAX for content upgrade
    add_action('wp_ajax_h5p_content_upgrade_library', array($this->library, 'ajax_upgrade_library'));
    add_action('wp_ajax_h5p_content_upgrade_progress', array($this->library, 'ajax_upgrade_progress'));

    // AJAX for handling content usage datas
    add_action('wp_ajax_h5p_contents_user_data', array($this, 'ajax_contents_user_data'));

    // AJAX for logging results
    add_action('wp_ajax_h5p_setFinished', array($this, 'ajax_results'));

    // AJAX for display content results
    add_action('wp_ajax_h5p_content_results', array($this->content, 'ajax_content_results'));

    // AJAX for display user results
    add_action('wp_ajax_h5p_my_results', array($this, 'ajax_my_results'));

    // AJAX for getting contents list
    add_action('wp_ajax_h5p_contents', array($this->content, 'ajax_contents'));

    // AJAX for restricting library access
    add_action('wp_ajax_h5p_restrict_library', array($this->library, 'ajax_restrict_access'));

    // Display admin notices
    add_action('admin_notices', array($this, 'admin_notices'));

    // Embed
    add_action('wp_ajax_h5p_embed', array($this, 'embed'));
    add_action('wp_ajax_nopriv_h5p_embed', array($this, 'embed'));

    // Remove user data and results
    add_action('deleted_user', array($this, 'deleted_user'));
  }

  /**
   * Print page for embed iframe
   *
   * @since 1.3.0
   */
  public function embed() {
    // Allow other sites to embed
    header_remove('X-Frame-Options');

    // Find content
    $id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
    if ($id !== NULL) {
      $plugin = H5P_Plugin::get_instance();
      $content = $plugin->get_content($id);
      if (!is_string($content)) {
        $lang = $plugin->get_language();
        $cache_buster = '?ver=' . H5P_Plugin::VERSION;

        // Get core settings
        $integration = $plugin->get_core_settings();
        // TODO: The non-content specific settings could be apart of a combined h5p-core.js file.

        // Get core scripts
        $scripts = array();
        foreach (H5PCore::$scripts as $script) {
          $scripts[] = plugins_url('h5p/h5p-php-library/' . $script) . $cache_buster;
        }

        // Get core styles
        $styles = array();
        foreach (H5PCore::$styles as $style) {
          $styles[] = plugins_url('h5p/h5p-php-library/' . $style) . $cache_buster;
        }

        // Get content settings
        $core = $plugin->get_h5p_instance('core');
        $integration['contents']['cid-' . $content['id']] = array(
          'library' => H5PCore::libraryToString($content['library']),
          'jsonContent' => $core->filterParameters($content),
          'fullScreen' => $content['library']['fullscreen'],
          'exportUrl' => get_option('h5p_export', TRUE) ? $plugin->get_h5p_url() . '/exports/' . $content['id'] . '.h5p' : '',
          'showH5PIconInActionBar' => get_option('h5p_icon', TRUE)
        );

        // Get content assets
        $preloaded_dependencies = $core->loadContentDependencies($content['id'], 'preloaded');
        $files = $core->getDependenciesFiles($preloaded_dependencies);

        $scripts = array_merge($scripts, $core->getAssetsUrls($files['scripts']));
        $styles = array_merge($styles, $core->getAssetsUrls($files['styles']));

        include_once(plugin_dir_path(__FILE__) . '../h5p-php-library/embed.php');
        exit;
      }
    }

    // Simple unavailble page
    print '<body style="margin:0"><div style="background: #fafafa url(http://h5p.org/sites/all/themes/professional_themec/images/h5p.svg) no-repeat center;background-size: 50% 50%;width: 100%;height: 100%;"></div><div style="width:100%;position:absolute;top:75%;text-align:center;color:#434343;font-family: Consolas,monaco,monospace">' . __('Content unavailable.', $this->plugin_slug) . '</div></body>';
    exit;
  }

  /**
   * Return an instance of this class.
   *
   * @since 1.0.0
   * @return \H5P_Plugin_Admin A single instance of this class.
   */
  public static function get_instance() {
    // If the single instance hasn't been set, set it now.
    if (null == self::$instance) {
      self::$instance = new self;
    }

    return self::$instance;
  }

  /**
   * Print messages to admin UI.
   *
   * @since 1.3.0
   */
  public function admin_notices() {
    if (!get_option('h5p_minitutorial', FALSE)) {
      // TODO: We should make the user close the message. (so the user doesn't miss out on it)
      update_option('h5p_minitutorial', TRUE);
      ?>
        <div class="updated">
          <p><?php _e('Thank you for choosing H5P.', $this->plugin_slug); ?></p>
          <?php $this->print_minitutorial(); ?>
          <p><strong><?php _e('Content type upgrades are here!', $this->plugin_slug); ?></strong><br/>
          <?php printf(wp_kses(__('If you\'ve just upgraded you plugin, you should install the <a href="%s" target="_blank">lastest version</a> of your content types.', $this->plugin_slug), array('a' => array('href' => array(), 'target' => array()))), esc_url('http://h5p.org/update-all-content-types')); ?></p>
        </div>
      <?php
    }
  }

  /**
   * Print mini tutorial.
   * TODO: Print to content page when no content/libraries?
   *
   * @since 1.3.0
   */
  public function print_minitutorial() {
    ?>
      <p><?php _e('In order to take advantage of this plugin you must first select and download the H5P content types you wish to use.', $this->plugin_slug); ?><br/>
        <strong><?php _e('Here is a short guide to get you started:', $this->plugin_slug); ?></strong></p>
      <ol>
        <li><?php printf(wp_kses(__('Select and download the desired content types from <a href="%s" target="_blank">H5P.org</a>.', $this->plugin_slug), array('a' => array('href' => array(), 'target' => array()))), esc_url('http://h5p.org/content-types-and-applications')); ?></li>
        <li><?php printf(wp_kses(__('Upload the content types through the <a href="%s" target="_blank">Add new</a> page on your WordPress installation.', $this->plugin_slug), array('a' => array('href' => array(), 'target' => array()))), admin_url('admin.php?page=h5p_new')); ?></li>
        <li><?php printf(wp_kses(__('Start creating your own interactive content through the <a href="%s" target="_blank">Add new</a> page.', $this->plugin_slug), array('a' => array('href' => array(), 'target' => array()))), admin_url('admin.php?page=h5p_new')); ?></li>
      </ol>
      <p><?php printf(wp_kses(__('If you need help getting started you can file a <a href="%s" target="_blank">support request</a>, check out our <a href="%s" target="_blank">forum</a> or join our IRC channel #H5P on Freenode.', $this->plugin_slug), array('a' => array('href' => array(), 'target' => array()))), esc_url('https://wordpress.org/support/plugin/h5p'), esc_url('http://h5p.org/forum')); ?></p>
    <?php
  }

  /**
   * Register and enqueue admin-specific style sheet.
   *
   * @since 1.0.0
   */
  public function enqueue_admin_styles_and_scripts() {
    $plugin = H5P_Plugin::get_instance();
    $plugin->enqueue_styles_and_scripts();
    wp_enqueue_style($this->plugin_slug . '-admin-styles', plugins_url('styles/admin.css', __FILE__), array(), H5P_Plugin::VERSION);
  }

  /**
   * Register the administration menu for this plugin into the WordPress Dashboard menu.
   *
   * @since 1.0.0
   */
  public function add_plugin_admin_menu() {
    // H5P Content pages
    $h5p_content = __('H5P Content', $this->plugin_slug);
    add_menu_page($h5p_content, $h5p_content, 'edit_h5p_contents', $this->plugin_slug, array($this->content, 'display_contents_page'), 'none');

    $all_h5p_content = __('All H5P Content', $this->plugin_slug);
    add_submenu_page($this->plugin_slug, $all_h5p_content, $all_h5p_content, 'edit_h5p_contents', $this->plugin_slug, array($this->content, 'display_contents_page'));

    $add_new = __('Add New', $this->plugin_slug);
    $contents_page = add_submenu_page($this->plugin_slug, $add_new, $add_new, 'edit_h5p_contents', $this->plugin_slug . '_new', array($this->content, 'display_new_content_page'));

    // Process form data when saving H5Ps.
    add_action('load-' . $contents_page, array($this->content, 'process_new_content'));

    $libraries = __('Libraries', $this->plugin_slug);
    $libraries_page = add_submenu_page($this->plugin_slug, $libraries, $libraries, 'manage_h5p_libraries', $this->plugin_slug . '_libraries', array($this->library, 'display_libraries_page'));

    // Process form data when upload H5Ps without content.
    add_action('load-' . $libraries_page, array($this->library, 'process_libraries'));

    if (get_option('h5p_track_user', TRUE)) {
      $my_results = __('My Results', $this->plugin_slug);
      add_submenu_page($this->plugin_slug, $my_results, $my_results, 'view_h5p_results', $this->plugin_slug . '_results', array($this, 'display_results_page'));
    }

    // Settings page
    add_options_page('H5P Settings', 'H5P', 'manage_options', $this->plugin_slug . '_settings', array($this, 'display_settings_page'));
  }

  /**
   * Display a settings page for H5P.
   *
   * @since 1.0.0
   */
  public function display_settings_page() {
    $save = filter_input(INPUT_POST, 'save_these_settings');
    if ($save !== NULL) {
      // Get input and store settings
      check_admin_referer('h5p_settings', 'save_these_settings'); // Verify form

      // Action bar
      $frame = filter_input(INPUT_POST, 'frame', FILTER_VALIDATE_BOOLEAN);
      update_option('h5p_frame', $frame);

      $download = filter_input(INPUT_POST, 'download', FILTER_VALIDATE_BOOLEAN);
      update_option('h5p_export', $download);

      $copyright = filter_input(INPUT_POST, 'copyright', FILTER_VALIDATE_BOOLEAN);
      update_option('h5p_copyright', $copyright);

      $about = filter_input(INPUT_POST, 'about', FILTER_VALIDATE_BOOLEAN);
      update_option('h5p_icon', $about);

      $track_user = filter_input(INPUT_POST, 'track_user', FILTER_VALIDATE_BOOLEAN);
      update_option('h5p_track_user', $track_user);

      $library_updates = filter_input(INPUT_POST, 'library_updates', FILTER_VALIDATE_BOOLEAN);
      update_option('h5p_library_updates', $track_user);
    }
    else {
      $frame = get_option('h5p_frame', TRUE);
      $download = get_option('h5p_export', TRUE);
      $copyright = get_option('h5p_copyright', TRUE);
      $about = get_option('h5p_icon', TRUE);
      $track_user = get_option('h5p_track_user', TRUE);
      $library_updates = get_option('h5p_library_updates', TRUE);
    }

    include_once('views/settings.php');
    H5P_Plugin_Admin::add_script('disable', 'h5p-php-library/js/disable.js');
  }

  /**
   * Load content and add to title for certain pages.
   * Should we have used get_current_screen() ?
   *
   * @since 1.1.0
   * @param string $admin_title
   * @param string $title
   * @return string
   */
  public function alter_title($admin_title, $title) {
    $page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_STRING);

    switch ($page) {
      case 'h5p':
      case 'h5p_new':
        return $this->content->alter_title($page, $admin_title, $title);

      case 'h5p_libraries':
        return $this->library->alter_title($page, $admin_title, $title);
    }

    return $admin_title;
  }

  /**
   * Handle upload of new H5P content file.
   *
   * @since 1.1.0
   * @param array $content
   * @return boolean
   */
  public function handle_upload($content = NULL, $only_upgrade = NULL) {
    $plugin = H5P_Plugin::get_instance();
    $validator = $plugin->get_h5p_instance('validator');
    $interface = $plugin->get_h5p_instance('interface');

    if (current_user_can('disable_h5p_security')) {
      $core = $plugin->get_h5p_instance('core');

      // Make it possible to disable file extension check
      $core->disableFileCheck = (filter_input(INPUT_POST, 'h5p_disable_file_check', FILTER_VALIDATE_BOOLEAN) ? TRUE : FALSE);
    }

    // Move so core can validate the file extension.
    rename($_FILES['h5p_file']['tmp_name'], $interface->getUploadedH5pPath());

    $skipContent = ($content === NULL);
    if ($validator->isValidPackage($skipContent, $only_upgrade) && ($skipContent || $content['title'] !== NULL)) {
      if (isset($content['id'])) {
        $interface->deleteLibraryUsage($content['id']);
      }
      $storage = $plugin->get_h5p_instance('storage');
      $storage->savePackage($content, NULL, $skipContent, $only_upgrade);
      return $storage->contentId;
    }

    // The uploaded file was not a valid H5P package
    @unlink($interface->getUploadedH5pPath());
    return FALSE;
  }

  /**
   * Set error message.
   *
   * @param string $message
   */
  public static function set_error($message) {
    $plugin = H5P_Plugin::get_instance();
    $interface = $plugin->get_h5p_instance('interface');
    $interface->setErrorMessage($message);
  }

  /**
   * Print messages.
   *
   * @since 1.0.0
   */
  public static function print_messages() {
    $plugin = H5P_Plugin::get_instance();
    $interface = $plugin->get_h5p_instance('interface');

    foreach (array('updated', 'error') as $type) {
      $messages = $interface->getMessages($type);
      if (!empty($messages)) {
        print '<div class="' . $type . '"><ul>';
        foreach ($messages as $message) {
          print '<li>' . $message . '</li>';
        }
        print '</ul></div>';
      }
    }
  }

  /**
   * Get proper handle for the given asset
   *
   * @since 1.1.0
   * @param string $path
   * @return string
   */
  private static function asset_handle($path) {
    $plugin = H5P_Plugin::get_instance();
    return $plugin->asset_handle($path);
  }

  /**
   * Small helper for simplifying script enqueuing.
   *
   * @since 1.1.0
   * @param string $handle
   * @param string $path
   */
  public static function add_script($handle, $path) {
    wp_enqueue_script(self::asset_handle($handle), plugins_url('h5p/' . $path), array(), H5P_Plugin::VERSION);
  }

  /**
   * Small helper for simplifying style enqueuing.
   *
   * @since 1.1.0
   * @param string $handle
   * @param string $path
   */
  public static function add_style($handle, $path) {
    wp_enqueue_style(self::asset_handle($handle), plugins_url('h5p/' . $path), array(), H5P_Plugin::VERSION);
  }

  /**
   * Handle user results reported by the H5P content.
   *
   * @since 1.2.0
   */
  public function ajax_results() {
    global $wpdb;

    $content_id = filter_input(INPUT_POST, 'contentId', FILTER_VALIDATE_INT);
    if (!$content_id) {
      return;
    }

    $user_id = get_current_user_id();
    $result_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id
        FROM {$wpdb->prefix}h5p_results
        WHERE user_id = %d
        AND content_id = %d",
        $user_id,
        $content_id
    ));

    $table = $wpdb->prefix . 'h5p_results';
    $data = array(
      'score' => filter_input(INPUT_POST, 'score', FILTER_VALIDATE_INT),
      'max_score' => filter_input(INPUT_POST, 'maxScore', FILTER_VALIDATE_INT),
      'opened' => filter_input(INPUT_POST, 'opened', FILTER_VALIDATE_INT),
      'finished' => filter_input(INPUT_POST, 'finished', FILTER_VALIDATE_INT),
      'time' => filter_input(INPUT_POST, 'time', FILTER_VALIDATE_INT)
    );
    $format = array(
      '%d',
      '%d',
      '%d',
      '%d',
      '%d'
    );

    if (!$result_id) {
      // Insert new results
      $data['user_id'] = $user_id;
      $format[] = '%d';
      $data['content_id'] = $content_id;
      $format[] = '%d';
      $wpdb->insert($table, $data, $format);
    }
    else {
      // Update existing results
      $wpdb->update($table, $data, array('id' => $result_id), $format, array('%d'));
    }
  }

  /**
   * Create the where part of the results queries.
   *
   * @since 1.2.0
   * @param array $query_args
   * @param int $content_id
   * @param int $user_id
   * @return array
   */
  private function get_results_query_where(&$query_args, $content_id = NULL, $user_id = NULL, $filters = array()) {
    if ($content_id !== NULL) {
      $where = ' WHERE hr.content_id = %d';
      $query_args[] = $content_id;
    }
    if ($user_id !== NULL) {
      $where = (isset($where) ? $where . ' AND' : ' WHERE') . ' hr.user_id = %d';
      $query_args[] = $user_id;
    }
    if (isset($where) && isset($filters[0])) {
      $where .= ' AND ' . ($content_id === NULL ? 'hc.title' : 'u.user_login') . " LIKE '%%%s%%'";
      $query_args[] = $filters[0];
    }
    return (isset($where) ? $where : '');
  }

  /**
   * Find number of results.
   *
   * @since 1.2.0
   * @param int $content_id
   * @param int $user_id
   * @return int
   */
  public function get_results_num($content_id = NULL, $user_id = NULL, $filters = array()) {
    global $wpdb;

    $query_args = array();
    return (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(id) FROM {$wpdb->prefix}h5p_results hr" .
        $this->get_results_query_where($query_args, $content_id, $user_id),
      $query_args
    ));
  }

  /**
   * Handle user results reported by the H5P content.
   *
   * @since 1.2.0
   * @param int $content_id
   * @param int $user_id
   * @return array
   */
  public function get_results($content_id = NULL, $user_id = NULL, $offset = 0, $limit = 20, $sort_by = 0, $sort_dir = 0, $filters = array()) {
    global $wpdb;

    $extra_fields = '';
    $joins = '';
    $query_args = array();

    // Add extra fields and joins for the different result lists
    if ($content_id === NULL) {
      $extra_fields .= " hr.content_id, hc.title AS content_title,";
      $joins .= " LEFT JOIN {$wpdb->prefix}h5p_contents hc ON hr.content_id = hc.id";
    }
    if ($user_id === NULL) {
      $extra_fields .= " hr.user_id, u.display_name AS user_name,";
      $joins .= " LEFT JOIN {$wpdb->base_prefix}users u ON hr.user_id = u.ID";
    }

    // Add filters
    $where = $this->get_results_query_where($query_args, $content_id, $user_id, $filters);

    // Order results by the select column and direction
    $order_by = $this->get_order_by($sort_by, $sort_dir, array(
      (object) array(
        'name' => ($content_id === NULL ? 'hc.title' : 'u.user_login'),
        'reverse' => TRUE
      ),
      'hr.score',
      'hr.max_score',
      'hr.opened',
      'hr.finished'
    ));

    $query_args[] = $offset;
    $query_args[] = $limit;

    return $wpdb->get_results($wpdb->prepare(
      "SELECT hr.id,
              {$extra_fields}
              hr.score,
              hr.max_score,
              hr.opened,
              hr.finished,
              hr.time
        FROM {$wpdb->prefix}h5p_results hr
        {$joins}
        {$where}
        {$order_by}
        LIMIT %d, %d",
      $query_args
    ));
  }

  /**
   * Generate order by part of SQLs.
   *
   * @since 1.2.0
   * @param int $field Index of field to order by
   * @param int $direction Direction to order in. 0=DESC,1=ASC
   * @param array $field Objects containing name and reverse sort option.
   * @return string Order by part of SQL
   */
  public function get_order_by($field, $direction, $fields) {
    // Make sure selected sortable field is valid
    if (!isset($fields[$field])) {
      $field = 0; // Fall back to default
    }

    // Find selected sortable field
    $field = $fields[$field];

    if (is_object($field)) {
      // Some fields are reverse sorted by default, e.g. text fields.
      if (!empty($field->reverse)) {
        $direction = !$direction;
      }

      $field = $field->name;
    }

    return 'ORDER BY ' . $field . ' ' . ($direction ? 'ASC' : 'DESC');
  }

  /**
   * Print settings, adds JavaScripts and stylesheets necessary for providing
   * a data view.
   *
   * @since 1.2.0
   * @param string $name of the data view
   * @param string $source URL for data
   * @param array $headers for the table
   */
  public function print_data_view_settings($name, $source, $headers, $filters, $empty, $order) {
    // Add JS settings
    $data_views = array();
    $data_views[$name] = array(
      'source' => $source,
      'headers' => $headers,
      'filters' => $filters,
      'order' => $order,
      'l10n' => array(
        'loading' => __('Loading data.', $this->plugin_slug),
        'ajaxFailed' => __('Failed to load data.', $this->plugin_slug),
        'noData' => __("There's no data available that matches your criteria.", $this->plugin_slug),
        'currentPage' => __('Page $current of $total', $this->plugin_slug),
        'nextPage' => __('Next page', $this->plugin_slug),
        'previousPage' =>__('Previous page', $this->plugin_slug),
        'search' =>__('Search', $this->plugin_slug),
        'empty' => $empty
      )
    );
    $plugin = H5P_Plugin::get_instance();
    $settings = array('dataViews' => $data_views);
    $plugin->print_settings($settings);

    // Add JS
    H5P_Plugin_Admin::add_script('jquery', 'h5p-php-library/js/jquery.js');
    H5P_Plugin_Admin::add_script('utils', 'h5p-php-library/js/h5p-utils.js');
    H5P_Plugin_Admin::add_script('data-view', 'h5p-php-library/js/h5p-data-view.js');
    H5P_Plugin_Admin::add_script('data-views', 'admin/scripts/h5p-data-views.js');
    H5P_Plugin_Admin::add_style('admin', 'h5p-php-library/styles/h5p-admin.css');
  }

  /**
   * Displays the "My Results" page.
   *
   * @since 1.2.0
   */
  public function display_results_page() {
    include_once('views/my-results.php');
    $this->print_data_view_settings(
      'h5p-my-results',
      admin_url('admin-ajax.php?action=h5p_my_results'),
      array(
        (object) array(
          'text' => __('Content', $this->plugin_slug),
          'sortable' => TRUE
        ),
        (object) array(
          'text' => __('Score', $this->plugin_slug),
          'sortable' => TRUE
        ),
        (object) array(
          'text' => __('Maximum Score', $this->plugin_slug),
          'sortable' => TRUE
        ),
        (object) array(
          'text' => __('Opened', $this->plugin_slug),
          'sortable' => TRUE
        ),
        (object) array(
          'text' => __('Finished', $this->plugin_slug),
          'sortable' => TRUE
        ),
        __('Time spent', $this->plugin_slug)
      ),
      array(true),
      __("There are no logged results for your user.", $this->plugin_slug),
      (object) array(
        'by' => 4,
        'dir' => 0
      )
    );
  }

  /**
   * Print results ajax data for either content or user, not both.
   *
   * @since 1.2.0
   * @param int $content_id
   * @param int $user_id
   */
  public function print_results($content_id = NULL, $user_id = NULL) {
    // Load input vars.
    list($offset, $limit, $sortBy, $sortDir, $filters) = $this->get_data_view_input();

    // Get results
    $results = $this->get_results($content_id, $user_id, $offset, $limit, $sortBy, $sortDir, $filters);

    $datetimeformat = get_option('date_format') . ' ' . get_option('time_format');
    $offset = get_option('gmt_offset') * 3600;

    // Make data more readable for humans
    $rows = array();
    foreach ($results as $result)  {
      if ($result->time === '0') {
        $result->time = $result->finished - $result->opened;
      }
      $seconds = ($result->time % 60);
      $time = floor($result->time / 60) . ':' . ($seconds < 10 ? '0' : '') . $seconds;

      $rows[] = array(
        esc_html($content_id === NULL ? $result->content_title : $result->user_name),
        (int) $result->score,
        (int) $result->max_score,
        date($datetimeformat, $offset + $result->opened),
        date($datetimeformat, $offset + $result->finished),
        $time,
      );
    }

    // Print results
    header('Cache-Control: no-cache');
    header('Content-type: application/json');
    print json_encode(array(
      'num' => $this->get_results_num($content_id, $user_id, $filters),
      'rows' => $rows
    ));
    exit;
  }

  /**
   * Provide data for content results view.
   *
   * @since 1.2.0
   */
  public function ajax_my_results() {
    $this->print_results(NULL, get_current_user_id());
  }

  /**
   * Load input vars for data views.
   *
   * @since 1.2.0
   * @return array offset, limit, sort by, sort direction, filters
   */
  public function get_data_view_input() {
    $offset = filter_input(INPUT_GET, 'offset', FILTER_SANITIZE_NUMBER_INT);
    $limit = filter_input(INPUT_GET, 'limit', FILTER_SANITIZE_NUMBER_INT);
    $sortBy = filter_input(INPUT_GET, 'sortBy', FILTER_SANITIZE_NUMBER_INT);
    $sortDir = filter_input(INPUT_GET, 'sortDir', FILTER_SANITIZE_NUMBER_INT);
    $filters = filter_input(INPUT_GET, 'filters', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);

    $limit = (!$limit ? 20 : (int) $limit);
    if ($limit > 100) {
      $limit = 100; // Prevent wrong usage.
    }

    // Use default if not set or invalid
    return array(
      (!$offset ? 0 : (int) $offset),
      $limit,
      (!$sortBy ? 0 : (int) $sortBy),
      (!$sortDir ? 0 : (int) $sortDir),
      $filters
    );

  }

  /**
   * Handle user results reported by the H5P content.
   *
   * @since 1.5.0
   */
  public function ajax_contents_user_data() {
    global $wpdb;

    $content_id = filter_input(INPUT_GET, 'content_id');
    $data_id = filter_input(INPUT_GET, 'data_type');
    $sub_content_id = filter_input(INPUT_GET, 'sub_content_id');
    $current_user = wp_get_current_user();

    if ($content_id === NULL ||
        $data_id === NULL ||
        $sub_content_id === NULL ||
        !$current_user->ID) {
      return; // Missing parameters
    }

    $response = (object) array(
      'success' => TRUE
    );

    $data = filter_input(INPUT_POST, 'data');
    $preload = filter_input(INPUT_POST, 'preload');
    $invalidate = filter_input(INPUT_POST, 'invalidate');
    if ($data !== NULL && $preload !== NULL && $invalidate !== NULL) {
      if ($data === '0') {
        // Remove data
        $wpdb->delete($wpdb->prefix . 'h5p_contents_user_data',
          array(
            'content_id' => $content_id,
            'data_id' => $data_id,
            'user_id' => $current_user->ID,
            'sub_content_id' => $sub_content_id
          ),
          array('%d', '%s', '%d', '%d'));
      }
      else {
        // Wash values to ensure 0 or 1.
        $preload = ($preload === '0' ? 0 : 1);
        $invalidate = ($invalidate === '0' ? 0 : 1);

        // Determine if we should update or insert
        $update = $wpdb->get_var($wpdb->prepare(
          "SELECT content_id
           FROM {$wpdb->prefix}h5p_contents_user_data
           WHERE content_id = %d
             AND user_id = %d
             AND data_id = %s
             AND sub_content_id = %d",
            $content_id, $current_user->ID, $data_id, $sub_content_id
        ));

        if ($update === NULL) {
          // Insert new data
          $wpdb->insert($wpdb->prefix . 'h5p_contents_user_data',
            array(
              'user_id' => $current_user->ID,
              'content_id' => $content_id,
              'sub_content_id' => $sub_content_id,
              'data_id' => $data_id,
              'data' => $data,
              'preload' => $preload,
              'invalidate' => $invalidate,
              'updated_at' => current_time('mysql', 1)
            ),
            array('%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s')
          );
        }
        else {
          // Update old data
          $wpdb->update($wpdb->prefix . 'h5p_contents_user_data',
            array(
              'data' => $data,
              'preload' => $preload,
              'invalidate' => $invalidate,
              'updated_at' => current_time('mysql', 1)
            ),
            array(
              'user_id' => $current_user->ID,
              'content_id' => $content_id,
              'data_id' => $data_id,
              'sub_content_id' => $sub_content_id
            ),
            array('%s', '%d', '%d', '%s'),
            array('%d', '%d', '%s', '%d')
          );
        }
      }
    }
    else {
      // Fetch data
      $response->data = $wpdb->get_var($wpdb->prepare(
        "SELECT hcud.data
         FROM {$wpdb->prefix}h5p_contents_user_data hcud
         WHERE user_id = %d
           AND content_id = %d
           AND data_id = %s
           AND sub_content_id %d",
        $current_user->ID, $content_id, $data_id, $sub_content_id
      ));
    }

    header('Cache-Control: no-cache');
    header('Content-type: application/json; charset=utf-8');
    print json_encode($response);
    exit;
  }

  /**
   * Remove user data and results when user is removed.
   *
   * @since 1.5.0
   */
  public function deleted_user($id) {

    // Remove user scores/results
    $wpdb->delete($wpdb->prefix . 'h5p_results', array('user_id' => $id), array('%d'));

    // Remove contents user/usage data
    $wpdb->delete($wpdb->prefix . 'h5p_contents_user_data', array('user_id' => $id), array('%d'));
  }

}
