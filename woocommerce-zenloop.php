<?php
/*
Plugin Name: zenloop for WooCommerce - Net Promoter Score (NPS) platform
Plugin URI: https://www.zenloop.com
Description: zenloop for WooCommerce is the official zenloop.com plugin. It connects zenloop’s Net Promoter Score (NPS) platform with your WooCommerce shop.
Version: 3.1
Text Domain: woocommerce-zenloop
Author: zenloop
License: GPL2
WC requires at least: 2.6.0
WC tested up to: 7.5.1
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Zenloop' ) ) {
	class WC_Zenloop {
		
		const PROPERTY_VALUE_MAX_LENGTH = 255;
		
		private static $instance = null;
		private $session_key = null;
		private $options = [];
		
		public static function get_instance() {
			null === self::$instance and self::$instance = new self;
			
			return self::$instance; // return the object
		}
		
		private function __construct() {
			
			register_deactivation_hook( __FILE__, array( $this, 'cron_deactivate' ) );
			
			add_action( 'init', array( $this, 'main' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_action_links' ) );
			
			
			add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
			
		}
		
		
		public function load_textdomain() {
			load_plugin_textdomain( 'woocommerce-zenloop', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		}
		
		public function main() {
			
			$this->options = get_option( 'wc_zenloop_options' );
			
			// Add main shipping import method to cron action
			if ( defined( 'DOING_CRON' ) ) {
				add_action( 'wc_zenloop_importer', array( $this, 'zenloop_process' ), 10, 1 );
			}
			
			// Initialize admin
			if ( is_admin() ) {
				$this->admin_includes();
				
				if ( isset( $_GET['page'] ) && $_GET['page'] == 'wc_zenloop_setting_admin' ) {
					$this->cron_activate();
				}
			}
			
			if ( is_admin() && isset( $_GET['wc_zenloop_force_process'] ) && $_GET['wc_zenloop_force_process'] == 1 ) {
				$this->zenloop_process();
			}
			
			if ( ! empty( $this->options['onsite_survey_id'] ) ) {
				
				if ( isset( $this->options['onsite_survey_position'] ) && $this->options['onsite_survey_position'] == 'bottom' ) {
					add_action( 'woocommerce_after_template_part', array(
						$this,
						'add_zenloop_to_thankyou_page'
					), 10, 4 );
				} else {
					add_action( 'woocommerce_before_template_part', array(
						$this,
						'add_zenloop_to_thankyou_page'
					), 10, 4 );
				}
				
			}
		}
		
		public function add_zenloop_to_thankyou_page( $template_name, $template_path, $located, $args ) {
			if ( $template_name == 'checkout/thankyou.php' && isset( $args['order'] ) && $args['order'] instanceof WC_Order ) {
				echo $this->render_onsite_survey( $args['order'] );
			}
		}
		
		public function render_onsite_survey( WC_Order $order ) {
			if ( is_null( $order ) || empty( $this->options['onsite_survey_id'] ) ) {
				return '';
			}
			
			$allowed_order_data = $this->options['order_data'];
			
			$billing_first_name  = ( isset( $allowed_order_data['billing_first_name'] ) ) ? $order->get_billing_first_name() : '';
			$billing_last_name   = ( isset( $allowed_order_data['billing_last_name'] ) ) ? $order->get_billing_last_name() : '';
			$customer_user_agent = ( isset( $allowed_order_data['customer_user_agent'] ) ) ? $order->get_customer_user_agent() : '';
			$billing_country     = ( isset( $allowed_order_data['billing_country'] ) ) ? $order->get_billing_country() : '';
			
			$survey_js     = '<script id="zl-website-overlay-loader" async src="https://zenloop-website-overlay-production.s3.amazonaws.com/loader/zenloop.load.min.js?&survey=' . urlencode( $this->options['onsite_survey_id'] ) . '"></script>';
			$additional_js = '<script>
				  var Zenloop = window.Zenloop || {};
				  Zenloop.recipient = {
				    email: "' . $order->get_billing_email() . '",
				    first_name: "' . $billing_first_name . '",
				    last_name: "' . $billing_last_name . '",
				    properties: {
				      user_agent: "' . $customer_user_agent . '",
				      country: "' . $billing_country . '"
				    },
				    metatags: {}
				  };
				</script>';
			
			return $survey_js . $additional_js;
		}
		
		// Method for adding the cron job
		public function cron_activate() {
			
			$time   = explode( ":", $this->options['email_order_time'] );
			$hour   = $time[0];
			$minute = $time[1];
			
			wp_clear_scheduled_hook( 'wc_zenloop_importer' );
			
			// Don't run imports for all multisites at the same time - Logic: Blog ID x 5 mins
			if ( is_multisite() ) {
				$blog_id = get_current_blog_id();
				
				$run_minute = $blog_id * 5;
				if ( $run_minute > 60 ) {
					$run_minute = rand( 1, 59 );
				}
				
				$run_date = new DateTime( 'today', new DateTimeZone( get_option( 'timezone_string' ) ) );
				$run_date->setTime( $hour, $run_minute, 0 );
				$run_time = $run_date->getTimestamp();
			} else {
				// Single site
				$run_date = new DateTime( 'today', new DateTimeZone( get_option( 'timezone_string' ) ) );
				$run_date->setTime( $hour, $minute, 0 );
				$run_time = $run_date->getTimestamp();
			}
			
			wp_schedule_event( $run_time, 'daily', 'wc_zenloop_importer' );
			
		}
		
		// Run on plugin deactivation
		public function cron_deactivate() {
			
			// Delete cron
			wp_clear_scheduled_hook( 'wc_zenloop_importer' );
			
			// Reset running status
			delete_option( 'wc_zenloop_import_running' );
		}
		
		public function admin_includes() {
			
			// Loads the admin settings page
			require_once 'class-wc-zenloop-options.php';
			$this->admin = new WC_Zenloop_Options();
			
		}
		
		private function zenloop_get_orders() {
			
			$timeframe_days = (int) $this->options['email_order_timeframe'];
			$timeframe      = apply_filters( 'wc_zenloop_get_orders_timeframe', '-' . $timeframe_days . ' days' ); // default: 5 days ago
			$date_timeframe = date( 'Y-m-d', strtotime( $timeframe ) );
			
			$orders = new WP_Query( array(
				'numberposts'    => '-1',
				'posts_per_page' => '-1',
				'post_type'      => 'shop_order',
				'fields'         => 'ids',
				'post_status'    => array( 'wc-completed' ),
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_completed_date',
						'value'   => $date_timeframe,
						'compare' => ' = ',
						'type'    => 'DATE',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_wc_zenloop_added',
							'compare' => 'NOT EXISTS'
						),
						array(
							'key'     => '_wc_zenloop_added',
							'value'   => '1',
							'compare' => '!='
						),
					)
				)
			) );
			
			if ( ! empty( $orders->posts ) ) {
				$order_count = count( $orders->posts );
				$this->log( $order_count . ' orders found.' );
			}
			
			// Return post ids
			return ( ! empty( $orders->posts ) ) ? $orders->posts : false;
			
		}
		
		private function parse_order_id_property( array $properties ) {
			$result = [];
			foreach ( $properties as $property ) {
				if ( $property['name'] == 'id' ) {
					if ( is_numeric( $property['value'] ) ) {
						$result[ $property['id'] ] = $property['value'];
					} else {
						$this->log( "Invalid json in 'id' property: " . $property['value'] );
					}
				}
			}
			
			return $result;
		}
		
		private function send_surveys() {
			$this->log( 'Start import.' );
			$order_post_ids = $this->zenloop_get_orders();
			if ( ! $order_post_ids ) {
				$this->log( 'No orders found.' );
				update_option( 'wc_zenloop_import_running', 'false' );
				
				return;
			}
			
			$order_post_ids = $this->zenloop_remove_duplicates( $order_post_ids );
			
			$orders_per_loop = 1000;
			
			// Calculate needed loops
			$order_count  = count( $order_post_ids );
			$needed_loops = $order_count / $orders_per_loop;
			$needed_loops = ceil( $needed_loops );
			
			$success_counter = 0;
			
			for ( $i = 0; $i < $needed_loops; $i ++ ) {
				
				$offset = $i * $orders_per_loop;
				
				// Get subset of order post ids
				$order_ids_subset = array_slice( $order_post_ids, $offset, $orders_per_loop );
				
				$recipients = $this->zenloop_build_request_data( $order_ids_subset );
				if ( $recipients === false ) {
					$this->log( 'Error: zenloop_build_request_data could not build order id subset' );
					update_option( 'wc_zenloop_import_running', 'false' );
					
					return;
				}
				
				// Send to API
				$result = $this->zenloop_bulk_add_recipients( $recipients );
				
				if ( $result ) {
					// Update orders
					$this->zenloop_bulk_update_post_meta( $order_ids_subset );
					$success_counter ++;
				} else {
					$this->log( 'Error: Couldnt bulk add recipients! ' . print_r( $order_ids_subset, true ) );
				}
				
				// Wait to next full minute + 2 sec. because of the bulk adding rate limit (1000 per minute - reset every full minute)
				if ( $order_count > 1000 ) {
					sleep( abs( date( 's' ) - 60 ) + 2 );
				}
			}
			$this->log( 'Finished import. Successfully added ' . $success_counter . ' batches each ' . $orders_per_loop . ' orders (' . $order_count . ' orders).' );
		}
		
		public function zenloop_process() {
			
			if ( $this->session_key === false || $this->session_key == null ) {
				$this->session_key = $this->zenloop_get_session_key();
			}
			if ( get_option( 'wc_zenloop_import_running' ) == 'true' ) {
				$this->log( 'Cronjob is already running' );
				
				return;
			}
			update_option( 'wc_zenloop_import_running', 'true' );
			update_option( 'wc_zenloop_last_run', current_time( 'd.m.Y H:i:s' ) );
			$this->log( 'Start cronjob.' );
			
			if ( empty( $this->options['username'] ) || empty( $this->options['password'] ) ) {
				$this->log( 'Error: Plugin not completely configured.' );
				update_option( 'wc_zenloop_import_running', 'false' );
				
				return;
			}
			if ( ! empty( $this->options['email_survey_id'] ) ) {
				$this->send_surveys(); // send surveys
			}
			update_option( 'wc_zenloop_import_running', 'false' );
			$this->log( 'Finish cronjob.' );
			
		}
		
		private function zenloop_bulk_update_post_meta( $order_post_ids ) {
			
			foreach ( $order_post_ids as $order_post_id ) {
				update_post_meta( $order_post_id, '_wc_zenloop_added', '1' );
			}
			
			return true;
		}
		
		private function zenloop_remove_duplicates( $order_post_ids ) {
			
			$included_emails  = array();
			$cleaned_post_ids = array();
			
			foreach ( $order_post_ids as $order_post_id ) {
				$order       = wc_get_order( $order_post_id );
				$order_id    = $order->get_order_number();
				$order_email = $order->get_billing_email();
				if ( in_array( $order_email, $included_emails ) ) {
					$this->log( 'Info: Recipient ' . $order_email . ' will be skipped because its already added to this survey (Order ' . $order_id . ').' );
					continue;
				}
				
				// Add email to list for filter duplicates
				$included_emails[] = $order_email;
				
				$cleaned_post_ids[] = $order_post_id;
				
			}
			
			return $cleaned_post_ids;
			
		}
		
		private function zenloop_build_request_data( $order_post_ids ) {
			
			$counter    = 0;
			$recipients = [];
			foreach ( $order_post_ids as $order_post_id ) {
				$order = wc_get_order( $order_post_id );
				
				// Order not found
				if ( ! $order ) {
					$this->log( 'Error: Order ' . $order_post_id . ' not found!' );
					continue;
				}
				
				$recipient = [];
				
				$email = $order->get_billing_email();
				if ( filter_var( $email, FILTER_VALIDATE_EMAIL ) === false ) {
					$this->log( 'Warning: Invalid email: ' . $email . ' in order ' . $order->get_id() );
					continue;
				}
				
				$additional_order_info = array(
					'id',
					'billing_country',
					'billing_city',
					'shipping_country',
					'shipping_city',
					'total',
					'total_discount',
					'currency',
					'payment_method',
					'customer_user_agent',
				);
				
				$recipient['email'] = $email;
				
				// Check if values are allowed to send
				$allowed_order_data = $this->options['order_data'];
				
				if ( isset( $allowed_order_data['billing_first_name'] ) ) {
					$recipient['first_name'] = $order->get_billing_first_name();
				}
				
				if ( isset( $allowed_order_data['billing_last_name'] ) ) {
					$recipient['last_name'] = $order->get_billing_last_name();
				}
				
				
				$recipient['properties'] = [];
				foreach ( $additional_order_info as $field_name ) {
					
					// Check if values are allowed to send
					if ( isset( $allowed_order_data[ $field_name ] ) ) {
						continue;
					}
					
					if ( method_exists( $order, 'get_' . $field_name ) ) {
						$value = call_user_func( array( $order, 'get_' . $field_name ) );
					} else {
						$value = $order->{$field_name};
					}
					
					if ( empty( $value ) ) { // ! THIS IS IMPORTANT, zenloop refuses empty values!
						$value = is_numeric( $value ) ? "0" : 'unknown';
					} elseif ( strlen( $value ) > static::PROPERTY_VALUE_MAX_LENGTH ) {
						$value = substr( $value, 0, static::PROPERTY_VALUE_MAX_LENGTH - 5 ) . '...';
					}
					
					$recipient['properties'][] = [ 'name' => $field_name, 'value' => strval( $value ), ];
				}
				
				$recipients[] = [ 'recipient' => $recipient, ];
				
				$counter ++;
				
			}
			
			return $recipients;
			
		}
		
		private function zenloop_bulk_add_recipients( $recipients ) {
			$request = [ 'survey_id' => $this->options['email_survey_id'], 'survey_recipients' => $recipients, ];
			
			if ( $this->session_key === false || $this->session_key == null ) {
				$this->session_key = $this->zenloop_get_session_key();
			}
			
			$session_key = $this->session_key;
			
			if ( WP_DEBUG ) {
				$this->log( 'Info: zenloop_build_request_data request: ' . print_r( json_encode( $request ), true ) );
			}
			$body = json_encode( $request );
			
			$request_options = $this->get_request_options( 'POST', $body, $session_key, 15 );
			
			$response = wp_remote_post( 'https://api.zenloop.com/v1/bulk/survey_recipients', $request_options );
			
			$response_code = wp_remote_retrieve_response_code( $response );
			
			$successful_codes = [ 200, 201, 202, ];
			
			if ( is_wp_error( $response ) || ! in_array( $response_code, $successful_codes ) ) {
				if ( is_wp_error( $response ) ) {
					$error_message = $response->get_error_message();
				} else {
					$error_message = wp_remote_retrieve_body( $response ); // This should be in log
				}
				
				$this->log( 'Error: bulk_add_recipients ' );
				$this->log( '[Request] body: ' . $body );
				$this->log( '[Response] code: ' . $response_code . ', body: ' . $error_message );
				
				return false;
			}
			
			return ( in_array( $response_code, $successful_codes ) ) ? true : false;
			
			
		}
		
		public function zenloop_get_surveys() {
			
			if ( $this->session_key === false || $this->session_key == null ) {
				$this->session_key = $this->zenloop_get_session_key();
			}
			
			$session_key = $this->session_key;
			
			$request_options = $this->get_request_options( 'GET', null, $session_key );
			
			$response = wp_remote_get( 'https://api.zenloop.com/v1/surveys', $request_options );
			
			$response_code = wp_remote_retrieve_response_code( $response );
			
			if ( is_wp_error( $response ) ) {
				if ( is_wp_error( $response ) ) {
					$error_message = $response->get_error_message();
				} else {
					$error_message = "unknown";
				}
				$this->log( 'Error: get_surveys ' . $error_message . ' Response Code: ' . $response_code );
				
				return false;
			}
			
			if ( $response_code === 200 ) {
				$response_body = wp_remote_retrieve_body( $response );
			} else {
				$this->log( 'Error: get_surveys .  Response Code: ' . $response_code );
			}
			
			return ( ! empty( $response_body ) ) ? $response_body : false;
			
		}
		
		private function zenloop_get_session_key() {
			
			if ( empty( $this->options['username'] ) || empty( $this->options['password'] ) ) {
				return;
			}
			
			$request                 = new stdClass();
			$request->user           = new stdClass();
			$request->user->email    = $this->options['username'];
			$request->user->password = $this->options['password'];
			
			$request_options = $this->get_request_options( 'POST', json_encode( $request ) );
			
			$response = wp_remote_post( 'https://api.zenloop.com/v1/sessions', $request_options );
			
			$response_code = wp_remote_retrieve_response_code( $response );
			
			if ( is_wp_error( $response ) ) {
				if ( is_wp_error( $response ) ) {
					$error_message = $response->get_error_message();
				} else {
					$error_message = "unknown";
				}
				$this->log( 'Error: get_session_key ' . $error_message . ' Response Code: ' . $response_code );
				
				return false;
			}
			
			$response = json_decode( wp_remote_retrieve_body( $response ) );
			
			$session_key = $response->session->jwt;
			
			return ( ! empty( $session_key ) ) ? $session_key : false;
			
		}
		
		private function log( $message ) {
			$logger = new WC_Logger();
			$logger->add( 'woocommerce-zenloop', $message );
		}
		
		private function get_request_options( $method, $body, $session_key = null, $timeout = 5 ) {
			$params = [
				'method'    => $method,
				'timeout'   => $timeout,
				'blocking'  => true,
				'sslverify' => true,
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => $body,
			];
			if ( $session_key ) {
				$params['headers']['Authorization'] = 'Bearer ' . $session_key;
			}
			
			return $params;
		}
		
		public function add_action_links( $links ) {
			$mylinks = array(
				'<a href="' . admin_url( 'options-general.php?page=wc_zenloop_setting_admin' ) . '">Settings</a>',
				'<a href="http://support.zenloop.com/" target="_blank">Docs / Help</a>'
			);
			
			return array_merge( $links, $mylinks );
		}
		
		
		/**
		 * @param $string
		 *
		 * @return array
		 */
		private function get_words_from_string( $string ) {
			if ( ! empty( $string ) && is_string( $string ) ) {
				$array = preg_split( '/[\ \n\r\,]+/', $string, - 1, PREG_SPLIT_NO_EMPTY );
				if ( $array ) {
					return array_map( 'strtolower', $array );
				}
				
			}
			
			return [];
		}
	}
}

WC_Zenloop::get_instance();