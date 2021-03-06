<?php
/**
 * Classic Editor
 *
 * Plugin Name: MKM API
 * Plugin URI:  https://wordpress.org
 * Version:     1.1.1
 * Description: The plugin receives data MKM API
 * Author:      Dmitriy Kovalev
 * Author URI:  https://www.upwork.com/freelancers/~014907274b0c121eb9
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Domain Path: /languages
 *
 */

    /**
     * Wordpress hooks, to which the functions of the plugin are attached, for proper operation
     */
    register_activation_hook( __FILE__, 'mkm_api_create_table_orders' );
    register_activation_hook( __FILE__, 'mkm_api_create_table_account' );
    register_activation_hook( __FILE__, 'mkm_api_create_table_article' );
    register_activation_hook( __FILE__, 'mkm_api_activation' );
    register_deactivation_hook( __FILE__, 'mkm_api_deactivation' );
    add_action( 'admin_menu', 'mkm_api_admin_menu' );
    add_action( 'admin_init', 'mkm_api_admin_settings' );
    add_action( 'wp_ajax_mkm_api_delete_key', 'mkm_api_ajax_delete_key' );
    add_action( 'wp_ajax_mkm_api_ajax_data', 'mkm_api_ajax_get_data' );
    add_action( 'wp_ajax_mkm_api_change_cron_select', 'mkm_api_ajax_change_cron_select' );
    add_action( 'wp_ajax_mkm_api_ajax_get_orders', 'mkm_api_ajax_get_orders' );
    add_action( 'wp_ajax_mkm_api_checkup', 'mkm_api_ajax_checkup' );
    add_action( 'wp_ajax_mkm_api_ajax_update_orders', 'mkm_api_ajax_update_orders' );
    add_action( 'wp_ajax_mkm_api_ajax_more_articles', 'mkm_api_ajax_more_articles' );
    add_action( 'admin_enqueue_scripts', 'mkm_api_enqueue_admin' );
    add_action( 'admin_print_footer_scripts-toplevel_page_mkm-api-options', 'mkm_api_modal_to_footer' );
    add_filter( 'cron_schedules', 'mkm_api_add_schedules', 20 );

    /**
     * Plugin global variables
     */
    $mkmApiBaseUrl = 'https://api.cardmarket.com/ws/v2.0/orders/1/';
    $mkmApiStates  = array(
        'evaluated' => 'Evaluated',
        'bought'    => 'Bought',
        'paid'      => 'Paid',
        'sent'      => 'Sent',
        'received'  => 'Received',
        'lost'      => 'Lost',
        'cancelled' => 'Cancelled'
    );

    /**
     * @return string
     * Custom screen output function for checking
     */
    if ( !function_exists( 'dump' ) ) {
		function dump( $var ) {
			echo '<pre style="color: #c3c3c3; background-color: #282923;">';
			print_r( $var );
			echo '</pre>';
		}
    }

    /**
     * @return string
     * Replacing an empty date value for display
     */
    function mkm_api_null_date( $date ) {
        if ( $date == '1970-01-01 00:00:00' ) return '---- -- --';

        return $date;
    }

    /**
     * @return void
     * Removing cron jobs when deactivation a plugin
     */
    function mkm_api_deactivation() {
        $options = get_option( 'mkm_api_options' );
        if ( is_array( $options ) && count( $options ) > 0 ) {
            foreach( $options as $key => $value ) {
                if ( wp_next_scheduled( 'mkm_api_cron_' . $key, array( array( 'key' => $key ) ) ) ) {
                    wp_clear_scheduled_hook( 'mkm_api_cron_' . $key, array( array( 'key' => $key ) ) );
                }

                if ( wp_next_scheduled( 'mkm_api_cron_check_' . $key, array( array( 'key' => $key ) ) ) ) {
                    wp_clear_scheduled_hook( 'mkm_api_cron_check_' . $key, array( array( 'key' => $key ) ) );
                }
            }
        }
    }

    /**
     * @return void
     * Connecting cron jobs when activating the plugin
     */
    function mkm_api_activation() {
        $options = get_option( 'mkm_api_options' );
        if ( is_array( $options ) && count( $options ) > 0 ) {
            foreach( $options as $key => $value ) {
                if ( !wp_next_scheduled( 'mkm_api_cron_' . $key, array( array( 'key' => $key ) ) ) ) {
                    wp_schedule_event( time(), $value['cron'], 'mkm_api_cron_' . $key, array( array( 'key' => $key ) ) );
                }
                
                if ( (bool)$value['checks']['account'] || (bool)$value['checks']['articles'] ) {
                    if ( !wp_next_scheduled( 'mkm_api_cron_check_' . $key, array( array( 'key' => $key ) ) ) ) {
                        wp_schedule_event( time(), 'daily', 'mkm_api_cron_check_' . $key, array( array( 'key' => $key ) ) );
                    }
                }
            }
        } else {
            update_option( 'mkm_api_options', array() );
        }
    }

    /**
     * @return void
     * Displays a modal window for the progress bar.
     */
    function mkm_api_modal_to_footer() {

        ?>
            <div id="content-for-modal">
                <div class="mkm-api-progress-bar">
                    <span class="mkm-api-progress" style="width: 30%"></span>
                    <span class="proc">30%</span>
                </div>
            </div>
        <?php
    }

    /**
     * @return void
     * Connecting CSS and JS files (custom and WP)
     */
    function mkm_api_enqueue_admin() {
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_script( 'mkm-api-admin', plugins_url( 'js/admin_scripts.js', __FILE__ ) );
        wp_enqueue_style( 'jqueryui', '//ajax.googleapis.com/ajax/libs/jqueryui/1.11.4/themes/smoothness/jquery-ui.css', false, null );
        wp_enqueue_style( 'mkm-api-admin', plugins_url( 'css/admin_style.css', __FILE__ ) );
    }

    /**
     * @return void
     * Creating a data table for orders when activating the plugin
     */
    function mkm_api_create_table_orders() {
        global $wpdb;

        $query = "CREATE TABLE IF NOT EXISTS `mkm_api_orders` (
            `id` INT(11) unsigned NOT NULL AUTO_INCREMENT,
            `id_order` INT(10) NOT NULL,
            `states` VARCHAR(50) NOT NULL,
            `date_bought` DATETIME,
            `date_paid` DATETIME,
            `date_sent` DATETIME,
            `date_received` DATETIME,
            `price` VARCHAR(50) NOT NULL,
            `is_insured` BOOLEAN NOT NULL,
            `city` VARCHAR(255) NOT NULL,
            `country` VARCHAR(255) NOT NULL,
            `article_count` INT(5) NOT NULL,
            `evaluation_grade` VARCHAR(255) NOT NULL,
            `item_description` VARCHAR(255) NOT NULL,
            `packaging` VARCHAR(255) NOT NULL,
            `article_value` VARCHAR(255) NOT NULL,
            `total_value` VARCHAR(255) NOT NULL,
            `appname` VARCHAR(50) NOT NULL,
            PRIMARY KEY (`id`)) CHARSET=utf8;";

        $wpdb->query($query);
    }

    /**
     * @return void
     * Creating a data table for articles when activating the plugin
     */
    function mkm_api_create_table_article() {
        global $wpdb;

        $query = "CREATE TABLE IF NOT EXISTS `mkm_api_articles` (
            `id` INT(11) unsigned NOT NULL AUTO_INCREMENT,
            `id_article` INT(10) NOT NULL,
            `id_product` INT(10) NOT NULL,
            `id_language` INT(10) NOT NULL,
            `product_nr` INT(10) NOT NULL,
            `expIcon` INT(10) NOT NULL,
            `in_shopping_cart` INT(1) NOT NULL,
            `is_foil` INT(1) NOT NULL,
            `is_signed` INT(1) NOT NULL,
            `is_altered` INT(1) NOT NULL,
            `is_playset` INT(1) NOT NULL,
            `appname` VARCHAR(50) NOT NULL,
            `language_name` VARCHAR(50) NOT NULL,
            `price` VARCHAR(50) NOT NULL,
            `counts` VARCHAR(50) NOT NULL,
            `en_name` VARCHAR(50) NOT NULL,
            `loc_name` VARCHAR(50) NOT NULL,
            `a_image` VARCHAR(255) NOT NULL,
            `rarity` VARCHAR(255) NOT NULL,
            `a_condition` VARCHAR(50) NOT NULL,
            `last_edited` DATETIME,
            PRIMARY KEY (`id`)) CHARSET=utf8;";

        $wpdb->query($query);
    }

    /**
     * @return void
     * Creating a data table for account when activating the plugin
     */
    function mkm_api_create_table_account() {
        global $wpdb;

        $query = "CREATE TABLE IF NOT EXISTS `mkm_api_accounts` (
            `id` INT(11) unsigned NOT NULL AUTO_INCREMENT,
            `key_account` VARCHAR(25) NOT NULL,
            `appname` VARCHAR(50) NOT NULL,
            `username` VARCHAR(50) NOT NULL,
            `country` VARCHAR(50) NOT NULL,
            `total_balance` VARCHAR(50) NOT NULL,
            `money_balance` VARCHAR(50) NOT NULL,
            `bonus_balance` VARCHAR(50) NOT NULL,
            `unpaid_amount` VARCHAR(50) NOT NULL,
            `provider_recharge_amount` VARCHAR(50) NOT NULL,
            `id_user` INT(10) NOT NULL,
            `is_сommercial` INT(1) NOT NULL,
            `may_sell` INT(1) NOT NULL,
            `seller_activation` INT(1) NOT NULL,
            `reputation` INT(1) NOT NULL,
            `ships_fast` INT(1) NOT NULL,
            `sell_count` INT(10) NOT NULL,
            `sold_items` INT(10) NOT NULL,
            `avg_shipping_time` INT(10) NOT NULL,
            `on_vacation` INT(1) NOT NULL,
            `id_display_language` INT(1) NOT NULL,
            PRIMARY KEY (`id`)) CHARSET=utf8;";

        $wpdb->query($query);
    }

    /**
     * @param  app|string
     * @return void
     * Removing orders from the database when deleting the application
     */
    function mkm_api_delete_app_orders( $app ) {
        global $wpdb;
        $wpdb->delete( 'mkm_api_orders', array( 'appname' => $app ), array( '%s' ) );
        $wpdb->delete( 'mkm_api_articles', array( 'appname' => $app ), array( '%s' ) );
        $wpdb->delete( 'mkm_api_accounts', array( 'appname' => $app ), array( '%s' ) );
    }

    /**
     * @return void
     * Uninstall an application when a button is clicked
     */
    function mkm_api_ajax_delete_key() {

        $post    = $_POST;

        $flag    = 0;
        $options = get_option( 'mkm_api_options' );

        if ( is_array ( $options ) && count( $options ) > 0 ) {
            $appname = $options[$post['data']]['name'];
            $arr     = array();
            foreach( $options as $item ) {
                if ( $item['app_token'] == $post['data'] ) continue;
                $arr[$item['app_token']] = $item;
            }
        }

        $up = update_option( 'mkm_api_options', $arr );

        if ( $up ) {
            if ( wp_next_scheduled( 'mkm_api_cron_' . $post['data'], array( array( 'key' => $post['data'] ) ) ) ) {
                wp_clear_scheduled_hook( 'mkm_api_cron_' . $post['data'], array( array( 'key' => $post['data'] ) ) );
            }
            
            if ( wp_next_scheduled( 'mkm_api_cron_check_' . $post['data'], array( array( 'key' => $post['data'] ) ) ) ) {
                wp_clear_scheduled_hook( 'mkm_api_cron_check_' . $post['data'], array( array( 'key' => $post['data'] ) ) );
            }

            mkm_api_delete_app_orders( $appname );
            echo 1;
            wp_die();
        };

        die;
    }

    /**
     * @return string
     * We get all the data by API (works in conjunction with AJAX)
     */
    function mkm_api_ajax_get_data() {
        global $mkmApiBaseUrl;
        $post    = $_POST;
        $arr     = array();
        $key     = $post['key'];
        $api     = array( 1, 2, 4, 8, 32, 128 );
        $state   = 0;

        if( $key == '' ) wp_die( 'end' );
        if( $post['state'] > count( $api ) ) wp_die( 'end' );

        $option = get_option( 'mkm_api_options' );

        if ( $post['count'] == 1 ) {
            $count = mkm_api_auth( "https://api.cardmarket.com/ws/v2.0/account", $option[$key]['app_token'], $option[$key]['app_secret'], $option[$key]['access_token'], $option[$key]['token_secret']);
            $arr['count'] = esc_sql( $count->account->sellCount );
            mkm_api_add_account_from_db( $count, $key );
        } else {
            $arr['count'] = $post['count'];
        }

        $data = mkm_api_auth( $mkmApiBaseUrl . $api[$post['state']] . "/" . $post['data'], $option[$key]['app_token'], $option[$key]['app_secret'], $option[$key]['access_token'], $option[$key]['token_secret'] );

        if ( isset( $data->order[0]->idOrder ) && $data->order[0]->idOrder != 0 ) {
            mkm_api_add_data_from_db( $data, $key );
            $arr['data'] = $post['data'] + 100;
            $arr['key']  = $key;
            $arr['state']  = $post['state'];
            echo json_encode( $arr );
        } else {
            if ( $post['state'] <= count( $api ) - 1) {
                mkm_api_add_data_from_db( $data, $key );
                $arr['data'] = 1;
                $arr['key']  = $key;
                $arr['state']  = $post['state'] + 1;
                echo json_encode( $arr );
            } else {
                $option[$key]['get_data'] = 1;
                update_option( 'mkm_api_options', $option );
                echo 'end';
                die;
            }
        }

        die;
    }

    /**
     * @return void
     * Change the checkbox for sort update data (works in conjunction with AJAX)
     */
    function mkm_api_ajax_checkup() {
        $key    = $_POST['data'];
        $check  = $_POST['check'];
        $checks = array( 'orders', 'account', 'articles' );
        $option = get_option( 'mkm_api_options' );

        if( !(bool)$key || !(bool)$check || !in_array( $check, $checks ) || !array_key_exists( $key, $option ) ) wp_die( 'error' );

        $option[$key]['checks'][$check] = !$option[$key]['checks'][$check];

        if ( (bool)$option[$key]['checks']['account'] || (bool)$option[$key]['checks']['articles'] ) {
            if ( !wp_next_scheduled( 'mkm_api_cron_check_' . $key, array( array( 'key' => $key ) ) ) ) {
                wp_schedule_event( time(), 'daily', 'mkm_api_cron_check_' . $key, array( array( 'key' => $key ) ) );
            }
        }

        if ( !(bool)$option[$key]['checks']['account'] && !(bool)$option[$key]['checks']['articles'] ) {
            if ( wp_next_scheduled( 'mkm_api_cron_check_' . $key, array( array( 'key' => $key ) ) ) ) {
                wp_clear_scheduled_hook( 'mkm_api_cron_check_' . $key, array( array( 'key' => $key ) ) );
            }
        }

        $up = update_option( 'mkm_api_options', $option );

        if ( $up ) {
            echo 'check'; die;
        } else {
            echo 'non check';die;
        }

    }

    /**
     * @return void
     * Change the interval of operation of the cron (works in conjunction with AJAX)
     */
    function mkm_api_ajax_change_cron_select() {
        $post    = $_POST;
        $arr     = array();
        $key     = $post['key'];

        if( $key == '' ) wp_die( 'error' );

        $option    = get_option( 'mkm_api_options' );
        $schedules = wp_get_schedules();

        if ( !array_key_exists( $post['data'], $schedules ) ) wp_die( 'error' );

        $option[$key]['cron'] = $post['data'];

        if ( wp_next_scheduled( 'mkm_api_cron_' . $key, array( array( 'key' => $key ) ) ) ) {
            wp_clear_scheduled_hook( 'mkm_api_cron_' . $key, array( array( 'key' => $key ) ) );
        }

        wp_schedule_event( time(), $post['data'], 'mkm_api_cron_' . $key, array( array( 'key' => $key ) ) );
        update_option( 'mkm_api_options', $option );
    }

    /**
     * @return void
     * Updating order data (works in conjunction with AJAX)
     */
    function mkm_api_ajax_update_orders() {

        $post = $_POST;

        $key = $post['key'];
        if ( !isset( $key ) || !(bool)$key ) {
            echo 'done'; die;
        }

        $options = get_option( 'mkm_api_options' );
        if ( !array_key_exists( $key, $options ) || count( $options ) == 0 ) {
            echo 'done'; die;
        }

        global $mkmApiBaseUrl;
        $arr        = array();
        $count      = $post['count'];
        $state      = $post['state'];
        $api        = array( 1, 2, 4, 8 );
        $arr['key'] = $key;

        if ( (bool)$options[$key]['checks']['account'] ) {
            $data = mkm_api_auth( "https://api.cardmarket.com/ws/v2.0/account", $options[$key]['app_token'], $options[$key]['app_secret'], $options[$key]['access_token'], $options[$key]['token_secret'] );
            mkm_api_add_account_from_db( $data, $key );
        }

        if ( (bool)$options[$key]['checks']['orders'] && $state != 10 ) {
            $data    = mkm_api_auth( $mkmApiBaseUrl . $api[$state] . "/" . $count, $options[$key]['app_token'], $options[$key]['app_secret'], $options[$key]['access_token'], $options[$key]['token_secret'] );
            if ( isset ( $data->order[0]->idOrder ) &&  $data->order[0]->idOrder != 0 ) {
                sleep( 1 );
                mkm_api_add_data_from_db( $data, $key );
                $arr['count'] = $count + 100;
                $arr['state'] = $state;
                if ( $count >= 301 ) {
                    $arr['count'] = 1;
                    $arr['state'] = 10;
                }
                echo json_encode( $arr ); die;
            } else {
                if ( $state >= 4 ) {
                    $arr['count'] = 1;
                    $arr['state'] = 10;
                    echo json_encode( $arr ); die;
                } else {
                    $arr['count'] = 1;
                    $arr['state'] = $state + 1;
                    echo json_encode( $arr ); die;
                }
            }
        } else {
            $state = 10;
        }

        if ( (bool)$options[$key]['checks']['articles'] && $state == 10 ) {
            $data    = mkm_api_auth( 'https://api.cardmarket.com/ws/v2.0/stock/' . $count, $options[$key]['app_token'], $options[$key]['app_secret'], $options[$key]['access_token'], $options[$key]['token_secret'] );
            if ( isset ( $data->article[0]->idArticle ) &&  $data->article[0]->idArticle != 0 ) {
                mkm_api_add_articles_from_db( $data, $key );
                $arr['count'] = $count + 100;
                $arr['state'] = $state;
                echo json_encode( $arr ); die;
            } else {
                echo 'done'; die;
            }
        }

        echo 'done'; die;

    }

    /**
     * @return void
     * Forming Plugin Pages
     */
    function mkm_api_admin_menu() {
        add_menu_page( 'MKM API', 'MKM API', 'manage_options', 'mkm-api-options', 'mkm_api_options', 'dashicons-groups' );

        add_submenu_page( 'mkm-api-options', 'MKM API DATA ACCOUNTS', 'API Accounts', 'manage_options', 'mkm-api-subpage-accounts', 'mkm_api_orders_accounts' );
        add_submenu_page( 'mkm-api-options', 'MKM API DATA', 'API Orders', 'manage_options', 'mkm-api-subpage', 'mkm_api_orders' );
        add_submenu_page( 'mkm-api-options', 'MKM API DATA ARTICLES', 'API Articles', 'manage_options', 'mkm-api-subpage-articles', 'mkm_api_orders_articles' );
    }

    /**
     * @return void
     * Formation of the main option for applications
     */
    function mkm_api_admin_settings() {

        register_setting( 'mkm_api_group_options', 'mkm_api_options', 'mkm_api_sanitize' );

    }

    /**
     * @param array
     * @return array
     * Checking and saving options when creating an application
     */
    function mkm_api_sanitize( $option ) {

        if ( isset( $_POST['data'] ) ) return $option;

        $add_array  = array();
        $schedules  = wp_get_schedules();
        $arr        = ( is_array( get_option( 'mkm_api_options' ) ) && count( get_option( 'mkm_api_options' ) ) > 0 ) ? get_option( 'mkm_api_options' ) : array();

        if ( $option['name'] == '' ) return $arr;
        if ( $option['app_token'] == '' ) return $arr;
        if ( $option['app_secret'] == '' ) return $arr;
        if ( $option['access_token'] == '' ) return $arr;
        if ( $option['token_secret'] == '' ) return $arr;
        if ( !array_key_exists( $option['cron'], $schedules ) ) return $arr;
        if ( array_key_exists( $option['app_token'], $arr ) ) {
            add_settings_error( 'mkm_api_options', 'mkm_api_options', __( 'This App Token is already in use', 'mkm-api' ), 'error' );
            return $arr;
        }

        if ( count( $arr ) > 0 ) {
            foreach ( $arr as $app_elem ) {
                if ( $app_elem['name'] == $option['name'] ) {
                    add_settings_error( 'mkm_api_options', 'mkm_api_options', __( 'This name already exists', 'mkm-api' ), 'error' );
                    return $arr;
                }
            }
        }

        $add_array['token_secret'] = $option['token_secret'];
        $add_array['access_token'] = $option['access_token'];
        $add_array['app_secret']   = $option['app_secret'];
        $add_array['app_token']    = $option['app_token'];
        $add_array['checks']       = array( 'orders' => 0, 'account' => 0, 'articles' => 0 );
        $add_array['name']         = $option['name'];
        $add_array['cron']         = $option['cron'];
        $add_array['get_data']     = 0;

        $arr[$option['app_token']] = $add_array;

        if ( !wp_next_scheduled( 'mkm_api_cron_' . $option['app_token'] ) ) {
            wp_schedule_event( time(), $option['cron'], 'mkm_api_cron_' . $option['app_token'], array( array( 'key' => $option['app_token'] ) ) );
        }

        add_settings_error( 'mkm_api_options', 'mkm_api_options', __( 'New application added successfully', 'mkm-api' ), 'updated' );

        return $arr;
    }

    /**
     * @return void
     * Data output to plugin settings page
     */
    function mkm_api_options( ) {
        $option    = get_option( 'mkm_api_options' );
        $schedules = wp_get_schedules();

        ?>

            <div class="wrap">
                <h2><?php _e( 'MKM API Settings', 'mkm-api' ); ?></h2>
                <?php settings_errors(); ?>
                <form action="options.php" method="post">
                    <?php settings_fields( 'mkm_api_group_options' ); ?>
                    <table class="form-table">
                    <?php if ( is_array( $option ) && count( $option ) > 0 ) {  ?>
                        <tr>
                            <th></th>
                            <td class="mkm-api-app-td">
                                <table class="mkm-api-apps-show">
                                    <?php foreach( $option as $item ) { ?>
                                    <?php $interval = ''; ?>
                                        <tr class="mkm-api-key-row">
                                            <td><?php echo $item['name']; ?></td>
                                            <td>
                                                <select class="mkm-api-cron-select" data-key="<?php echo $item['app_token']; ?>">
                                                    <?php foreach( $schedules as $sch_key => $sch_val ) { ?>
                                                        <option <?php echo $sch_key == $item['cron'] ? 'selected ' : ''; ?>value="<?php echo $sch_key; ?>"><?php echo $sch_val['display']; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </td>
                                            <td>
                                                <div>
                                                    <input <?php echo (bool)$item['checks']['orders'] ? ' checked="checked" ' : '' ?> type="checkbox" id="mkm-api-check-order-<?php echo $item['app_token']; ?>" class="mkm-api-checkup" data-check="orders" data-key="<?php echo $item['app_token']; ?>"/>
                                                    <label for="mkm-api-check-order-<?php echo $item['app_token']; ?>"><?php _e( 'Orders', 'mkm-api' ); ?></label>
                                                </div>
                                                <div>
                                                    <input <?php echo (bool)$item['checks']['account'] ? ' checked="checked" ' : '' ?> type="checkbox" id="mkm-api-check-account-<?php echo $item['app_token']; ?>" class="mkm-api-checkup" data-check="account" data-key="<?php echo $item['app_token']; ?>"/>
                                                    <label for="mkm-api-check-account-<?php echo $item['app_token']; ?>"><?php _e( 'Account', 'mkm-api' ); ?></label>
                                                </div>
                                                <div>
                                                    <input <?php echo (bool)$item['checks']['articles'] ? ' checked="checked" ' : '' ?> type="checkbox" id="mkm-api-check-articles-<?php echo $item['app_token']; ?>" class="mkm-api-checkup" data-check="articles" data-key="<?php echo $item['app_token']; ?>"/>
                                                    <label for="mkm-api-check-articles-<?php echo $item['app_token']; ?>"><?php _e( 'Articles', 'mkm-api' ); ?></label>
                                                </div>
                                            </td>
                                            <td>
                                                <button class="button button-primary mkm-api-update-orders" data-key="<?php echo $item['app_token']; ?>">
                                                    <?php _e( 'Update', 'mkm-api' ); ?>
                                                    <span class="mkm-api-update-orders-span">
                                                        <span class="dashicons-before dashicons-update"></span>
                                                    </span>
                                                </button>
                                            </td>
                                            <td class="mkm-api-get-all-data-td"><?php echo (bool)$item['get_data'] ? __( 'Data received', 'mkm-api' ) : submit_button( __( 'Get all data', 'mkm-api' ), 'primary mkm-api-get-all-data', 'submit', true, array( 'data-key' => $item['app_token'] ) ) ?></td>
                                            <td class="mkm-api-delete-key"><a href="" data-key="<?php echo $item['app_token']; ?>"><?php _e( 'Delete', 'mkm-api' ); ?></a></td>
                                        </tr>
                                    <?php } ?>
                                </table>
                            </td>
                        </tr>
                        <?php } ?>
                        <tr>
                            <th></th>
                            <td>
                                <p>
                                    <label class="mkm-api-app-form-label" for="mkm_api_setting_name_id"><?php _e( 'Name App', 'mkm-api' ); ?></label>
                                    <input type="text" value="" class="regular-text" name="mkm_api_options[name]" id="mkm_api_setting_name_id" required>
                                    <label for="mkm_api_setting_cron_id"><?php _e( 'Interval', 'mkm-api' ); ?></label>
                                    <select name="mkm_api_options[cron]" id="mkm_api_setting_cron_id">
                                    <?php foreach ( $schedules as $time_key => $time_val ) { ?>
                                        <option value="<?php echo $time_key; ?>"><?php echo $time_val['display']; ?></option>
                                    <?php } ?>
                                    </select>
                                </p>
                                <p>
                                    <label class="mkm-api-app-form-label" for="mkm_api_setting_app_token_id"><?php _e( 'App Token', 'mkm-api' ); ?></label>
                                    <input type="text" value="" class="regular-text" name="mkm_api_options[app_token]" id="mkm_api_setting_app_token_id" required>
                                </p>
                                <p>
                                    <label class="mkm-api-app-form-label" for="mkm_api_setting_app_secret_id"><?php _e( 'App Secret', 'mkm-api' ); ?></label>
                                    <input type="text" value="" class="regular-text" name="mkm_api_options[app_secret]" id="mkm_api_setting_app_secret_id" required>
                                </p>
                                <p>
                                    <label class="mkm-api-app-form-label" for="mkm_api_setting_access_token_id"><?php _e( 'Access Token', 'mkm-api' ); ?></label>
                                    <input type="text" value="" class="regular-text" name="mkm_api_options[access_token]" id="mkm_api_setting_access_token_id" required>
                                </p>
                                <p>
                                    <label class="mkm-api-app-form-label" for="mkm_api_setting_token_secret_id"><?php _e( 'Access Token Secret', 'mkm-api' ); ?></label>
                                    <input type="text" value="" class="regular-text" name="mkm_api_options[token_secret]" id="mkm_api_setting_token_secret_id" required>
                                </p>
                            </td>
                        </tr>
                    </table>

                <?php submit_button( __( 'Add App', 'mkm-api' ) ); ?>
                </form>
            </div>

        <?php
    }

    /**
     * @return void
     * Recording new and updating old orders in the database
     */
    function mkm_api_add_data_from_db( $data, $key ) {
        global $wpdb;
        $option = get_option( 'mkm_api_options' );

        foreach ( $data->order as $value ) {
            $idOrder         = esc_sql( (int)$value->idOrder );
            if ( !isset( $idOrder ) || $idOrder == 0 ) continue;
            $state           = esc_sql( $value->state->state );
            $dateBought      = date( "Y-m-d H:i:s", strtotime( esc_sql( $value->state->dateBought ) ) );
            $datePaid        = date( "Y-m-d H:i:s", strtotime( esc_sql( $value->state->datePaid ) ) );
            $dateSent        = date( "Y-m-d H:i:s", strtotime( esc_sql( $value->state->dateSent ) ) );
            $dateReceived    = date( "Y-m-d H:i:s", strtotime( esc_sql( $value->state->dateReceived ) ) );
            $price           = esc_sql( $value->shippingMethod->price );
            $isInsured       = (int)esc_sql( $value->shippingMethod->isInsured );
            $city            = esc_sql( $value->shippingAddress->city );
            $country         = esc_sql( $value->shippingAddress->country );
            $articleCount    = (int)esc_sql( $value->articleCount );
            $evaluationGrade = esc_sql( $value->evaluation->evaluationGrade );
            $itemDescription = esc_sql( $value->evaluation->itemDescription );
            $packaging       = esc_sql( $value->evaluation->packaging );
            $articleValue    = esc_sql( $value->articleValue );
            $totalValue      = esc_sql( $value->totalValue );
            $appName         = esc_sql( $option[$key]['name'] );


            if ( !$wpdb->get_var( "SELECT id_order FROM mkm_api_orders WHERE id_order = $idOrder" ) ) {
                $wpdb->query( $wpdb->prepare( "INSERT INTO mkm_api_orders (id_order, states, date_bought, date_paid, date_sent, date_received, price, is_insured, city, country, article_count, evaluation_grade, item_description, packaging, article_value, total_value, appname ) VALUES ( %d, %s, %s, %s, %s, %s, %f, %d, %s, %s, %d, %s, %s, %s, %f, %f, %s )", $idOrder, $state, $dateBought, $datePaid, $dateSent, $dateReceived, $price, $isInsured, $city, $country, $articleCount, $evaluationGrade, $itemDescription, $packaging, $articleValue, $totalValue, $appName ) );
            } else {
                $wpdb->update( 'mkm_api_orders',
                    array(
                        'states'           => $state,
                        'date_bought'      => $dateBought,
                        'date_paid'        => $datePaid,
                        'date_sent'        => $dateSent,
                        'date_received'    => $dateReceived,
                        'price'            => $price,
                        'is_insured'       => $isInsured,
                        'city'             => $city,
                        'country'          => $country,
                        'article_count'    => $articleCount,
                        'evaluation_grade' => $evaluationGrade,
                        'item_description' => $itemDescription,
                        'packaging'        => $packaging,
                        'article_value'    => $articleValue,
                        'total_value'      => $totalValue,
                    ),
                    array( 'id_order' => $idOrder ),
                    array( '%s', '%s', '%s', '%s', '%s', '%f', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%f', '%f' ),
                    array( '%d' )
                );
            }
        }
    }

    /**
     * @return void
     * Recording new and updating old orders in the database
     */
    function mkm_api_add_articles_from_db( $data, $key ) {
        global $wpdb;
        $option = get_option( 'mkm_api_options' );

        foreach ( $data->article as $value ) {
            $idArticle       = esc_sql( (int)$value->idArticle );
            if ( !isset( $idArticle ) || $idArticle == 0 ) continue;
            $idProduct       = esc_sql( (int)$value->idProduct );
            $idLanguage      = esc_sql( (int)$value->language->idLanguage );
            $productNr       = esc_sql( (int)$value->product->nr );
            $expIcon         = esc_sql( (int)$value->product->expIcon );
            $inShoppingCart  = esc_sql( $value->inShoppingCart );
            $isFoil          = esc_sql( $value->isFoil );
            $isSigned        = esc_sql( $value->isSigned );
            $isAltered       = esc_sql( $value->isAltered );
            $isPlayset       = esc_sql( $value->isPlayset );
            $languageName    = esc_sql( $value->language->languageName );
            $price           = esc_sql( $value->price );
            $count           = esc_sql( (int)$value->count );
            $enName          = esc_sql( $value->product->enName );
            $locName         = esc_sql( $value->product->locName );
            $image           = esc_sql( $value->product->image );
            $rarity          = esc_sql( $value->product->rarity );
            $condition       = esc_sql( $value->condition );
            $lastEdited      = date( "Y-m-d H:i:s", strtotime( esc_sql( $value->lastEdited ) ) );
            $appName         = esc_sql( $option[$key]['name'] );


            if ( !$wpdb->get_var( "SELECT id_article FROM mkm_api_articles WHERE id_article = $idArticle" ) ) {
                $wpdb->query( $wpdb->prepare( "INSERT INTO mkm_api_articles (id_article, id_product, id_language, product_nr, expIcon, in_shopping_cart, is_foil, is_signed, is_altered, is_playset, appname, language_name, price, counts, en_name, loc_name, a_image, rarity, a_condition, last_edited ) VALUES ( %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s )", $idArticle, $idProduct, $idLanguage, $productNr, $expIcon, $inShoppingCart, $isFoil, $isSigned, $isAltered, $isPlayset, $appName, $languageName, $price, $count, $enName, $locName, $image, $rarity, $condition, $lastEdited ) );
            } else {
                $wpdb->update( 'mkm_api_articles',
                    array(
                        'id_article'       => $idArticle,
                        'id_product'       => $idProduct,
                        'id_language'      => $idLanguage,
                        'product_nr'       => $productNr,
                        'expIcon'          => $expIcon,
                        'in_shopping_cart' => $inShoppingCart,
                        'is_foil'          => $isFoil,
                        'is_signed'        => $isSigned,
                        'is_altered'       => $isAltered,
                        'is_playset'       => $isPlayset,
                        'appname'          => $appName,
                        'language_name'    => $languageName,
                        'price'            => $price,
                        'counts'           => $count,
                        'en_name'          => $enName,
                        'loc_name'         => $locName,
                        'a_image'          => $image,
                        'rarity'           => $rarity,
                        'a_condition'      => $condition,
                        'last_edited'      => $lastEdited,
                    ),
                    array( 'id_article' => $idArticle ),
                    array( '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
                    array( '%d' )
                );
            }
        }
    }

    /**
     * @return void
     * Recording accounts in the database
     */
    function mkm_api_add_account_from_db( $data, $key ) {
        global $wpdb;
        $option = get_option( 'mkm_api_options' );

        $value = $data->account;

        $appName           = $option[$key]['name'];
        $idUser            = esc_sql( (int)$value->idUser );
        $username          = esc_sql( $value->username );
        $country           = esc_sql( $value->country );
        $totalBalance      = esc_sql( $value->moneyDetails->totalBalance );
        $moneyBalance      = esc_sql( $value->moneyDetails->moneyBalance );
        $bonusBalance      = esc_sql( $value->moneyDetails->bonusBalance );
        $unpaidAmount      = esc_sql( $value->moneyDetails->unpaidAmount );
        $providerAmount    = esc_sql( $value->moneyDetails->providerRechargeAmount );
        $isCommercial      = esc_sql( $value->isCommercial );
        $maySell           = esc_sql( $value->maySell );
        $sellerActivation  = esc_sql( $value->sellerActivation );
        $reputation        = esc_sql( $value->reputation );
        $shipsFast         = esc_sql( $value->shipsFast );
        $sellCount         = esc_sql( $value->sellCount );
        $soldItems         = esc_sql( $value->soldItems );
        $avgShippingTime   = esc_sql( $value->avgShippingTime );
        $onVacation        = esc_sql( $value->onVacation );
        $idDisplayLanguage = esc_sql( $value->idDisplayLanguage );


        if ( !$wpdb->get_var( "SELECT key_account FROM mkm_api_accounts WHERE key_account = '$key'" ) ) {
            $wpdb->query( $wpdb->prepare( "INSERT INTO mkm_api_accounts (key_account, appname, username, country, total_balance, money_balance,bonus_balance, unpaid_amount, provider_recharge_amount, id_user, is_сommercial, may_sell, seller_activation, reputation, ships_fast, sell_count, sold_items, avg_shipping_time, on_vacation, id_display_language ) VALUES ( %s, %s, %s, %s, %f, %f, %f, %f, %s, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s )", $key, $appName, $username, $country, $totalBalance, $moneyBalance, $bonusBalance, $unpaidAmount, $providerAmount, $idUser, $isCommercial, $maySell, $sellerActivation, $reputation, $shipsFast, $sellCount, $soldItems, $avgShippingTime, $onVacation, $idDisplayLanguage ) );
        } else {
            $wpdb->update( 'mkm_api_accounts',
                array(
                    'appname'       => $appName,
                    'username'      => $username,
                    'country'       => $country,
                    'total_balance' => $totalBalance,
                    'money_balance' => $moneyBalance,
                    'bonus_balance' => $bonusBalance,
                    'unpaid_amount' => $unpaidAmount,
                    'provider_recharge_amount' => $providerAmount,
                    'id_user'       => $idUser,
                    'is_сommercial' => $isCommercial,
                    'may_sell'      => $maySell,
                    'seller_activation' => $sellerActivation,
                    'reputation'    => $reputation,
                    'ships_fast'    => $shipsFast,
                    'sell_count'    => $sellCount,
                    'sold_items'    => $soldItems,
                    'avg_shipping_time' => $avgShippingTime,
                    'on_vacation'   => $onVacation,
                    'id_display_language' => $idDisplayLanguage,
                ),
                array( 'key_account' => $key ),
                array( '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
                array( '%s' )
            );
        }
    }

    /**
     * @param string
     * @param string
     * @param string
     * @param string
     * @param string
     * @return string
     * The function of connecting via API and receiving data in XML Format
     */
    function mkm_api_auth( $url, $appToken, $appSecret, $accessToken, $accessSecret ) {

        /**
        * Declare and assign all needed variables for the request and the header
        *
        * @var $method string Request method
        * @var $url string Full request URI
        * @var $appToken string App token found at the profile page
        * @var $appSecret string App secret found at the profile page
        * @var $accessToken string Access token found at the profile page (or retrieved from the /access request)
        * @var $accessSecret string Access token secret found at the profile page (or retrieved from the /access request)
        * @var $nonce string Custom made unique string, you can use uniqid() for this
        * @var $timestamp string Actual UNIX time stamp, you can use time() for this
        * @var $signatureMethod string Cryptographic hash function used for signing the base string with the signature, always HMAC-SHA1
        * @var version string OAuth version, currently 1.0
        */

        $method             = "GET";
        $nonce              = 'c6d25d33be';
        $timestamp          = time();
        $signatureMethod    = "HMAC-SHA1";
        $version            = "1.0";

        /**
            * Gather all parameters that need to be included in the Authorization header and are know yet
            *
            * Attention: If you have query parameters, they MUST also be part of this array!
            *
            * @var $params array|string[] Associative array of all needed authorization header parameters
            */
        $params             = array(
            'realm'                     => $url,
            'oauth_consumer_key'        => $appToken,
            'oauth_token'               => $accessToken,
            'oauth_nonce'               => $nonce,
            'oauth_timestamp'           => $timestamp,
            'oauth_signature_method'    => $signatureMethod,
            'oauth_version'             => $version,
        );

        /**
            * Start composing the base string from the method and request URI
            *
            * Attention: If you have query parameters, don't include them in the URI
            *
            * @var $baseString string Finally the encoded base string for that request, that needs to be signed
            */
        $baseString         = strtoupper($method) . "&";
        $baseString        .= rawurlencode($url) . "&";

        /*
            * Gather, encode, and sort the base string parameters
            */
        $encodedParams      = array();
        foreach ($params as $key => $value)
        {
            if ("realm" != $key)
            {
                $encodedParams[rawurlencode($key)] = rawurlencode($value);
            }
        }
        ksort($encodedParams);

        /*
            * Expand the base string by the encoded parameter=value pairs
            */
        $values             = array();
        foreach ($encodedParams as $key => $value)
        {
            $values[] = $key . "=" . $value;
        }
        $paramsString       = rawurlencode(implode("&", $values));
        $baseString        .= $paramsString;

        /*
            * Create the signingKey
            */
        $signatureKey       = rawurlencode($appSecret) . "&" . rawurlencode($accessSecret);

        /**
            * Create the OAuth signature
            * Attention: Make sure to provide the binary data to the Base64 encoder
            *
            * @var $oAuthSignature string OAuth signature value
            */
        $rawSignature       = hash_hmac("sha1", $baseString, $signatureKey, true);
        $oAuthSignature     = base64_encode($rawSignature);

        /*
            * Include the OAuth signature parameter in the header parameters array
            */
        $params['oauth_signature'] = $oAuthSignature;

        /*
            * Construct the header string
            */
        $header             = "Authorization: OAuth ";
        $headerParams       = array();
        foreach ($params as $key => $value)
        {
            $headerParams[] = $key . "=\"" . $value . "\"";
        }
        $header            .= implode(", ", $headerParams);

        /*
            * Get the cURL handler from the library function
            */
        $curlHandle         = curl_init();

        /*
            * Set the required cURL options to successfully fire a request to MKM's API
            *
            * For more information about cURL options refer to PHP's cURL manual:
            * http://php.net/manual/en/function.curl-setopt.php
            */
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_URL, $url);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, array($header));
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, false);

        /**
            * Execute the request, retrieve information about the request and response, and close the connection
            *
            * @var $content string Response to the request
            * @var $info array Array with information about the last request on the $curlHandle
            */
        $content            = curl_exec($curlHandle);
        $info               = curl_getinfo($curlHandle);
        curl_close($curlHandle);

        /*
            * Convert the response string into an object
            *
            * If you have chosen XML as response format (which is standard) use simplexml_load_string
            * If you have chosen JSON as response format use json_decode
            *
            * @var $decoded \SimpleXMLElement|\stdClass Converted Object (XML|JSON)
            */

        //$decoded            = json_decode($content);

        $decoded            = simplexml_load_string($content);

        return $decoded;
    }

    /**
     * @return void
     * Forms an output of these orders to the screen
     */
    function mkm_api_ajax_get_orders(){
        $post = $_POST;

        if ( $post['app'] == '' ) {
            echo 'no_data';
            die();
        }

        $start = ( !isset( $post['start'] ) || $post['start'] == 0 || $post['start'] == '' ) ? 0 : $post['start'];
        $from  = $post['from'] != '' ? $post['from'] . ' 00:00:00' : '1970-01-01 00:00:00';
        $to    = $post['to'] != '' ? $post['to'] . ' 23:59:59' : date( 'Y-m-d H:i:s', time() );

        $html = '';

        $data = mkm_api_get_orders( $start, $post['app'], $from, $to, $post['state'] );
        if ( $data['count'] > 0 ) {
            foreach ( $data['result'] as $res_val ) {
                $html .= '<tr class="mkm-api-list-order-row">';

                $html .= '<td><div class="mkm-api-td-left">' . __( 'ID Order', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . $res_val->id_order. '</div>';
                $html .= '<div class="mkm-api-td-left">' . __( 'App name', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . $res_val->appname . '</div></td>';

                $html .= '<td><div class="mkm-api-td-left">' . __( 'Date bought', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . mkm_api_null_date( $res_val->date_bought ) . '</div>';
                $html .= '<div class="mkm-api-td-left">' . __( 'Date received', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . mkm_api_null_date( $res_val->date_received ) . '</div></td>';

                $html .= '<td><div class="mkm-api-td-left">' . __( 'Date Paid', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . mkm_api_null_date( $res_val->date_paid ) . '</div>';
                $html .= '<div class="mkm-api-td-left">' . __( 'Date sent', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . mkm_api_null_date( $res_val->date_sent ) . '</div></td>';

                $html .= '<td><div class="mkm-api-td-left">' . __( 'State', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . $res_val->states. '</div>';
                $html .= '<div class="mkm-api-td-left">' . __( 'Price', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . number_format( $res_val->price, 2, '.', '' ) . '</div></td>';

                $html .= '<td><div class="mkm-api-td-left">' . __( 'City/Country', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . $res_val->city . ' ' . $res_val->country . '</div>';
                $html .= '<div class="mkm-api-td-left">' . __( 'Article count', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . $res_val->article_count . '</div></td>';

                $html .= '<td><div class="mkm-api-td-left">' . __( 'Article value', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . number_format( $res_val->article_value   , 2, '.', '' ) . '</div>';
                $html .= '<div class="mkm-api-td-left">' . __( 'Total value', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . number_format( $res_val->total_value, 2, '.', '' ) . '</div></td>';

                $html .= '<td><div class="mkm-api-td-left">' . __( 'Is insured', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . $res_val->is_insured . '</div>';
                $html .= '<div class="mkm-api-td-left">' . __( 'Packaging', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . $res_val->packaging . '</div></td>';

                $html .= '<td><div class="mkm-api-td-left">' . __( 'Evaluation grade', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . $res_val->evaluation_grade . '</div>';
                $html .= '<div class="mkm-api-td-left">' . __( 'Item description', 'mkm-api' ) . '</div><div class="mkm-api-td-right">' . $res_val->item_description . '</div></td>';

                $html .= '</tr>';
            }
        }

        $data['html'] = $html;
        $data['start'] = $start + 30;

        echo json_encode( $data );
        die();
    }

    /**
     * @return bool|string
     * Function helper for output yes or no
     */
    function mkm_api_yes_no( $flag ) {
        return (bool)$flag ? __( 'Yes', 'mkm-api' ) : __( 'No', 'mkm-api' );
    }

    /**
     * @return void
     * Forms an output of these articles to the screen
     */
    function mkm_api_ajax_more_articles() {
        $post  = $_POST;
        $start = $post['start'];
        if ( !isset( $post ) || $start < 30 ) wp_die();

        $data  = mkm_api_get_articles( $start );
        $html  = '';

        if ( $data['count'] > 0 ) {
            foreach ( $data['result'] as $item ) {
                $html .= '<div class="mkm-api-account-item">';
                $html .= '<div class="mkm-api-row">';
                $html .= '<div class="mkm-api-colum-2">';
                $html .= '<img src="https://cardmarket.com/' . $item->a_image . '" style="width: 100%;">';
                $html .= '</div>';
                $html .= '<div class="mkm-api-colum-10">';
                $html .= '<div class="mkm-api-row">';
                $html .= '<div class="mkm-api-colum-4">';
                $html .= '<div class="mkm-api-account-item-str">';
                $html .= '<div>' . $item->en_name . ' (#' . $item->id_article . ')' . '</div>';
                $html .= '<small>' . __( 'Name, ID Article', 'mkm-api' ) . '</small>';
                $html .= '</div>';
                $html .= '<div class="mkm-api-account-item-str">';
                $html .= '<div>' . $item->id_product . '</div>';
                $html .= '<small>' . __( 'ID Product', 'mkm-api' ) . '</small>';
                $html .= '</div>';
                $html .= '<div class="mkm-api-account-item-str">';
                $html .= '<div>' . $item->language_name .'</div>';
                $html .= '<small>' . __( 'Language', 'mkm-api' ) .'</small>';
                $html .= '</div>';
                $html .= '<div class="mkm-api-account-item-str">';
                $html .= '<div>' . number_format( $item->price, 2, '.', ' ' ) . '</div>';
                $html .= '<small>' . __( 'Price', 'mkm-api' ) . '</small>';
                $html .= '</div>';
                $html .= '<div class="mkm-api-account-item-str">';
                $html .= '<div><span class="mkm-api-card-icon" style="background-position: -' . ( $item->expIcon%10 ) * 21 . 'px -' . floor( $item->expIcon / 10 ) * 21 .'px;"></span></div>';
                $html .= '<small>' . __( 'Icon', 'mkm-api' ) . '</small>';
                $html .= '</div>';
                $html .= '</div>';
                $html .= '<div class="mkm-api-colum-4">';
                $html .= '<div class="mkm-api-account-item-str">';
                $html .= '<div>' . $item->appname . '</div>';
                $html .= '<small>' . __( "App Name", 'mkm-api' ) . '</small>';
                $html .= '</div>';
                $html .= '<div class="mkm-api-account-item-str">';
                $html .= '<div>' . $item->rarity . '</div>';
                $html .= '<small>' . __( "Product's rarity", 'mkm-api' ) . '</small>';
                $html .= '</div>';
                $html .= '<div class="mkm-api-account-item-str">';
                $html .= '<div>' . $item->a_condition . '</div>';
                $html .= '<small>' . __( "Product's condition", 'mkm-api' ) . '</small>';
                $html .= '</div>';
                $html .= '<div class="mkm-api-account-item-str">';
                $html .= '<div>' . $item->last_edited . '</div>';
                $html .= '<small>' . __( "Last edited", 'mkm-api' ) . '</small>';
                $html .= '</div>';
                $html .= '<div class="mkm-api-account-item-str">';
                $html .= '<div>' . $item->counts . '</div>';
                $html .= '<small>' . __( 'Number of single within the expansion', 'mkm-api' ) . '</small>';
                $html .= '</div>';
                $html .= '</div>';
                $html .= '<div class="mkm-api-colum-4">';
                $html .= '<div class="mkm-api-account-item-str">';
                $html .= '<div>' . mkm_api_yes_no( $item->in_shopping_cart ) . '</div>';
                $html .= '<small>' . __( "Product in basket", 'mkm-api' ) . '</small>';
                $html .= '</div>';
                $html .= '<div class="mkm-api-account-item-str">';
                $html .= '<div>' . mkm_api_yes_no( $item->is_foil ) . '</div>';
                $html .= '<small>' . __( "Foil", 'mkm-api' ) . '</small>';
                $html .= '</div>';
                $html .= '<div class="mkm-api-account-item-str">';
                $html .= '<div>' . mkm_api_yes_no( $item->is_signed ) . '</div>';
                $html .= '<small>' . __( "Signed", 'mkm-api' ) . '</small>';
                $html .= '</div>';
                $html .= '<div class="mkm-api-account-item-str">';
                $html .= '<div>' . mkm_api_yes_no( $item->is_altered ) . '</div>';
                $html .= '<small>' . __( "Altered", 'mkm-api' ) . '</small>';
                $html .= '</div>';
                $html .= '<div class="mkm-api-account-item-str">';
                $html .= '<div>' . mkm_api_yes_no( $item->is_playset ) . '</div>';
                $html .= '<small>' . __( "Playset", 'mkm-api' ) . '</small>';
                $html .= '</div>';
                $html .= '</div>';
                $html .= '</div>';
                $html .= '</div>';
                $html .= '</div>';
                $html .= '</div>';

            }
            echo $html; die;
        } else {
            echo 'done'; die;
        }

        echo 'done';
        die;
    }

    /**
     * @param int
     * @param string
     * @param string
     * @param string
     * @param string
     * @return array
     * Getting orders from the database
     */
    function mkm_api_get_orders( $start = 0, $apps = 'all', $from = '1970-01-01 00:00:00', $to = 0, $state = 'evaluated' ) {
        global $mkmApiStates;
        global $wpdb;
        $perpage = 30;
        $data    = array();
        $where   = "WHERE states = '$state' AND";
        $to      = $to == 0 ? date( 'Y-m-d H:i:s', time() ) : $to;
        $state   = array_key_exists( $state, $mkmApiStates ) ? $state : 'evaluated';
        if ( $apps != 'all' ) {
            $where .= " appname = '$apps' AND";
        }
        $data['count']  = $wpdb->get_var( "SELECT count(*) FROM mkm_api_orders $where date_bought BETWEEN '$from' AND '$to'" );
        $data['result'] = $wpdb->get_results( "SELECT * FROM mkm_api_orders $where date_bought BETWEEN '$from' AND '$to' ORDER BY date_bought DESC LIMIT $start, $perpage" );
        return $data;
    }

    /**
     * @return void
     * Forms the initial output of these orders to the screen
     */
    function mkm_api_orders() {

        $result  = mkm_api_get_orders();
        $data    = $result['result'];
        $options = get_option( 'mkm_api_options' );
        global $mkmApiStates;

        ?>
            <div class="wrap mkm-api-wrap">
                <h2><?php _e( 'MKM API Orders', 'mkm-api' ); ?></h2>
            <div class="mkm-api-filter">
                <div class="mkm-api-filter-count" data-count="<?php echo $result['count']; ?>">
                    <?php echo __( 'Count orders', 'mkm-api' ) . ': <span class="mkm-api-data-count">' . $result['count']; ?></span>
                </div>

                <div class="mkm-api-filter-select-app">
                    <label for="mkm-api-filter-select-app-id"><?php _e( 'Filter App', 'mkm-api' ); ?></label>
                    <select id="mkm-api-filter-select-app-id">
                        <option value="all"><?php _e( 'All Apps', 'mkm-api' ); ?></option>
                        <?php foreach( $options as $item ) { ?>
                            <option value="<?php echo $item['name']; ?>"><?php echo $item['name']; ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="mkm-api-filter-select-state">
                    <label for="mkm-api-filter-select-state-id"><?php _e( 'Filter State', 'mkm-api' ); ?></label>
                    <select id="mkm-api-filter-select-state-id">
                        <?php foreach( $mkmApiStates as $state_key => $state_val ) { ?>
                            <option value="<?php echo $state_key; ?>"><?php echo $state_val; ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="mkm-api-filter-date">
                    <div class="mkm-api-filter-date-item">
                        <?php _e( 'Filter Date: ', 'mkm-api' ); ?>
                    </div>
                    <div class="mkm-api-filter-date-item">
                        <label for="mkm-api-filter-date-from"><?php _e( 'From', 'mkm-api' ); ?></label>
                        <input id="mkm-api-filter-date-from">
                    </div>
                    <div class="mkm-api-filter-date-item">
                        <label for="mkm-api-filter-date-to"><?php _e( 'To', 'mkm-api' ); ?></label>
                        <input id="mkm-api-filter-date-to">
                    </div>
                </div>
            </div>
            <table class="form-table mkm-api-orders-table">
                <tr class="mkm-api-list-orders">
                    <td><?php _e( 'ID Order', 'mkm-api' ); ?><br><?php _e( 'App name', 'mkm-api' ); ?></td>
                    <td><?php _e( 'Date bought', 'mkm-api' ); ?><br><?php _e( 'Date received', 'mkm-api' ); ?></td>
                    <td><?php _e( 'Date paid', 'mkm-api' ); ?><br><?php _e( 'Date sent', 'mkm-api' ); ?></td>
                    <td><?php _e( 'State', 'mkm-api' ); ?><br><?php _e( 'Price', 'mkm-api' ); ?></td>
                    <td><?php _e( 'City/Country', 'mkm-api' ); ?><br><?php _e( 'Article count', 'mkm-api' ); ?></td>
                    <td><?php _e( 'Article value', 'mkm-api' ); ?><br><?php _e( 'Total value', 'mkm-api' ); ?></td>
                    <td><?php _e( 'Is insured', 'mkm-api' ); ?><br><?php _e( 'Packaging', 'mkm-api' ); ?></td>
                    <td><?php _e( 'Evaluation grade', 'mkm-api' ); ?><br><?php _e( 'Item description', 'mkm-api' ); ?></td>
                </tr>
                <?php foreach ( $data as $value ) { ?>
                <tr class="mkm-api-list-order-row">
                    <td>
                        <div class="mkm-api-td-left"><?php _e( 'ID Order', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo $value->id_order; ?></div>
                        <div class="mkm-api-td-left"><?php _e( 'App name', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo $value->appname; ?></td>
                    </td>
                    <td>
                        <div class="mkm-api-td-left"><?php _e( 'Date bought', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo mkm_api_null_date( $value->date_bought ); ?></div>
                        <div class="mkm-api-td-left"><?php _e( 'Date received', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo mkm_api_null_date( $value->date_received ); ?></div>
                    </td>
                    <td>
                        <div class="mkm-api-td-left"><?php _e( 'Date paid', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo mkm_api_null_date( $value->date_paid ); ?></div>
                        <div class="mkm-api-td-left"><?php _e( 'Date sent', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo mkm_api_null_date( $value->date_sent ); ?></div>
                    </td>
                    <td>
                        <div class="mkm-api-td-left"><?php _e( 'State', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo $value->states; ?></div>
                        <div class="mkm-api-td-left"><?php _e( 'Price', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo number_format( $value->price, 2, '.', '' ); ?></div>
                    </td>
                    <td>
                        <div class="mkm-api-td-left"><?php _e( 'City/Country', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo $value->city . ' ' . $value->country;  ?></div>
                        <div class="mkm-api-td-left"><?php _e( 'Article count', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo $value->article_count; ?></td>
                    </td>
                    <td>
                        <div class="mkm-api-td-left"><?php _e( 'Article value', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo number_format( $value->article_value, 2, '.', '' ); ?></div>
                        <div class="mkm-api-td-left"><?php _e( 'Total value', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo number_format( $value->total_value, 2, '.', '' ); ?></div>
                    </td>
                    <td>
                        <div class="mkm-api-td-left"><?php _e( 'Is insured', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo $value->is_insured; ?></div>
                        <div class="mkm-api-td-left"><?php _e( 'Packaging', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo $value->packaging; ?></div>
                    </td>
                    <td>
                        <div class="mkm-api-td-left"><?php _e( 'Evaluation grade', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo $value->evaluation_grade; ?></div>
                        <div class="mkm-api-td-left"><?php _e( 'Item description', 'mkm-api' ); ?></div>
                        <div class="mkm-api-td-right"><?php echo $value->item_description; ?></div>
                    </td>
                </tr>
                <?php } ?>
            </table>
            <div class="mkm-api-loader">
                <div class="gear"></div> 
            </div>
            <div class="mkm-api-show-more-list-orders">
                <button class="button button-primary" data-start="30"><?php _e( 'Show more', 'mkm-api' ); ?> <span id="mkm-api-show-more"></span></button>
            </div>
        </div>
        <?php
    }

    /**
     * @return void
     * Forms the initial output of these accounts to the screen
     */
    function mkm_api_orders_accounts() {
        $result = mkm_api_get_accounts();
        $isCommercial = array(
            'Private user',
            'Commercial user',
            'Powerseller'
        );

        $sellerActivation = array(
            'No seller activation',
            'Seller activation requested',
            'Transfers for requests processed',
            'Activated seller'
        );

        $reputation = array(
            'Not enough sells to rate',
            'Outstanding seller',
            'Very good seller',
            'Good seller',
            'Average seller',
            'Bad seller'
        );

        $shipsFast = array(
            'Normal shipping speed',
            'Ships very fast',
            'Ships fast'
        );

        $idDisplayLanguage = array(
            'Custom',
            'English',
            'French',
            'German',
            'Spanish',
            'Italian'
        );
        ?>
            <div class="wrap mkm-api-wrap">
                <h2 style="margin-bottom: 20px;"><?php _e( 'MKM API Accounts', 'mkm-api' ); ?></h2>
                <?php foreach ( $result as $item ) { ?>
                <div class="mkm-api-account-item">
                    <div class="mkm-api-row">
                        <div class="mkm-api-colum-4">
                            <div class="mkm-api-account-item-str">
                                <div><?php echo $item->username . ' (#' . $item->id_user . ') ' . $item->country; ?></div>
                                <small><?php _e( 'Username and ID user', 'mkm-api' ); ?></small>
                            </div>
                            <div class="mkm-api-account-item-str">
                                <div><?php echo $idDisplayLanguage[abs( $item->id_display_language )]; ?></div>
                                <small><?php _e( 'Language', 'mkm-api' ); ?></small>
                            </div>
                            <div class="mkm-api-account-item-str">
                                <div><?php echo number_format( $item->sell_count, 0, '', ' ' ); ?></div>
                                <small><?php _e( 'Number of sales', 'mkm-api' ); ?></small>
                            </div>
                            <div class="mkm-api-account-item-str">
                                <div><?php echo number_format( $item->sold_items, 0, '', ' ' ); ?></div>
                                <small><?php _e( 'Total number of sold items', 'mkm-api' ); ?></small>
                            </div>
                            <div class="mkm-api-account-item-str">
                                <div><?php echo $item->avg_shipping_time; ?></div>
                                <small><?php _e( 'Average shipping time', 'mkm-api' ); ?></small>
                            </div>
                        </div>
                        <div class="mkm-api-colum-4">
                            <div class="mkm-api-account-item-str">
                                <div><?php echo $isCommercial[$item->is_сommercial]; ?></div>
                                <small><?php _e( 'Is сommercial', 'mkm-api' ); ?></small>
                            </div>
                            <div class="mkm-api-account-item-str">
                                <div><?php echo $sellerActivation[$item->seller_activation]; ?></div>
                                <small><?php _e( 'Seller activation', 'mkm-api' ); ?></small>
                            </div>
                            <div class="mkm-api-account-item-str">
                                <div><?php echo $reputation[$item->reputation]; ?></div>
                                <small><?php _e( 'Reputation', 'mkm-api' ); ?></small>
                            </div>
                            <div class="mkm-api-account-item-str">
                                <div><?php echo $shipsFast[abs( $item->ships_fast )]; ?></div>
                                <small><?php _e( 'Ships fast', 'mkm-api' ); ?></small>
                            </div>
                            <div class="mkm-api-account-item-str">
                                <div><?php echo (bool)$item->on_vacation ? 'Yes' : 'No'; ?></div>
                                <small><?php _e( 'On vacation', 'mkm-api' ); ?></small>
                            </div>
                        </div>
                        <div class="mkm-api-colum-4">
                            <div class="mkm-api-account-item-str">
                                <div><?php echo number_format( $item->total_balance, 2, '.', ' ' ); ?></div>
                                <small><?php _e( 'Total money balance', 'mkm-api' ); ?></small>
                            </div>
                            <div class="mkm-api-account-item-str">
                                <div><?php echo number_format( $item->money_balance, 2, '.', ' ' ); ?></div>
                                <small><?php _e( 'Real money balance', 'mkm-api' ); ?></small>
                            </div>
                            <div class="mkm-api-account-item-str">
                                <div><?php echo number_format( $item->bonus_balance, 2, '.', ' ' ); ?></div>
                                <small><?php _e( 'Bonus credit balance', 'mkm-api' ); ?></small>
                            </div>
                            <div class="mkm-api-account-item-str">
                                <div><?php echo number_format( $item->unpaid_amount, 2, '.', ' ' ); ?></div>
                                <small><?php _e( 'Total amount of unpaid orders', 'mkm-api' ); ?></small>
                            </div>
                            <div class="mkm-api-account-item-str">
                                <div><?php echo number_format( $item->provider_recharge_amount, 2, '.', ' ' ); ?></div>
                                <small><?php _e( 'Total amount to be paid payment providers', 'mkm-api' ); ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
        <?php
    }

    /**
     * @return void
     * Forms the initial output of these articles to the screen
     */
    function mkm_api_orders_articles() {
        $data = mkm_api_get_articles( 0, 'all' );
        ?>
            <div class="wrap mkm-api-wrap">
                <h2 style="margin-bottom: 20px;"><?php _e( 'MKM API Articles', 'mkm-api' ); ?></h2>
                <?php if ( $data['count'] > 0 ) { ?>
                    <div id="mlm-api-article-wrap">
                        <?php foreach ( $data['result'] as $item ) { ?>
                        <div class="mkm-api-account-item">
                            <div class="mkm-api-row">
                                <div class="mkm-api-colum-2">
                                    <img src="https://cardmarket.com/<?php echo $item->a_image ?>" style="width: 100%;">
                                </div>
                                <div class="mkm-api-colum-10">
                                    <div class="mkm-api-row">
                                        <div class="mkm-api-colum-4">
                                            <div class="mkm-api-account-item-str">
                                                <div><?php echo $item->en_name . ' (#' . $item->id_article . ')'; ?></div>
                                                <small><?php _e( 'Name, ID Article', 'mkm-api' ); ?></small>
                                            </div>
                                            <div class="mkm-api-account-item-str">
                                                <div><?php echo $item->id_product; ?></div>
                                                <small><?php _e( 'ID Product', 'mkm-api' ); ?></small>
                                            </div>
                                            <div class="mkm-api-account-item-str">
                                                <div><?php echo $item->language_name; ?></div>
                                                <small><?php _e( 'Language', 'mkm-api' ); ?></small>
                                            </div>
                                            <div class="mkm-api-account-item-str">
                                                <div><?php echo number_format( $item->price, 2, '.', ' ' ); ?></div>
                                                <small><?php _e( 'Price', 'mkm-api' ); ?></small>
                                            </div>
                                            <div class="mkm-api-account-item-str">
                                                <div><span class="mkm-api-card-icon" style="background-position: -<?php echo ( $item->expIcon%10 ) * 21; ?>px -<?php echo floor( $item->expIcon / 10 ) * 21; ?>px;"></span></div>
                                                <small><?php _e( 'Icon', 'mkm-api' ); ?></small>
                                            </div>
                                        </div>
                                        <div class="mkm-api-colum-4">
                                            <div class="mkm-api-account-item-str">
                                                <div><?php echo $item->appname; ?></div>
                                                <small><?php _e( "App Name", 'mkm-api' ); ?></small>
                                            </div>
                                            <div class="mkm-api-account-item-str">
                                                <div><?php echo $item->rarity; ?></div>
                                                <small><?php _e( "Product's rarity", 'mkm-api' ); ?></small>
                                            </div>
                                            <div class="mkm-api-account-item-str">
                                                <div><?php echo $item->a_condition; ?></div>
                                                <small><?php _e( "Product's condition", 'mkm-api' ); ?></small>
                                            </div>
                                            <div class="mkm-api-account-item-str">
                                                <div><?php echo $item->last_edited; ?></div>
                                                <small><?php _e( "Last edited", 'mkm-api' ); ?></small>
                                            </div>
                                            <div class="mkm-api-account-item-str">
                                                <div><?php echo $item->counts; ?></div>
                                                <small><?php _e( 'Number of single within the expansion', 'mkm-api' ); ?></small>
                                            </div>
                                        </div>
                                        <div class="mkm-api-colum-4">
                                            <div class="mkm-api-account-item-str">
                                                <div><?php echo (bool)$item->in_shopping_cart ? 'Yes' : 'No'; ?></div>
                                                <small><?php _e( "Product in basket", 'mkm-api' ); ?></small>
                                            </div>
                                            <div class="mkm-api-account-item-str">
                                                <div><?php echo (bool)$item->is_foil ? 'Yes' : 'No'; ?></div>
                                                <small><?php _e( "Foil", 'mkm-api' ); ?></small>
                                            </div>
                                            <div class="mkm-api-account-item-str">
                                                <div><?php echo (bool)$item->is_signed ? 'Yes' : 'No'; ?></div>
                                                <small><?php _e( "Signed", 'mkm-api' ); ?></small>
                                            </div>
                                            <div class="mkm-api-account-item-str">
                                                <div><?php echo (bool)$item->is_altered ? 'Yes' : 'No'; ?></div>
                                                <small><?php _e( "Altered", 'mkm-api' ); ?></small>
                                            </div>
                                            <div class="mkm-api-account-item-str">
                                                <div><?php echo (bool)$item->is_playset ? 'Yes' : 'No'; ?></div>
                                                <small><?php _e( "Playset", 'mkm-api' ); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php } } ?>
                    </div>
                <button class="button button-primary" id="more-articles" data-start="30">Show more <span id="mkm-api-show-more-articles"><?php echo $data['count'] - 30; ?></span></button>
            </div>
        <?php
    }

    /**
     * @return array
     * Get data for accounts
     */
    function mkm_api_get_accounts() {
        global $wpdb;
        $data = $wpdb->get_results( "SELECT * FROM mkm_api_accounts");
        return $data;
    }

    /**
     * @return array
     * Get data for articles
     */
    function mkm_api_get_articles( $start = 0, $apps = 'all' ) {
        global $wpdb;
        $perpage = 30;
        $where   = $apps == 'all' ? '' : "WHERE appname='$apps'";
        $data['count']  = $wpdb->get_var( "SELECT count(*) FROM mkm_api_articles $where" );
        $data['result'] = $wpdb->get_results( "SELECT * FROM mkm_api_articles $where ORDER BY last_edited DESC LIMIT $start, $perpage" );
        return $data;
    }

    /**
     * @return array
     * Adding Time Intervals to Standard WP Intervals
     */
    function mkm_api_add_schedules( $schedules ) {
        $schedules['mkm-api-minute'] = array(
            'interval' => 60,
            'display'  => __( 'Every 1 minute', 'mkm-api' ),
        );

        $schedules['mkm-api-ten-minutes'] = array(
            'interval' => 600,
            'display'  => __( 'Every 10 minutes', 'mkm-api' ),
        );

        $schedules['mkm-api-four-hours'] = array(
            'interval' => 4* HOUR_IN_SECONDS,
            'display'  => __( 'Every 4 hours', 'mkm-api' ),
        );

        uasort( $schedules, function( $a, $b ){
            if ( $a['interval'] == $b['interval'] )return 0;
            return $a['interval'] < $b['interval'] ? -1 : 1;
        });
        return $schedules;
    }

    $options = get_option( 'mkm_api_options' );

    if ( is_array( $options ) && count( $options ) > 0 ) {

        foreach ( $options as $options_key => $options_val ) {
            add_action( 'mkm_api_cron_' . $options_key, 'mkm_cron_setup' );
            add_action( 'mkm_api_cron_check_' . $options_key, 'mkm_cron_setup_check' );
        }
    }

    /**
     * @param array
     * @return void
     * Performing Cron Tasks
     */
    function mkm_cron_setup( $args ) {

        global $mkmApiBaseUrl;
        $options = get_option( 'mkm_api_options' );
        $key     = $args['key'];
        $flag    = true;
        $count   = 1;
        $state   = 0;
        $api     = array( 1, 2, 4, 8 );

        if ( (bool)$options[$key]['checks']['orders'] ) {
            while ( $flag ) {
                $data    = mkm_api_auth( $mkmApiBaseUrl . $api[$state] . "/" . $count, $options[$key]['app_token'], $options[$key]['app_secret'], $options[$key]['access_token'], $options[$key]['token_secret'] );
                if ( isset ( $data->order[0]->idOrder ) &&  $data->order[0]->idOrder != 0 ) {
                    sleep( 1 );
                    mkm_api_add_data_from_db( $data, $key );
                    $count = $count + 100;
                    if ( $count >= 501 ) $flag = false;
                } else {
                    if ( $state >= 4 ) {
                        $flag = false;
                    } else {
                        $count = 1;
                        $state++;
                    }
                }
            }
        }

    }

    /**
     * @param array
     * @return void
     * Performing Cron Tasks for article and accounts
     */
    function mkm_cron_setup_check( $args ) {

        global $mkmApiBaseUrl;
        $options = get_option( 'mkm_api_options' );
        $key     = $args['key'];
        $flag    = true;
        $count   = 1;

        if ( (bool)$options[$key]['checks']['articles'] ) {
            while ( $flag ) {
                $data    = mkm_api_auth( 'https://api.cardmarket.com/ws/v2.0/stock/' . $count, $options[$key]['app_token'], $options[$key]['app_secret'], $options[$key]['access_token'], $options[$key]['token_secret'] );
                if ( isset ( $data->article[0]->idArticle ) &&  $data->article[0]->idArticle != 0 ) {
                    sleep( 1 );
                    mkm_api_add_articles_from_db( $data, $key );
                    $count += 100;
                } else {
                    $flag  = false;
                    $count = 1;
                }
            }
        }

        if ( (bool)$options[$key]['checks']['account'] ) {
            $data = mkm_api_auth( "https://api.cardmarket.com/ws/v2.0/account", $options[$key]['app_token'], $options[$key]['app_secret'], $options[$key]['access_token'], $options[$key]['token_secret'] );
            mkm_api_add_account_from_db( $data, $key );
        }

    }
