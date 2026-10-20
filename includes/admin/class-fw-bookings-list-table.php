<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The bookings list: upcoming first, by status, by bookable, searchable by reference / guest.
 */
class FW_Bookings_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( array( 'singular' => 'booking', 'plural' => 'bookings', 'ajax' => false ) );
	}

	private function f( $k ) {
		return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function get_columns() {
		return array(
			'ref'      => __( 'Reference', 'fw' ),
			'when'     => __( 'When', 'fw' ),
			'bookable' => __( 'What', 'fw' ),
			'guest'    => __( 'Guest', 'fw' ),
			'guests'   => __( 'Guests', 'fw' ),
			'total'    => __( 'Total', 'fw' ),
			'status'   => __( 'Status', 'fw' ),
			'source'   => __( 'From', 'fw' ),
		);
	}

	public function prepare_items() {
		$when = $this->f( 'when' );
		$q    = FW_Bookings_Store::query( array(
			'bookable_id' => (int) $this->f( 'bookable' ),
			'status'      => $this->f( 'status' ),
			'kind'        => 'blocks' === $when ? 'block' : '',
			'when'        => 'past' === $when ? 'past' : ( 'all' === $when ? '' : 'upcoming' ),
			'search'      => $this->f( 's' ),
			'per_page'    => 30,
			'page'        => $this->get_pagenum(),
			'order'       => 'past' === $when ? 'DESC' : 'ASC',
		) );
		$this->items           = $q['rows'];
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args( array( 'total_items' => $q['total'], 'per_page' => 30 ) );
	}

	protected function get_views() {
		$c     = FW_Bookings_Store::counts();
		$base  = FW_Bookings_Admin::url( array_filter( array( 'bookable' => $this->f( 'bookable' ) ) ) );
		$cur   = $this->f( 'status' ) ? 'st-' . $this->f( 'status' ) : ( $this->f( 'when' ) ? 'w-' . $this->f( 'when' ) : 'w-upcoming' );
		$views = array();
		$list  = array(
			'w-upcoming'   => array( __( 'Upcoming', 'fw' ), array(), null ),
			'st-pending'   => array( __( 'Waiting for you', 'fw' ), array( 'status' => 'pending', 'when' => 'all' ), $c['pending'] ),
			'st-confirmed' => array( __( 'Confirmed', 'fw' ), array( 'status' => 'confirmed', 'when' => 'all' ), $c['confirmed'] ),
			'w-past'       => array( __( 'Past', 'fw' ), array( 'when' => 'past' ), null ),
			'w-blocks'     => array( __( 'Blocks', 'fw' ), array( 'when' => 'blocks' ), null ),
			'st-declined'  => array( __( 'Declined', 'fw' ), array( 'status' => 'declined', 'when' => 'all' ), $c['declined'] ),
			'st-cancelled' => array( __( 'Cancelled', 'fw' ), array( 'status' => 'cancelled', 'when' => 'all' ), $c['cancelled'] ),
		);
		foreach ( $list as $k => $v ) {
			$views[ $k ] = '<a href="' . esc_url( add_query_arg( $v[1], $base ) ) . '"' . ( $k === $cur ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $v[0] ) . ( null !== $v[2] ? ' <span class="count">(' . (int) $v[2] . ')</span>' : '' ) . '</a>';
		}
		return $views;
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$items = FW_Bookings_Bookables::choices();
		if ( count( $items ) < 2 ) {
			return;
		}
		echo '<div class="alignleft actions"><label class="screen-reader-text" for="fw-bk-filter">' . esc_html__( 'What', 'fw' ) . '</label><select name="bookable" id="fw-bk-filter"><option value="">' . esc_html__( 'Everything', 'fw' ) . '</option>';
		foreach ( $items as $id => $t ) {
			echo '<option value="' . (int) $id . '"' . selected( (string) $id, $this->f( 'bookable' ), false ) . '>' . esc_html( $t ) . '</option>';
		}
		echo '</select>';
		foreach ( array( 'status', 'when' ) as $k ) {
			if ( '' !== $this->f( $k ) ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $this->f( $k ) ) . '">';
			}
		}
		submit_button( __( 'Filter', 'fw' ), '', 'filter_action', false );
		echo '</div>';
	}

	public function no_items() {
		esc_html_e( 'No bookings here.', 'fw' );
	}

	protected function column_ref( $b ) {
		$out     = '<strong>' . esc_html( $b->ref ) . '</strong>';
		$actions = array();
		if ( 'block' !== $b->kind ) {
			if ( 'confirmed' !== $b->status ) {
				$actions['confirm'] = '<a href="' . esc_url( FW_Bookings_Admin::action_url( $b->id, 'confirm' ) ) . '">' . esc_html__( 'Confirm', 'fw' ) . '</a>';
			}
			if ( 'pending' === $b->status ) {
				$actions['decline'] = '<a href="' . esc_url( FW_Bookings_Admin::action_url( $b->id, 'decline' ) ) . '">' . esc_html__( 'Decline', 'fw' ) . '</a>';
			}
			if ( 'confirmed' === $b->status ) {
				$actions['cancel'] = '<a href="' . esc_url( FW_Bookings_Admin::action_url( $b->id, 'cancel' ) ) . '">' . esc_html__( 'Cancel', 'fw' ) . '</a>';
			}
			if ( $b->entry_id && class_exists( 'FW_Forms_Entries_Admin' ) ) {
				$actions['entry'] = '<a href="' . esc_url( FW_Forms_Entries_Admin::entry_url( $b->entry_id ) ) . '">' . esc_html__( 'Form entry', 'fw' ) . '</a>';
			}
		}
		$actions['delete'] = '<a class="submitdelete" href="' . esc_url( FW_Bookings_Admin::action_url( $b->id, 'delete' ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this for good?', 'fw' ) ) . '\')">' . esc_html__( 'Delete', 'fw' ) . '</a>';
		return $out . $this->row_actions( $actions );
	}

	protected function column_when( $b ) {
		return esc_html( FW_Bookings_Availability::when( $b->start_at, $b->end_at ) );
	}

	protected function column_bookable( $b ) {
		return esc_html( get_the_title( $b->bookable_id ) );
	}

	protected function column_guest( $b ) {
		if ( 'block' === $b->kind ) {
			return '<em>' . esc_html( '' !== $b->name ? sprintf( __( 'Blocked: %s', 'fw' ), $b->name ) : __( 'Blocked', 'fw' ) ) . '</em>';
		}
		$out = esc_html( $b->name );
		if ( '' !== $b->email ) {
			$out .= '<br><a href="mailto:' . esc_attr( $b->email ) . '">' . esc_html( $b->email ) . '</a>';
		}
		if ( '' !== $b->phone ) {
			$out .= '<br>' . esc_html( $b->phone );
		}
		if ( '' !== (string) $b->notes ) {
			$out .= '<details><summary>' . esc_html__( 'Notes', 'fw' ) . '</summary>' . nl2br( esc_html( $b->notes ) ) . '</details>';
		}
		return $out;
	}

	protected function column_guests( $b ) {
		return 'block' === $b->kind ? '—' : (int) $b->guests;
	}

	protected function column_total( $b ) {
		if ( $b->total <= 0 ) {
			return '—';
		}
		$out = esc_html( FW_Bookings_Availability::money( $b->total ) );
		if ( $b->paid > 0 ) {
			$out .= '<br><small style="color:#007017">' . esc_html( $b->paid >= $b->total ? __( 'Paid', 'fw' ) : sprintf( __( '%s paid', 'fw' ), FW_Bookings_Availability::money( $b->paid ) ) ) . '</small>';
		}
		return $out;
	}

	protected function column_status( $b ) {
		$l = array( 'pending' => __( 'Waiting for you', 'fw' ), 'confirmed' => __( 'Confirmed', 'fw' ), 'declined' => __( 'Declined', 'fw' ), 'cancelled' => __( 'Cancelled', 'fw' ) );
		$c = array( 'pending' => '#996800', 'confirmed' => '#007017', 'declined' => '#b32d2e', 'cancelled' => '#646970' );
		return 'block' === $b->kind ? esc_html__( 'Block', 'fw' ) : '<span style="font-weight:600;color:' . esc_attr( $c[ $b->status ] ?? '#000' ) . '">' . esc_html( $l[ $b->status ] ?? $b->status ) . '</span>';
	}

	protected function column_source( $b ) {
		return esc_html( 0 === strpos( $b->source, 'form:' ) ? __( 'A form', 'fw' ) : ucfirst( $b->source ) );
	}
}
