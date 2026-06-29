<?php
/*
Plugin Name: SM Live Visitor Map
Plugin URI: https://github.com/sebastian-m-dev/live-visitor-map
Description: Real-time visitor tracking with a live map showing visitor locations, session analytics, and an interactive dashboard. Pixel-based tracking works on any theme.
Version: 1.1.0
Author: Sebastian Morales
Author URI: https://sebastianmorales.site
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: live-visitor-map
Domain Path: /languages
Icon: assets/icon-128x128.png
*/

if (!defined('ABSPATH'))
    exit;

define('LVM_VERSION', '1.1.0');
define('LVM_VISITS_TABLE', 'lvm_visits');
define('LVM_SESSIONS_TABLE', 'lvm_sessions');
define('LVM_PLUGIN_URL', plugin_dir_url(__FILE__));

class Live_Visitor_Map
{
    private static $instance = null;

    public static function instance()
    {
        if (is_null(self::$instance))
            self::$instance = new self();
        return self::$instance;
    }

    private function __construct()
    {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('admin_head', [$this, 'admin_menu_icon_style']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_head', [$this, 'tracking_script_tag'], 0);
        add_action('init', [$this, 'tracking_endpoint']);
        add_action('wp_scheduled_delete', [$this, 'purge_old_data']);
    }

    public function activate()
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . LVM_VISITS_TABLE . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            city VARCHAR(100) NOT NULL DEFAULT '',
            country VARCHAR(100) NOT NULL DEFAULT '',
            country_code VARCHAR(5) NOT NULL DEFAULT '',
            latitude DECIMAL(10,8) NOT NULL DEFAULT 0,
            longitude DECIMAL(11,8) NOT NULL DEFAULT 0,
            user_agent TEXT,
            referrer TEXT,
            page_url TEXT,
            session_id VARCHAR(64) NOT NULL DEFAULT '',
            visit_time DATETIME NOT NULL,
            visit_date DATE NOT NULL,
            visit_hour TINYINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session (session_id),
            INDEX idx_date (visit_date),
            INDEX idx_hour (visit_hour),
            INDEX idx_time (visit_time)
        ) $charset;";

        $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . LVM_SESSIONS_TABLE . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(64) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            city VARCHAR(100) NOT NULL DEFAULT '',
            country VARCHAR(100) NOT NULL DEFAULT '',
            country_code VARCHAR(5) NOT NULL DEFAULT '',
            page_views INT UNSIGNED NOT NULL DEFAULT 1,
            first_visit DATETIME NOT NULL,
            last_visit DATETIME NOT NULL,
            session_duration INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            INDEX idx_session (session_id),
            INDEX idx_active (is_active),
            INDEX idx_last (last_visit)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql1);
        dbDelta($sql2);
    }

    public function register_routes()
    {
        register_rest_route('live-visitor-map/v1', '/track', [
            'methods' => 'POST',
            'callback' => [$this, 'track_visit'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('live-visitor-map/v1', '/heartbeat', [
            'methods' => 'POST',
            'callback' => [$this, 'heartbeat'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('live-visitor-map/v1', '/dashboard', [
            'methods' => 'GET',
            'callback' => [$this, 'dashboard_data'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    public function get_client_ip()
    {
        $ips = [];
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    public function anonymize_ip($ip)
    {
        if (!get_option('lvm_anonymize_ip', 0))
            return $ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            return preg_replace('/\.\d+$/', '.0', $ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
            return preg_replace('/:[0-9a-fA-F]+$/', ':0', $ip);
        return $ip;
    }

    public function geo_lookup($ip)
    {
        if ($ip === '0.0.0.0' || $ip === '127.0.0.1' || $ip === '::1') {
            return [
                'city' => 'Localhost',
                'country' => 'Localhost',
                'countryCode' => 'XX',
                'lat' => 0,
                'lon' => 0,
            ];
        }

        $cache_key = 'lvm_geo_' . md5($ip);
        $cached = get_transient($cache_key);
        if ($cached !== false)
            return $cached;

        $resp = wp_remote_get("http://ip-api.com/json/{$ip}?fields=city,country,countryCode,lat,lon", [
            'timeout' => 2,
        ]);

        if (is_wp_error($resp)) {
            return [
                'city' => 'Unknown',
                'country' => 'Unknown',
                'countryCode' => 'XX',
                'lat' => 0,
                'lon' => 0,
            ];
        }

        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (!$data || !empty($data['status']) && $data['status'] === 'fail') {
            return [
                'city' => 'Unknown',
                'country' => 'Unknown',
                'countryCode' => 'XX',
                'lat' => 0,
                'lon' => 0,
            ];
        }

        $result = [
            'city' => $data['city'] ?? 'Unknown',
            'country' => $data['country'] ?? 'Unknown',
            'countryCode' => $data['countryCode'] ?? 'XX',
            'lat' => $data['lat'] ?? 0,
            'lon' => $data['lon'] ?? 0,
        ];

        set_transient($cache_key, $result, DAY_IN_SECONDS);
        return $result;
    }

    public function track_visit($request)
    {
        $params = json_decode($request->get_body(), true);
        $ip = $this->get_client_ip();
        $session_id = !empty($params['session_id']) ? sanitize_text_field($params['session_id']) : md5($ip . time());
        $page_url = !empty($params['page_url']) ? esc_url_raw($params['page_url']) : '';
        $referrer = !empty($params['referrer']) ? esc_url_raw($params['referrer']) : '';
        $user_agent = !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';

        $this->log_visit($ip, $session_id, $page_url, $referrer, $user_agent);

        return ['success' => true];
    }

    public function heartbeat($request)
    {
        global $wpdb;

        $params = json_decode($request->get_body(), true);
        $session_id = !empty($params['session_id']) ? sanitize_text_field($params['session_id']) : '';

        if (!empty($session_id)) {
            $sessions_table = $wpdb->prefix . LVM_SESSIONS_TABLE;
            $now = current_time('mysql');
            $wpdb->update(
                $sessions_table,
                [
                    'last_visit' => $now,
                    'is_active' => 1,
                ],
                ['session_id' => $session_id]
            );
        }

        return ['success' => true];
    }

    public function dashboard_data()
    {
        global $wpdb;

        $table = $wpdb->prefix . LVM_VISITS_TABLE;
        $sessions_table = $wpdb->prefix . LVM_SESSIONS_TABLE;
        $today = current_time('Y-m-d');

        $two_min_ago = date('Y-m-d H:i:s', strtotime('-2 minutes'));
        $active_visitors = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM {$sessions_table} WHERE last_visit >= %s AND is_active = 1",
            $two_min_ago
        ));

        $today_visits = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE visit_date = %s",
            $today
        ));

        $today_unique = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT ip) FROM {$table} WHERE visit_date = %s",
            $today
        ));

        $today_sessions = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$sessions_table} WHERE DATE(first_visit) = %s",
            $today
        ));

        $hourly = $wpdb->get_results($wpdb->prepare(
            "SELECT visit_hour as hour, COUNT(*) as visits, COUNT(DISTINCT ip) as unique_visits
             FROM {$table} WHERE visit_date = %s
             GROUP BY visit_hour ORDER BY visit_hour ASC",
            $today
        ));

        $hourly_data = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly_data[$h] = ['visits' => 0, 'unique' => 0];
        }
        foreach ($hourly as $h) {
            $hourly_data[(int) $h->hour] = ['visits' => (int) $h->visits, 'unique' => (int) $h->unique_visits];
        }

        $thirty_days_ago = date('Y-m-d', strtotime('-30 days'));
        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT visit_date as date, COUNT(*) as visits, COUNT(DISTINCT ip) as unique_visits
             FROM {$table} WHERE visit_date >= %s
             GROUP BY visit_date ORDER BY visit_date ASC",
            $thirty_days_ago
        ));

        $countries_today = $wpdb->get_results($wpdb->prepare(
            "SELECT country, country_code, COUNT(*) as visits, COUNT(DISTINCT ip) as unique_visits
             FROM {$table} WHERE visit_date = %s
             GROUP BY country, country_code ORDER BY visits DESC LIMIT 20",
            $today
        ));

        $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
        $recent = $wpdb->get_results($wpdb->prepare(
            "SELECT ip, city, country, country_code, page_url, visit_time, session_id
             FROM {$table} WHERE visit_time >= %s ORDER BY visit_time DESC LIMIT 50",
            $seven_days_ago
        ));

        $avg_session = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(session_duration) FROM {$sessions_table} WHERE DATE(first_visit) = %s",
            $today
        ));

        $map_active = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT s.session_id, s.ip, s.city, s.country, s.country_code,
                    v.latitude, v.longitude, v.page_url, s.last_visit, s.page_views
             FROM {$sessions_table} s
             JOIN {$table} v ON v.session_id = s.session_id
             WHERE s.last_visit >= %s AND s.is_active = 1 AND v.latitude != 0
             GROUP BY s.session_id
             ORDER BY s.last_visit DESC LIMIT 50",
            $two_min_ago
        ));

        $twentyfour_ago = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $map_24h = $wpdb->get_results($wpdb->prepare(
            "SELECT v.ip, v.city, v.country, v.country_code,
                    v.latitude, v.longitude, v.page_url, v.visit_time, v.session_id
             FROM {$table} v
             WHERE v.visit_time >= %s AND v.latitude != 0
             ORDER BY v.visit_time DESC LIMIT 100",
            $twentyfour_ago
        ));

        $total_visits = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $total_unique = (int) $wpdb->get_var("SELECT COUNT(DISTINCT ip) FROM {$table}");

        return [
            'realtime' => [
                'active_visitors' => $active_visitors,
            ],
            'today' => [
                'visits' => $today_visits,
                'unique' => $today_unique,
                'sessions' => $today_sessions,
                'avg_session_duration' => round($avg_session),
            ],
            'hourly' => $hourly_data,
            'daily' => $daily,
            'countries' => $countries_today,
            'recent' => $recent,
            'map_active' => $map_active,
            'map_24h' => $map_24h,
            'total' => [
                'visits' => $total_visits,
                'unique' => $total_unique,
            ],
        ];
    }

    public function admin_menu()
    {
        add_menu_page(
            'Live Visitor Map',
            'Live Visitor Map',
            'manage_options',
            'live-visitor-map',
            [$this, 'render_dashboard'],
            LVM_PLUGIN_URL . 'assets/icon.svg',
            30
        );
        add_submenu_page(
            'live-visitor-map',
            'Settings',
            'Settings',
            'manage_options',
            'live-visitor-map-settings',
            [$this, 'render_settings']
        );
    }

    public function register_settings()
    {
        register_setting('lvm_settings', 'lvm_exclude_roles', [
            'type' => 'array',
            'default' => [],
            'sanitize_callback' => [$this, 'sanitize_exclude_roles'],
        ]);
        register_setting('lvm_settings', 'lvm_retention_days', [
            'type' => 'integer',
            'default' => 90,
            'sanitize_callback' => 'absint',
        ]);
        register_setting('lvm_settings', 'lvm_anonymize_ip', [
            'type' => 'boolean',
            'default' => 0,
            'sanitize_callback' => 'absint',
        ]);
    }

    public function sanitize_exclude_roles($input)
    {
        if (!is_array($input))
            return [];
        $allowed = array_keys(wp_roles()->roles);
        return array_intersect($input, $allowed);
    }

    public function render_settings()
    {
        if (!current_user_can('manage_options'))
            return;
?>
<div class="wrap" style="max-width:720px;margin:20px auto;color:#fff;">
    <h1><?php esc_html_e('Live Visitor Map Settings', 'live-visitor-map'); ?></h1>
    <form method="post" action="options.php" style="background:rgba(255,255,255,0.04);backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:32px;margin-top:24px;">
        <?php settings_fields('lvm_settings'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="lvm_exclude_roles"><?php esc_html_e('Exclude Roles', 'live-visitor-map'); ?></label></th>
                <td>
                    <select name="lvm_exclude_roles[]" id="lvm_exclude_roles" multiple style="width:100%;min-height:120px;background:#1e1e2e;color:#fff;border:1px solid rgba(255,255,255,0.12);border-radius:6px;padding:8px;">
                        <?php
                        $selected = get_option('lvm_exclude_roles', []);
                        foreach (wp_roles()->roles as $role => $info) {
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr($role),
                                in_array($role, $selected) ? 'selected' : '',
                                esc_html($info['name'])
                            );
                        }
                        ?>
                    </select>
                    <p class="description" style="color:rgba(255,255,255,0.5);margin-top:6px;"><?php esc_html_e('Users with these roles will not be tracked.', 'live-visitor-map'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lvm_retention_days"><?php esc_html_e('Data Retention', 'live-visitor-map'); ?></label></th>
                <td>
                    <input type="number" name="lvm_retention_days" id="lvm_retention_days" value="<?php echo esc_attr(get_option('lvm_retention_days', 90)); ?>" min="1" max="365" style="width:100px;background:#1e1e2e;color:#fff;border:1px solid rgba(255,255,255,0.12);border-radius:6px;padding:8px;">
                    <p class="description" style="color:rgba(255,255,255,0.5);margin-top:6px;"><?php esc_html_e('Auto-delete visits older than this many days (runs daily).', 'live-visitor-map'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Anonymize IPs', 'live-visitor-map'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="lvm_anonymize_ip" value="1" <?php checked(get_option('lvm_anonymize_ip', 0), 1); ?> style="accent-color:#4aff8f;">
                        <?php esc_html_e('Store anonymized IPs (last octet set to 0)', 'live-visitor-map'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php submit_button(__('Save Settings', 'live-visitor-map'), 'primary', 'submit', false, ['style' => 'background:linear-gradient(135deg,#7c5cfc,#5b3dc7);border:none;padding:10px 24px;border-radius:8px;cursor:pointer;color:#fff;font-weight:600;margin-top:16px;']); ?>
    </form>
</div>
<?php
    }

    public function admin_menu_icon_style()
    {
        echo '<style>#toplevel_page_live-visitor-map .wp-menu-image{padding:0!important}#toplevel_page_live-visitor-map .wp-menu-image img{padding:0!important;margin-right:5px!important;opacity:1!important}</style>';
    }

    public function admin_assets($hook)
    {
        if (empty($_GET['page']) || $_GET['page'] !== 'live-visitor-map')
            return;

        wp_enqueue_style('lvm-dashboard', LVM_PLUGIN_URL . 'css/dashboard.css', [], LVM_VERSION);
        wp_enqueue_style('lvm-font', 'https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&display=swap', [], null);
        wp_enqueue_style('lvm-leaflet', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css', [], '1.9.4');
        wp_enqueue_script('lvm-leaflet', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js', [], '1.9.4', true);
        add_action('admin_footer', function () {
            ?>
            <script>(function () { if (typeof L === 'undefined') { var s = document.createElement('script'); s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'; s.onload = function () { var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; document.head.appendChild(l); document.querySelectorAll('[data-lvm-map]').forEach(function (e) { e.style.display = 'block' }); }; document.body.appendChild(s); var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; document.head.appendChild(l); } })();</script><?php
        }, 1);
        wp_enqueue_script('lvm-chart', 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js', [], '4', true);
        wp_enqueue_script('lvm-dashboard', LVM_PLUGIN_URL . 'js/dashboard.js', ['lvm-leaflet', 'lvm-chart'], LVM_VERSION, true);
        $admin_ip = $this->get_client_ip();
        $admin_geo = $this->geo_lookup($admin_ip);
        wp_localize_script('lvm-dashboard', 'lvmData', [
            'rest_url' => rest_url('live-visitor-map/v1/dashboard'),
            'nonce' => wp_create_nonce('wp_rest'),
            'timezone' => wp_timezone_string(),
            'admin_ip' => $admin_ip,
            'admin_city' => $admin_geo['city'],
            'admin_country' => $admin_geo['country'],
            'admin_country_code' => $admin_geo['countryCode'],
            'admin_lat' => (string) $admin_geo['lat'],
            'admin_lon' => (string) $admin_geo['lon'],
        ]);
    }

    public function tracking_endpoint()
    {
        try {
            if (!empty($_GET['lvm_track'])) {
                $ip = $this->get_client_ip();
                $session_id = !empty($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : md5($ip . time());
                $page_url = !empty($_GET['url']) ? esc_url_raw($_GET['url']) : '';
                $referrer = !empty($_GET['ref']) ? esc_url_raw($_GET['ref']) : '';
                $user_agent = !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
                $this->log_visit($ip, $session_id, $page_url, $referrer, $user_agent);

                header('Content-Type: image/gif');
                header('Cache-Control: no-store, no-cache, must-revalidate');
                header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
                echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
                exit;
            }

            if (!empty($_GET['lvm_heartbeat'])) {
                global $wpdb;
                $session_id = !empty($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';
                if (!empty($session_id)) {
                    $now = current_time('mysql');
                    $sessions_table = $wpdb->prefix . LVM_SESSIONS_TABLE;
                    $wpdb->update($sessions_table, ['last_visit' => $now, 'is_active' => 1], ['session_id' => $session_id]);
                }
                header('Content-Type: image/gif');
                header('Cache-Control: no-store, no-cache, must-revalidate');
                echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
                exit;
            }
        } catch (Exception $e) {
        }
    }

    public function tracking_script_tag()
    {
        if (is_admin())
            return;
        $excluded = get_option('lvm_exclude_roles', []);
        if (!empty($excluded) && is_user_logged_in()) {
            $user = wp_get_current_user();
            $roles = (array) $user->roles;
            if (!empty(array_intersect($excluded, $roles)))
                return;
        }
        $url = LVM_PLUGIN_URL . 'js/track.js?ver=' . LVM_VERSION;
        $rest_url = rest_url('live-visitor-map/v1/');
        $pixel_url = add_query_arg('lvm_track', '1', trailingslashit(home_url()));
        echo '<script>window.lvmTracking={rest:"' . esc_url($rest_url) . '",pixel:"' . esc_url($pixel_url) . '"}</script>' . "\n";
        echo '<script src="' . esc_url($url) . '" defer></script>' . "\n";
    }

    public function log_visit($ip, $session_id, $page_url, $referrer, $user_agent)
    {
        try {
            global $wpdb;
            $ip = $this->anonymize_ip($ip);
            $geo = $this->geo_lookup($ip);
            $now = current_time('mysql');
            $date = current_time('Y-m-d');
            $hour = (int) current_time('H');

            $table = $wpdb->prefix . LVM_VISITS_TABLE;
            $wpdb->insert($table, [
                'ip' => $ip,
                'city' => $geo['city'],
                'country' => $geo['country'],
                'country_code' => $geo['countryCode'],
                'latitude' => $geo['lat'],
                'longitude' => $geo['lon'],
                'user_agent' => $user_agent,
                'referrer' => $referrer,
                'page_url' => $page_url,
                'session_id' => $session_id,
                'visit_time' => $now,
                'visit_date' => $date,
                'visit_hour' => $hour,
            ]);

            $sessions_table = $wpdb->prefix . LVM_SESSIONS_TABLE;
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, page_views FROM {$sessions_table} WHERE session_id = %s AND is_active = 1",
                $session_id
            ));

            if ($existing) {
                $wpdb->update(
                    $sessions_table,
                    [
                        'page_views' => $existing->page_views + 1,
                        'last_visit' => $now,
                        'session_duration' => strtotime($now) - strtotime($existing->first_visit),
                    ],
                    ['id' => $existing->id]
                );
            } else {
                $wpdb->insert($sessions_table, [
                    'session_id' => $session_id,
                    'ip' => $ip,
                    'city' => $geo['city'],
                    'country' => $geo['country'],
                    'country_code' => $geo['countryCode'],
                    'first_visit' => $now,
                    'last_visit' => $now,
                    'session_duration' => 0,
                    'is_active' => 1,
                ]);
            }
        } catch (Exception $e) {
        }
    }

    public function purge_old_data()
    {
        global $wpdb;
        $days = (int) get_option('lvm_retention_days', 90);
        $cutoff = date('Y-m-d', strtotime("-{$days} days"));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}" . LVM_VISITS_TABLE . " WHERE visit_date < %s",
            $cutoff
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}" . LVM_SESSIONS_TABLE . " WHERE DATE(last_visit) < %s",
            $cutoff
        ));
    }

    public function render_dashboard()
    {
        ?>
        <div class="wrap lvm-dashboard"
            style="background:radial-gradient(ellipse at 20% 50%, rgba(179,154,255,0.04) 0%, transparent 50%), radial-gradient(ellipse at 80% 20%, rgba(77,255,143,0.03) 0%, transparent 50%), #16171d;margin:-20px -20px 0 -22px;padding:20px 22px 40px;min-height:100vh;color:#fff;position:relative">
            <div class="lvm-mesh-bg"></div>
            <div class="lvm-dashboard-heading"><img src="<?php echo LVM_PLUGIN_URL; ?>assets/icon.png" alt=""
                    class="lvm-dashboard-icon">
                <h1>SM Live Visitor Map</h1>
            </div>
            <div id="lvm-root"></div>
        </div>
        <?php
    }
}

Live_Visitor_Map::instance();
