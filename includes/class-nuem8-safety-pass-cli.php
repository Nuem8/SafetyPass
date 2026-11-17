<?php
/**
 * WP-CLI commands for nuem8-safety-pass by NueM8.
 *
 * Loaded only when WP-CLI is running.
 *
 * @package NueM8_Safety_Pass
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read NueM8 Safety Pass from the command line.
 *
 * ## EXAMPLES
 *
 *     wp safetypass status
 *     wp safetypass check
 *     wp safetypass approve 12
 *     wp safetypass approve 12 --user=1
 */
class NueM8_Safety_Pass_CLI {

	/**
	 * The one thing to do first.
	 *
	 * Exit status is 0 when every check is OK, 1 when a check says Look, and 2 when a check says Stop.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: plain
	 * options:
	 *   - plain
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp safetypass status
	 *     wp safetypass status --format=json
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function status( $args, $assoc_args ) {
		$next   = NueM8_Safety_Pass_Checks::next_step( $this->report() );
		$status = is_array( $next ) && ! empty( $next['status'] ) ? (string) $next['status'] : 'ok';
		$row    = array(
			'status' => $status,
			'id'     => is_array( $next ) && isset( $next['id'] ) ? (string) $next['id'] : '',
			'title'  => is_array( $next ) && isset( $next['title'] ) ? (string) $next['title'] : __( 'OK', 'safety-pass-by-nuem8' ),
			'detail' => is_array( $next ) && isset( $next['detail'] ) ? (string) $next['detail'] : __( 'Nothing needs you this month. Open nuem8-safety-pass again after you add a plugin or a user.', 'safety-pass-by-nuem8' ),
			'action' => is_array( $next ) && isset( $next['action'] ) ? (string) $next['action'] : '',
		);

		$this->render_rows( array( $row ), $assoc_args, array( 'status', 'id', 'title', 'detail', 'action' ) );
		$this->halt_for( $status );
	}

	/**
	 * Run every check that is turned on.
	 *
	 * File names that differ are listed under that check. Exit status matches status: 0 for OK, 1 for Look, and 2 for Stop.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: plain
	 * options:
	 *   - plain
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp safetypass check
	 *     wp safetypass check --format=json
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function check( $args, $assoc_args ) {
		$findings = $this->report();
		$format   = $this->format( $assoc_args );
		$worst    = 'ok';

		if ( 'json' === $format || 'yaml' === $format ) {
			WP_CLI::print_value( array_values( $findings ), $assoc_args );
		} else {
			$rows = array();

			foreach ( $findings as $finding ) {
				if ( ! is_array( $finding ) ) {
					continue;
				}

				$status = isset( $finding['status'] ) ? (string) $finding['status'] : 'look';
				$worst  = $this->worse( $worst, $status );
				$rows[] = array(
					'id'     => isset( $finding['id'] ) ? (string) $finding['id'] : '',
					'status' => $status,
					'title'  => isset( $finding['title'] ) ? (string) $finding['title'] : '',
					'detail' => isset( $finding['detail'] ) ? (string) $finding['detail'] : '',
					'action' => isset( $finding['action'] ) ? (string) $finding['action'] : '',
				);

				if ( 'plain' === $format ) {
					$this->log_plain_row( $rows[ count( $rows ) - 1 ] );
					$this->log_items( isset( $finding['items'] ) ? $finding['items'] : array() );
					WP_CLI::log( '' );
				}
			}

			if ( 'plain' !== $format ) {
				$this->render_rows( $rows, $assoc_args, array( 'id', 'status', 'title', 'detail', 'action' ) );
			}
		}

		if ( 'json' === $format || 'yaml' === $format ) {
			foreach ( $findings as $finding ) {
				if ( is_array( $finding ) && isset( $finding['status'] ) ) {
					$worst = $this->worse( $worst, (string) $finding['status'] );
				}
			}
		}

		$this->halt_for( $worst );
	}

	/**
	 * Approve one administrator and allow that account to sign in.
	 *
	 * The account is added to the expected list, and a Safety Pass hold is lifted.
	 * When --user is set, that user must be an administrator who can open Safety Pass, and they cannot approve themselves.
	 * With no --user, the shell is the operator.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : The user ID to approve.
	 *
	 * ## EXAMPLES
	 *
	 *     wp safetypass approve 12
	 *     wp safetypass approve 12 --user=1
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function approve( $args, $assoc_args ) {
		$user_id = isset( $args[0] ) ? (int) $args[0] : 0;

		if ( $user_id < 1 ) {
			WP_CLI::error( __( 'Pass the user ID to approve.', 'safety-pass-by-nuem8' ) );
		}

		if ( (int) get_current_user_id() > 0 && ! NueM8_Safety_Pass_Checks::current_user_can_view() ) {
			WP_CLI::error( __( 'Pass --user with an administrator who can open Safety Pass.', 'safety-pass-by-nuem8' ) );
		}

		$result = NueM8_Safety_Pass_Checks::approve_for_cli( $user_id );

		if ( 'approved' === $result ) {
			WP_CLI::success(
				sprintf(
					/* translators: %d: user ID. */
					__( 'User %d is approved and can sign in.', 'safety-pass-by-nuem8' ),
					$user_id
				)
			);
			return;
		}

		if ( 'unchanged' === $result ) {
			WP_CLI::success(
				sprintf(
					/* translators: %d: user ID. */
					__( 'User %d is already expected.', 'safety-pass-by-nuem8' ),
					$user_id
				)
			);
			return;
		}

		if ( 'approve_self' === $result ) {
			WP_CLI::error( __( 'The account you are signed in with cannot approve itself.', 'safety-pass-by-nuem8' ) );
		}

		WP_CLI::error( __( 'That account is not an administrator, so it was left unchanged.', 'safety-pass-by-nuem8' ) );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function report() {
		if ( (int) get_current_user_id() > 0 && ! NueM8_Safety_Pass_Checks::current_user_can_view() ) {
			WP_CLI::error( __( 'Pass --user with an administrator who can open Safety Pass.', 'safety-pass-by-nuem8' ) );
		}

		return NueM8_Safety_Pass_Checks::publish_report();
	}

	/**
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return string
	 */
	private function format( array $assoc_args ) {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'plain';

		if ( ! in_array( $format, array( 'plain', 'table', 'json', 'csv', 'yaml' ), true ) ) {
			WP_CLI::error( __( 'Format must be plain, table, json, csv, or yaml.', 'safety-pass-by-nuem8' ) );
		}

		return $format;
	}

	/**
	 * @param array<int, array<string, string>> $rows       Output rows.
	 * @param array<string, string>             $assoc_args Named arguments.
	 * @param string[]                          $fields     Column names.
	 */
	private function render_rows( array $rows, array $assoc_args, array $fields ) {
		$format = $this->format( $assoc_args );

		if ( 'plain' === $format ) {
			foreach ( $rows as $row ) {
				$this->log_plain_row( $row );
			}
			return;
		}

		if ( 'json' === $format || 'yaml' === $format ) {
			WP_CLI::print_value( $rows, $assoc_args );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * @param array<string, string> $row One result.
	 */
	private function log_plain_row( array $row ) {
		$status = isset( $row['status'] ) ? $row['status'] : '';
		$title  = isset( $row['title'] ) ? $row['title'] : '';
		WP_CLI::log( strtoupper( $status ) . ( '' !== $title ? "\t" . $title : '' ) );

		if ( ! empty( $row['detail'] ) ) {
			WP_CLI::log( $row['detail'] );
		}

		if ( ! empty( $row['action'] ) ) {
			WP_CLI::log( $row['action'] );
		}
	}

	/**
	 * @param mixed $items Extra rows from a check.
	 */
	private function log_items( $items ) {
		if ( ! is_array( $items ) ) {
			return;
		}

		$lines = array();

		foreach ( $items as $item ) {
			$line = $this->item_line( $item );

			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		$extra = count( $lines ) - 100;

		foreach ( array_slice( $lines, 0, 100 ) as $line ) {
			WP_CLI::log( '  ' . $line );
		}

		if ( $extra > 0 ) {
			WP_CLI::log(
				'  ' . sprintf(
					/* translators: %d: number of additional rows not listed. */
					__( '%d more', 'safety-pass-by-nuem8' ),
					$extra
				)
			);
		}
	}

	/**
	 * @param mixed $item One extra row.
	 * @return string
	 */
	private function item_line( $item ) {
		if ( is_scalar( $item ) ) {
			return trim( (string) $item );
		}

		if ( ! is_array( $item ) ) {
			return '';
		}

		if ( isset( $item['login'] ) ) {
			$bits = array(
				'#' . ( isset( $item['id'] ) ? (int) $item['id'] : 0 ),
				(string) $item['login'],
			);

			if ( ! empty( $item['is_new'] ) ) {
				$bits[] = 'new';
			}

			if ( ! empty( $item['disabled'] ) ) {
				$bits[] = 'disabled';
			}

			return implode( ' ', $bits );
		}

		if ( isset( $item['name'] ) ) {
			$line = (string) $item['name'];

			if ( ! empty( $item['version'] ) && ! empty( $item['offered'] ) ) {
				$line .= ' ' . $item['version'] . ' -> ' . $item['offered'];
			}

			return $line;
		}

		return '';
	}

	/**
	 * @param string $current Worst status so far.
	 * @param string $status  Next status.
	 * @return string
	 */
	private function worse( $current, $status ) {
		$rank = array(
			'ok'   => 0,
			'look' => 1,
			'stop' => 2,
		);
		$next = isset( $rank[ $status ] ) ? $rank[ $status ] : 1;
		$have = isset( $rank[ $current ] ) ? $rank[ $current ] : 0;

		return $next > $have ? ( isset( $rank[ $status ] ) ? $status : 'look' ) : $current;
	}

	/**
	 * @param string $status ok, look, or stop.
	 */
	private function halt_for( $status ) {
		$codes = array(
			'ok'   => 0,
			'look' => 1,
			'stop' => 2,
		);

		WP_CLI::halt( isset( $codes[ $status ] ) ? $codes[ $status ] : 1 );
	}
}
