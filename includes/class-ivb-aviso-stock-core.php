<?php
defined( 'ABSPATH' ) || exit;

class IVB_Aviso_Stock_Core {

	const CRON_HOOK = 'ivb_aviso_stock_send';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ivb_stock_alerts';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			email varchar(190) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created datetime NOT NULL,
			notified datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_email (product_id,email),
			KEY pending (product_id,notified)
		) {$charset};" );
		update_option( 'ivb_aviso_stock_db', IVB_AVISO_STOCK_VERSION );
	}

	public static function init() {
		if ( get_option( 'ivb_aviso_stock_db' ) !== IVB_AVISO_STOCK_VERSION ) {
			self::install();
		}

		// Formulario en la ficha.
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_simple_form' ), 31 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'variation_form' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );

		// Alta por AJAX.
		add_action( 'wp_ajax_ivb_aviso_stock', array( __CLASS__, 'ajax_subscribe' ) );
		add_action( 'wp_ajax_nopriv_ivb_aviso_stock', array( __CLASS__, 'ajax_subscribe' ) );

		// Detección de vuelta a stock.
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( 'woocommerce_product_object_updated_props', array( __CLASS__, 'on_props' ), 10, 2 );

		// Casilla por producto: el aviso solo se ofrece en los productos marcados.
		add_action( 'woocommerce_product_options_stock_fields', array( __CLASS__, 'field' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_field' ) );

		add_action( self::CRON_HOOK, array( __CLASS__, 'send_for_product' ) );
	}

	/* ---------- Activación por producto ---------- */

	public static function enabled( $product ) {
		$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		return 'yes' === get_post_meta( $id, '_ivb_aviso_stock', true );
	}

	public static function field() {
		woocommerce_wp_checkbox( array(
			'id'          => '_ivb_aviso_stock',
			'label'       => __( 'Aviso de stock', 'ivb-aviso-stock' ),
			'description' => __( 'Mostrar «Avísame cuando haya stock» si el producto (o una variación) está agotado.', 'ivb-aviso-stock' ),
		) );
	}

	public static function save_field( $post_id ) {
		update_post_meta( $post_id, '_ivb_aviso_stock', isset( $_POST['_ivb_aviso_stock'] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/* ---------- Frontend ---------- */

	public static function assets() {
		if ( ! is_product() ) {
			return;
		}
		wp_enqueue_script( 'ivb-aviso-stock', IVB_AVISO_STOCK_URL . 'assets/ivb-aviso-stock.js', array(), IVB_AVISO_STOCK_VERSION, true );
		wp_localize_script( 'ivb-aviso-stock', 'ivbAvisoStock', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'ivb_aviso_stock' ),
		) );
		wp_enqueue_style( 'ivb-aviso-stock', IVB_AVISO_STOCK_URL . 'assets/ivb-aviso-stock.css', array(), IVB_AVISO_STOCK_VERSION );
	}

	public static function render_simple_form() {
		global $product;
		if ( ! $product || $product->is_type( array( 'variable', 'grouped', 'external' ) ) || $product->is_in_stock() || ! self::enabled( $product ) ) {
			return;
		}
		echo self::form_html( $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function variation_form( $data, $parent, $variation ) {
		if ( ! $variation->is_in_stock() && self::enabled( $variation ) ) {
			$data['availability_html'] = ( $data['availability_html'] ?? '' ) . self::form_html( $variation->get_id() );
		}
		return $data;
	}

	protected static function form_html( $product_id ) {
		$email = is_user_logged_in() ? wp_get_current_user()->user_email : '';
		ob_start();
		?>
		<form class="ivb-as-form" data-product="<?php echo esc_attr( $product_id ); ?>">
			<p class="ivb-as-title"><?php esc_html_e( 'Producto agotado. Te avisaremos por email cuando vuelva a haber stock.', 'ivb-aviso-stock' ); ?></p>
			<div class="ivb-as-row">
				<?php if ( $email ) : ?>
					<input type="hidden" name="email" value="<?php echo esc_attr( $email ); ?>" />
				<?php else : ?>
					<input type="email" name="email" value="" placeholder="<?php esc_attr_e( 'Tu email', 'ivb-aviso-stock' ); ?>" required />
				<?php endif; ?>
				<input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="ivb-as-hp" aria-hidden="true" />
				<button type="submit" class="button ivb-as-btn"><?php esc_html_e( 'Avísame cuando haya stock', 'ivb-aviso-stock' ); ?></button>
			</div>
			<p class="ivb-as-msg" role="status"></p>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function ajax_subscribe() {
		check_ajax_referer( 'ivb_aviso_stock', 'nonce' );
		if ( ! empty( $_POST['website'] ) ) { // honeypot
			wp_send_json_success( array( 'message' => __( 'Listo.', 'ivb-aviso-stock' ) ) );
		}
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$email      = is_user_logged_in() ? wp_get_current_user()->user_email : sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product || ! self::enabled( $product ) || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Revisa el email e inténtalo de nuevo.', 'ivb-aviso-stock' ) ) );
		}
		if ( $product->is_in_stock() ) {
			wp_send_json_error( array( 'message' => __( 'Este producto ya tiene stock.', 'ivb-aviso-stock' ) ) );
		}

		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (product_id,email,user_id,created,notified) VALUES (%d,%s,%d,%s,NULL)
			 ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), created=VALUES(created), notified=NULL",
			$product_id,
			strtolower( $email ),
			get_current_user_id(),
			current_time( 'mysql' )
		) );
		wp_send_json_success( array( 'message' => __( 'Hecho. Te enviaremos un email cuando vuelva a haber stock.', 'ivb-aviso-stock' ) ) );
	}

	/* ---------- Detección ---------- */

	public static function on_status( $product_id, $status, $product = null ) {
		if ( 'instock' === $status ) {
			self::queue( (int) $product_id );
		}
	}

	public static function on_props( $product, $updated_props ) {
		if ( in_array( 'stock_status', (array) $updated_props, true ) && 'instock' === $product->get_stock_status() ) {
			self::queue( $product->get_id() );
		}
	}

	protected static function queue( $product_id ) {
		global $wpdb;
		$table = self::table();
		$has   = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE product_id=%d AND notified IS NULL LIMIT 1", $product_id ) );
		if ( $has && ! wp_next_scheduled( self::CRON_HOOK, array( $product_id ) ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, array( $product_id ) );
		}
	}

	/* ---------- Envío ---------- */

	public static function send_for_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_in_stock() ) {
			return;
		}
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id,email FROM {$table} WHERE product_id=%d AND notified IS NULL", $product_id ) );
		foreach ( $rows as $row ) {
			// Se marca antes de enviar para no duplicar avisos si el proceso se repite.
			$wpdb->update( $table, array( 'notified' => current_time( 'mysql' ) ), array( 'id' => $row->id ) );
			self::send_email( $row->email, $product );
		}
	}

	protected static function send_email( $to, $product ) {
		$name    = $product->get_name();
		$url     = $product->get_permalink();
		$subject = apply_filters( 'ivb_aviso_stock_subject', sprintf( __( '«%s» vuelve a estar disponible', 'ivb-aviso-stock' ), wp_strip_all_tags( $name ) ), $product );
		$mailer  = WC()->mailer();
		$body    = '<p>' . esc_html__( 'Hola,', 'ivb-aviso-stock' ) . '</p>'
			. '<p>' . sprintf( esc_html__( 'El producto que esperabas ya tiene stock: %s.', 'ivb-aviso-stock' ), '<strong>' . esc_html( $name ) . '</strong>' ) . '</p>'
			. '<p><a href="' . esc_url( $url ) . '" style="display:inline-block;background:' . esc_attr( get_option( 'woocommerce_email_base_color', '#7f54b3' ) ) . ';color:#fff;padding:10px 18px;text-decoration:none;border-radius:3px;">' . esc_html__( 'Ver producto', 'ivb-aviso-stock' ) . '</a></p>'
			. '<p>' . esc_html__( 'Las unidades pueden agotarse de nuevo, así que no tardes en hacer tu pedido.', 'ivb-aviso-stock' ) . '</p>';
		$message = $mailer->wrap_message( __( 'Ya hay stock', 'ivb-aviso-stock' ), $body );
		$mailer->send( $to, $subject, $message );
	}
}
