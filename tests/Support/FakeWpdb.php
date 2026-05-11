<?php
/**
 * Minimal $wpdb shim for SDK tests.
 *
 * Supports the small slice of $wpdb that Ledger queries actually use:
 *   - prepare() — printf-style substitution of %s / %d
 *   - get_var() — SHOW TABLES LIKE, SELECT SUM(amount) WHERE user_id = N
 *   - get_results() — SELECT ... FROM table WHERE user_id = N LIMIT/OFFSET
 *   - insert() / delete() — array-of-rows storage per table
 *   - prefix / get_charset_collate() — properties
 *
 * Tables are in-memory dicts keyed by table name. dbDelta() is captured
 * separately so SchemaContractTest can assert what the SDK tried to
 * create without us needing a real MySQL parser.
 *
 * @package Wbcom\Credits\Tests\Support
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Support;

final class FakeWpdb {

	public string $prefix = 'wp_';

	/** @var array<string, array<int, array<string,mixed>>> */
	public array $tables = array();

	/** @var array<int, string> */
	public array $create_table_sql = array();

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function prepare( string $sql, mixed ...$args ): string {
		if ( ! empty( $args ) && is_array( $args[0] ) && count( $args ) === 1 ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$pos_s = strpos( $sql, '%s' );
			$pos_d = strpos( $sql, '%d' );
			if ( false === $pos_s && false === $pos_d ) {
				break;
			}
			$use_s = ( false !== $pos_s && ( false === $pos_d || $pos_s < $pos_d ) );
			if ( $use_s ) {
				$replacement = "'" . addslashes( (string) $arg ) . "'";
				$sql         = substr_replace( $sql, $replacement, $pos_s, 2 );
			} else {
				$sql = substr_replace( $sql, (string) (int) $arg, $pos_d, 2 );
			}
		}
		return $sql;
	}

	public function get_var( string $sql ): mixed {
		if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/i", $sql, $m ) ) {
			return isset( $this->tables[ $m[1] ] ) ? $m[1] : null;
		}
		if ( preg_match( '/SELECT\s+COALESCE\(\s*SUM\(\s*amount\s*\)\s*,\s*0\s*\)\s+FROM\s+(\S+)\s+WHERE\s+user_id\s*=\s*(\d+)/i', $sql, $m ) ) {
			$table   = $m[1];
			$user_id = (int) $m[2];
			$sum     = 0;
			foreach ( $this->tables[ $table ] ?? array() as $row ) {
				if ( (int) ( $row['user_id'] ?? 0 ) === $user_id ) {
					$sum += (int) ( $row['amount'] ?? 0 );
				}
			}
			return (string) $sum;
		}
		return null;
	}

	public function get_results( string $sql ): array {
		if ( preg_match( '/FROM\s+(\S+)\s+WHERE\s+user_id\s*=\s*(\d+)\s+ORDER BY[^L]+LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $sql, $m ) ) {
			$table   = $m[1];
			$user_id = (int) $m[2];
			$limit   = (int) $m[3];
			$offset  = (int) $m[4];
			$rows    = array_values(
				array_filter(
					$this->tables[ $table ] ?? array(),
					static fn ( $r ) => (int) ( $r['user_id'] ?? 0 ) === $user_id
				)
			);
			usort( $rows, static fn ( $a, $b ) => strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) ) );
			return array_slice( $rows, $offset, $limit );
		}
		return array();
	}

	public function insert( string $table, array $data, ?array $format = null ): int|false {
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->tables[ $table ] = array();
		}
		$data['id']         = count( $this->tables[ $table ] ) + 1;
		$data['created_at'] = $data['created_at'] ?? gmdate( 'Y-m-d H:i:s.u' );
		$this->tables[ $table ][] = $data;
		return 1;
	}

	public function delete( string $table, array $where, ?array $where_format = null ): int|false {
		if ( ! isset( $this->tables[ $table ] ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $this->tables[ $table ] as $i => $row ) {
			$match = true;
			foreach ( $where as $k => $v ) {
				if ( ! array_key_exists( $k, $row ) || $row[ $k ] != $v ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				unset( $this->tables[ $table ][ $i ] );
				++$count;
			}
		}
		$this->tables[ $table ] = array_values( $this->tables[ $table ] );
		return $count;
	}

	public function record_create_table( string $sql ): void {
		$this->create_table_sql[] = $sql;
		if ( preg_match( '/CREATE TABLE\s+(\S+)/i', $sql, $m ) ) {
			$table = $m[1];
			if ( ! isset( $this->tables[ $table ] ) ) {
				$this->tables[ $table ] = array();
			}
		}
	}

	public function reset(): void {
		$this->tables           = array();
		$this->create_table_sql = array();
	}
}
