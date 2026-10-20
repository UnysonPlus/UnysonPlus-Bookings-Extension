<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * "Dates and availability" — a Forms field (type `booking`) supplied by Bookings.
 *
 * Which bookable it books is set on the field (never posted), so a visitor cannot book something else
 * by editing the page. It posts:
 *   by the night   <shortcode>[start], [end] (Y-m-d), [guests]
 *   by time slot   <shortcode>[date] (Y-m-d), [time] (HH:MM), [guests]
 * The markup is plain controls that work without JavaScript; static/js/booking.js turns them into the
 * live calendar. The server checks everything again (FW_Bookings_Availability::check) and keeps the
 * result for the Reserve action and the answer tags. The stored value is one readable line.
 */
class FW_Option_Type_Form_Builder_Item_Booking extends FW_Option_Type_Form_Builder_Item_Simple {

	public function get_type() {
		return 'booking';
	}

	protected function title() {
		return __( 'Dates and availability', 'fw' );
	}

	protected function tip() {
		return __( 'A live calendar for something bookable (Bookings): taken dates or times greyed out, and the price. The form\'s Reserve action books it when the form is sent.', 'fw' );
	}

	protected function get_uri( $append = '' ) {
		return fw_ext( 'bookings' )->get_uri() . '/includes/forms/field' . $append;
	}

	public function get_options() {
		$items = array( '' => __( '— Choose what is booked —', 'fw' ) ) + FW_Bookings_Bookables::choices();
		return array(
			$this->label_group( __( 'Your dates', 'fw' ), true ),
			array(
				'g_booking' => array(
					'type'    => 'group',
					'options' => array(
						array(
							'bookable' => array(
								'type'    => 'select',
								'label'   => __( 'What is booked', 'fw' ),
								'desc'    => __( 'A room, rental, table or service from Bookings → Bookables.', 'fw' ),
								'value'   => '',
								'choices' => $items,
							),
						),
						array(
							'months' => array(
								'type'    => 'select',
								'label'   => __( 'Months shown', 'fw' ),
								'desc'    => __( 'Side by side on wide screens; one on phones.', 'fw' ),
								'value'   => '2',
								'choices' => array( '1' => '1', '2' => '2' ),
							),
						),
						array(
							'guests_label' => array(
								'type'  => 'text',
								'label' => __( 'Guests label', 'fw' ),
								'desc'  => __( 'e.g. Guests, People, Seats.', 'fw' ),
								'value' => '',
							),
						),
					),
				),
			),
			$this->info_group(),
			$this->get_extra_options(),
		);
	}

	protected function fix_options( array $attributes ) {
		$attributes['width'] = '1_1';
		return $attributes;
	}

	private static function bookable( array $item ) {
		$id = (int) ( $item['options']['bookable'] ?? 0 );
		return $id && FW_Bookings_Bookables::exists( $id ) ? $id : 0;
	}

	/** The posted value as a pick for FW_Bookings_Availability::check(). */
	public static function parse( $value ) {
		$v   = is_array( $value ) ? $value : array();
		$get = function ( $k ) use ( $v ) {
			return isset( $v[ $k ] ) && is_scalar( $v[ $k ] ) ? trim( (string) $v[ $k ] ) : '';
		};
		return array( 'start' => $get( 'start' ), 'end' => $get( 'end' ), 'date' => $get( 'date' ), 'time' => $get( 'time' ), 'guests' => (int) $get( 'guests' ) );
	}

	private static function empty_pick( array $p ) {
		return '' === $p['start'] && '' === $p['end'] && '' === $p['date'] && '' === $p['time'];
	}

	public function frontend_render( array $item, $input_value ) {
		$name = (string) ( $item['shortcode'] ?? '' );
		$id   = self::bookable( $item );
		$req  = ! empty( $item['options']['required'] );
		$fid  = 'id-' . fw_unique_increment();
		$lab  = fw_htmlspecialchars( $item['options']['label'] ?? '' );
		if ( ! $id ) {
			return current_user_can( 'edit_posts' ) ? '<div class="' . esc_attr( fw_ext_builder_get_item_width( 'form-builder', '1_1/frontend_class' ) ) . '"><p><em>' . esc_html__( 'Dates and availability: choose what is booked in this field\'s settings.', 'fw' ) . '</em></p></div>' : '';
		}
		$ver = fw_ext( 'bookings' )->manifest->get_version();
		wp_enqueue_style( 'fw-bookings', fw_min_uri( fw_ext( 'bookings' )->get_uri() . '/static/css/booking.css' ), array(), $ver );
		wp_enqueue_script( 'fw-bookings', fw_min_uri( fw_ext( 'bookings' )->get_uri() . '/static/js/booking.js' ), array(), $ver, true );

		$s   = FW_Bookings_Bookables::settings( $id );
		$v   = self::parse( $input_value );
		$cfg = array_merge( FW_Bookings_Availability::public_config( $id ), array(
			'months' => '1' === (string) ( $item['options']['months'] ?? '2' ) ? 1 : 2,
			'i18n'   => FW_Bookings_Forms::i18n(),
		) );
		$gl = trim( (string) ( $item['options']['guests_label'] ?? '' ) );
		if ( '' !== $gl ) {
			$cfg['i18n']['guests'] = $gl;
		}
		if ( ! $v['guests'] ) {
			$v['guests'] = min( 2, (int) $s['max_guests'] );
		}
		$today = FW_Bookings_Availability::today();
		$slots = 'slots' === $s['mode'];
		ob_start();
		?>
		<div class="<?php echo esc_attr( fw_ext_builder_get_item_width( 'form-builder', $item['width'] . '/frontend_class' ) ); ?>">
			<fieldset class="field-booking" id="<?php echo esc_attr( $fid ); ?>"<?php echo ! empty( $item['options']['info'] ) ? ' aria-describedby="' . esc_attr( $fid ) . '-info"' : ''; ?>>
				<legend><?php echo $lab; // phpcs:ignore WordPress.Security.EscapeOutput -- fw_htmlspecialchars ?><?php if ( $req ) : ?><sup>*</sup><?php endif; ?></legend>
				<div class="fw-bk" data-fw-bk="<?php echo esc_attr( wp_json_encode( $cfg ) ); ?>"></div>
				<div class="fw-bk-plain" data-fw-bk-plain>
					<?php if ( $slots ) : ?>
						<label><span><?php esc_html_e( 'Day', 'fw' ); ?></span>
							<input type="date" name="<?php echo esc_attr( $name ); ?>[date]" value="<?php echo esc_attr( $v['date'] ); ?>" min="<?php echo esc_attr( $today ); ?>" data-bk-a<?php echo $req ? ' required' : ''; ?>></label>
						<label><span><?php esc_html_e( 'Time', 'fw' ); ?></span>
							<input type="time" name="<?php echo esc_attr( $name ); ?>[time]" value="<?php echo esc_attr( $v['time'] ); ?>" step="<?php echo (int) $s['slot_minutes'] * 60; ?>" data-bk-time<?php echo $req ? ' required' : ''; ?>></label>
					<?php else : ?>
						<label><span><?php esc_html_e( 'Check-in', 'fw' ); ?></span>
							<input type="date" name="<?php echo esc_attr( $name ); ?>[start]" value="<?php echo esc_attr( $v['start'] ); ?>" min="<?php echo esc_attr( $today ); ?>" data-bk-a<?php echo $req ? ' required' : ''; ?>></label>
						<label><span><?php esc_html_e( 'Check-out', 'fw' ); ?></span>
							<input type="date" name="<?php echo esc_attr( $name ); ?>[end]" value="<?php echo esc_attr( $v['end'] ); ?>" min="<?php echo esc_attr( $today ); ?>" data-bk-b<?php echo $req ? ' required' : ''; ?>></label>
					<?php endif; ?>
					<label><span><?php echo esc_html( '' !== $gl ? $gl : __( 'Guests', 'fw' ) ); ?></span>
						<select name="<?php echo esc_attr( $name ); ?>[guests]" data-bk-guests>
							<?php for ( $g = 1; $g <= (int) $s['max_guests']; $g++ ) : ?>
								<option value="<?php echo (int) $g; ?>"<?php selected( $g, $v['guests'] ); ?>><?php echo (int) $g; ?></option>
							<?php endfor; ?>
						</select></label>
				</div>
				<?php if ( ! empty( $item['options']['info'] ) ) : ?>
					<p class="field-info" id="<?php echo esc_attr( $fid ); ?>-info"><em><?php echo esc_html( $item['options']['info'] ); ?></em></p>
				<?php endif; ?>
			</fieldset>
		</div>
		<?php
		return ob_get_clean();
	}

	public function frontend_validate( array $item, $input_value ) {
		$id = self::bookable( $item );
		$p  = self::parse( $input_value );
		if ( self::empty_pick( $p ) ) {
			return ! empty( $item['options']['required'] ) ? $this->msg( $item, __( 'Please choose your dates or time', 'fw' ) ) : null;
		}
		if ( ! $id ) {
			return __( 'This form is not set up for bookings yet.', 'fw' );
		}
		$span = FW_Bookings_Availability::check( $id, $p );
		if ( is_string( $span ) ) {
			return $span;
		}
		FW_Bookings_Forms::$picks[ (string) ( $item['shortcode'] ?? '' ) ] = array( 'bookable' => $id, 'pick' => $p, 'span' => $span );
		return null;
	}

	public function get_value_from_item( $value, $item = array(), $all = array() ) {
		$sc = is_array( $item ) ? (string) ( $item['shortcode'] ?? '' ) : '';
		if ( '' !== $sc && isset( FW_Bookings_Forms::$picks[ $sc ] ) ) {
			return FW_Bookings_Forms::$picks[ $sc ]['span']['label'];
		}
		$p = self::parse( $value );
		if ( self::empty_pick( $p ) ) {
			return '';
		}
		return '' !== $p['date'] ? trim( $p['date'] . ' ' . $p['time'] ) : trim( $p['start'] . ' – ' . $p['end'] );
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_Option_Type_Form_Builder_Item_Booking' );
