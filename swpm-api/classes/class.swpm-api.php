<?php

class SwpmAPI extends SwpmRegistration {

    public function __construct() {
	if ( class_exists( 'SimpleWpMembership' ) ) {
	    add_action( 'swpm_addon_settings_section', array( &$this, 'settings_ui' ) );
	    add_action( 'swpm_addon_settings_save', array( &$this, 'settings_save' ) );
	    add_action( 'init', array( &$this, 'handle_api_req' ) );
	}
    }

    function handle_api_req() {

	function post_reply( $reply, $success = true ) {
	    if ( $success ) {
		$res = 'success';
	    } else {
		$res = 'failure';
	    }
	    $reply = array( 'result' => $res ) + $reply;
	    ob_end_clean();
	    echo json_encode( $reply );
	    die( 0 );
	}

	if ( ! isset( $_REQUEST[ 'swpm_api_action' ] ) ) {
	    //this is not API request. Aborting.
	    return false;
	}

	$action = $_REQUEST[ 'swpm_api_action' ];

	//check if API is enabled
	$settings = SwpmSettings::get_instance();
	if ( ! $settings->get_value( 'swpm-addon-enable-api' ) ) {
	    //API disabled in the settings.
	    return false;
	}

	if ( ! isset( $_REQUEST[ 'key' ] ) ) {
	    $reply[ 'message' ] = "No API key provided";
	    post_reply( $reply, false );
	}

	$api_key = $settings->get_value( 'swpm-addon-api-key' );

	if ( $api_key !== $_REQUEST[ 'key' ] ) {
	    //invalid API key
	    $reply[ 'message' ] = "Invalid API key";
	    post_reply( $reply, false );
	}

	global $wpdb;
	$reply = array();

	if ( $action === 'create' ) {
	    //create member action
	    //check for mandatory fields
	    if ( ! isset( $_POST[ 'first_name' ] ) || ! isset( $_POST[ 'last_name' ] ) && ! isset( $_POST[ 'email' ] ) ) {
		//one of the mandatory fields is missing
		$reply[ 'message' ] = "Missing one of the mandatory fields: first_name, last_name, email";
		post_reply( $reply, false );
	    }

	    $member	 = SwpmTransfer::$default_fields;
	    $form	 = new SwpmForm( $member );

	    if ( ! $form->is_valid() ) {
		$errors = $form->get_errors();
		// check if this is "Password mistach" error. If it is, we ignore it
		if ( isset( $errors[ 'password' ] ) && $errors[ 'password' ] === SwpmUtils::_( 'Password mismatch' ) ) {
		    unset( $errors[ 'password' ] );
		}
		// since we allow user to be created with minimal set of params and SWMPForm doesn't know that, we need to check if it produced related errors
		// we ignore "password required" and "username required" errors
		if ( isset( $errors[ 'password' ] ) && $errors[ 'password' ] === SwpmUtils::_( 'Password is required' ) ) {
		    unset( $errors[ 'password' ] );
		}
		if ( isset( $errors[ 'user_name' ] ) && $errors[ 'user_name' ] === SwpmUtils::_( 'Username is required' ) ) {
		    unset( $errors[ 'user_name' ] );
		}
		if ( ! empty( $errors ) ) {
		    // there are sanitization errors
		    $reply[ 'errors' ]	 = $errors;
		    $reply[ 'message' ]	 = 'Errors occurred';
		    post_reply( $reply, false );
		}
	    }

	    unset( $member );

	    $member = $form->get_sanitized_member_form_data();

	    if ( ! isset( $member[ 'account_state' ] ) ) {
		$member[ 'account_state' ] = $settings->get_value( 'default-account-status', 'active' );
	    }

	    if ( ! isset( $member[ 'membership_level' ] ) ) {
		if ( $settings->get_value( 'enable-free-membership' ) ) {
		    $member[ 'membership_level' ] = $settings->get_value( 'free-membership-id' );
		}
	    }

	    if ( ! isset( $member[ 'reg_code' ] ) ) {
		$md5_code		 = md5( uniqid() );
		$member[ 'reg_code' ]	 = $md5_code;
	    }

	    $plain_pass = '';

	    if ( isset( $member[ 'plain_password' ] ) ) {
		$plain_pass = $member[ 'plain_password' ];
		unset( $member[ 'plain_password' ] );
	    }

            //Insert the member record into SWPM members table
	    $res = $wpdb->insert( $wpdb->prefix . "swpm_members_tbl", $member );
            SwpmLog::log_simple_debug('SWPM API addon: executed the member data insert db query.', true);

	    if ( ! $res ) {
		//DB error occured
                SwpmLog::log_simple_debug('SWPM API addon: Insert db query failed.', false);
		$reply[ 'message' ] = 'DB error occured: ' . json_encode( $wpdb->last_result );
		post_reply( $reply, false );
	    }

	    $member[ 'member_id' ] = $wpdb->insert_id;

	    //let's check if we need to create WP user also
	    //if no username or password provided, it means user creation will be handled by core plugin
	    if ( isset( $member[ 'user_name' ] ) && isset( $member[ 'password' ] ) ) {
		/* NEW USER registration fully complete scenario */
                //We should create WP user
		$query					 = $wpdb->prepare( "SELECT role FROM " . $wpdb->prefix . "swpm_membership_tbl WHERE id = %d", $member[ 'membership_level' ] );
		$wp_user_info				 = array();
		$wp_user_info[ 'user_nicename' ]	 = implode( '-', explode( ' ', $member[ 'user_name' ] ) );
		$wp_user_info[ 'display_name' ]		 = $member[ 'user_name' ];
		$wp_user_info[ 'user_email' ]		 = $member[ 'email' ];
		$wp_user_info[ 'nickname' ]		 = $member[ 'user_name' ];
		$wp_user_info[ 'first_name' ]		 = $member[ 'first_name' ];
		$wp_user_info[ 'last_name' ]		 = $member[ 'last_name' ];
		$wp_user_info[ 'user_login' ]		 = $member[ 'user_name' ];
		$wp_user_info[ 'password' ]		 = $plain_pass;
		$wp_user_info[ 'role' ]			 = $wpdb->get_var( $query );
		$wp_user_info[ 'user_registered' ]	 = date( 'Y-m-d H:i:s' );
		SwpmUtils::create_wp_user( $wp_user_info );

		//Check if we need to send notification email
		//if send_email parameter is present, we use it to determinte should we send it or not
		//if it's not present, we're using gloabal setting 'enable-notification-after-manual-user-add'
		if ( (isset( $_POST[ 'send_email' ] ) && $_POST[ 'send_email' ]) ) {
		    $member[ 'plain_password' ]	 = $plain_pass;
		    $this->member_info		 = $member;

                    SwpmLog::log_simple_debug('SWPM API addon: Calling the send_reg_email() function to handle the email sending after full rego complete.', true);
		    $this->send_reg_email();//Send the "registration successful and complete" email.
		    unset( $member[ 'plain_password' ] );
		}
	    } else {
                /* NEW USER prompt to complete rego scenario */
		//We need to send "Complete your registration" email to user
		//But first we need to check if we're not disallowed to send emails
		if ( (isset( $_POST[ 'send_email' ] ) && $_POST[ 'send_email' ]) ) {
		    $separator	 = '?';
		    $url		 = $settings->get_value( 'registration-page-url' );
		    if ( strpos( $url, '?' ) !== false ) {
			$separator = '&';
		    }

		    $reg_url = $url . $separator . 'member_id=' . $member[ 'member_id' ] . '&code=' . $md5_code;

		    $subject = $settings->get_value( 'reg-prompt-complete-mail-subject' );
		    if ( empty( $subject ) ) {
			$subject = "Please complete your registration";
		    }
		    $body = $settings->get_value( 'reg-prompt-complete-mail-body' );
		    if ( empty( $body ) ) {
			$body = "Please use the following link to complete your registration. \n {reg_link}";
		    }
		    $from_address	 = $settings->get_value( 'email-from' );
		    $body		 = html_entity_decode( $body );

		    $additional_args = array( 'reg_link' => $reg_url );
		    $email_body	 = SwpmMiscUtils::replace_dynamic_tags( $body, $member[ 'member_id' ], $additional_args );
		    $headers	 = 'From: ' . $from_address . "\r\n";
		    $subject	 = apply_filters( 'swpm_email_complete_registration_subject_api_addon', $subject );
		    $email_body	 = apply_filters( 'swpm_email_complete_registration_body_api_addon', $email_body );
		    wp_mail( $member[ 'email' ], $subject, $email_body, $headers );
                    SwpmLog::log_simple_debug('SWPM API addon: Prompt to complete registration email sent to: ' . $member[ 'email' ], true);
		}
	    }

	    $reply[ 'message' ]	 = 'Member created successfully';
	    unset( $member[ 'password' ] );
	    $reply[ 'member' ]	 = $member;

	    post_reply( $reply );
	}

	if ( $action === 'update' ) {
	    //update member action
	    if ( ! isset( $_POST[ 'member_id' ] ) ) {
		//no required parameters provided
		$reply[ 'message' ] = 'Missing required parameters: member_id';
		post_reply( $reply, false );
	    }

	    $member_id = absint( $_POST[ 'member_id' ] );

	    //let's try to get member info with gived Id
	    $res = SwpmMemberUtils::get_user_by_id( $member_id );
	    if ( ! $res ) {
		//member not exists
		$reply[ 'message' ] = 'Member with given Id can\'t be found';
		post_reply( $reply, false );
	    }

	    //convert object to array
	    $member = get_object_vars( $res );

	    $form = new SwpmForm( $member );
	    if ( ! $form->is_valid() ) {
		$errors = $form->get_errors();
		// check if this is "Password mistach" error. If it is, we ignore it
		if ( isset( $errors[ 'password' ] ) && $errors[ 'password' ] === SwpmUtils::_( 'Password mismatch' ) ) {
		    unset( $errors[ 'password' ] );
		}
		// since we allow user to be created with minimal set of params and SWMPForm doesn't know that, we need to check if it produced related errors
		// we ignore "password required" and "username required" errors
		if ( isset( $errors[ 'password' ] ) && $errors[ 'password' ] === SwpmUtils::_( 'Password is required' ) ) {
		    unset( $errors[ 'password' ] );
		}
		if ( isset( $errors[ 'user_name' ] ) && $errors[ 'user_name' ] === SwpmUtils::_( 'Username is required' ) ) {
		    unset( $errors[ 'user_name' ] );
		}
		//We ignore "Email is required" error in this case
		if ( isset( $errors[ 'email' ] ) && $errors[ 'email' ] === SwpmUtils::_( 'Email is required' ) ) {
		    unset( $errors[ 'email' ] );
		}
		//We ignore "wp_user" error
		if ( isset( $errors[ 'wp_user' ] ) ) {
		    unset( $errors[ 'wp_user' ] );
		}

		if ( ! empty( $errors ) ) {
		    // there are sanitization errors
		    $reply[ 'errors' ]	 = $errors;
		    $reply[ 'message' ]	 = 'Errors occurred';
		    post_reply( $reply, false );
		}
	    }

	    $update_data = $form->get_sanitized_member_form_data();

	    //if there is no subscr_id or company_name in the post, we should unset those from update_data
	    //this is a workaround for SwmpForm setting those values as Null
	    if ( ! isset( $_POST[ 'subscr_id' ] ) && is_null( $update_data[ 'subscr_id' ] ) ) {
		unset( $update_data[ 'subscr_id' ] );
	    }
	    if ( ! isset( $_POST[ 'company_name' ] ) && is_null( $update_data[ 'company_name' ] ) ) {
		unset( $update_data[ 'company_name' ] );
	    }
	    //we need to unset user_name as it's not allowed to be changed
	    if ( isset( $update_data[ 'company_name' ] ) ) {
		unset( $update_data[ 'user_name' ] );
	    }

	    $updated_member = array_merge( $member, $update_data );

	    $plain_pass = '';

	    if ( isset( $updated_member[ 'plain_password' ] ) ) {
		$plain_pass = $updated_member[ 'plain_password' ];
		unset( $updated_member[ 'plain_password' ] );
	    }

	    if ( $member === $updated_member ) {
		//nothing to update. we do not produce error here
		$reply[ 'message' ] = "Nothing to update";
		post_reply( $reply );
	    }

	    $res = $wpdb->update( $wpdb->prefix . "swpm_members_tbl", $updated_member, array( 'member_id' => $member_id ) );

	    if ( ! $res ) {
		//DB error occured
		$reply[ 'message' ] = 'DB error occured: ' . json_encode( $wpdb->last_error );
		post_reply( $reply, false );
	    }

	    //let's update WP user if needed

	    $wp_user = SwpmMemberUtils::get_wp_user_from_swpm_user_id( $member_id );

	    if ( $wp_user ) {
		//WP user exists. Let's update it
		$res = SwpmUtils::update_wp_user( $wp_user->user_login, $updated_member );
		if ( ! $res ) {
		    //error occured during WP user update
		    //TODO: decide what to do here
		}
	    }

	    $reply[ 'message' ]	 = 'Member updated successfully';
	    unset( $updated_member[ 'password' ] );
	    $reply[ 'member' ]	 = $updated_member;

	    post_reply( $reply );
	}

	if ( $action === 'query' ) {
	    //query member action
	    if ( ! isset( $_REQUEST[ 'member_id' ] ) && ! isset( $_REQUEST[ 'email' ] ) ) {
		//no required parameters provided
		$reply[ 'message' ] = 'Missing required parameters: member_id or email';
		post_reply( $reply, false );
	    }
	    $q = "SELECT * FROM " . $wpdb->prefix . "swpm_members_tbl WHERE ";
	    if ( isset( $_REQUEST[ 'member_id' ] ) ) {
		$q	 .= " member_id = %d";
		$q	 = $wpdb->prepare( $q, $_REQUEST[ 'member_id' ] );
	    } else if ( isset( $_REQUEST[ 'email' ] ) ) {
		$q	 .= " email = %s";
		$q	 = $wpdb->prepare( $q, $_REQUEST[ 'email' ] );
	    }
	    $res = $wpdb->get_row( $q, ARRAY_A );
	    if ( $res ) {
		//member found
		$reply[ 'message' ]	 = 'Member found';
		$reply[ 'member_data' ]	 = $res;
		post_reply( $reply );
	    } else {
		//member not found
		$reply[ 'message' ] = "Member not found";
		post_reply( $reply, false );
	    }
	}
    }

    function settings_ui() {
	$settings	 = SwpmSettings::get_instance();
	$enable_api	 = $settings->get_value( 'swpm-addon-enable-api' );
	$api_key	 = $settings->get_value( 'swpm-addon-api-key' );
	if ( ! $api_key ) {
	    $api_key = md5( uniqid() );
	    $settings->set_value( 'swpm-addon-api-key', sanitize_text_field( $api_key ) );
	    $settings->save();
	}
	require_once (SWPM_API_PATH . 'views/settings.php');
    }

    function settings_save() {
	$message	 = array( 'succeeded' => true, 'message' => '<p>' . BUtils::_( 'Settings updated!' ) . '</p>' );
	SwpmTransfer::get_instance()->set( 'status', $message );
	$enable_api	 = filter_input( INPUT_POST, 'swpm-addon-enable-api' );
	$api_key	 = filter_input( INPUT_POST, 'swpm-addon-api-key' );

	$settings = SwpmSettings::get_instance();
	$settings->set_value( 'swpm-addon-enable-api', empty( $enable_api ) ? "" : $enable_api  );
	$settings->set_value( 'swpm-addon-api-key', sanitize_text_field( $api_key ) );
	$settings->save();
    }

}
