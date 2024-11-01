<?php
if ( ! class_exists( 'WC_Zenloop_Options' ) ) {
	class WC_Zenloop_Options {
		
		private $options;
		
		/**
		 * Start up
		 */
		public function __construct() {
			
			$this->options = get_option( 'wc_zenloop_options' );
			
			add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
			add_action( 'admin_init', array( $this, 'page_init' ) );
			
		}
		
		/**
		 * Add options page
		 */
		public function add_plugin_page() {
			// This page will be under "Settings"
			add_options_page( 'Settings Admin', 'zenloop Settings', 'manage_options', 'wc_zenloop_setting_admin', array(
				$this,
				'create_admin_page'
			) );
		}
		
		/**
		 * Options page callback
		 */
		public function create_admin_page() {
			
			?>
            <style>
                .submit {
                    padding: 0 !important;
                }
            </style>

            <div class="wrap">
                <img src="<?php echo plugins_url( 'images/zenloop-icon.svg', __FILE__ ); ?>" width="120"
                     title="zenloop for WooCommerce"/>
                <h2><?php _e( 'zenloop for WooCommerce', 'woocommerce-zenloop' ); ?></h2>
                <form method="post" action="options.php">
					<?php
					// This prints out all hidden setting fields
					settings_fields( 'wc_zenloop_option_group' );
					do_settings_sections( 'wc_zenloop_setting_admin' );
					submit_button();
					
					if ( ! empty( $this->options['username'] ) && ! empty( $this->options['password'] ) ) {
						submit_button( __( 'Force Process', 'woocommerce-zenloop' ), 'small', 'force_process' );
					}
					
					echo "<br />";
					echo __( 'Import running', 'woocommerce-zenloop' ) . ": " . get_option( "wc_zenloop_import_running", "no" ) . "<br />";
					echo __( 'Import last run', 'woocommerce-zenloop' ) . ": " . get_option( "wc_zenloop_last_run", "-" );
					?>
                </form>
            </div>
			<?php
		}
		
		/**
		 * Register and add settings
		 */
		public function page_init() {
			register_setting( 'wc_zenloop_option_group', // Option group
				'wc_zenloop_options', // Option name
				array( $this, 'sanitize' ) // Sanitize
			);
			
			add_settings_section( 'wc_zenloop_settings', // ID
				__( 'Settings', 'woocommerce-zenloop' ), // Title
				array( $this, 'print_section_info' ), // Callback
				'wc_zenloop_setting_admin' // Page
			);
			
			//add setting for username
			add_settings_field( 'username', __( 'Username (E-Mail)', 'woocommerce-zenloop' ), array(
				$this,
				'username_callback'
			), 'wc_zenloop_setting_admin', 'wc_zenloop_settings' );
			
			//add setting for user password
			add_settings_field( 'password', __( 'Password', 'woocommerce-zenloop' ), array(
				$this,
				'password_callback'
			), 'wc_zenloop_setting_admin', 'wc_zenloop_settings' );
			
			
			//add setting for surveys
			if ( ! empty( $this->options['username'] ) && ! empty( $this->options['password'] ) ) {
				
				//add setting for user data
				add_settings_field( 'order_data', __( 'Select order data to be send to zenloop', 'woocommerce-zenloop' ), array(
					$this,
					'order_data_callback'
				), 'wc_zenloop_setting_admin', 'wc_zenloop_settings' );
				
				//add setting for surveys
				add_settings_field( 'email_survey_id', __( 'E-Mail Survey', 'woocommerce-zenloop' ), array(
					$this,
					'email_survey_callback'
				), 'wc_zenloop_setting_admin', 'wc_zenloop_settings' );
				
				//add setting for email order timeframe
				add_settings_field( 'email_order_timeframe', __( 'Send orders older than x days to zenloop', 'woocommerce-zenloop' ), array(
					$this,
					'email_order_timeframe_callback'
				), 'wc_zenloop_setting_admin', 'wc_zenloop_settings' );
				
				//add setting for email order time
				add_settings_field( 'email_order_time', __( 'Sending time', 'woocommerce-zenloop' ), array(
					$this,
					'email_order_time_callback'
				), 'wc_zenloop_setting_admin', 'wc_zenloop_settings' );
				
				//add setting for surveys
				add_settings_field( 'onsite_survey_id', __( 'On-Site (Embedded) survey', 'woocommerce-zenloop' ), array(
					$this,
					'onsite_survey_callback'
				), 'wc_zenloop_setting_admin', 'wc_zenloop_settings' );
				
				//add setting for survey position
				add_settings_field( 'onsite_survey_position', __( 'On-Site (Embedded) position', 'woocommerce-zenloop' ), array(
					$this,
					'onsite_survey_position_callback'
				), 'wc_zenloop_setting_admin', 'wc_zenloop_settings' );
				
			}
			
			//var_dump($this->options);
			
		}
		
		
		/**
		 * Sanitize each setting field as needed
		 *
		 * @param array $input Contains all settings fields as array keys
		 */
		public function sanitize( $input ) {
			
			$new_input = array();
			
			if ( isset( $input['username'] ) ) {
				$new_input['username'] = sanitize_text_field( $input['username'] );
			}
			
			if ( isset( $input['password'] ) ) {
				$new_input['password'] = sanitize_text_field( $input['password'] );
			}
			
			if ( isset( $input['email_survey_id'] ) ) {
				$new_input['email_survey_id'] = sanitize_text_field( $input['email_survey_id'] );
			}
			
			if ( isset( $input['onsite_survey_id'] ) ) {
				$new_input['onsite_survey_id'] = sanitize_text_field( $input['onsite_survey_id'] );
			}
			
			if ( isset( $input['onsite_survey_position'] ) ) {
				$new_input['onsite_survey_position'] = sanitize_text_field( $input['onsite_survey_position'] );
			}
			
			if ( isset( $input['order_data'] ) ) {
				$new_input['order_data'] = array();
				
				foreach ( $input['order_data'] as $field ) {
					$new_input['order_data'][ sanitize_text_field( $field ) ] = '';
				}
			}
			
			if ( isset( $input['email_order_timeframe'] ) ) {
				$new_input['email_order_timeframe'] = (int) $input['email_order_timeframe'];
			} else {
				// Default
				$new_input['email_order_time'] = 5;
			}
			
			if ( isset( $input['email_order_time'] ) ) {
				$new_input['email_order_time'] = $input['email_order_time'];
			} else {
				// Default
				$new_input['email_order_time'] = '17:05';
			}
			
			return $new_input;
			
		}
		
		/**
		 * Print the Section text
		 */
		public function print_section_info() {
			echo __( 'Enter your settings below:', 'woocommerce-zenloop' );
		}
		
		public function username_callback() {
			printf( '<input type="text" id="username" style="min-width:300px" name="wc_zenloop_options[username]" value="%s" />', isset( $this->options['username'] ) ? esc_attr( $this->options['username'] ) : '' );
		}
		
		public function password_callback() {
			printf( '<input type="password" id="password" style="min-width:300px" name="wc_zenloop_options[password]" value="%s" />', isset( $this->options['password'] ) ? esc_attr( $this->options['password'] ) : '' );
			
			if ( empty( $this->options['username'] ) && empty( $this->options['password'] ) ) {
				echo "<p>";
				_e( 'No zenloop.com account yet? Register <a href="https://app.zenloop.com/register" target="_blank">here</a>!' );
				echo "</p>";
			}
		}
		
		public function send_email_surveys_callback() {
			printf( '<input type="checkbox" id="send_email_surveys" name="wc_zenloop_options[send_email_surveys]" %s />', ! empty( $this->options['send_email_surveys'] ) ? 'checked' : '' );
		}
		
		public function email_survey_callback() {
			$this->render_survey_selector( 'email_survey_id' );
		}
		
		public function onsite_survey_callback() {
			$this->render_survey_selector( 'onsite_survey_id' );
		}
		
		public function onsite_survey_position_callback() {
			$this->render_survey_position_selector( 'onsite_survey_position' );
		}
		
		public function order_data_callback() {
			$this->render_order_data_selector( 'order_data_selector' );
		}
		
		private function render_survey_selector( $name ) {
			$wc_zenloop = WC_Zenloop::get_instance();
			$surveys    = $wc_zenloop->zenloop_get_surveys();
			if ( $surveys === false ) {
				return;
			}
			$surveys = json_decode( $surveys );
			printf( '<select name="wc_zenloop_options[' . $name . ']" id="' . $name . '">' );
			printf( '<option value="" >%s</option>', __( 'None', 'woocommerce-zenloop' ) );
			foreach ( $surveys->surveys as $survey ) {
				printf( '<option value="%s" %s >%s</option>', $survey->public_hash_id, ( isset( $this->options[ $name ] ) && $this->options[ $name ] == $survey->public_hash_id ) ? 'selected' : '', $survey->title );
			}
			printf( '</select>' );
		}
		
		private function render_survey_position_selector( $name ) {
			$wc_zenloop = WC_Zenloop::get_instance();
			$surveys    = $wc_zenloop->zenloop_get_surveys();
			if ( $surveys === false ) {
				return;
			}
			
			printf( '<select name="wc_zenloop_options[' . $name . ']" id="' . $name . '">' );
			printf( '<option value="top" %s>%s</option>', ( isset( $this->options[ $name ] ) && $this->options[ $name ] == 'top' ) ? 'selected' : '', __( 'Above page content', 'woocommerce-zenloop' ) );
			printf( '<option value="bottom" %s>%s</option>', ( isset( $this->options[ $name ] ) && $this->options[ $name ] == 'bottom' ) ? 'selected' : '', __( 'Below page content', 'woocommerce-zenloop' ) );
			printf( '</select>' );
		}
		
		private function render_order_data_selector( $name ) {
			
			?>
            <label>
                <select name="wc_zenloop_options[order_data][]" size="15" multiple>
                    <optgroup label="<?php _e( 'Required', 'woocommerce-zenloop' ); ?>">
                        <option value="email" disabled>E-Mail</option>
                    </optgroup>
                    <optgroup label="Optional">

                        <option value="id" <?php echo ( isset( $this->options['order_data']['id'] ) ) ? 'selected' : '' ?>><?php _e( 'Order ID', 'woocommerce-zenloop' ); ?></option>
                        <option value="total" <?php echo ( isset( $this->options['order_data']['total'] ) ) ? 'selected' : '' ?>><?php _e( 'Total', 'woocommerce-zenloop' ); ?></option>
                        <option value="total_discount" <?php echo ( isset( $this->options['order_data']['total_discount'] ) ) ? 'selected' : '' ?>><?php _e( 'Total Discount', 'woocommerce-zenloop' ); ?></option>
                        <option value="currency" <?php echo ( isset( $this->options['order_data']['currency'] ) ) ? 'selected' : '' ?>><?php _e( 'Currency', 'woocommerce-zenloop' ); ?></option>
                        <option value="payment_method" <?php echo ( isset( $this->options['order_data']['payment_method'] ) ) ? 'selected' : '' ?>><?php _e( 'Payment Method', 'woocommerce-zenloop' ); ?></option>

                        <option value="billing_first_name" <?php echo ( isset( $this->options['order_data']['billing_first_name'] ) ) ? 'selected' : '' ?>><?php _e( 'Billing Firstname', 'woocommerce-zenloop' ); ?></option>
                        <option value="billing_last_name" <?php echo ( isset( $this->options['order_data']['billing_last_name'] ) ) ? 'selected' : '' ?>><?php _e( 'Billing Lastname', 'woocommerce-zenloop' ); ?></option>
                        <option value="billing_country" <?php echo ( isset( $this->options['order_data']['billing_country'] ) ) ? 'selected' : '' ?>><?php _e( 'Billing Country', 'woocommerce-zenloop' ); ?></option>
                        <option value="billing_city" <?php echo ( isset( $this->options['order_data']['billing_city'] ) ) ? 'selected' : '' ?>><?php _e( 'Billing City', 'woocommerce-zenloop' ); ?></option>

                        <option value="shipping_country" <?php echo ( isset( $this->options['order_data']['shipping_country'] ) ) ? 'selected' : '' ?>><?php _e( 'Shipping Country', 'woocommerce-zenloop' ); ?></option>
                        <option value="shipping_city" <?php echo ( isset( $this->options['order_data']['shipping_city'] ) ) ? 'selected' : '' ?>><?php _e( 'Shipping City', 'woocommerce-zenloop' ); ?></option>

                        <option value="customer_user_agent" <?php echo ( isset( $this->options['order_data']['customer_user_agent'] ) ) ? 'selected' : '' ?>><?php _e( 'Customer Browser User Agent', 'woocommerce-zenloop' ); ?></option>

                    </optgroup>
                </select>
            </label>
			<?php
		}
		
		public function email_order_timeframe_callback() {
			printf( '<input type="number" min="1" max="30" id="email_order_timeframe" name="wc_zenloop_options[email_order_timeframe]" value="%s" /> %s', isset( $this->options['email_order_timeframe'] ) ? esc_attr( $this->options['email_order_timeframe'] ) : '5', __( 'days', 'woocommerce-zenloop' ) );
		}
		
		public function email_order_time_callback() {
			printf( '<input type="time" id="email_order_time" name="wc_zenloop_options[email_order_time]" value="%s" /> %s', isset( $this->options['email_order_time'] ) ? esc_attr( $this->options['email_order_time'] ) : '17:05', __( 'Format: hh:mm', 'woocommerce-zenloop' ) );
		}
		
		
	}
}

