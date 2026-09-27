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

	/** @var int Named locks granted. */
	public int $locks = 0;

	public string $prefix = 'wp_';

	public int $insert_id = 0;

	public int $rows_affected = 0;

	/** @var array<string, array<int, array<string,mixed>>> */
	public array $tables = array();

	/** @var array<int, string> */
	public array $create_table_sql = array();

	/**
	 * Declared UNIQUE keys per table: table => array of array<column-name>.
	 * Lets the shim reject duplicate INSERT IGNOREs the way MySQL would,
	 * so atomic-claim tests exercise the real constraint behaviour.
	 *
	 * @var array<string, array<int, array<int, string>>>
	 */
	public array $unique_keys = array();

	/**
	 * Column DEFAULT values parsed from CREATE TABLE, applied on insert so
	 * the shim mirrors MySQL columns that have a DEFAULT (e.g.
	 * refunded_cents BIGINT NOT NULL DEFAULT 0).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $column_defaults = array();

	/**
	 * Declared column names per table, parsed from CREATE TABLE and updated by
	 * ALTER TABLE ADD COLUMN. Lets SHOW COLUMNS report the real DDL state so
	 * the schema-upgrade backfill (ensure_intent_column) exercises both the
	 * "column already present" and "column missing" branches like MySQL.
	 *
	 * @var array<string, array<int, string>>
	 */
	public array $table_columns = array();

	/**
	 * Declared index key-names per table, parsed from CREATE TABLE and updated
	 * by ALTER TABLE ADD KEY. Backs SHOW INDEX.
	 *
	 * @var array<string, array<int, string>>
	 */
	public array $table_indexes = array();

	/**
	 * Tables as they were at START TRANSACTION; ROLLBACK restores them.
	 *
	 * @var array<string, array<int, array<string,mixed>>>|null
	 */
	private ?array $snapshot = null;

	/** What GET_LOCK returns: '1' acquired, '0' timed out. */
	public string $lock_result = '1';

	/** Named locks taken, in order (for assertions). @var array<int,string> */
	public array $locks_taken = array();

	/** Statements run through query(), for assertions. @var array<int,string> */
	public array $queries = array();

	/** Statements run through get_var(), for assertions. @var array<int,string> */
	public array $reads = array();

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
		$this->reads[] = $sql;
		if ( preg_match( "/SELECT GET_LOCK\(\s*'([^']+)'/i", $sql, $m ) ) {
			if ( '1' === $this->lock_result ) {
				$this->locks_taken[] = $m[1];
				++$this->locks;
			}
			return $this->lock_result;
		}
		if ( preg_match( '/SELECT RELEASE_LOCK/i', $sql ) ) {
			return '1';
		}
		// Ledger::query() count / sum: WHERE 1=1 AND ...
		if ( preg_match( '/SELECT\s+(COUNT\(\*\)|COALESCE\(\s*SUM\(\s*amount\s*\)\s*,\s*0\s*\))\s+FROM\s+(\S+)\s+WHERE\s+1=1(.*)$/is', $sql, $m ) ) {
			$rows = $this->filter_where( $m[2], $m[3] );
			if ( 0 === stripos( $m[1], 'COUNT' ) ) {
				return (string) count( $rows );
			}
			return (string) array_sum( array_map( static fn ( $r ) => (int) ( $r['amount'] ?? 0 ), $rows ) );
		}
		if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/i", $sql, $m ) ) {
			return isset( $this->tables[ $m[1] ] ) ? $m[1] : null;
		}
		// SHOW COLUMNS FROM `table` LIKE 'col' — returns the Field name when the
		// column exists (get_var reads the first column of the first row), null
		// otherwise. Mirrors the ensure_intent_column() probe.
		if ( preg_match( "/SHOW COLUMNS FROM\s+`?([^`\s]+)`?\s+LIKE\s+'([^']*)'/i", $sql, $m ) ) {
			$cols = $this->table_columns[ $m[1] ] ?? array();
			return in_array( $m[2], $cols, true ) ? $m[2] : null;
		}
		// SHOW INDEX FROM `table` WHERE Key_name = 'name' — returns the table
		// name (non-null) when the index exists, null otherwise.
		if ( preg_match( "/SHOW INDEX FROM\s+`?([^`\s]+)`?\s+WHERE\s+Key_name\s*=\s*'([^']*)'/i", $sql, $m ) ) {
			$idx = $this->table_indexes[ $m[1] ] ?? array();
			return in_array( $m[2], $idx, true ) ? $m[1] : null;
		}
		// Existence pre-check used by Processed_Events::exists():
		//   SELECT id FROM <table> WHERE slug='..' AND gateway='..' AND event_id='..' LIMIT 1
		if ( preg_match( "/SELECT\s+id\s+FROM\s+(\S+)\s+WHERE\s+slug='([^']*)'\s+AND\s+gateway='([^']*)'\s+AND\s+event_id='([^']*)'/i", $sql, $m ) ) {
			$table = $m[1];
			foreach ( $this->tables[ $table ] ?? array() as $row ) {
				if ( (string) ( $row['slug'] ?? '' ) === $m[2]
					&& (string) ( $row['gateway'] ?? '' ) === $m[3]
					&& (string) ( $row['event_id'] ?? '' ) === $m[4] ) {
					return (string) ( $row['id'] ?? '1' );
				}
			}
			return null;
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
		// Transaction_Log::count_transactions — SELECT COUNT(*) FROM t WHERE slug='..' [AND kind='..'] [AND gateway='..'] [AND user_id=N]
		if ( preg_match( '/SELECT\s+COUNT\(\*\)\s+FROM\s+(\S+)\s+WHERE\s+(.+?)\s*$/is', $sql, $m ) ) {
			return (string) count( $this->filter_log_rows( $m[1], $m[2] ) );
		}
		return null;
	}

	public function get_results( string $sql ): array {
		// Ledger::open_holds(): one item's rows, oldest first, as objects.
		if ( preg_match( '/FROM\s+(\S+)\s+WHERE\s+user_id\s*=\s*(\d+)\s+AND\s+item_id\s*=\s*(\d+)\s+ORDER BY id ASC/i', $sql, $m ) ) {
			$rows = array_values(
				array_filter(
					$this->tables[ $m[1] ] ?? array(),
					static fn ( $r ) => (int) ( $r['user_id'] ?? 0 ) === (int) $m[2] && (int) ( $r['item_id'] ?? 0 ) === (int) $m[3]
				)
			);
			usort( $rows, static fn ( $a, $b ) => (int) $a['id'] <=> (int) $b['id'] );
			return array_map( array( $this, 'as_ledger_object' ), $rows );
		}
		// Ledger::query() rows: WHERE 1=1 AND ... ORDER BY id DESC|ASC LIMIT n OFFSET m.
		if ( preg_match( '/FROM\s+(\S+)\s+WHERE\s+1=1(.*?)\s+ORDER BY id (ASC|DESC)\s+LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/is', $sql, $m ) ) {
			$rows = $this->filter_where( $m[1], $m[2] );
			usort( $rows, static fn ( $a, $b ) => 'ASC' === strtoupper( $m[3] ) ? (int) $a['id'] <=> (int) $b['id'] : (int) $b['id'] <=> (int) $a['id'] );
			return array_map( array( $this, 'as_ledger_object' ), array_slice( $rows, (int) $m[5], (int) $m[4] ) );
		}
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
		// Transaction_Log::list_transactions — SELECT * FROM t WHERE slug='..' [AND kind='..'] [AND gateway='..'] [AND user_id=N] ORDER BY id DESC LIMIT N OFFSET N
		if ( preg_match( '/SELECT \* FROM\s+(\S+)\s+WHERE\s+(.+?)\s+ORDER BY\s+id DESC\s+LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/is', $sql, $m ) ) {
			$rows = $this->filter_log_rows( $m[1], $m[2] );
			usort( $rows, static fn ( $a, $b ) => (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 ) );
			return array_slice( $rows, (int) $m[4], (int) $m[3] );
		}
		return array();
	}

	/**
	 * Filter stored rows of a table by the equality conditions found in a
	 * WHERE clause (slug / kind / gateway string cols + user_id int). Shared by
	 * the Transaction_Log list + count shims.
	 *
	 * @param string $table Table name.
	 * @param string $where WHERE clause with params already interpolated.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_log_rows( string $table, string $where ): array {
		$rows = array_values( $this->tables[ $table ] ?? array() );
		foreach ( array( 'slug', 'kind', 'gateway' ) as $col ) {
			if ( preg_match( "/\b{$col}\s*=\s*'([^']*)'/i", $where, $mm ) ) {
				$val  = $mm[1];
				$rows = array_values(
					array_filter( $rows, static fn ( $r ) => (string) ( $r[ $col ] ?? '' ) === $val )
				);
			}
		}
		if ( preg_match( '/\buser_id\s*=\s*(\d+)/i', $where, $mm ) ) {
			$uid  = (int) $mm[1];
			$rows = array_values(
				array_filter( $rows, static fn ( $r ) => (int) ( $r['user_id'] ?? 0 ) === $uid )
			);
		}
		return $rows;
	}

	/**
	 * Return a single matching row as an associative array. Supports the
	 * gateway transaction-log lookups:
	 *   SELECT * FROM <t> WHERE slug='..' AND gateway='..' AND kind='..' AND session_id='..'
	 *   SELECT * FROM <t> WHERE slug='..' AND gateway='..' AND kind='..' AND payment_intent='..'
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $sql, string $output = 'OBJECT' ): array|object|null {
		if ( ! preg_match( '/FROM\s+(\S+)\s+WHERE\s+(.+?)(?:\s+LIMIT|\s*$)/is', $sql, $m ) ) {
			return null;
		}
		$table  = $m[1];
		$where  = array();
		if ( preg_match_all( "/(\w+)\s*=\s*(?:'([^']*)'|(\d+))/", $m[2], $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $p ) {
				$where[ $p[1] ] = isset( $p[3] ) && '' !== $p[3] ? $p[3] : $p[2];
			}
		}
		foreach ( $this->tables[ $table ] ?? array() as $row ) {
			$match = true;
			foreach ( $where as $k => $v ) {
				if ( (string) ( $row[ $k ] ?? '' ) !== $v ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				return 'OBJECT' === $output ? $this->as_ledger_object( $row ) : $row;
			}
		}
		return null;
	}

	/**
	 * A stored row as $wpdb returns it by default: an object whose values
	 * are strings, with the 1.9.0 ledger columns defaulted.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return object
	 */
	public function as_ledger_object( array $row ): object {
		$row += array( 'reason' => '', 'reference' => '', 'hold_id' => 0 );
		return (object) array_map( static fn ( $v ) => is_scalar( $v ) ? (string) $v : $v, $row );
	}

	/**
	 * Rows of a table matching "AND col = x / col IN (...) / col >= 'x' /
	 * col < 'x'" conditions, the shape Ledger::query() builds.
	 *
	 * @param string $table Table.
	 * @param string $conds Everything after "WHERE 1=1".
	 * @return array<int, array<string,mixed>>
	 */
	private function filter_where( string $table, string $conds ): array {
		$rows = array_values( $this->tables[ $table ] ?? array() );
		$rows = array_map( static fn ( $r ) => $r + array( 'reason' => '', 'reference' => '', 'hold_id' => 0 ), $rows );
		preg_match_all( "/AND\s+(\w+)\s*(=|>=|<=|<|>|IN)\s*(\([^)]*\)|'[^']*'|-?\d+)/i", $conds, $all, PREG_SET_ORDER );
		foreach ( $all as $c ) {
			list( , $col, $op, $raw ) = $c;
			$op = strtoupper( $op );
			if ( 'IN' === $op ) {
				preg_match_all( "/'([^']*)'|(-?\d+)/", $raw, $vm, PREG_SET_ORDER );
				$vals = array_map( static fn ( $v ) => isset( $v[2] ) && '' !== $v[2] ? $v[2] : $v[1], $vm );
				$rows = array_filter( $rows, static fn ( $r ) => in_array( (string) $r[ $col ], $vals, true ) );
				continue;
			}
			$val  = trim( $raw, "'" );
			$rows = array_filter(
				$rows,
				static function ( $r ) use ( $col, $op, $val ) {
					$have = (string) ( $r[ $col ] ?? '' );
					$cmp  = is_numeric( $have ) && is_numeric( $val ) ? ( (float) $have <=> (float) $val ) : strcmp( $have, $val );
					switch ( $op ) {
						case '=':
							return 0 === $cmp;
						case '>=':
							return $cmp >= 0;
						case '<=':
							return $cmp <= 0;
						case '<':
							return $cmp < 0;
						default:
							return $cmp > 0;
					}
				}
			);
		}
		return array_values( $rows );
	}

	/**
	 * Execute a non-SELECT statement. Supports:
	 *   INSERT IGNORE INTO <t> (slug, gateway, event_id) VALUES ('..','..','..')
	 *   UPDATE <t> SET refunded_cents = refunded_cents + N WHERE id=K AND kind='..'
	 *   START TRANSACTION / COMMIT / ROLLBACK (no-ops)
	 *
	 * @return int|false Rows affected (also recorded in rows_affected).
	 */
	public function query( string $sql ): int|false {
		$this->rows_affected = 0;

		$this->queries[] = $sql;

		if ( preg_match( '/^\s*START TRANSACTION/i', $sql ) ) {
			$this->snapshot = $this->tables;
			return 0;
		}
		if ( preg_match( '/^\s*COMMIT/i', $sql ) ) {
			$this->snapshot = null;
			return 0;
		}
		if ( preg_match( '/^\s*ROLLBACK/i', $sql ) ) {
			if ( null !== $this->snapshot ) {
				$this->tables   = $this->snapshot;
				$this->snapshot = null;
			}
			return 0;
		}

		if ( preg_match( "/INSERT\s+IGNORE\s+INTO\s+(\S+)\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/is", $sql, $m ) ) {
			$table = $m[1];
			$cols  = array_map( 'trim', explode( ',', $m[2] ) );
			$vals  = array();
			if ( preg_match_all( "/'([^']*)'|(\d+)/", $m[3], $vm, PREG_SET_ORDER ) ) {
				foreach ( $vm as $v ) {
					$vals[] = isset( $v[2] ) && '' !== $v[2] ? $v[2] : $v[1];
				}
			}
			$data = array();
			foreach ( $cols as $i => $c ) {
				$data[ $c ] = $vals[ $i ] ?? '';
			}
			if ( $this->violates_unique( $table, $data ) ) {
				// MySQL INSERT IGNORE: duplicate is a silent no-op, 0 rows.
				$this->rows_affected = 0;
				return 0;
			}
			if ( ! isset( $this->tables[ $table ] ) ) {
				$this->tables[ $table ] = array();
			}
			$data['id']               = $this->next_id( $table );
			$data['created_at']       = gmdate( 'Y-m-d H:i:s.u' );
			$this->tables[ $table ][] = $data;
			$this->insert_id          = (int) $data['id'];
			$this->rows_affected      = 1;
			return 1;
		}

		// ALTER TABLE `t` ADD COLUMN <name> ... — record the new column so a
		// subsequent SHOW COLUMNS sees it (idempotency of the backfill).
		if ( preg_match( '/ALTER TABLE\s+`?([^`\s]+)`?\s+ADD COLUMN\s+(\w+)/i', $sql, $m ) ) {
			$table = $m[1];
			$col   = $m[2];
			if ( ! in_array( $col, $this->table_columns[ $table ] ?? array(), true ) ) {
				$this->table_columns[ $table ][] = $col;
			}
			// Capture a DEFAULT so insert() mirrors it (e.g. NOT NULL DEFAULT '').
			if ( preg_match( "/DEFAULT\s+'([^']*)'/i", $sql, $dm ) ) {
				$this->column_defaults[ $table ][ $col ] = $dm[1];
			}
			$this->rows_affected = 0;
			return 0;
		}

		// ALTER TABLE `t` ADD KEY <name> (...) — record the new index.
		if ( preg_match( '/ALTER TABLE\s+`?([^`\s]+)`?\s+ADD KEY\s+(\w+)/i', $sql, $m ) ) {
			$table = $m[1];
			$idx   = $m[2];
			if ( ! in_array( $idx, $this->table_indexes[ $table ] ?? array(), true ) ) {
				$this->table_indexes[ $table ][] = $idx;
			}
			$this->rows_affected = 0;
			return 0;
		}

		if ( preg_match( '/UPDATE\s+(\S+)\s+SET\s+refunded_cents\s*=\s*refunded_cents\s*\+\s*(\d+)\s+WHERE\s+id=(\d+)/i', $sql, $m ) ) {
			$table = $m[1];
			$delta = (int) $m[2];
			$id    = (int) $m[3];
			foreach ( $this->tables[ $table ] ?? array() as $i => $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === $id ) {
					$this->tables[ $table ][ $i ]['refunded_cents'] = (int) ( $row['refunded_cents'] ?? 0 ) + $delta;
					$this->rows_affected = 1;
					return 1;
				}
			}
			return 0;
		}

		return 0;
	}

	/**
	 * Register a UNIQUE key so INSERT IGNORE rejects duplicates like MySQL.
	 *
	 * @param string             $table   Full table name.
	 * @param array<int, string> $columns Columns forming the unique key.
	 */
	public function register_unique_key( string $table, array $columns ): void {
		$this->unique_keys[ $table ][] = $columns;
	}

	/**
	 * Would inserting $data collide with any declared UNIQUE key on $table?
	 *
	 * @param array<string,mixed> $data
	 */
	private function violates_unique( string $table, array $data ): bool {
		foreach ( $this->unique_keys[ $table ] ?? array() as $key_cols ) {
			foreach ( $this->tables[ $table ] ?? array() as $row ) {
				$same = true;
				foreach ( $key_cols as $col ) {
					if ( (string) ( $row[ $col ] ?? '' ) !== (string) ( $data[ $col ] ?? '' ) ) {
						$same = false;
						break;
					}
				}
				if ( $same ) {
					return true;
				}
			}
		}
		return false;
	}

	public function insert( string $table, array $data, ?array $format = null ): int|false {
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->tables[ $table ] = array();
		}
		// Apply column DEFAULTs for any column the caller didn't supply,
		// mirroring MySQL (e.g. refunded_cents DEFAULT 0).
		foreach ( $this->column_defaults[ $table ] ?? array() as $col => $default ) {
			if ( ! array_key_exists( $col, $data ) ) {
				$data[ $col ] = $default;
			}
		}
		$data['id']               = $this->next_id( $table );
		$data['created_at']       = $data['created_at'] ?? gmdate( 'Y-m-d H:i:s.u' );
		$this->tables[ $table ][] = $data;
		$this->insert_id          = (int) $data['id'];
		$this->rows_affected      = 1;
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
		// A single dbDelta() call may bundle multiple CREATE TABLE statements
		// (the test bootstrap joins them with ';'). Handle each.
		foreach ( preg_split( '/;\s*/', $sql ) as $stmt ) {
			if ( ! preg_match( '/CREATE TABLE\s+(\S+)/i', $stmt, $m ) ) {
				continue;
			}
			$table = $m[1];
			if ( ! isset( $this->tables[ $table ] ) ) {
				$this->tables[ $table ] = array();
			}
			// Capture declared column names + KEY names from the CREATE TABLE
			// body so SHOW COLUMNS / SHOW INDEX report the real DDL state.
			if ( preg_match( '/CREATE TABLE\s+\S+\s*\((.*)\)\s*[^)]*$/is', $stmt, $bm ) ) {
				foreach ( preg_split( '/,\s*\n/', $bm[1] ) as $line ) {
					$line = trim( $line );
					if ( preg_match( '/^(PRIMARY KEY|UNIQUE KEY|KEY|INDEX)\s+(\w+)?/i', $line, $km ) ) {
						if ( ! empty( $km[2] ) ) {
							$this->table_indexes[ $table ][] = $km[2];
						}
					} elseif ( preg_match( '/^(\w+)\s+[A-Za-z]/', $line, $cm ) ) {
						$this->table_columns[ $table ][] = $cm[1];
					}
				}
			}
			// Capture UNIQUE KEY declarations so INSERT IGNORE enforces them.
			if ( preg_match_all( '/UNIQUE KEY\s+\w+\s*\(([^)]+)\)/i', $stmt, $um, PREG_SET_ORDER ) ) {
				foreach ( $um as $u ) {
					$cols = array_map( 'trim', explode( ',', $u[1] ) );
					$this->register_unique_key( $table, $cols );
				}
			}
			// Capture column DEFAULTs (numeric + quoted-string) so insert()
			// mirrors MySQL's column defaults.
			if ( preg_match_all( "/^\s*(\w+)\s+[A-Z].*?DEFAULT\s+('([^']*)'|(-?\d+))/im", $stmt, $dm, PREG_SET_ORDER ) ) {
				foreach ( $dm as $d ) {
					$col = $d[1];
					if ( in_array( strtoupper( $col ), array( 'PRIMARY', 'UNIQUE', 'KEY', 'INDEX' ), true ) ) {
						continue;
					}
					$this->column_defaults[ $table ][ $col ] = isset( $d[4] ) && '' !== $d[4] ? (int) $d[4] : $d[3];
				}
			}
		}
	}

	/**
	 * Next AUTO_INCREMENT id: never reuses an id after a delete, like MySQL.
	 *
	 * @param string $table Table.
	 * @return int
	 */
	private function next_id( string $table ): int {
		$max = 0;
		foreach ( $this->tables[ $table ] ?? array() as $row ) {
			$max = max( $max, (int) ( $row['id'] ?? 0 ) );
		}
		$this->auto_increment[ $table ] = max( $this->auto_increment[ $table ] ?? 0, $max ) + 1;
		return $this->auto_increment[ $table ];
	}

	/** Highest id handed out per table. @var array<string,int> */
	private array $auto_increment = array();

	public function reset(): void {
		$this->snapshot         = null;
		$this->auto_increment   = array();
		$this->lock_result      = '1';
		$this->locks            = 0;
		$this->locks_taken      = array();
		$this->queries          = array();
		$this->reads            = array();
		$this->tables           = array();
		$this->create_table_sql = array();
		$this->unique_keys      = array();
		$this->column_defaults  = array();
		$this->table_columns    = array();
		$this->table_indexes    = array();
		$this->rows_affected    = 0;
		$this->insert_id        = 0;
	}
}
