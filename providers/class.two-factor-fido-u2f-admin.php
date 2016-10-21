<?php
/**
 * Class for registering & modifying FIDO U2F security keys.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
class Two_Factor_FIDO_U2F_Admin {

	/**
	 * The user meta register data.
	 *
	 * @type string
	 */
	const REGISTER_DATA_USER_META_KEY = '_two_factor_fido_u2f_register_request';

	/**
	 * Add various hooks.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 */
	public static function add_hooks() {
		add_action( 'admin_enqueue_scripts',       array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'show_user_security_settings', array( __CLASS__, 'show_user_profile' ) );
		add_action( 'personal_options_update',     array( __CLASS__, 'catch_submission' ), 0 );
		add_action( 'edit_user_profile_update',    array( __CLASS__, 'catch_submission' ), 0 );
		add_action( 'load-profile.php',            array( __CLASS__, 'catch_delete_security_key' ) );
		add_action( 'load-user-edit.php',          array( __CLASS__, 'catch_delete_security_key' ) );
		add_action( 'wp_ajax_inline-save-key',     array( __CLASS__, 'wp_ajax_inline_save' ) );
	}

	/**
	 * Enqueue assets.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param string $hook Current page.
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'user-edit.php', 'profile.php' ) ) ) {
			return;
		}

		if ( ! Two_Factor_FIDO_U2F::is_browser_support() ) {
			return;
		}

		wp_enqueue_script( 'u2f-api',        plugins_url( 'includes/Google/u2f-api.js', dirname( __FILE__ ) ), null, null, true );
		wp_enqueue_script( 'fido-u2f-admin', plugins_url( 'js/fido-u2f-admin.js', __FILE__ ), array( 'jquery', 'u2f-api' ), null, true );

		$user_id = self::get_profile_user_id();
		$security_keys = Two_Factor_FIDO_U2F::get_security_keys( $user_id );

		try {
			$data = Two_Factor_FIDO_U2F::$u2f->getRegisterData( $security_keys );
			list( $req,$sigs ) = $data;

			update_user_meta( $user_id, self::REGISTER_DATA_USER_META_KEY, $req );
		} catch ( Exception $e ) {
			return false;
		}

		$translation_array = array(
			'userId' => $user_id,
			'register' => array(
				'request' => $req,
				'sigs' => $sigs,
			),
			'text' => array(
				'insert' => esc_html__( 'Now insert (and tap) your Security Key.' ),
				'error' => esc_html__( 'Failed...' ),
			),
		);

		wp_localize_script( 'fido-u2f-admin', 'u2fL10n', $translation_array );
		wp_enqueue_script( 'inline-edit-key', plugins_url( 'js/fido-u2f-admin-inline-edit.js', __FILE__ ), array( 'jquery' ), null, true );
		wp_localize_script( 'inline-edit-key', 'inlineEditL10n', array(
			'error' => esc_html__( 'Error while saving the changes.' ),
		) );
	}

	/**
	 * Display the security key section in a users profile.
	 *
	 * This executes during the `show_user_security_settings` action.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public static function show_user_profile( $user ) {
		wp_nonce_field( "user_security_keys-{$user->ID}", '_nonce_user_security_keys' );
		$new_key = false;

		$security_keys = Two_Factor_FIDO_U2F::get_security_keys( $user->ID );
		if ( $security_keys ) {
			foreach ( $security_keys as &$security_key ) {
				if ( property_exists( $security_key, 'new' ) ) {
					$new_key = true;
					unset( $security_key->new );

					// If we've got a new one, update the db record to not save it there any longer.
					Two_Factor_FIDO_U2F::update_security_key( $user->ID, $security_key );
				}
			}
			unset( $security_key );
		}

		?>
		<div class="security-keys" id="security-keys-section">
			<h3><?php esc_html_e( 'Security Keys' ); ?></h3>
			<p><?php esc_html_e( 'FIDO U2F is only supported in Chrome 41+.' ); ?></p>
			<p><a href="https://support.google.com/accounts/answer/6103523"><?php esc_html_e( 'You can find FIDO U2F Security Key devices for sale from here.' ); ?></a></p>
			<div class="register-security-key">
				<?php if ( Two_Factor_FIDO_U2F::is_browser_support() ) : ?>
				<input type="hidden" name="do_new_security_key" id="do_new_security_key" />
				<input type="hidden" name="u2f_response" id="u2f_response" />
				<button type="button" class="button button-secondary" id="register_security_key"><?php esc_html_e( 'Add New' ); ?></button>
				<?php else : ?>
				<p><?php esc_html_e( 'Your browser doesn\'t support FIDO U2F.' ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( $new_key ) : ?>
			<p class="new-security-key"><?php esc_html_e( 'Your new security key registered.' ); ?></p>
			<?php endif; ?>

			<?php
				require( TWO_FACTOR_DIR . 'providers/class.two-factor-fido-u2f-admin-list-table.php' );
				$u2f_list_table = new Two_Factor_FIDO_U2F_Admin_List_Table();
				$u2f_list_table->items = $security_keys;
				$u2f_list_table->prepare_items();
				$u2f_list_table->display();
				$u2f_list_table->inline_edit();
			?>
		</div>
		<?php
	}

	/**
	 * Catch the non-ajax submission from the new form.
	 *
	 * This executes during the `personal_options_update` & `edit_user_profile_update` actions.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param int $user_id User ID.
	 */
	public static function catch_submission( $user_id ) {
		if ( ! empty( $_REQUEST['do_new_security_key'] ) ) {
			check_admin_referer( sprintf( 'user_security_keys-%s', $user_id ), '_nonce_user_security_keys' );

			try {
				$response = json_decode( stripslashes( $_POST['u2f_response'] ) );
				$reg = Two_Factor_FIDO_U2F::$u2f->doRegister( get_user_meta( $user_id, self::REGISTER_DATA_USER_META_KEY, true ), $response );
				$reg->new = true;

				Two_Factor_FIDO_U2F::add_security_key( $user_id, $reg );
			} catch ( Exception $e ) {
				return false;
			}

			delete_user_meta( $user_id, self::REGISTER_DATA_USER_META_KEY );

			$redirect_url = add_query_arg( array(
				'new_app_pass' => 1,
			) );

			$redirect_url = remove_query_arg( array(
				'wp_http_referer',
			), $redirect_url );

			wp_safe_redirect(  $redirect_url . '#security-keys-section' );
			exit;
		}
	}

	/**
	 * Catch the delete security key request.
	 *
	 * This executes during the `load-profile.php` & `load-user-edit.php` actions.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 */
	public static function catch_delete_security_key() {
		if ( ! empty( $_REQUEST['delete_security_key'] ) ) {
			$slug = $_REQUEST['delete_security_key'];
			check_admin_referer( sprintf( 'delete_security_key-%s', $slug ), '_nonce_delete_security_key' );

			$user_id = self::get_profile_user_id();
			Two_Factor_FIDO_U2F::delete_security_key( $user_id, $slug );

			$redirect_url = remove_query_arg( array(
				'new_app_pass',
				'wp_http_referer',
				'delete_security_key',
				'_nonce_delete_security_key',
			), wp_get_referer() );

			wp_safe_redirect( $redirect_url . '#security-keys-section' );
			exit;
		}
	}

	/**
	 * Generate a link to rename a specified security key.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param array $item The current item.
	 * @return string
	 */
	public static function rename_link( $item ) {
		return sprintf( '<a href="#" class="editinline">%s</a>', esc_html__( 'Rename' ) );
	}

	/**
	 * Generate a link to delete a specified security key.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param array $item The current item.
	 * @return string
	 */
	public static function delete_link( $item ) {
		$delete_link = add_query_arg( array(
			'user_id' => self::get_profile_user_id(),
			'delete_security_key' => $item->keyHandle,
			'_nonce_delete_security_key' => wp_create_nonce( sprintf( 'delete_security_key-%s', $item->keyHandle ) ),
		), wp_get_referer() );

		$delete_link = remove_query_arg( array(
			'wp_http_referer',
			'new_app_pass',
		), $delete_link );

		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $delete_link ), esc_html__( 'Delete' ) );
	}

	/**
	 * Ajax handler for quick edit saving for a security key.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 */
	public static function wp_ajax_inline_save() {
		if ( ! isset( $_REQUEST['userId'] ) ) {
			wp_die();
		}

		$user_id = intval( $_REQUEST['userId'] );

		check_ajax_referer( 'keyinlineeditnonce_' . $user_id, '_inline_edit' );

		require( TWO_FACTOR_DIR . 'providers/class.two-factor-fido-u2f-admin-list-table.php' );
		$wp_list_table = new Two_Factor_FIDO_U2F_Admin_List_Table();

		if ( ! isset( $_POST['keyHandle'] ) ) {
			wp_die();
		}

		$security_keys = Two_Factor_FIDO_U2F::get_security_keys( $user_id );
		if ( ! $security_keys ) {
			wp_die();
		}

		foreach ( $security_keys as &$key ) {
			if ( $key->keyHandle === $_POST['keyHandle'] ) {
				break;
			}
		}

		$key->name = $_POST['name'];

		$updated = Two_Factor_FIDO_U2F::update_security_key( $user_id, $key );
		if ( ! $updated ) {
			wp_die( esc_html__( 'Item not updated.' ) );
		}
		$wp_list_table->single_row( $key );
		wp_die();
	}

	public static function get_profile_user_id() {
		if ( isset( $_REQUEST['user_id'] ) && current_user_can( 'edit_user', $_REQUEST['user_id'] ) ) {
			return intval( $_REQUEST['user_id'] );
		} elseif ( is_user_logged_in() ) {
			return get_current_user_id();
		}

		return false;
	}
}
