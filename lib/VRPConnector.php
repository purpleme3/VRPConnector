<?php

namespace Gueststream;

/**
 * VRPConnector Class
 */

class VRPConnector
{
    public $api;
    public $theme = "";                            // Full path to plugin theme folder
    public $themename = "";                        // Plugin theme name.
    public $default_theme_name = "mountainsunset"; // Default plugin theme name.
    public $available_themes = ['mountainsunset' => 'Mountain Sunset', 'oceanbreeze' => 'Ocean Breeze', 'relaxation' => 'Relaxation'];
    public $otheractions = [];                //
    public $time;                                  // Time (in seconds?) spent making calls to the API
    public $debug = [];                       // Container for debug data
    public $action = false; // VRP Action
    public $favorites;
    public $search;
    private $pagetitle;
    private $pagedescription;
    private $pluginNotification = ['type' => 'default', 'prettyType' => "",'message' => ""];

    /**
     * Class Construct
     */
    public function __construct()
    {
        $this->api = new VRPApi;
        if(!$this->api) {
            $this->pluginNotification('warning', 'Warning', 'To connect to the VRPc API, please provide a valid production key.');
        }

        $this->prepareData();
        $this->initializeActions();
        $this->initializeShortcodes();
        //Prepare theme...
        $this->setTheme();
        $this->initializeThemeActions();
    }

    /* Plugin security, initialization, helper & notification methods */

    /**
     * Generates the admin automatic login url.
     *
     * @param $email
     * @param $password
     *
     * @return array|mixed
     */
    public function doLogin($email, $password)
    {
        $url = $this->api->apiURL . $this->api->apiKey() . "/userlogin/?email=$email&password=$password";

        return json_decode(file_get_contents($url));
    }

    public function initializeShortcodes()
    {
        $shortcodes = new VRPShortCodes;
        add_filter('widget_text', 'do_shortcode');
    }

    /**
     * init WordPress Actions, Filters & shortcodes
     */
    public function initializeActions()
    {
        if (is_admin()) {
            add_action('admin_menu', [$this, 'setupPage']);
            add_action('admin_init', [$this, 'registerSettings']);
            add_filter('plugin_action_links',[$this, 'add_action_links'], 10, 2);
        }

        // Actions
        add_action('init', [$this, 'ajax']);
        add_action('init', [$this, 'sitemap']);
        add_action('init', [$this->api, 'featuredunit']);
        add_action('init', [$this, 'otheractions']);
        add_action('init', [$this, 'rewrite']);
        add_action('init', [$this, 'villafilter']);
        add_action('parse_request', [$this, 'router']);
        add_action('update_option_vrpApiKey', [$this, 'flush_rewrites'], 10, 2);
        add_action('update_option_vrpAPI', [$this, 'flush_rewrites'], 10, 2);
        add_action('wp', [$this, 'remove_filters']);
        add_action('pre_get_posts', [$this, 'query_template']);

        // Filters
        add_filter('robots_txt', [$this, 'robots_mod'], 10, 2);
        remove_filter('template_redirect', 'redirect_canonical');
    }

    /**
     * Validates nonce token for wordpress security
     *
     * @return bool
     */
    private function validateNonce()
    {
        if (
            ! isset($_GET['vrpUpdateSection'])
            || ! isset( $_POST['nonceField'] )
            || ! wp_verify_nonce( $_POST['nonceField'], $_GET['vrpUpdateSection'] )
        ) {
            $this->preparePluginNotification('warning', 'Warning', 'Your none token did not verify.');
            return false;
        }

        return true;
    }

    /**
     * Sets plugin notification
     *
     * @param $type
     * @param $prettyType
     * @param $message
     *
     * @return bool
     */
    private function preparePluginNotification($type, $prettyType, $message)
    {

        return $this->pluginNotification = [
            'type' => $type,
            'prettyType' => $prettyType,
            'message' => $message
        ];

    }

    /**
     * Alters WP_Query to tell it to load the page template instead of home.
     *
     * @param WP_Query $query
     *
     * @return WP_Query
     */
    public function query_template($query)
    {
        if (!isset($query->query_vars['action'])) {
            return $query;
        }
        $query->is_page = true;
        $query->is_home = false;

        return $query;
    }

    public function otheractions()
    {
        if (isset($_GET['otherslug']) && $_GET['otherslug'] != '') {
            $theme = $this->themename;
            $theme = new $theme;
            $func = $theme->otheractions;
            $func2 = $func[$_GET['otherslug']];
            call_user_method($func2, $theme);
        }
    }

    /**
     * Uses built-in rewrite rules to get pretty URL. (/vrp/)
     */
    public function rewrite()
    {
        add_rewrite_tag('%action%', '([^&]+)');
        add_rewrite_tag('%slug%', '([^&]+)');
        add_rewrite_rule('^vrp/([^/]*)/([^/]*)/?', 'index.php?action=$matches[1]&slug=$matches[2]', 'top');

    }

    /**
     * Only on activation.
     */
    static function rewrite_activate()
    {
        add_rewrite_tag('%action%', '([^&]+)');
        add_rewrite_tag('%slug%', '([^&]+)');
        add_rewrite_rule('^vrp/([^/]*)/([^/]*)/?', 'index.php?action=$matches[1]&slug=$matches[2]', 'top');

    }

    function flush_rewrites($old, $new)
    {
        flush_rewrite_rules();
    }

    /**
     * Sets up action and slug as query variable.
     *
     * @param $vars [] $vars Query String Variables.
     *
     * @return $vars[]
     */
    public function query_vars($vars)
    {
        array_push($vars, 'action', 'slug', 'other');

        return $vars;
    }

    /**
     * Checks to see if VRP slug is active, if so, sets up a page.
     *
     * @return bool
     */
    public function router($query)
    {

        if (!isset($query->query_vars['action'])) {
            return false;
        }
        if ($query->query_vars['action'] == 'xml') {
            $this->xmlexport();
        }

        if ($query->query_vars['action'] == 'flipkey') {
            $this->getflipkey();
        }
        add_filter('the_posts', [$this, "filterPosts"], 1, 2);
    }

    /**
     * @param $posts
     *
     * @return array
     */
    public function filterPosts($posts, $query)
    {
        if (!isset($query->query_vars['action'])) {
            return false;
        }

        $content = "";
        $pagetitle = "";
        $pagedescription = "";
        $action = $query->query_vars['action'];
        $slug = $query->query_vars['slug'];

        switch ($action) {
            case "unit":
                $data2 = $this->api->call("getunit/" . $slug);
                $data = json_decode($data2);

                if (isset($data->SEOTitle)) {
                    $pagetitle = $data->SEOTitle;
                } else {
                    $pagetitle = $data->Name;
                }

                $pagedescription = $data->SEODescription;

                if (!isset($data->id)) {
                    global $wp_query;
                    $wp_query->is_404 = true;
                }

                if (isset($data->Error)) {
                    $content = $this->loadTheme("error", $data);
                } else {
                    $content = $this->loadTheme("unit", $data);
                }


                break;

            case "complex": // If Complex Page.
                $data = json_decode($this->api->call("getcomplex/" . $slug));

                if (isset($data->Error)) {
                    $content = $this->loadTheme("error", $data);
                } else {
                    $content = $this->loadTheme("complex", $data);
                }
                $pagetitle = $data->name;

                break;

            case "favorites":
                $content = "hi";
                switch ($slug) {
                    case "add":
                        $this->addFavorite();
                        break;
                    case "remove":
                        $this->removeFavorite();
                        break;
                    case "json":
                        echo json_encode($this->favorites);
                        exit;
                        break;
                    default:
                        $content = $this->showFavorites();
                        $pagetitle = "Favorites";
                        break;
                }
                break;

            case "specials": // If Special Page.
                $content = $this->specialPage($slug);
                $pagetitle = $this->pagetitle; //
                break;

            case "search": // If Search Page.
                $data = json_decode($this->search());

                if ($data->count > 0) {
                    $data = $this->prepareSearchResults($data);
                }

                if (isset($_GET['json'])) {
                    echo json_encode($data, JSON_PRETTY_PRINT);
                    exit;
                }

                if (isset($data->type)) {
                    $content = $this->loadTheme($data->type, $data);
                } else {
                    $content = $this->loadTheme("results", $data);
                }

                $pagetitle = "Search Results";
                break;

            case "complexsearch": // If Search Page.
                $data = json_decode($this->complexsearch());
                if (isset($data->type)) {
                    $content = $this->loadTheme($data->type, $data);
                } else {
                    $content = $this->loadTheme("complexresults", $data);
                }
                $pagetitle = "Search Results";
                break;

            case "book":
                if ($slug == 'dobooking') {
                    if (isset($_SESSION['package'])) {
                        $_POST['booking']['packages'] = $_SESSION['package'];
                    }
                }

                if (isset($_POST['email'])) {
                    $userinfo = $this->doLogin($_POST['email'], $_POST['password']);
                    $_SESSION['userinfo'] = $userinfo;
                    if (!isset($userinfo->Error)) {
                        $query->query_vars['slug'] = "step3";
                    }
                }

                if (isset($_POST['booking'])) {
                    $_SESSION['userinfo'] = $_POST['booking'];
                }

                $data = json_decode($_SESSION['bookingresults']);
                if ($data->ID != $_GET['obj']['PropID']) {
                    $data = json_decode($this->checkavailability(false, true));
                    $data->new = true;
                }

                if ($slug != 'confirm') {
                    $data = json_decode($this->checkavailability(false, true));
                    $data->new = true;
                }

                $data->PropID = $_GET['obj']['PropID'];
                $data->booksettings = $this->bookSettings($data->PropID);

                if ($slug == 'step1') {
                    unset($_SESSION['package']);
                }

                $data->package = new \stdClass;
                $data->package->packagecost = "0.00";
                $data->package->items = [];

                if (isset($_SESSION['package'])) {
                    $data->package = $_SESSION['package'];
                }

                if ($slug == 'step1a') {
                    if (isset($data->booksettings->HasPackages)) {
                        $a = date("Y-m-d", strtotime($data->Arrival));
                        $d = date("Y-m-d", strtotime($data->Departure));
                        $data->packages = json_decode($this->api->call("getpackages/$a/$d/"));
                    } else {
                        $query->query_vars['slug'] = 'step2';
                    }
                }

                if ($slug == 'step3') {
                    $data->form = json_decode($this->api->call("bookingform/"));
                }

                if ($slug == 'confirm') {
                    $data->thebooking = json_decode($_SESSION['bresults']);
                    $pagetitle = "Reservations";
                    $content = $this->loadTheme("confirm", $data);
                } else {
                    $pagetitle = "Reservations";
                    $content = $this->loadTheme("booking", $data);
                }
                break;

            case "xml":
                $content = "";
                $pagetitle = "";
                break;
        }

        return [new DummyResult(0, $pagetitle, $content, $pagedescription)];
    }

    private function specialPage($slug)
    {
        if ($slug == "list") {
            // Special by Category
            $data = json_decode($this->api->call("getspecialsbycat/1"));
            $this->pagetitle = "Specials";

            return $this->loadTheme("specials", $data);
        }

        if (is_numeric($slug)) {
            // Special by ID
            $data = json_decode($this->api->call("getspecialbyid/" . $slug));
            $this->pagetitle = $data->title;

            return $this->loadTheme("special", $data);
        }

        if (is_string($slug)) {
            // Special by slug
            $data = json_decode($this->api->call("getspecial/" . $slug));
            $this->pagetitle = $data->title;

            return $this->loadTheme("special", $data);
        }
    }

    public function villafilter()
    {
        if (!$this->is_vrp_page()) {
            return;
        }

        if ('complexsearch' == $this->action) {
            if ($_GET['search']['type'] == 'Villa') {
                $this->action = 'search';
                global $wp_query;
                $wp_query->query_vars['action'] = $this->action;
            }
        }
    }

    public function searchjax()
    {
        if (isset($_GET['search']['arrival'])) {
            $_SESSION['arrival'] = $_GET['search']['arrival'];
        }

        if (isset($_GET['search']['departure'])) {
            $_SESSION['depart'] = $_GET['search']['departure'];
        }

        ob_start();
        $results = json_decode($this->search());

        $units = $results->results;

        include TEMPLATEPATH . "/vrp/unitsresults.php";
        $content = ob_get_contents();
        ob_end_clean();
        echo wp_kses_post($content);
    }

    public function search()
    {
        $obj = new \stdClass();

        foreach ($_GET['search'] as $k => $v) {
            $obj->$k = $v;
        }

        if (isset($_GET['page'])) {
            $obj->page = (int) $_GET['page'];
        } else {
            $obj->page = 1;
        }

        if (!isset($obj->limit)) {
            $obj->limit = 10;
            if (isset($_GET['show'])) {
                $obj->limit = (int) $_GET['show'];
            }
        }

        if (isset($obj->arrival)) {
            if ($obj->arrival == 'Not Sure') {
                $obj->arrival = '';
                $obj->depart = '';
            } else {
                $obj->arrival = date("m/d/Y", strtotime($obj->arrival));
            }
        }

        $search['search'] = json_encode($obj);

        if (isset($_GET['specialsearch'])) {
            // This might only be used by suite-paradise.com but is available
            // To all ISILink based PMS softwares.
            return $this->api->call('specialsearch', $search);
        }

        return $this->api->call('search', $search);
    }

    public function complexsearch()
    {
        $url = $this->api->apiURL . $this->api->apiKey() . "/complexsearch3/";

        $obj = new \stdClass();
        foreach ($_GET['search'] as $k => $v) {
            $obj->$k = $v;
        }
        if (isset($_GET['page'])) {
            $obj->page = (int) $_GET['page'];
        } else {
            $obj->page = 1;
        }
        if (isset($_GET['show'])) {
            $obj->limit = (int) $_GET['show'];
        } else {
            $obj->limit = 10;
        }
        if ($obj->arrival == 'Not Sure') {
            $obj->arrival = '';
            $obj->depart = '';
        }

        $search['search'] = json_encode($obj);
        $results = $this->api->call('complexsearch3', $search);

        return $results;
    }

    public function ajax()
    {
        if (!isset($_GET['vrpjax'])) {
            return false;
        }
        $act = $_GET['act'];
        $par = $_GET['par'];
        if (method_exists($this, $act)) {
            $this->$act($par);
        }
        exit;
    }

    public function checkavailability($par = false, $ret = false)
    {
        set_time_limit(50);

        $fields_string = "obj=" . json_encode($_GET['obj']);
        $results = $this->api->call('checkavail', $fields_string);

        if ($ret == true) {
            $_SESSION['bookingresults'] = $results;

            return $results;
        }

        if ($par != false) {
            $_SESSION['bookingresults'] = $results;
            echo wp_kses_post($results);

            return false;
        }

        $res = json_decode($results);

        if (isset($res->Error)) {
            echo esc_html($res->Error);
        } else {
            $_SESSION['bookingresults'] = $results;
            echo "1";
        }
    }

    public function processbooking($par = false, $ret = false)
    {
        if (isset($_POST['booking']['comments'])) {
            $_POST['booking']['comments'] = urlencode($_POST['booking']['comments']);
        }

        $fields_string = "obj=" . json_encode($_POST['booking']);
        $results = $this->api->call('processbooking', $fields_string);
        $res = json_decode($results);
        if (isset($res->Results)) {
            $_SESSION['bresults'] = json_encode($res->Results);
        }
        echo wp_kses_post($results);
    }

    public function addtopackage()
    {
        $TotalCost = $_GET['TotalCost'];
        if (!isset($_GET['package'])) {
            unset($_SESSION['package']);
            $obj = new \stdClass();
            $obj->packagecost = "$0.00";

            $obj->TotalCost = "$" . number_format($TotalCost, 2);
            echo json_encode($obj);

            return false;
        }

        $currentpackage = new \stdClass();
        $currentpackage->items = [];
        $grandtotal = 0;
        // ID & QTY
        $package = $_GET['package'];
        $qty = $_GET['qty'];
        $cost = $_GET['cost'];
        $name = $_GET['name'];
        foreach ($package as $v):
            $amount = $qty[$v]; // Qty of item.
            $obj = new \stdClass();
            $obj->name = $name[$v];
            $obj->qty = $amount;
            $obj->total = $cost[$v] * $amount;
            $grandtotal = $grandtotal + $obj->total;
            $currentpackage->items[$v] = $obj;
        endforeach;

        $TotalCost = $TotalCost + $grandtotal;
        $obj = new \stdClass();
        $obj->packagecost = "$" . number_format($grandtotal, 2);

        $obj->TotalCost = "$" . number_format($TotalCost, 2);
        echo json_encode($obj);
        $currentpackage->packagecost = $grandtotal;
        $currentpackage->TotalCost = $TotalCost;
        $_SESSION['package'] = $currentpackage;
    }

    public function getspecial()
    {
        return json_decode($this->api->call("getonespecial"));
    }

    public function getTheSpecial($id)
    {
        $data = json_decode($this->api->call("getspecialbyid/" . $id));

        return $data;
    }

    public function sitemap()
    {
        if (!isset($_GET['vrpsitemap'])) {
            return false;
        }
        $data = json_decode($this->api->call("allvrppages"));
        ob_start();
        include "xml.php";
        $content = ob_get_contents();
        ob_end_clean();
        echo wp_kses_post($content);
        exit;
    }

    public function xmlexport()
    {
        header("Content-type: text/xml");
        echo wp_kses($this->customcall("generatexml"));
        exit;
    }

    //
    // Wordpress Filters
    //

    public function robots_mod($output, $public)
    {
        $siteurl = get_option("siteurl");
        $output .= "Sitemap: " . $siteurl . "/?vrpsitemap=1 \n";

        return $output;
    }

    public function add_action_links($links, $file)
    {
        if( $file == 'vrpconnector/VRPConnector.php' && function_exists( "admin_url" ) ) {
            $settings_link = '<a href="' . admin_url( 'options-general.php?page=VRPConnector' ) . '">' . __('Settings') . '</a>';
            array_unshift( $links, $settings_link ); // before other links
        }
        return $links;
    }

    //
    //  VRP Favorites/Compare
    //

    private function addFavorite()
    {
        if (!isset($_GET['unit'])) {
            return false;
        }

        if (!isset($_SESSION['favorites'])) {
            $_SESSION['favorites'] = [];
        }

        $unit_id = $_GET['unit'];
        if (!in_array($unit_id, $_SESSION['favorites'])) {
            array_push($_SESSION['favorites'], $unit_id);
        }

        exit;
    }

    private function removeFavorite()
    {
        if (!isset($_GET['unit'])) {
            return false;
        }
        if (!isset($_SESSION['favorites'])) {
            return false;
        }
        $unit = $_GET['unit'];
        foreach ($this->favorites as $key => $unit_id) {
            if ($unit == $unit_id) {
                unset($this->favorites[$key]);
            }
        }
        $_SESSION['favorites'] = $this->favorites;
        exit;
    }

    public function savecompare()
    {
        $obj = new \stdClass();
        $obj->compare = $_SESSION['compare'];
        $obj->arrival = $_SESSION['arrival'];
        $obj->depart = $_SESSION['depart'];
        $search['search'] = json_encode($obj);
        $results = $this->api->call('savecompare', $search);

        return $results;
    }

    public function showFavorites()
    {
        if (isset($_GET['shared'])) {
            $_SESSION['cp'] = 1;
            $id = (int) $_GET['shared'];
            $source = "";
            if (isset($_GET['source'])) {
                $source = $_GET['source'];
            }
            $data = json_decode($this->api->call("getshared/" . $id . "/" . $source));
            $_SESSION['compare'] = $data->compare;
            $_SESSION['arrival'] = $data->arrival;
            $_SESSION['depart'] = $data->depart;
        }

        $obj = new \stdClass();

        if (!isset($_GET['favorites'])) {
            if (count($this->favorites) == 0) {
                return $this->loadTheme('vrpFavoritesEmpty');
            }

            $url_string = site_url() . "/vrp/favorites/show?";
            foreach ($this->favorites as $unit_id) {
                $url_string .= "&favorites[]=" . $unit_id;
            }
            header("Location: " . $url_string);
        }

        $compare = $_GET['favorites'];
        $_SESSION['favorites'] = $compare;

        if (isset($_GET['arrival'])) {
            $obj->arrival = $_GET['arrival'];
            $obj->departure = $_GET['depart'];
            $_SESSION['arrival'] = $obj->arrival;
            $_SESSION['depart'] = $obj->departure;
        } else {
            if (isset($_SESSION['arrival'])) {
                $obj->arrival = $_SESSION['arrival'];
                $obj->departure = $_SESSION['depart'];
            }
        }

        $obj->items = $compare;
        sort($obj->items);
        $search['search'] = json_encode($obj);
        $results = json_decode($this->api->call('compare', $search));
        if (count($results->results) == 0) {
            return $this->loadTheme('vrpFavoritesEmpty');
        }

        $results = $this->prepareSearchResults($results);

        return $this->loadTheme('vrpFavorites', $results);
    }

    private function setFavorites()
    {
        if (isset($_SESSION['favorites'])) {
            foreach ($_SESSION['favorites'] as $unit_id) {
                $this->favorites[] = (int) $unit_id;
            }

            return;
        }

        $this->favorites = [];

        return;
    }


    //
    //  Wordpress Admin Methods
    //


    /**
     * Checks to see if the page loaded is a VRP page.
     * Formally $_GET['action'].
     * @global WP_Query $wp_query
     * @return bool
     */
    public function is_vrp_page()
    {
        global $wp_query;
        if (isset($wp_query->query_vars['action'])) { // Is VRP page.
            $this->action = $wp_query->query_vars['action'];

            return true;
        }

        return false;
    }

    public function remove_filters()
    {
        if ($this->is_vrp_page()) {
            remove_filter('the_content', 'wptexturize');
            remove_filter('the_content', 'wpautop');
        }
    }

    /* VRPConnector Plugin Data Processing Methods
     *
     *
     */


    private function prepareData()
    {
        $this->setFavorites();
        $this->prepareSearchData();
    }

    private function prepareSearchResults($data)
    {
        foreach ($data->results as $key => $unit) {
            if (strlen($unit->Thumb) == 0) {
                // Replacing non-existent thumbnails w/full size Photo URL
                $unit->Thumb = $unit->Photo;
            }
            $data->results[$key] = $unit;
        }

        return $data;
    }

    private function prepareSearchData()
    {
        $this->search = new \stdClass();

        // Arrival
        if (isset($_GET['search']['arrival'])) {
            $_SESSION['arrival'] = $_GET['search']['arrival'];
        }

        if (isset($_SESSION['arrival'])) {
            $this->search->arrival = date('m/d/Y', strtotime($_SESSION['arrival']));
        } else {
            $this->search->arrival = date('m/d/Y', strtotime("+1 Days"));
        }

        // Departure
        if (isset($_GET['search']['departure'])) {
            $_SESSION['depart'] = $_GET['search']['departure'];
        }

        if (isset($_SESSION['depart'])) {
            $this->search->depart = date('m/d/Y', strtotime($_SESSION['depart']));
        } else {
            $this->search->depart = date('m/d/Y', strtotime("+4 Days"));
        }

        // Nights
        if (isset($_GET['search']['nights'])) {
            $_SESSION['nights'] = $_GET['search']['nights'];
        }

        if (isset($_SESSION['nights'])) {
            $this->search->nights = $_SESSION['nights'];
        } else {
            $this->search->nights = (strtotime($this->search->depart) - strtotime($this->search->arrival)) / 60 / 60 / 24;
        }

        $this->search->type = "";
        if (isset($_GET['search']['type'])) {
            $_SESSION['type'] = $_GET['search']['type'];
        }

        if (isset($_SESSION['type'])) {
            $this->search->type = $_SESSION['type'];
            $this->search->complex = $_SESSION['type'];
        }

        // Sleeps
        $this->search->sleeps = "";
        if (isset($_GET['search']['sleeps'])) {
            $_SESSION['sleeps'] = $_GET['search']['sleeps'];
        }

        if (isset($_SESSION['sleeps'])) {
            $this->search->sleeps = $_SESSION['sleeps'];
        } else {
            $this->search->sleeps = false;
        }

        // Location
        $this->search->location = "";
        if (isset($_GET['search']['location'])) {
            $_SESSION['location'] = $_GET['search']['location'];
        }

        if (isset($_SESSION['location'])) {
            $this->search->location = $_SESSION['location'];
        } else {
            $this->search->location = false;
        }

        // Bedrooms
        $this->search->bedrooms = "";
        if (isset($_GET['search']['bedrooms'])) {
            $_SESSION['bedrooms'] = $_GET['search']['bedrooms'];
        }

        if (isset($_SESSION['bedrooms'])) {
            $this->search->bedrooms = $_SESSION['bedrooms'];
        } else {
            $this->search->bedrooms = false;
        }

        // Adults
        if (isset($_GET['search']['adults'])) {
            $_SESSION['adults'] = (int) $_GET['search']['adults'];
        }

        if (isset($_GET['obj']['Adults'])) {
            $_SESSION['adults'] = (int) $_GET['obj']['Adults'];
        }

        if (isset($_SESSION['adults'])) {
            $this->search->adults = $_SESSION['adults'];
        } else {
            $this->search->adults = 2;
        }

        // Children
        if (isset($_GET['search']['children'])) {
            $_SESSION['children'] = $_GET['search']['children'];
        }

        if (isset($_SESSION['children'])) {
            $this->search->children = $_SESSION['children'];
        } else {
            $this->search->children = 0;
        }

    }


    /* VRPConnector Plugin Administration Methods */

    /** @TODO: remove, depreciated?
     * Displays the 'VRP Login' admin page.
     */
//    public function loadVRP()
//    {
//        include VRP_PATH . 'views/login.php';
//    }

    /**
     * Admin nav menu items
     */
    public function setupPage()
    {
        add_options_page(
            "Settings Admin",
            'VRPConnector',
            'activate_plugins',
            "VRPConnector",
            [$this, 'settingsPage']
        );
    }

    public function registerSettings()
    {
        register_setting('VRPConnector', 'vrpAPI');
        register_setting('VRPConnector', 'vrpTheme');
        register_setting('VRPConnector', 'vrpPluginMode');
    }

    /**
     * Displays the 'VRP API Code Entry' admin page
     */
    public function settingsPage()
    {
        if(!empty($_POST) && $this->validateNonce() !== false) {
            $this->processVRPAPIUpdates();
            $this->processVRPThemeUpdates();
        }

        wp_enqueue_script('vrp-bootstrap-js', plugins_url('vrpconnector/resources/bower/bootstrap/dist/js/bootstrap.min.js'), false, null, false);
        wp_enqueue_script('vrp-bootstrap-fix', plugins_url('vrpconnector/resources/js/bootstrap-fix.js'), false, null, false);
        wp_enqueue_script('vrp-settings-js', plugins_url('vrpconnector/resources/js/settings.js'), false, null, false);
        include VRP_PATH . 'views/settings.php';
    }

    /**
     * Checks if VRP Theme settings are being updated
     *
     * @return bool
     */
    private function processVRPThemeUpdates()
    {
        if(
        isset($_POST['vrpTheme'])
        ) {
            if(!in_array($_POST['vrpTheme'], array_keys($this->available_themes))) {
                $this->preparePluginNotification('danger', 'Error', 'The theme you\'ve selected is not available!');
                return false;
            }

            update_option('vrpTheme', $_POST['vrpTheme']);
            $this->preparePluginNotification('success', 'Success', 'Your settings have been updated!');
            $this->themename = $_POST['vrpTheme'];
            return true;
        }

        return false;
    }

    /**
     * Checks if VRP API credentials are being updated
     *
     * @return bool
     */
    private function processVRPAPIUpdates()
    {
        if(
            isset($_POST['vrpAPI']) && isset($_POST['vrpPluginMode'])
        ) {

            update_option('vrpPluginMode', trim($_POST['vrpPluginMode']));
            update_option('vrpAPI', trim($_POST['vrpAPI']));
            $this->api->setAPIKey(trim($_POST['vrpAPI']));
            $this->preparePluginNotification('success', 'Success', 'Your settings have been updated!');

            return true;
        }
        return false;
    }

    /* VRPConnector Plugin magic methods
     *
     *
     */

    /**
     * Class Destruct w/basic debugging.
     */
    public function __destruct()
    {
        if (!isset($_GET['showdebug'])) {
            return false;
        }

        if (!$this->is_vrp_page()) {
            return false;
        }

        echo "<div style='position:absolute;left:0;width:100%;background:white;color:black;'>";
        echo "API Time Spent: " . esc_html($this->time) . "<br/>";
        echo "GET VARIABLES:<br><pre>";
        print_r($_GET);
        echo "</pre>";
        echo "Debug VARIABLES:<br><pre>";
        print_r($this->debug);
        echo "</pre>";
        echo "Post Type: " . esc_html($wp->query_vars["post_type"]);
        echo "</div>";
    }

}
