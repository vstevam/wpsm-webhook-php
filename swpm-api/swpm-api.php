<?php
/*
  Plugin Name: Simple Membership API
  Version: v1.5
  Plugin URI: https://simple-membership-plugin.com/
  Author: alexanderfoxc
  Author URI: https://simple-membership-plugin.com/
  Description: This addon allows you to use API to manage users.
 */

//Direct access to this file is not permitted
if ( ! defined( 'ABSPATH' ) ) {
    exit; //Exit if accessed directly
}

define( 'SWPM_API_PATH', dirname( __FILE__ ) . '/' );

if ( class_exists( 'SimpleWpMembership' ) && class_exists( 'SwpmRegistration' ) ) {
    require_once ('classes/class.swpm-api.php');

    add_action( 'plugins_loaded', "swpm_api_plugins_loaded" );
}

function swpm_api_plugins_loaded() {
    new SwpmAPI();
}
