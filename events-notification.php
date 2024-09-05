<?php
/**
 * Plugin Name: Event Notification
 * Description: Send mail to user based on subscription of categories.
 * Version: 1.9
 * Author: Cool Plugins
 *
 * This file contains the setup and activation code for your plugin.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// new plugin constant defined.
if ( ! defined( 'CPEN_PLUGIN_CURRENT_VERSION' ) ) {
	define( 'CPEN_PLUGIN_CURRENT_VERSION', '1.9' );
}
if ( ! defined( 'CPEN_PLUGIN_FILE' ) ) {
	define( 'CPEN_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'CPEN_PLUGIN_URL' ) ) {
	define( 'CPEN_PLUGIN_URL', plugin_dir_url( CPEN_PLUGIN_FILE ) );
}
// if ( ! defined( 'EWPE_PLUGIN_DIR' ) ) {
// define( 'EWPE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
// }
register_activation_hook( CPEN_PLUGIN_FILE, array( 'Events_Notification', 'cpen_activate' ) );
register_deactivation_hook( CPEN_PLUGIN_FILE, array( 'Events_Notification', 'cpen_deactivate' ) );
/**
 * Class Events_Calendar_Addon_Pro
 */
final class Events_Notification {
	/**
	 * Plugin instance.
	 *
	 * @var Events_Notification
	 * @access private
	 */
	private static $instance = null;
	/**
	 * Get plugin instance.
	 *
	 * @return Events_Notification
	 * @static
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	/**
	 * Constructor.
	 *
	 * @access private
	 */
	private function __construct() {
		add_shortcode( 'display-category', array( $this, 'cpen_categories' ) );
		add_shortcode( 'select-event-category', array( $this, 'cpen_get_event_categories' ) );
		add_shortcode( 'display-category-title', array( $this, 'cpen_category_title' ) );
		wp_enqueue_style( 'common-css', plugin_dir_url( __FILE__ ) . '/common.css', array(), CPEN_PLUGIN_CURRENT_VERSION );
		add_action( 'wp_ajax_save_category', array( $this, 'save_category_data' ) );
		add_action( 'wp_ajax_nopriv_save_category', array( $this, 'save_category_data' ) );
		add_action( 'wp', array( $this, 'schedule_email_notification_task' ) );
		add_filter( 'cron_schedules', array( $this, 'cpen_add_minute_interval' ) );
		// add_action( 'admin_init', array( $this, 'cpen_send_notification' ), 20 );
		add_action( 'send_event_notification', array( $this, 'cpen_send_notification' ), 20 );
		// add_action( 'template_redirect', array( $this, 'add_custom_button_to_title' ) );
		add_action( 'pmpro_membership_level_after_other_settings', array( $this, 'cpen_custom_pmpro_membership_level_after_other_settings' ) );
		add_action( 'pmpro_after_change_membership_level', array( $this, 'cpen_update_data_after_levelchange' ), 10, 3 );
	}

	/**
	 * Created our own schedule for 30 minutes cron job.
	 */
	public function cpen_update_data_after_levelchange( $level_id, $user_id, $cancel_level ) {
		$member_id       = '';
		$subscription_id = '';
		global $wpdb;
		$results = $this->cpen_fetch_membershipuserdata( $user_id );
		// fetching total credits from new level.
		$resultcatcount = $this->cpen_fetch_levelmetadata( $level_id );
		if ( isset( $results[0] ) ) {
			$member_id       = $results[0]->user_id;
			$subscription_id = $results[0]->membership_id;
			$results2        = $this->cpen_fetch_userselectiondata( $member_id );
			if ( $results2 ) {
					$totalcredit       = $resultcatcount[0]->meta_value;
					$selected_category = maybe_unserialize( $results2[0]->selcategory );
					$alreadyselected   = count( $selected_category );
				if ( $alreadyselected > $totalcredit ) {
					$creditavailable  = 0;
					$creditused       = $totalcredit;
					$updated_category = array_slice( $selected_category, 0, $totalcredit );
					$data             = array(
						'subscriptionid'  => $subscription_id,
						'totalcredit'     => $totalcredit,
						'creditavailable' => $creditavailable,
						'creditused'      => $creditused,
						'selcategory'     => maybe_serialize( $updated_category ),
					);
				} else {
					$creditavailable = $totalcredit - $alreadyselected;
					$creditused      = $totalcredit - $creditavailable;
					$data            = array(
						'subscriptionid'  => $subscription_id,
						'totalcredit'     => $totalcredit,
						'creditavailable' => $creditavailable,
						'creditused'      => $creditused,
					);
				}
					$where = array(
						'id' => $results2[0]->id,
					);
					$wpdb->update( $wpdb->prefix . 'cpen_userselection', $data, $where );

			}
		}
	}
	/**
	 * Created our own schedule for 30 minutes cron job.
	 */
	public function cpen_add_minute_interval( $schedules ) {
		$settingdata                              = get_option( 'event-notifications' );
		$interval_minutes                         = $settingdata['cpen-cron-time'];
		$schedules['event-notification-interval'] = array(
			'interval' => $interval_minutes * 60, // minutes in seconds
			'display'  => __( 'Every 30 Minutes' ),
		);
		return $schedules;
	}
	/**
	 * Add custom setting after other settings in PMPro membership level edit page.
	 */
	public function cpen_custom_pmpro_membership_level_after_other_settings( $level_id ) {
		// Get the current value of the custom setting, if any.

		global $wpdb;
			$memberlevelmeta = $wpdb->prefix . 'pmpro_membership_levelmeta';
			// Prefix the table name with WordPress prefix
			$querysetting    = "SELECT meta_value FROM $memberlevelmeta where pmpro_membership_level_id =" . $level_id->id . " AND meta_key = 'custom_setting_category';";
			$resultmetavalue = $wpdb->get_results( $querysetting );
		if ( isset( $resultmetavalue[0] ) ) {
			$custom_category = $resultmetavalue[0]->meta_value;
		} else {
			$custom_category = '';
		}
		$data     = '';
		$dataname = 'custom_setting_' . $level_id->id;
		$data    .= '<tr class="form-field">';
		$data    .= '<th scope="row" valign="top">';
		$data    .= '<label for= ' . $dataname . '>Total Categories Allowed</label>';
		$data    .= '	</th>
			<td>';
		$data    .= '<input type="number" id="' . $dataname . '" name="' . $dataname . '" value="' . $custom_category . '" class="regular-text" />';
		$data    .= '<p class="description">Enter the number of category you want user can select</p>';
		$data    .= '</td>
		</tr>';
		echo $data;

	}


	/**
	 * Save custom setting value when membership level is saved.
	 */
	public function cpen_custom_pmpro_save_membership_level_custom_setting( $level_id ) {
		// var_dump($_POST);
		// var_dump($level_id);
		// die();
		if ( isset( $_POST[ 'custom_setting_' . $level_id ] ) ) {
			$custom_setting_value = sanitize_text_field( $_POST[ 'custom_setting_' . $level_id ] );
			global $wpdb;
			$table1 = $wpdb->prefix . 'pmpro_membership_levelmeta';
			// Prefix the table name with WordPress prefix
			$query   = "SELECT meta_value FROM $table1 where pmpro_membership_level_id =" . $level_id . " AND meta_key = 'custom_setting_category';";
			$results = $wpdb->get_results( $query );
			if ( isset( $results[0] ) ) {
				// Data to update (only one column)
				$data = array(
					'meta_value' => $custom_setting_value,
				);

				// Condition to match rows to update
				$where = array(
					'pmpro_membership_level_id' => $level_id,
					'meta_key'                  => 'custom_setting_category',
				);

				$wpdb->update( $table1, $data, $where );
			} else {
				add_pmpro_membership_level_meta( $level_id, 'custom_setting_category', $custom_setting_value );
			}

			// update_post_meta( $level_id, 'custom_setting_category', $custom_setting_value );
		}
	}

	/**
	 * Fetch category name n button on category page.
	 */
	public function cpen_category_title() {
		if ( is_tax( 'tribe_events_cat' ) ) {

			wp_enqueue_script( 'subcribe-js', plugin_dir_url( __FILE__ ) . '/subscribebutton.js', array( 'jquery' ), CPEN_PLUGIN_CURRENT_VERSION );
			wp_enqueue_style( 'subscribe-css', plugin_dir_url( __FILE__ ) . '/subscirbebutton.css', array(), CPEN_PLUGIN_CURRENT_VERSION );
			$nonce = wp_create_nonce( 'select_category' );
			wp_localize_script(
				'subcribe-js',
				'subscribeButton',
				array(
					'url'   => admin_url( 'admin-ajax.php' ),
					'nonce' => $nonce,
				)
			);
			$category      = get_queried_object();
			$category_name = $category->name;
			$user_id       = get_current_user_id();
			// global $wpdb;
			// $table1          = $wpdb->prefix . 'pmpro_memberships_users';
			// $table3          = $wpdb->prefix . 'cpen_userselection';
			// $query           = "SELECT user_id, membership_id FROM $table1 where user_id =" . $user_id . " AND status = 'active';";
			// $results         = $wpdb->get_results( $query );
			// $member_id       = $results[0]->user_id;
			// $subscription_id = $results[0]->membership_id;
			// $query2          = "SELECT * FROM $table3 where memberid = $member_id;";
			// $results2        = $wpdb->get_results( $query2 );
			$member_id       = '';
			$subscription_id = '';
			global $wpdb;
			$results = $this->cpen_fetch_membershipuserdata( $user_id );
			if ( isset( $results[0] ) ) {
				$member_id       = $results[0]->user_id;
				$subscription_id = $results[0]->membership_id;
				$results2        = $this->cpen_fetch_userselectiondata( $member_id );
				$resultcatcount  = $this->cpen_fetch_levelmetadata( $subscription_id );
				$data            = '';
				// Display the category name
				$data .= '<div class="category_name_btn">';
				// $data .= '<p>' . $category_name . '</p>';
				if ( $results2 ) {
					$selected_category = maybe_unserialize( $results2[0]->selcategory );
					if ( is_array( $selected_category ) == false ) {
						$selected_cat = (array) $selected_category;
					} else {

						$selected_cat = $selected_category;
					}
				}

				if ( ! empty( $selected_cat ) ) {

					if ( in_array( $category->term_id, $selected_cat ) !== false ) {

						$data .= '<input type="button" id="subscribeButton" name="' . $category->term_id . '" value="Subscribed">';
					} else {
							$data .= '<input type="button" id="subscribeButton" name="' . $category->term_id . '" value="Subscribe">';
					}
				} else {
						$data .= '<input type="button" id="subscribeButton" name="' . $category->term_id . '" value="Subscribe">';
				}
				$data .= '</div>';
				return $data;
			}
		}
	}
	/**
	 * Run when activate plugin.
	 */
	public static function cpen_activate() {
		// Add activation tasks here, such as creating database tables, options, etc.
		global $wpdb;
		$table_name = $wpdb->prefix . 'cpen_userselection';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				memberid mediumint(9) NOT NULL,
				subscriptionid mediumint(9),
				totalcredit mediumint(9) ,
				creditavailable mediumint(9) ,
				creditused mediumint(9) ,
				selcategory longtext,
				notificationtype varchar(50),
				unsubscribe longtext,
				PRIMARY KEY  (id)
				) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$table_name1 = $wpdb->prefix . 'cpen_emaillog';
		$sql1        = "CREATE TABLE $table_name1 (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				memberid mediumint(9) NOT NULL,
				eventid mediumint(9),
				notificationtype varchar(50),
				reminder varchar(55),
				remindertime timestamp,
				PRIMARY KEY  (id)
				) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql1 );
	}
	/**
	 * Run when deactivate plugin.
	 */
	public static function cpen_deactivate() {
		// Add deactivation tasks here, such as removing database entries, etc.
	}
	/**
	 * Run when ajax is called.
	 */
	public function save_category_data() {

		// {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'select_category' ) ) {
			wp_die( 'Unauthorized request' );
		}
		if ( isset( $_POST ) ) {
			$user_id         = get_current_user_id();
			$useremail       = wp_get_current_user()->data->user_email;
			$selected_fields = $_REQUEST['category'];
			$member_id       = '';
			$subscription_id = '';
			global $wpdb;
			$results = $this->cpen_fetch_membershipuserdata( $user_id );
			if ( isset( $results[0] ) ) {
				$member_id       = $results[0]->user_id;
				$subscription_id = $results[0]->membership_id;
				$results2        = $this->cpen_fetch_userselectiondata( $member_id );
				$resultcatcount  = $this->cpen_fetch_levelmetadata( $subscription_id );
				if ( $results2 ) {
					// var_dump($results2[0]->selcategory);
					$new_selected_cat  = array();
					$selected_category = maybe_unserialize( $results2[0]->selcategory );
					if ( is_array( $selected_category ) == false ) {
						$selected_cat = (array) $selected_category;
					} else {
						$selected_cat = array();
						$selected_cat = $selected_category;
						// /var_dump($selected_cat);
					}
					if ( count( $selected_cat ) >= 1 ) {
						// $category = explode( ',', $results2[0]->selcategory );
						if ( in_array( $selected_fields, $selected_cat ) ) {
							$new_selected_cat = array_diff( $selected_cat, array( $selected_fields ) );
						} else {
							array_push( $selected_cat, $selected_fields );
							$new_selected_cat = $selected_cat;
						}
					} else {
						$new_selected_cat[] = $selected_fields;
					}
					if ( is_array( $new_selected_cat ) ) {
						$checkedcategory = count( $new_selected_cat );
						if ( $checkedcategory > $resultcatcount[0]->meta_value ) {
							wp_send_json( 'limit reached' );
						}
						if ( $new_selected_cat != null ) {
							$updatecategories = $new_selected_cat;
						}
					} else {
						$checkedcategory  = 1;
						$updatecategories = $new_selected_cat;
					}
					$totalcredit     = $resultcatcount[0]->meta_value;
					$creditavailable = $totalcredit - $checkedcategory;
					$creditused      = $totalcredit - $creditavailable;
					$notify          = array( 24, 12, 6 );
					$notifydata      = json_encode( $notify );
					$data            = array(
						'id'               => $results2[0]->id,
						'memberid'         => $results2[0]->memberid,
						'subscriptionid'   => $results2[0]->subscriptionid,
						'totalcredit'      => $totalcredit,
						'creditavailable'  => $creditavailable,
						'creditused'       => $creditused,
						'selcategory'      => maybe_serialize( $updatecategories ),
						'notificationtype' => $notifydata,
					);
					$ajax_data       = array(
						'creditavailable' => $creditavailable,
						'creditused'      => $creditused,
						'selcategory'     => $updatecategories,
					);
					// Define the format of the data
					$format = array( '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' );
					$wpdb->replace(
						$wpdb->prefix . 'cpen_userselection', // Replace 'your_table_name' with the actual table name
						$data,
						$format
					);

					wp_send_json( $ajax_data );

				} else {
					$totalcredit     = $resultcatcount[0]->meta_value;
					$creditavailable = $totalcredit - 1;
					$creditused      = $totalcredit - $creditavailable;
					$selcat          = array();
					array_push( $selcat, $selected_fields );
					// $selectedcat = json_encode( $selcat );
					$selectedcat = $selcat;
					$record      = array(
						'memberid'        => $member_id,
						'subscriptionid'  => $subscription_id,
						'totalcredit'     => $totalcredit,
						'creditavailable' => $creditavailable,
						'creditused'      => $creditused,
						'selcategory'     => maybe_serialize( $selectedcat ),
					);
					$ajax_record = array(
						'creditavailable' => $creditavailable,
						'creditused'      => $creditused,
						'selcategory'     => $selectedcat,
					);
					$wpdb->insert(
						$wpdb->prefix . 'cpen_userselection',
						$record
					);
					wp_send_json( $ajax_record );
				}
				exit;
			} else {
				die( 'No data found' );
			}
		}
	}


	/**
	 * Fetches all categories.
	 *
	 * @since 1.0.0
	 */
	public function cpen_get_event_categories() {
		wp_enqueue_script( 'my-js', plugin_dir_url( __FILE__ ) . '/category.js', array( 'jquery' ), CPEN_PLUGIN_CURRENT_VERSION );
		wp_enqueue_style( 'my-css', plugin_dir_url( __FILE__ ) . '/style.css', array(), CPEN_PLUGIN_CURRENT_VERSION );
		wp_enqueue_style( 'dashicons' );
		$terms           = get_terms(
			array(
				'taxonomy'   => 'tribe_events_cat',
				'hide_empty' => false,
			)
		);
		$user_id         = get_current_user_id();
		$member_id       = '';
		$subscription_id = '';
		global $wpdb;
		$results = $this->cpen_fetch_membershipuserdata( $user_id );
		if ( isset( $results[0] ) ) {
			$member_id       = $results[0]->user_id;
			$subscription_id = $results[0]->membership_id;
			$results2        = $this->cpen_fetch_userselectiondata( $member_id );
			$resultcatcount  = $this->cpen_fetch_levelmetadata( $subscription_id );
			$data            = '';
			$data           .= '<div class="category-container">
			<h2>List of subscribed platforms.</h2>';
			$creditavailable = '';
			$creditused      = '';
			if ( ! empty( $results2 ) ) {
				$creditavailable = $results2[0]->creditavailable;
				$creditused      = $results2[0]->creditused;
			} else {
				$creditavailable = $resultcatcount[0]->meta_value;
				$creditused      = 0;
			}
			$data .= '<div class="category-details">';
			$data .= '<div class="category-available"><h4>Credits Available: </h4> <span class="cat_available">' . $creditavailable . '</span></div>';
			$data .= '<div class="category-used"><h4>Credits Used: </h4> <span class="cat_selected">' . $creditused . '</span><br></div>';
			$data .= '</div>';
			$data .= '<div class="all-categories">';
			foreach ( $terms as $old_post ) {
				/**
				 * Includes the template file.
				 *
				 * @since 1.0.0
				 */
				if ( $results2 ) {
					$selected_category = maybe_unserialize( $results2[0]->selcategory );
					if ( is_array( $selected_category ) == false ) {
						$selected_cat = (array) $selected_category;
					} else {

						$selected_cat = $selected_category;
					}
				}
				if ( ! empty( $selected_cat ) ) {

					if ( in_array( $old_post->term_id, $selected_cat ) !== false ) {
						$data .= '<button class="category-button" value="' . $old_post->term_id . '" style="margin-right:20px;margin-bottom:10px;">' . $old_post->name . '<span class="ticker dashicons dashicons-yes selected"></span></button>';
					}
				}
			}
			$data .= '</div>';
			$data .= ' <div id="message"></div>';
			$data .= '</div>';
			$nonce = wp_create_nonce( 'select_category' );
			wp_localize_script(
				'my-js',
				'category_container',
				array(
					'url'   => admin_url( 'admin-ajax.php' ),
					'nonce' => $nonce,
				)
			);

			echo $data;

		}
	}
	/**
	 * Schedules email event.
	 */
	public function schedule_email_notification_task() {
		if ( ! wp_next_scheduled( 'send_event_notification' ) ) {
			$starttime = time();
			wp_schedule_event( $starttime, 'event-notification-interval', 'send_event_notification' );
		}
	}

	/**
	 * Send emails to all users.
	 */
	public function cpen_send_notification() {
		$current_timestamp        = time();
		$current_datetime         = gmdate( 'Y-m-d H:i:s', $current_timestamp );
		$settingdata              = get_option( 'event-notifications' );
		$notification_interval    = $settingdata['cpen-notifications-time'];
		$next_half_hour_timestamp = $current_timestamp + ( $notification_interval * 60 );
		$next_half_hour_time      = gmdate( 'Y-m-d H:i:s', $next_half_hour_timestamp );

		$args = array(
			'post_type'      => 'tribe_events',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_EventStartDate',
					'value'   => $current_datetime,
					'compare' => '>=', // Event start date is greater than or equal to the current datetime
					'type'    => 'DATETIME',
				),
				array(
					'key'     => '_EventStartDate',
					'value'   => $next_half_hour_time,
					'compare' => '<=', // Event start date is less than or equal to the next half-hour time
					'type'    => 'DATETIME',
				),
			),
		);

		$events = tribe_get_events( $args );
		foreach ( $events as $event ) {
			$selecteduser = array();
			$event_cat    = get_the_terms( $event->ID, 'tribe_events_cat' );

			foreach ( $event_cat  as $cat ) {
				global $wpdb;
				$userselectiontable = $wpdb->prefix . 'cpen_userselection';
				$query              = "SELECT * FROM $userselectiontable";
				$results            = $wpdb->get_results( $query );

				foreach ( $results as $result ) {
					$json_array = maybe_unserialize( $result->selcategory );
					if ( is_array( $json_array ) ) {
						if ( in_array( $cat->term_id, $json_array ) ) {
							$user_data      = get_userdata( $result->memberid );
							$selecteduser[] = $user_data->user_email;
						}
					} else {
						$new_array = (array) $json_array;
						if ( in_array( $cat->term_id, $new_array ) ) {
							$user_data      = get_userdata( $result->memberid );
							$selecteduser[] = $user_data->user_email;
						}
					}
				}
			}

			$unique_user = array_unique( $selecteduser );
			foreach ( $unique_user as $id ) {
				global $wpdb;
				// $userselectiontable = $wpdb->prefix . 'cpen_userselection';
				// $query              = "SELECT memberid FROM $userselectiontable where useremail = '" . $id . "'";
				// $results            = $wpdb->get_results( $query );
				// $member             = $results[0]->memberid;
				$userdata        = get_user_by( 'email', $id );
				$member          = $userdata->ID;
				$eventid         = $event->ID;
				$emailchecktable = $wpdb->prefix . 'cpen_emaillog';
				$queryreminder   = "SELECT reminder FROM $emailchecktable where memberid = " . $member . '  AND eventid = ' . $eventid . '';
				$reminderresults = $wpdb->get_results( $queryreminder );
				if ( isset( $reminderresults[0]->reminder ) && $reminderresults[0]->reminder == 'yes' ) {
					continue;
				} else {
					$to = $id;
					// $subject = 'Reminder for Event -' . $event->post_title;
					$emaildata    = get_option( 'event-notifications' );
					$emailsubject = $emaildata['email-title'];
					$emailbody    = $emaildata['email-template'];
					$tokens       = array(
						'{event-title}'       => $event->post_title,
						'{event-description}' => $event->post_content,
						'{event-startdate}'   => tribe_get_start_date( $event->ID ),
						'{event-link}'        => tribe_get_event_link( $event->ID ),
					);

					// Replace tokens with values
					foreach ( $tokens as $token => $value ) {
						$emailsubject = str_replace( $token, $value, $emailsubject );
						$emailbody    = str_replace( $token, $value, $emailbody );

					}

					// $message      = 'Hello, this is the reminder for the event "' . $event->post_title . '" taking place on ' . tribe_get_start_date( $event->ID ) . 'For More Details: <a href="' . tribe_get_event_link( $event->ID ) . '"/>Visit Event Page';
					$sent_message = wp_mail( $to, $emailsubject, $emailbody );
					if ( $sent_message ) {
						echo 'The test message was sent. Check your email inbox.';
						$emailtable = $wpdb->prefix . 'cpen_emaillog';
						$data       = array(
							'memberid'         => $member,
							'eventid'          => $event->ID,
							'notificationtype' => '',
							'reminder'         => 'yes',
						);
						$format     = array( '%d', '%d', '%s', '%s' );
						$wpdb->replace(
							$emailtable,
							$data,
							$format
						);
					} else {
						echo 'The message was not sent!';
					}
				}
			}
		}

	}

	/**
	 * Shortcode for new category display table on page.
	 */
	public function cpen_categories( $atts ) {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'datatables', '//cdn.datatables.net/1.10.24/js/jquery.dataTables.min.js', array( 'jquery' ), '1.10.24', true );
		// Enqueue DataTables CSS
		wp_enqueue_style( 'datatables', '//cdn.datatables.net/1.10.24/css/jquery.dataTables.min.css', array(), '1.10.24' );
		wp_enqueue_script( 'allcatsubuscribebtn-js', plugin_dir_url( __FILE__ ) . '/allcatsubscribebtn.js', array( 'jquery' ), CPEN_PLUGIN_CURRENT_VERSION );
		$atts               = shortcode_atts(
			array(
				'parent_category' => 'launchpads', // Default number of categories per page
			),
			$atts
		);
		$parent_category    = get_term_by( 'name', $atts['parent_category'], 'tribe_events_cat' );
		$tablecolumnheading = $atts['parent_category'];
		$platformheading    = ucfirst( $tablecolumnheading );
		// Arguments for fetching categories
		$args = get_terms(
			array(
				'taxonomy'   => 'tribe_events_cat',
				'orderby'    => 'name',
				'order'      => 'ASC',
				'hide_empty' => false,
				'parent'     => $parent_category->term_id,
			)
		);

		// query to fetch selected category by logged in user.
		if ( is_user_logged_in() ) {
			$user_id         = get_current_user_id();
			$member_id       = '';
			$subscription_id = '';
			global $wpdb;
			$results = $this->cpen_fetch_membershipuserdata( $user_id );
			if ( isset( $results[0] ) ) {
				$member_id       = $results[0]->user_id;
				$subscription_id = $results[0]->membership_id;
				$results2        = $this->cpen_fetch_userselectiondata( $member_id );

				// Output categories in a table.
				$output  = '<table class="cpen-categories-table" id="myDataTable">';
				$output .= '<thead><tr><th>' . $platformheading . '</th><th>Description</th><th>Action</th></tr></thead>';
				$output .= '<tbody>';
				foreach ( $args as $category ) {
					$img_id    = get_term_meta( $category->term_id, 'featured-image' );
					$post_guid = '';
					$alt_text  = '';
					if ( isset( $img_id ) ) {
						$post_guid = get_post_field( 'guid', $img_id[0] );
						$alt_text  = get_post_field( 'post_title', $img_id[0] );
					}
					$output .= '<tr>';
					$output .= '<td class="cpen-category"><a class="cpen-category-link" target="" href="' . get_term_link( $category->term_id ) . '"><img class="cpen-category-img" src="' . $post_guid . '" alt="' . $alt_text . '">' . $category->name . '</a></td>';
					$output .= '<td class="cpen-description">';
					if ( ! empty( $category->description ) ) {
						$output .= $category->description;
					} else {
						$output .= '<p style="color:#959292;">----No description for this category----</p>';
					}
					$output      .= '</td>';
					$selected_cat = array();
					if ( $results2 ) {
						$selected_category = maybe_unserialize( $results2[0]->selcategory );
						if ( is_array( $selected_category ) == false ) {
							$selected_cat = (array) $selected_category;
						} else {

							$selected_cat = $selected_category;
						}
					}

					if ( ! empty( $selected_cat ) ) {

						if ( in_array( $category->term_id, $selected_cat ) !== false ) {
							$output .= '<td class="cpen-subscribe" style="text-align: center;"><button class="cpen-subscribe-cat-button subscribed" data-category-id="' . $category->term_id . '" value="' . $category->term_id . '">Subscribed</button></td>';
						} else {
							$output .= '<td class="cpen-subscribe" style="text-align: center;"><button class="cpen-subscribe-cat-button" data-category-id="' . $category->term_id . '" value="' . $category->term_id . '">Subscribe</button></td>';
						}
					} else {
						$output .= '<td class="cpen-subscribe" style="text-align: center;"><button class="cpen-subscribe-cat-button" data-category-id="' . $category->term_id . '" value="' . $category->term_id . '">Subscribe</button></td>';
					}
					// $output .= '<td class="cpen-subscribe" style="text-align: center;"><button class="cpen-subscribe-cat-button" data-category-id="' . $category->term_id . '" value="' . $category->name . '">Subscribe</button></td>';
					$output .= '</tr>';
				}
				$output .= '</tbody>';
				$output .= '</table>';
				$nonce   = wp_create_nonce( 'select_category' );
				wp_localize_script(
					'allcatsubuscribebtn-js',
					'subscribe_category_btn',
					array(
						'url'   => admin_url( 'admin-ajax.php' ),
						'nonce' => $nonce,
					)
				);
				return $output;
			}
		} else {
				// Output categories in a table
			$output  = '<table class="cpen-categories-table" id="myDataTable">';
			$output .= '<thead><tr><th>' . $platformheading . '</th><th>Description</th><th>Action</th></tr></thead>';
			$output .= '<tbody>';
			foreach ( $args as $category ) {
				$img_id        = get_term_meta( $category->term_id, 'featured-image' );
					$post_guid = '';
					$alt_text  = '';
				if ( isset( $img_id ) ) {
					$post_guid = get_post_field( 'guid', $img_id[0] );
					$alt_text  = get_post_field( 'post_title', $img_id[0] );
				}
				$output .= '<tr>';
				$output .= '<td class="cpen-category"><a class="cpen-category-link" target="" href="' . get_term_link( $category->term_id ) . '"><img class="cpen-category-img" src="' . $post_guid . '" alt="' . $alt_text . '">' . $category->name . '</a></td>';
				$output .= '<td class="cpen-description">';
				if ( ! empty( $category->description ) ) {
					$output .= $category->description;
				} else {
					$output .= '<p style="color:#959292;">----No description for this category----</p>';
				}
				$output .= '</td>';
				$output .= '<td class="cpen-subscribe" style="text-align: center;"><button class="cpen-subscribe-button" data-category-id="' . $category->term_id . '">Subscribe</button></td>';
				$output .= '</tr>';
			}
			$output .= '</tbody>';
			$output .= '</table>';
			return $output;
		}
	}

	/**
	 * Shortcode for new category page.
	 */
	public function cpen_fetch_membershipuserdata( $user_id ) {
		global $wpdb;
		$table1  = $wpdb->prefix . 'pmpro_memberships_users';
		$query   = "SELECT user_id, membership_id FROM $table1 where user_id =" . $user_id . " AND status = 'active';";
		$results = $wpdb->get_results( $query );
		return $results;
	}
	/**
	 * Shortcode for new category page.
	 */
	public function cpen_fetch_userselectiondata( $member_id ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'cpen_userselection';
		$query   = "SELECT * FROM $table where memberid = $member_id;";
		$results = $wpdb->get_results( $query );
		return $results;
	}
	/**
	 * Shortcode for new category page.
	 */
	public function cpen_fetch_levelmetadata( $subscription_id ) {
		global $wpdb;
		$table          = $wpdb->prefix . 'pmpro_membership_levelmeta';
		$querycatcount  = "SELECT meta_value FROM $table where pmpro_membership_level_id =" . $subscription_id . " AND meta_key = 'custom_setting_category';";
		$resultcatcount = $wpdb->get_results( $querycatcount );
		return $resultcatcount;
	}

}
/**
 * Function creates instance for the class.
 */
function events_notification() {
	return Events_Notification::get_instance();
}
events_notification();




