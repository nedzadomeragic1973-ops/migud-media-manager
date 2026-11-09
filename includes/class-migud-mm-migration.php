<?php
/**
 * Data migration from the former Fena Media Manager keys (fmm_*) to the
 * MIGUD Media Manager keys (migud_mm_*).
 *
 * Not wired to any hook: the migration only runs when it is called explicitly.
 *
 * @package MIGUD_Media_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Safe, resumable, lock-protected migration of the stored plugin data.
 *
 * - Folders keep their term IDs, term_taxonomy IDs, hierarchy and
 *   attachment assignments: the taxonomy is renamed in place.
 * - Term meta, user meta and options are copied to the new keys; the old
 *   keys are never deleted or changed.
 * - Existing new values are never overwritten. Different values abort the run.
 * - Dry run (default) only reads.
 */
class Migud_MM_Migration {

	/**
	 * Identifier of this migration.
	 */
	const ID = 'fmm-to-migud-mm';

	/**
	 * Version of the stored data after this migration.
	 */
	const VERSION = 1;

	/**
	 * Option holding the migration state (status, steps, baseline). Autoload off.
	 */
	const STATE_OPTION = 'migud_mm_migration';

	/**
	 * Option used as migration lock. Autoload off.
	 */
	const LOCK_OPTION = 'migud_mm_migration_lock';

	/**
	 * Seconds after which a lock without heartbeat counts as stale.
	 */
	const LOCK_TTL = 600;

	/**
	 * Former folder taxonomy.
	 */
	const SOURCE_TAXONOMY = 'fmm_folder';

	/**
	 * New folder taxonomy.
	 */
	const TARGET_TAXONOMY = 'migud_mm_folder';

	/**
	 * Term meta keys of folder terms (old => new).
	 */
	const TERM_META = array(
		'fmm_color'  => 'migud_mm_color',
		'fmm_icon'   => 'migud_mm_icon',
		'fmm_locked' => 'migud_mm_locked',
		'fmm_order'  => 'migud_mm_order',
	);

	/**
	 * User meta keys (old => new).
	 */
	const USER_META = array(
		'fmm_folder_sort' => 'migud_mm_folder_sort',
	);

	/**
	 * Options (old => new).
	 */
	const OPTIONS = array(
		'fmm_settings'        => 'migud_mm_settings',
		'fmm_defaults_seeded' => 'migud_mm_defaults_seeded',
	);

	/**
	 * Option that marks the default folders as created.
	 */
	const SEED_OPTION = 'migud_mm_defaults_seeded';

	/**
	 * Former import lock option (an import in progress blocks the migration).
	 */
	const LEGACY_IMPORT_LOCK = 'fmm_import_lock';

	/**
	 * Seconds the former import lock counts as active (Transfer::LOCK_TTL).
	 */
	const LEGACY_IMPORT_LOCK_TTL = 300;

	/**
	 * Main file of the former plugin.
	 */
	const LEGACY_PLUGIN_FILE = 'fena-media-manager.php';

	/**
	 * Migration steps in execution order. Every step is idempotent.
	 */
	const STEPS = array( 'options', 'term_meta', 'user_meta', 'taxonomy', 'verify' );

	/**
	 * Database access.
	 *
	 * @var wpdb
	 */
	private $db;

	/**
	 * Run arguments.
	 *
	 * @var array
	 */
	private $args;

	/**
	 * Report of this run.
	 *
	 * @var array
	 */
	private $report;

	/**
	 * Current lock value while this run holds the lock.
	 *
	 * @var string
	 */
	private $lock_value = '';

	/**
	 * Rows written by this run.
	 *
	 * @var int
	 */
	private $changes = 0;

	/**
	 * Constructor.
	 *
	 * @param string $mode 'migrate' or 'rollback'.
	 * @param array  $args Run arguments.
	 */
	private function __construct( $mode, $args ) {
		global $wpdb;

		$this->db   = $wpdb;
		$this->args = wp_parse_args(
			$args,
			array(
				'dry_run'          => true,
				'stop_after'       => '',
				'lock_ttl'         => self::LOCK_TTL,
				'allow_divergence' => false,
			)
		);

		$this->args['dry_run']  = (bool) $this->args['dry_run'];
		$this->args['lock_ttl'] = max( 1, (int) $this->args['lock_ttl'] );

		$this->report = array(
			'id'          => self::ID,
			'version'     => self::VERSION,
			'mode'        => $mode,
			'dry_run'     => $this->args['dry_run'],
			'status'      => '',
			'errors'      => array(),
			'warnings'    => array(),
			'before'      => array(),
			'after'       => array(),
			'plan'        => array(),
			'steps'       => array(),
			'changes'     => 0,
			'integrity'   => array(),
			'started_at'  => gmdate( 'c' ),
			'finished_at' => '',
		);
	}

	/**
	 * Runs (or plans) the migration.
	 *
	 * Arguments:
	 * - dry_run    (bool, default true): only read and report.
	 * - stop_after (string): pause after this step (controlled interruption).
	 * - lock_ttl   (int): seconds after which a lock without heartbeat is stale.
	 *
	 * @param array $args Run arguments.
	 * @return array Report.
	 */
	public static function run( $args = array() ) {
		$run = new self( 'migrate', $args );

		return $run->migrate();
	}

	/**
	 * Restores the former taxonomy name (rollback). New keys are kept.
	 *
	 * Arguments:
	 * - dry_run          (bool, default true): only read and report.
	 * - allow_divergence (bool): restore although values under the new keys
	 *                    differ from the old ones (the old values win for
	 *                    the former plugin; nothing is deleted).
	 *
	 * @param array $args Run arguments.
	 * @return array Report.
	 */
	public static function rollback( $args = array() ) {
		$run = new self( 'rollback', $args );

		return $run->restore();
	}

	/**
	 * Stored migration state.
	 *
	 * @return array Empty when the migration never ran.
	 */
	public static function state() {
		global $wpdb;

		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::STATE_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- State must bypass the option cache.
		$state = null === $raw ? array() : maybe_unserialize( $raw );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Whether this migration completed.
	 *
	 * @return bool
	 */
	public static function is_complete() {
		$state = self::state();

		return isset( $state['status'], $state['version'] ) && 'complete' === $state['status'] && (int) $state['version'] >= self::VERSION;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Migration flow
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Migration flow: preflight, lock, steps, verification.
	 *
	 * @return array Report.
	 */
	private function migrate() {
		try {
			$this->report['before'] = $this->inventory();
			$state                  = self::state();

			if ( self::is_complete() ) {
				$this->report['integrity'] = $this->verify_against( isset( $state['baseline'] ) ? $state['baseline'] : array() );
				$this->report['status']    = $this->report['integrity']['ok'] ? 'already_complete' : 'error';
				if ( ! $this->report['integrity']['ok'] ) {
					$this->error( 'integrity_failed', 'The migration is marked complete, but the integrity check failed.', $this->report['integrity'] );
				}
				return $this->finish();
			}

			$this->preflight( $state );
			$this->report['plan'] = $this->plan();

			if ( $this->report['errors'] ) {
				$this->report['status'] = 'aborted';
				return $this->finish();
			}

			if ( $this->args['dry_run'] ) {
				$this->report['status'] = 'planned';
				return $this->finish();
			}

			if ( ! $this->acquire_lock() ) {
				$this->error( 'locked', 'Another migration run holds the lock.' );
				$this->report['status'] = 'aborted';
				return $this->finish();
			}

			$state = $this->start_state( self::state() );

			foreach ( self::STEPS as $step ) {
				if ( ! empty( $state['steps'][ $step ] ) ) {
					$this->report['steps'][ $step ] = 'skipped (done before)';
					continue;
				}

				$this->heartbeat();
				do_action( 'migud_mm_migration_before_step', $step, $this->args );

				$state = $this->run_step( $step, $state );

				do_action( 'migud_mm_migration_after_step', $step, $this->args );

				if ( $step === $this->args['stop_after'] && 'verify' !== $step ) {
					$this->report['status'] = 'paused';
					$this->release_lock();
					return $this->finish();
				}
			}

			$this->report['status'] = 'complete';
			$this->release_lock();
		} catch ( Throwable $e ) {
			$this->rollback_transaction();
			$this->fail( $e );
		}

		return $this->finish();
	}

	/**
	 * Rollback flow: restores the former taxonomy name.
	 *
	 * @return array Report.
	 */
	private function restore() {
		try {
			$this->report['before'] = $this->inventory();
			$state                  = self::state();

			$this->check_legacy_inactive();

			if ( empty( $state['steps']['taxonomy'] ) || empty( $state['baseline']['tt_ids'] ) ) {
				$this->error( 'nothing_to_restore', 'The folder taxonomy was not renamed by this migration.' );
			} else {
				$this->check_restorable( $state['baseline'] );
			}

			$this->report['plan'] = array(
				'taxonomy' => array(
					'from' => self::TARGET_TAXONOMY,
					'to'   => self::SOURCE_TAXONOMY,
					'rows' => $this->count_tt( self::TARGET_TAXONOMY ),
				),
				'kept'     => 'New term meta, user meta and options stay in place; the former keys were never changed.',
			);

			if ( $this->report['errors'] ) {
				$this->report['status'] = 'aborted';
				return $this->finish();
			}

			if ( $this->args['dry_run'] ) {
				$this->report['status'] = 'planned';
				return $this->finish();
			}

			if ( ! $this->acquire_lock() ) {
				$this->error( 'locked', 'Another migration run holds the lock.' );
				$this->report['status'] = 'aborted';
				return $this->finish();
			}

			$this->begin_transaction();
			$rows = $this->db->query( $this->db->prepare( "UPDATE {$this->db->term_taxonomy} SET taxonomy = %s WHERE taxonomy = %s", self::SOURCE_TAXONOMY, self::TARGET_TAXONOMY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- In-place rename keeps all IDs.
			$this->assert_query( $rows, 'restore taxonomy' );
			$this->changes += (int) $rows;

			$state['status']      = 'rolled_back';
			$state['steps']       = array();
			$state['rolled_back'] = gmdate( 'c' );
			$this->save_state( $state );
			$this->commit_transaction();

			$this->clean_caches( $state['baseline'] );
			$this->report['steps']['taxonomy'] = (int) $rows . ' rows renamed back';

			$this->report['integrity'] = $this->verify_restore( $state['baseline'] );
			if ( ! $this->report['integrity']['ok'] ) {
				$this->error( 'integrity_failed', 'The restored data does not match the baseline.', $this->report['integrity'] );
			}

			$this->report['status'] = $this->report['errors'] ? 'error' : 'rolled_back';
			$this->release_lock();
		} catch ( Throwable $e ) {
			$this->rollback_transaction();
			$this->fail( $e );
		}

		return $this->finish();
	}

	/**
	 * Runs one step inside a transaction together with its state update.
	 *
	 * @param string $step  Step name.
	 * @param array  $state Current state.
	 * @return array Updated state.
	 */
	private function run_step( $step, $state ) {
		$this->begin_transaction();

		switch ( $step ) {
			case 'options':
				$result = $this->step_options();
				break;
			case 'term_meta':
				$result = $this->step_term_meta();
				break;
			case 'user_meta':
				$result = $this->step_user_meta();
				break;
			case 'taxonomy':
				$result = $this->step_taxonomy( $state['baseline'] );
				break;
			default:
				$result = $this->step_verify( $state['baseline'] );
		}

		$state['steps'][ $step ] = gmdate( 'c' );
		if ( 'verify' === $step ) {
			$state['status']       = 'complete';
			$state['version']      = self::VERSION;
			$state['completed_at'] = gmdate( 'c' );
		}
		$this->save_state( $state );
		$this->commit_transaction();

		$this->clean_caches( $state['baseline'] );
		$this->report['steps'][ $step ] = $result;

		return $state;
	}

	/**
	 * Creates or resumes the stored state. The baseline is captured once,
	 * before the first write, and reused when an interrupted run resumes.
	 *
	 * @param array $state Stored state.
	 * @return array
	 */
	private function start_state( $state ) {
		$resumable = isset( $state['status'] ) && in_array( $state['status'], array( 'running', 'paused', 'failed' ), true ) && ! empty( $state['baseline'] );

		if ( $resumable ) {
			$this->warning( 'resumed', 'Resuming an interrupted migration run.', array( 'done' => array_keys( (array) $state['steps'] ) ) );
			$state['status']  = 'running';
			$state['resumed'] = gmdate( 'c' );
		} else {
			$state = array(
				'id'         => self::ID,
				'version'    => 0,
				'status'     => 'running',
				'started_at' => gmdate( 'c' ),
				'steps'      => array(),
				'baseline'   => $this->baseline(),
			);
		}

		$this->save_state( $state );

		return $state;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Steps
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Copies the options. A new seed marker is also written when the site has
	 * former folder data but no former marker, so no example folders are added.
	 *
	 * @return array Step result.
	 */
	private function step_options() {
		$copied = array();

		foreach ( self::OPTIONS as $old => $new ) {
			$source = $this->option_row( $old );
			$target = $this->option_row( $new );

			if ( null === $source || null !== $target ) {
				continue;
			}

			$this->insert_option( $new, $source['option_value'], $source['autoload'] );
			$copied[] = $new;
		}

		if ( null === $this->option_row( self::SEED_OPTION ) && $this->has_legacy_data() ) {
			$this->insert_option( self::SEED_OPTION, 'migrated', 'yes' );
			$copied[] = self::SEED_OPTION . ' (marker)';
		}

		return array( 'copied' => $copied );
	}

	/**
	 * Copies the folder term meta rows that have no counterpart yet.
	 *
	 * @return array Step result.
	 */
	private function step_term_meta() {
		$this->assert_no_conflicts( $this->term_meta_conflicts() );

		$result = array();
		foreach ( self::TERM_META as $old => $new ) {
			$rows = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk copy, caches are cleaned afterwards.
				$this->db->prepare(
					"INSERT INTO {$this->db->termmeta} (term_id, meta_key, meta_value)
					SELECT o.term_id, %s, o.meta_value
					FROM {$this->db->termmeta} o
					INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_id = o.term_id AND tt.taxonomy IN (%s, %s)
					LEFT JOIN {$this->db->termmeta} n ON n.term_id = o.term_id AND n.meta_key = %s
					WHERE o.meta_key = %s AND n.meta_id IS NULL",
					$new,
					self::SOURCE_TAXONOMY,
					self::TARGET_TAXONOMY,
					$new,
					$old
				)
			);
			$this->assert_query( $rows, 'copy term meta ' . $old );
			$this->changes += (int) $rows;
			$result[ $new ] = (int) $rows;
		}

		return array( 'copied' => $result );
	}

	/**
	 * Copies the user meta rows that have no counterpart yet.
	 *
	 * @return array Step result.
	 */
	private function step_user_meta() {
		$this->assert_no_conflicts( $this->user_meta_conflicts() );

		$result = array();
		foreach ( self::USER_META as $old => $new ) {
			$rows = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk copy, caches are cleaned afterwards.
				$this->db->prepare(
					"INSERT INTO {$this->db->usermeta} (user_id, meta_key, meta_value)
					SELECT o.user_id, %s, o.meta_value
					FROM {$this->db->usermeta} o
					LEFT JOIN {$this->db->usermeta} n ON n.user_id = o.user_id AND n.meta_key = %s
					WHERE o.meta_key = %s AND n.umeta_id IS NULL",
					$new,
					$new,
					$old
				)
			);
			$this->assert_query( $rows, 'copy user meta ' . $old );
			$this->changes += (int) $rows;
			$result[ $new ] = (int) $rows;
		}

		return array( 'copied' => $result );
	}

	/**
	 * Renames the folder taxonomy in place: term IDs, term_taxonomy IDs,
	 * parents, counts and attachment relationships stay unchanged.
	 *
	 * @param array $baseline Baseline snapshot.
	 * @return array Step result.
	 */
	private function step_taxonomy( $baseline ) {
		$source = $this->tt_ids( self::SOURCE_TAXONOMY );
		$target = $this->tt_ids( self::TARGET_TAXONOMY );

		// Every folder must be one of the baseline folders, in either taxonomy.
		$current = array_merge( $source, $target );
		sort( $current );
		if ( $current !== $baseline['tt_ids'] ) {
			$this->stop(
				'data_changed',
				'The folders changed since the migration started.',
				array(
					'expected' => count( $baseline['tt_ids'] ),
					'found'    => count( $current ),
				)
			);
		}

		$rows = $this->db->query( $this->db->prepare( "UPDATE {$this->db->term_taxonomy} SET taxonomy = %s WHERE taxonomy = %s", self::TARGET_TAXONOMY, self::SOURCE_TAXONOMY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- In-place rename keeps all IDs.
		$this->assert_query( $rows, 'rename taxonomy' );
		$this->changes += (int) $rows;

		return array( 'renamed' => (int) $rows );
	}

	/**
	 * Verifies the migrated data against the baseline; a failure rolls the
	 * step back and leaves the state resumable.
	 *
	 * @param array $baseline Baseline snapshot.
	 * @return array Step result.
	 */
	private function step_verify( $baseline ) {
		$this->report['integrity'] = $this->verify_against( $baseline );
		if ( ! $this->report['integrity']['ok'] ) {
			$this->stop( 'integrity_failed', 'The migrated data does not match the baseline.', $this->report['integrity'] );
		}

		return array( 'checks' => count( $this->report['integrity']['checks'] ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Preflight and plan (read only)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Collects every blocking problem as an error. Reads only.
	 *
	 * @param array $state Stored state.
	 */
	private function preflight( $state ) {
		$this->check_legacy_inactive();

		// Read at runtime: the check is meant for the code version that ships with the migration.
		$loaded = class_exists( 'Migud_MM_Taxonomy', false ) && defined( 'Migud_MM_Taxonomy::NAME' ) ? (string) constant( 'Migud_MM_Taxonomy::NAME' ) : self::TARGET_TAXONOMY;
		if ( self::TARGET_TAXONOMY !== $loaded ) {
			$this->error( 'code_not_ready', 'The loaded plugin code still uses the former folder taxonomy; migrating now would hide the folders.', array( 'taxonomy' => $loaded ) );
		}

		$lock = $this->option_row( self::LEGACY_IMPORT_LOCK );
		if ( null !== $lock ) {
			if ( time() - (int) $lock['option_value'] <= self::LEGACY_IMPORT_LOCK_TTL ) {
				$this->error( 'import_running', 'A folder import of the former plugin is running.' );
			} else {
				$this->warning( 'stale_import_lock', 'A stale import lock of the former plugin exists (kept, not migrated).' );
			}
		}

		$held = $this->option_row( self::LOCK_OPTION );
		if ( null !== $held && ! $this->lock_is_stale( $held['option_value'] ) ) {
			$this->error( 'locked', 'Another migration run holds the lock.' );
		}

		$baseline = isset( $state['baseline'] ) ? $state['baseline'] : null;
		$foreign  = $this->count_foreign_target_terms( $baseline );
		if ( $foreign > 0 ) {
			$this->error( 'target_taxonomy_in_use', 'The new folder taxonomy already contains folders that were not migrated.', array( 'rows' => $foreign ) );
		}

		$this->collect_conflicts( 'term_meta_conflict', $this->term_meta_conflicts() );
		$this->collect_conflicts( 'user_meta_conflict', $this->user_meta_conflicts() );

		foreach ( self::OPTIONS as $old => $new ) {
			$source = $this->option_row( $old );
			$target = $this->option_row( $new );
			if ( null !== $source && null !== $target && $source['option_value'] !== $target['option_value'] && self::SEED_OPTION !== $new ) {
				$this->error( 'option_conflict', 'Both the former and the new option exist with different values.', array( 'option' => $new ) );
			}
		}

		if ( ! $this->transactions_supported() ) {
			$this->warning( 'no_transactions', 'Not all tables support transactions; an interrupted step is repaired by the next run.' );
		}
	}

	/**
	 * Adds an error when the former plugin is loaded or active.
	 */
	private function check_legacy_inactive() {
		if ( defined( 'FMM_VERSION' ) || $this->legacy_plugin_active() ) {
			$this->error( 'legacy_active', 'The former plugin Fena Media Manager is active. Deactivate it first.' );
		}
	}

	/**
	 * Whether the former plugin is active on this site or network.
	 *
	 * @return bool
	 */
	private function legacy_plugin_active() {
		$plugins = (array) maybe_unserialize( (string) $this->option_value( 'active_plugins' ) );

		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( $plugins as $plugin ) {
			if ( self::LEGACY_PLUGIN_FILE === basename( (string) $plugin ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rollback preconditions.
	 *
	 * @param array $baseline Baseline snapshot.
	 */
	private function check_restorable( $baseline ) {
		if ( $this->count_tt( self::SOURCE_TAXONOMY ) > 0 ) {
			$this->error( 'source_taxonomy_in_use', 'The former folder taxonomy already contains folders again.' );
		}

		$target = $this->tt_ids( self::TARGET_TAXONOMY );
		if ( $target !== $baseline['tt_ids'] ) {
			$this->error(
				'folders_changed',
				'Folders were added or removed after the migration; restoring would hide or lose them for the former plugin.',
				array(
					'added'   => array_values( array_diff( $target, $baseline['tt_ids'] ) ),
					'removed' => array_values( array_diff( $baseline['tt_ids'], $target ) ),
				)
			);
		}

		$divergence = $this->divergence();
		if ( $divergence ) {
			if ( $this->args['allow_divergence'] ) {
				$this->warning( 'diverged', 'Values under the new keys differ from the former keys; the former plugin will show the former values.', $divergence );
			} else {
				$this->error( 'diverged', 'Values under the new keys were changed after the migration. Restore with allow_divergence or keep the migration.', $divergence );
			}
		}
	}

	/**
	 * What a run would write (counts only).
	 *
	 * @return array
	 */
	private function plan() {
		$plan = array(
			'options'   => array(),
			'term_meta' => array(),
			'user_meta' => array(),
			'taxonomy'  => array(
				'from' => self::SOURCE_TAXONOMY,
				'to'   => self::TARGET_TAXONOMY,
				'rows' => $this->count_tt( self::SOURCE_TAXONOMY ),
			),
		);

		foreach ( self::OPTIONS as $old => $new ) {
			$source = $this->option_row( $old );
			$target = $this->option_row( $new );
			if ( null === $source ) {
				$action = 'missing';
			} elseif ( null === $target ) {
				$action = 'copy';
			} else {
				$action = $source['option_value'] === $target['option_value'] || self::SEED_OPTION === $new ? 'keep' : 'conflict';
			}
			$plan['options'][ $old . ' -> ' . $new ] = $action;
		}
		if ( null === $this->option_row( 'fmm_defaults_seeded' ) && null === $this->option_row( self::SEED_OPTION ) && $this->has_legacy_data() ) {
			$plan['options'][ self::SEED_OPTION ] = 'create marker (former folders exist)';
		}

		foreach ( self::TERM_META as $old => $new ) {
			$plan['term_meta'][ $old . ' -> ' . $new ] = $this->count_missing_term_meta( $old, $new );
		}
		foreach ( self::USER_META as $old => $new ) {
			$plan['user_meta'][ $old . ' -> ' . $new ] = $this->count_missing_user_meta( $old, $new );
		}

		return $plan;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Inventory, baseline and integrity (read only)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Read-only overview of all stored plugin data, for the report.
	 *
	 * @return array
	 */
	private function inventory() {
		$like_fmm = $this->db->esc_like( 'fmm_' ) . '%';
		$known    = array_merge( array_keys( self::OPTIONS ), array( self::LEGACY_IMPORT_LOCK, self::SOURCE_TAXONOMY . '_children' ) );

		$options = $this->db->get_col( $this->db->prepare( "SELECT option_name FROM {$this->db->options} WHERE option_name LIKE %s ORDER BY option_name", $like_fmm ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only inventory.

		$transients = (int) $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only inventory.
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->db->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$this->db->esc_like( '_transient_fmm_' ) . '%',
				$this->db->esc_like( '_transient_timeout_fmm_' ) . '%'
			)
		);

		$term_meta = $this->meta_key_counts( $this->db->termmeta, $like_fmm, $this->db->esc_like( 'migud_mm_' ) . '%' );
		$user_meta = $this->meta_key_counts( $this->db->usermeta, $like_fmm, $this->db->esc_like( 'migud_mm_' ) . '%' );
		$post_meta = $this->meta_key_counts( $this->db->postmeta, $like_fmm, $this->db->esc_like( 'migud_mm_' ) . '%' );

		$foreign_term_meta = (int) $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only inventory.
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->db->termmeta} m
				WHERE m.meta_key LIKE %s AND NOT EXISTS (
					SELECT 1 FROM {$this->db->term_taxonomy} tt WHERE tt.term_id = m.term_id AND tt.taxonomy IN (%s, %s)
				)",
				$like_fmm,
				self::SOURCE_TAXONOMY,
				self::TARGET_TAXONOMY
			)
		);

		$unknown = array(
			'options'   => array_values( array_diff( $options, $known ) ),
			'term_meta' => array_values( array_diff( array_keys( $term_meta ), array_keys( self::TERM_META ), array_values( self::TERM_META ) ) ),
			'user_meta' => array_values( array_diff( array_keys( $user_meta ), array_keys( self::USER_META ), array_values( self::USER_META ) ) ),
			'post_meta' => array_keys( $post_meta ),
		);

		$inventory = array(
			'taxonomy'          => array(
				self::SOURCE_TAXONOMY => $this->count_tt( self::SOURCE_TAXONOMY ),
				self::TARGET_TAXONOMY => $this->count_tt( self::TARGET_TAXONOMY ),
			),
			'relationships'     => $this->count_relationships(),
			'term_meta'         => $term_meta,
			'user_meta'         => $user_meta,
			'post_meta'         => $post_meta,
			'options'           => array(),
			'fmm_options_found' => $options,
			'fmm_transients'    => $transients,
			'foreign_term_meta' => $foreign_term_meta,
			'unknown_keys'      => $unknown,
			'legacy_active'     => defined( 'FMM_VERSION' ) || $this->legacy_plugin_active(),
		);

		foreach ( array_merge( array_keys( self::OPTIONS ), array_values( self::OPTIONS ) ) as $name ) {
			$row                           = $this->option_row( $name );
			$inventory['options'][ $name ] = null === $row ? null : hash( 'sha256', $row['option_value'] );
		}

		if ( is_multisite() ) {
			$inventory['site_meta'] = $this->db->get_col( $this->db->prepare( "SELECT meta_key FROM {$this->db->sitemeta} WHERE meta_key LIKE %s", $like_fmm ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only inventory.
		}

		foreach ( $unknown as $type => $keys ) {
			if ( $keys ) {
				$this->warning(
					'unknown_keys',
					'Further fmm_ keys found; they are kept and not migrated.',
					array(
						'type' => $type,
						'keys' => $keys,
					)
				);
			}
		}
		if ( $foreign_term_meta ) {
			$this->warning( 'foreign_term_meta', 'fmm_ term meta on terms outside the folder taxonomy; kept and not migrated.', array( 'rows' => $foreign_term_meta ) );
		}
		if ( $transients ) {
			$this->warning( 'transients', 'Temporary fmm_ import data found; it expires on its own and is not migrated.', array( 'rows' => $transients ) );
		}

		return $inventory;
	}

	/**
	 * Snapshot of everything that must survive unchanged.
	 *
	 * @return array
	 */
	private function baseline() {
		$baseline = array(
			'tt_ids'        => $this->tt_ids( self::SOURCE_TAXONOMY ),
			'terms'         => $this->folder_digest( self::SOURCE_TAXONOMY ),
			'relationships' => $this->relationship_digest( self::SOURCE_TAXONOMY ),
			'term_meta'     => array(),
			'user_meta'     => array(),
			'options'       => array(),
		);

		foreach ( self::TERM_META as $old => $new ) {
			$baseline['term_meta'][ $old ] = $this->meta_digest( $this->db->termmeta, 'term_id', $old );
		}
		foreach ( self::USER_META as $old => $new ) {
			$baseline['user_meta'][ $old ] = $this->meta_digest( $this->db->usermeta, 'user_id', $old );
		}
		foreach ( self::OPTIONS as $old => $new ) {
			$row                         = $this->option_row( $old );
			$baseline['options'][ $old ] = null === $row ? null : hash( 'sha256', $row['option_value'] );
		}

		return $baseline;
	}

	/**
	 * Integrity checks of a migrated site against the baseline. Reads only.
	 *
	 * @param array $baseline Baseline snapshot.
	 * @return array { ok: bool, checks: array<string, bool> }
	 */
	private function verify_against( $baseline ) {
		$checks = array();

		if ( empty( $baseline ) ) {
			return array(
				'ok'     => false,
				'checks' => array( 'baseline present' => false ),
			);
		}

		$checks['former taxonomy is empty']                  = 0 === $this->count_tt( self::SOURCE_TAXONOMY );
		$checks['term_taxonomy IDs unchanged']               = $this->tt_ids( self::TARGET_TAXONOMY ) === $baseline['tt_ids'];
		$checks['term IDs, names, slugs, parents unchanged'] = $this->folder_digest( self::TARGET_TAXONOMY ) === $baseline['terms'];
		$checks['attachment assignments unchanged']          = $this->relationship_digest( self::TARGET_TAXONOMY ) === $baseline['relationships'];

		foreach ( self::TERM_META as $old => $new ) {
			$checks[ 'term meta ' . $old . ' kept' ]     = $this->meta_digest( $this->db->termmeta, 'term_id', $old ) === $baseline['term_meta'][ $old ];
			$checks[ 'term meta ' . $new . ' complete' ] = 0 === $this->count_term_meta_mismatches( $old, $new );
		}
		foreach ( self::USER_META as $old => $new ) {
			$checks[ 'user meta ' . $old . ' kept' ]     = $this->meta_digest( $this->db->usermeta, 'user_id', $old ) === $baseline['user_meta'][ $old ];
			$checks[ 'user meta ' . $new . ' complete' ] = 0 === $this->count_user_meta_mismatches( $old, $new );
		}
		foreach ( self::OPTIONS as $old => $new ) {
			$source = $this->option_row( $old );
			$target = $this->option_row( $new );

			$checks[ 'option ' . $old . ' kept' ] = ( null === $source ? null : hash( 'sha256', $source['option_value'] ) ) === $baseline['options'][ $old ];
			if ( self::SEED_OPTION === $new ) {
				$checks[ 'option ' . $new . ' present when needed' ] = null !== $target || ! $this->has_legacy_data();
			} else {
				$checks[ 'option ' . $new . ' matches' ] = null === $source || ( null !== $target && $source['option_value'] === $target['option_value'] );
			}
		}

		return array(
			'ok'     => ! in_array( false, $checks, true ),
			'checks' => $checks,
		);
	}

	/**
	 * Integrity checks after a rollback. Reads only.
	 *
	 * @param array $baseline Baseline snapshot.
	 * @return array { ok: bool, checks: array<string, bool> }
	 */
	private function verify_restore( $baseline ) {
		$checks = array(
			'new taxonomy is empty'                     => 0 === $this->count_tt( self::TARGET_TAXONOMY ),
			'term_taxonomy IDs unchanged'               => $this->tt_ids( self::SOURCE_TAXONOMY ) === $baseline['tt_ids'],
			'term IDs, names, slugs, parents unchanged' => $this->folder_digest( self::SOURCE_TAXONOMY ) === $baseline['terms'],
			'attachment assignments unchanged'          => $this->relationship_digest( self::SOURCE_TAXONOMY ) === $baseline['relationships'],
		);

		foreach ( self::TERM_META as $old => $new ) {
			$checks[ 'term meta ' . $old . ' kept' ] = $this->meta_digest( $this->db->termmeta, 'term_id', $old ) === $baseline['term_meta'][ $old ];
		}
		foreach ( self::USER_META as $old => $new ) {
			$checks[ 'user meta ' . $old . ' kept' ] = $this->meta_digest( $this->db->usermeta, 'user_id', $old ) === $baseline['user_meta'][ $old ];
		}

		return array(
			'ok'     => ! in_array( false, $checks, true ),
			'checks' => $checks,
		);
	}

	/**
	 * Keys whose new value differs from the former value (after a migration).
	 *
	 * @return array
	 */
	private function divergence() {
		$diverged = array();

		foreach ( self::TERM_META as $old => $new ) {
			$n = $this->count_term_meta_mismatches( $old, $new, true );
			if ( $n ) {
				$diverged[ $new ] = $n;
			}
		}
		foreach ( self::USER_META as $old => $new ) {
			$n = $this->count_user_meta_mismatches( $old, $new, true );
			if ( $n ) {
				$diverged[ $new ] = $n;
			}
		}
		foreach ( self::OPTIONS as $old => $new ) {
			$source = $this->option_row( $old );
			$target = $this->option_row( $new );
			if ( self::SEED_OPTION !== $new && null !== $target && ( null === $source || $source['option_value'] !== $target['option_value'] ) ) {
				$diverged[ $new ] = 1;
			}
		}

		return $diverged;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Conflict queries (read only)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Folder term meta conflicts: different values under old and new key, or
	 * more than one row per term and key.
	 *
	 * @return array List of conflicts.
	 */
	private function term_meta_conflicts() {
		$conflicts = array();

		foreach ( self::TERM_META as $old => $new ) {
			$different = $this->db->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only conflict check.
				$this->db->prepare(
					"SELECT DISTINCT o.term_id FROM {$this->db->termmeta} o
					INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_id = o.term_id AND tt.taxonomy IN (%s, %s)
					INNER JOIN {$this->db->termmeta} n ON n.term_id = o.term_id AND n.meta_key = %s
					WHERE o.meta_key = %s AND NOT ( o.meta_value <=> n.meta_value )",
					self::SOURCE_TAXONOMY,
					self::TARGET_TAXONOMY,
					$new,
					$old
				)
			);
			if ( $different ) {
				$conflicts[] = array(
					'key'      => $new,
					'reason'   => 'different value',
					'term_ids' => array_map( 'intval', $different ),
				);
			}

			foreach ( array( $old, $new ) as $key ) {
				$duplicates = $this->db->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only conflict check.
					$this->db->prepare(
						"SELECT m.term_id FROM {$this->db->termmeta} m
						INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_id = m.term_id AND tt.taxonomy IN (%s, %s)
						WHERE m.meta_key = %s GROUP BY m.term_id HAVING COUNT(*) > 1",
						self::SOURCE_TAXONOMY,
						self::TARGET_TAXONOMY,
						$key
					)
				);
				if ( $duplicates ) {
					$conflicts[] = array(
						'key'      => $key,
						'reason'   => 'several rows per term',
						'term_ids' => array_map( 'intval', $duplicates ),
					);
				}
			}
		}

		return $conflicts;
	}

	/**
	 * User meta conflicts (see term_meta_conflicts()).
	 *
	 * @return array List of conflicts.
	 */
	private function user_meta_conflicts() {
		$conflicts = array();

		foreach ( self::USER_META as $old => $new ) {
			$different = $this->db->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only conflict check.
				$this->db->prepare(
					"SELECT DISTINCT o.user_id FROM {$this->db->usermeta} o
					INNER JOIN {$this->db->usermeta} n ON n.user_id = o.user_id AND n.meta_key = %s
					WHERE o.meta_key = %s AND NOT ( o.meta_value <=> n.meta_value )",
					$new,
					$old
				)
			);
			if ( $different ) {
				$conflicts[] = array(
					'key'      => $new,
					'reason'   => 'different value',
					'user_ids' => array_map( 'intval', $different ),
				);
			}

			foreach ( array( $old, $new ) as $key ) {
				$duplicates = $this->db->get_col( $this->db->prepare( "SELECT user_id FROM {$this->db->usermeta} WHERE meta_key = %s GROUP BY user_id HAVING COUNT(*) > 1", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only conflict check.
				if ( $duplicates ) {
					$conflicts[] = array(
						'key'      => $key,
						'reason'   => 'several rows per user',
						'user_ids' => array_map( 'intval', $duplicates ),
					);
				}
			}
		}

		return $conflicts;
	}

	/**
	 * Rows in the new taxonomy that are not migrated folders.
	 *
	 * @param array|null $baseline Baseline of a resumed run.
	 * @return int
	 */
	private function count_foreign_target_terms( $baseline ) {
		$target = $this->tt_ids( self::TARGET_TAXONOMY );
		if ( ! $target ) {
			return 0;
		}

		$own = isset( $baseline['tt_ids'] ) ? $baseline['tt_ids'] : array();

		return count( array_diff( $target, $own ) );
	}

	/**
	 * Folder term meta rows not yet copied.
	 *
	 * @param string $old_key Former key.
	 * @param string $new_key New key.
	 * @return int
	 */
	private function count_missing_term_meta( $old_key, $new_key ) {
		return (int) $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only plan.
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->db->termmeta} o
				INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_id = o.term_id AND tt.taxonomy IN (%s, %s)
				LEFT JOIN {$this->db->termmeta} n ON n.term_id = o.term_id AND n.meta_key = %s
				WHERE o.meta_key = %s AND n.meta_id IS NULL",
				self::SOURCE_TAXONOMY,
				self::TARGET_TAXONOMY,
				$new_key,
				$old_key
			)
		);
	}

	/**
	 * User meta rows not yet copied.
	 *
	 * @param string $old_key Former key.
	 * @param string $new_key New key.
	 * @return int
	 */
	private function count_missing_user_meta( $old_key, $new_key ) {
		return (int) $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only plan.
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->db->usermeta} o
				LEFT JOIN {$this->db->usermeta} n ON n.user_id = o.user_id AND n.meta_key = %s
				WHERE o.meta_key = %s AND n.umeta_id IS NULL",
				$new_key,
				$old_key
			)
		);
	}

	/**
	 * Folder term meta rows whose new counterpart is missing or different.
	 *
	 * @param string $old_key            Former key.
	 * @param string $new_key            New key.
	 * @param bool   $including_new  Also count new rows without former row.
	 * @return int
	 */
	private function count_term_meta_mismatches( $old_key, $new_key, $including_new = false ) {
		$missing = (int) $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only verification.
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->db->termmeta} o
				INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_id = o.term_id AND tt.taxonomy IN (%s, %s)
				LEFT JOIN {$this->db->termmeta} n ON n.term_id = o.term_id AND n.meta_key = %s
				WHERE o.meta_key = %s AND ( n.meta_id IS NULL OR NOT ( o.meta_value <=> n.meta_value ) )",
				self::SOURCE_TAXONOMY,
				self::TARGET_TAXONOMY,
				$new_key,
				$old_key
			)
		);

		if ( ! $including_new ) {
			return $missing;
		}

		return $missing + (int) $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only verification.
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->db->termmeta} n
				INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_id = n.term_id AND tt.taxonomy IN (%s, %s)
				LEFT JOIN {$this->db->termmeta} o ON o.term_id = n.term_id AND o.meta_key = %s
				WHERE n.meta_key = %s AND o.meta_id IS NULL",
				self::SOURCE_TAXONOMY,
				self::TARGET_TAXONOMY,
				$old_key,
				$new_key
			)
		);
	}

	/**
	 * User meta rows whose new counterpart is missing or different.
	 *
	 * @param string $old_key            Former key.
	 * @param string $new_key            New key.
	 * @param bool   $including_new  Also count new rows without former row.
	 * @return int
	 */
	private function count_user_meta_mismatches( $old_key, $new_key, $including_new = false ) {
		$missing = (int) $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only verification.
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->db->usermeta} o
				LEFT JOIN {$this->db->usermeta} n ON n.user_id = o.user_id AND n.meta_key = %s
				WHERE o.meta_key = %s AND ( n.umeta_id IS NULL OR NOT ( o.meta_value <=> n.meta_value ) )",
				$new_key,
				$old_key
			)
		);

		if ( ! $including_new ) {
			return $missing;
		}

		return $missing + (int) $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only verification.
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->db->usermeta} n
				LEFT JOIN {$this->db->usermeta} o ON o.user_id = n.user_id AND o.meta_key = %s
				WHERE n.meta_key = %s AND o.umeta_id IS NULL",
				$old_key,
				$new_key
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Data helpers (read only)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Sorted term_taxonomy IDs of a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	private function tt_ids( $taxonomy ) {
		$ids = array_map( 'intval', $this->db->get_col( $this->db->prepare( "SELECT term_taxonomy_id FROM {$this->db->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration reads the raw rows.
		sort( $ids );

		return $ids;
	}

	/**
	 * Number of term_taxonomy rows of a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return int
	 */
	private function count_tt( $taxonomy ) {
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->db->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration reads the raw rows.
	}

	/**
	 * Attachment assignments of both folder taxonomies.
	 *
	 * @return int
	 */
	private function count_relationships() {
		return (int) $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration reads the raw rows.
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->db->term_relationships} r
				INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id
				WHERE tt.taxonomy IN (%s, %s)",
				self::SOURCE_TAXONOMY,
				self::TARGET_TAXONOMY
			)
		);
	}

	/**
	 * Digest of the folders: IDs, names, slugs, parents, descriptions, counts.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	private function folder_digest( $taxonomy ) {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration reads the raw rows.
			$this->db->prepare(
				"SELECT tt.term_taxonomy_id, tt.term_id, tt.parent, tt.count, tt.description, t.name, t.slug, t.term_group
				FROM {$this->db->term_taxonomy} tt
				LEFT JOIN {$this->db->terms} t ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s ORDER BY tt.term_taxonomy_id",
				$taxonomy
			),
			ARRAY_N
		);

		return hash( 'sha256', (string) wp_json_encode( $rows ) ) . ':' . count( $rows );
	}

	/**
	 * Digest of the attachment assignments (object ID, term_taxonomy ID, order).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	private function relationship_digest( $taxonomy ) {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration reads the raw rows.
			$this->db->prepare(
				"SELECT r.object_id, r.term_taxonomy_id, r.term_order
				FROM {$this->db->term_relationships} r
				INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id
				WHERE tt.taxonomy = %s ORDER BY r.object_id, r.term_taxonomy_id",
				$taxonomy
			),
			ARRAY_N
		);

		return hash( 'sha256', (string) wp_json_encode( $rows ) ) . ':' . count( $rows );
	}

	/**
	 * Digest of all rows of one meta key.
	 *
	 * @param string $table  Meta table.
	 * @param string $column Object ID column.
	 * @param string $key    Meta key.
	 * @return string
	 */
	private function meta_digest( $table, $column, $key ) {
		$id_column = $this->db->usermeta === $table ? 'umeta_id' : 'meta_id';
		$rows      = $this->db->get_results( $this->db->prepare( 'SELECT %i, %i, meta_value FROM %i WHERE meta_key = %s ORDER BY %i', $id_column, $column, $table, $key, $id_column ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration reads the raw rows.

		return hash( 'sha256', (string) wp_json_encode( $rows ) ) . ':' . count( $rows );
	}

	/**
	 * Row counts per fmm_/migud_mm_ meta key.
	 *
	 * @param string $table    Meta table.
	 * @param string $like_old LIKE pattern of the former prefix.
	 * @param string $like_new LIKE pattern of the new prefix.
	 * @return array<string, int>
	 */
	private function meta_key_counts( $table, $like_old, $like_new ) {
		$rows   = $this->db->get_results( $this->db->prepare( 'SELECT meta_key, COUNT(*) AS n FROM %i WHERE meta_key LIKE %s OR meta_key LIKE %s GROUP BY meta_key ORDER BY meta_key', $table, $like_old, $like_new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only inventory.
		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row->meta_key ] = (int) $row->n;
		}

		return $counts;
	}

	/**
	 * Raw option row, bypassing the option cache.
	 *
	 * @param string $name Option name.
	 * @return array|null { option_value, autoload }
	 */
	private function option_row( $name ) {
		$row = $this->db->get_row( $this->db->prepare( "SELECT option_value, autoload FROM {$this->db->options} WHERE option_name = %s", $name ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration reads the raw rows.

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Raw option value, bypassing the option cache.
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	private function option_value( $name ) {
		$row = $this->option_row( $name );

		return null === $row ? null : $row['option_value'];
	}

	/**
	 * Whether the site has data of the former plugin.
	 *
	 * @return bool
	 */
	private function has_legacy_data() {
		if ( $this->count_tt( self::SOURCE_TAXONOMY ) > 0 || $this->count_tt( self::TARGET_TAXONOMY ) > 0 ) {
			return true;
		}

		foreach ( array_keys( self::OPTIONS ) as $old ) {
			if ( null !== $this->option_row( $old ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether all affected tables support transactions.
	 *
	 * @return bool
	 */
	private function transactions_supported() {
		$tables  = array( $this->db->options, $this->db->termmeta, $this->db->usermeta, $this->db->term_taxonomy );
		$engines = $this->db->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only environment check.
			$this->db->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s, %s, %s)',
				$tables[0],
				$tables[1],
				$tables[2],
				$tables[3]
			)
		);

		if ( count( $engines ) !== count( $tables ) ) {
			return false;
		}

		foreach ( $engines as $engine ) {
			if ( 'innodb' !== strtolower( (string) $engine ) ) {
				return false;
			}
		}

		return true;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Writes
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Inserts a new option; never replaces an existing one.
	 *
	 * @param string $name     Option name.
	 * @param string $value    Raw (serialized) value.
	 * @param string $autoload Autoload value.
	 */
	private function insert_option( $name, $value, $autoload ) {
		$rows = $this->db->query( $this->db->prepare( "INSERT IGNORE INTO {$this->db->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, $value, $autoload ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw copy without re-serialization; caches are cleaned afterwards.
		$this->assert_query( $rows, 'insert option ' . $name );
		if ( 1 !== (int) $rows ) {
			$this->stop( 'option_conflict', 'The new option appeared during the migration.', array( 'option' => $name ) );
		}
		++$this->changes;
	}

	/**
	 * Stores the state (inside the step transaction).
	 *
	 * @param array $state State.
	 */
	private function save_state( $state ) {
		$state['updated_at'] = gmdate( 'c' );

		$rows = $this->db->query( $this->db->prepare( "INSERT INTO {$this->db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", self::STATE_OPTION, maybe_serialize( $state ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Part of the step transaction.
		$this->assert_query( $rows, 'save state' );
		wp_cache_delete( self::STATE_OPTION, 'options' );
	}

	/**
	 * Drops cached copies of the migrated data.
	 *
	 * @param array $baseline Baseline snapshot.
	 */
	private function clean_caches( $baseline ) {
		foreach ( array_merge( array_keys( self::OPTIONS ), array_values( self::OPTIONS ), array( self::STATE_OPTION, self::LOCK_OPTION ) ) as $name ) {
			wp_cache_delete( $name, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$tt_ids = isset( $baseline['tt_ids'] ) ? $baseline['tt_ids'] : array();
		if ( $tt_ids ) {
			$term_ids = array_map( 'intval', $this->db->get_col( $this->db->prepare( "SELECT term_id FROM {$this->db->term_taxonomy} WHERE taxonomy IN (%s, %s)", self::SOURCE_TAXONOMY, self::TARGET_TAXONOMY ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache cleanup.
			foreach ( $term_ids as $term_id ) {
				wp_cache_delete( $term_id, 'term_meta' );
				wp_cache_delete( $term_id, 'terms' );
			}
			$objects = array_map( 'intval', $this->db->get_col( $this->db->prepare( "SELECT DISTINCT r.object_id FROM {$this->db->term_relationships} r INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id WHERE tt.taxonomy IN (%s, %s)", self::SOURCE_TAXONOMY, self::TARGET_TAXONOMY ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache cleanup.
			foreach ( $objects as $object_id ) {
				wp_cache_delete( $object_id, self::SOURCE_TAXONOMY . '_relationships' );
				wp_cache_delete( $object_id, self::TARGET_TAXONOMY . '_relationships' );
			}
			wp_cache_set_terms_last_changed();
		}

		$users = array_map( 'intval', $this->db->get_col( $this->db->prepare( "SELECT DISTINCT user_id FROM {$this->db->usermeta} WHERE meta_key IN (%s, %s)", 'fmm_folder_sort', 'migud_mm_folder_sort' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache cleanup.
		foreach ( $users as $user_id ) {
			wp_cache_delete( $user_id, 'user_meta' );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Lock
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Takes the lock atomically (INSERT IGNORE); a stale lock is taken over
	 * with a compare-and-swap on its old value.
	 *
	 * @return bool
	 */
	private function acquire_lock() {
		$value = $this->new_lock_value();

		$inserted = $this->db->query( $this->db->prepare( "INSERT IGNORE INTO {$this->db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock.
		if ( 1 === (int) $inserted ) {
			$this->lock_value = $value;
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			return true;
		}

		$held = $this->option_value( self::LOCK_OPTION );
		if ( null === $held || ! $this->lock_is_stale( $held ) ) {
			return false;
		}

		$won = $this->db->query( $this->db->prepare( "UPDATE {$this->db->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK_OPTION, $held ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock takeover.
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		if ( 1 !== (int) $won ) {
			return false;
		}

		$this->lock_value = $value;
		$this->warning( 'stale_lock_taken_over', 'A stale migration lock was taken over.' );

		return true;
	}

	/**
	 * Refreshes the lock time; stops when the lock was lost.
	 */
	private function heartbeat() {
		$value = $this->new_lock_value();
		$rows  = $this->db->query( $this->db->prepare( "UPDATE {$this->db->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK_OPTION, $this->lock_value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock heartbeat.

		if ( 1 !== (int) $rows ) {
			$this->lock_value = '';
			$this->stop( 'lock_lost', 'The migration lock was lost (another run took it over).' );
		}

		$this->lock_value = $value;
	}

	/**
	 * Releases the lock if this run still holds it.
	 */
	private function release_lock() {
		if ( '' === $this->lock_value ) {
			return;
		}

		$this->db->query( $this->db->prepare( "DELETE FROM {$this->db->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $this->lock_value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only this run's own lock.
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		$this->lock_value = '';
	}

	/**
	 * New lock value: random token and current time.
	 *
	 * @return string
	 */
	private function new_lock_value() {
		return wp_generate_password( 20, false ) . '|' . time();
	}

	/**
	 * Whether a lock value is older than the lock TTL.
	 *
	 * @param string $value Lock value.
	 * @return bool
	 */
	private function lock_is_stale( $value ) {
		$parts = explode( '|', (string) $value );
		$time  = isset( $parts[1] ) ? (int) $parts[1] : 0;

		return time() - $time > $this->args['lock_ttl'];
	}

	/*
	 * ---------------------------------------------------------------------
	 * Transactions and errors
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Starts a transaction.
	 */
	private function begin_transaction() {
		$this->db->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Step transaction.
	}

	/**
	 * Commits the transaction.
	 */
	private function commit_transaction() {
		$this->db->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Step transaction.
	}

	/**
	 * Rolls back an open transaction.
	 */
	private function rollback_transaction() {
		$this->db->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Step transaction.
	}

	/**
	 * Stops the run when a query failed.
	 *
	 * @param int|bool $result Query result.
	 * @param string   $what   Description.
	 */
	private function assert_query( $result, $what ) {
		if ( false === $result ) {
			$this->stop( 'db_error', 'Database error: ' . $what, array( 'error' => $this->db->last_error ) );
		}
	}

	/**
	 * Stops the run when conflicts exist.
	 *
	 * @param array $conflicts Conflicts.
	 */
	private function assert_no_conflicts( $conflicts ) {
		if ( $conflicts ) {
			$this->stop( 'conflict', 'Conflicting data appeared during the migration.', $conflicts );
		}
	}

	/**
	 * Adds one error per conflict.
	 *
	 * @param string $code      Error code.
	 * @param array  $conflicts Conflicts.
	 */
	private function collect_conflicts( $code, $conflicts ) {
		foreach ( $conflicts as $conflict ) {
			$this->error( $code, 'Former and new data contradict each other.', $conflict );
		}
	}

	/**
	 * Stops the run (caught in migrate()/restore()).
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param array  $data    Details.
	 * @throws RuntimeException Always.
	 */
	private function stop( $code, $message, $data = array() ) {
		$this->error( $code, $message, $data );

		throw new RuntimeException( $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal control flow, never printed.
	}

	/**
	 * Handles an exception: the open step was rolled back, the state stays
	 * resumable, the lock is released.
	 *
	 * @param Throwable $e Exception.
	 */
	private function fail( $e ) {
		if ( ! $this->report['errors'] ) {
			$this->error( 'exception', $e->getMessage() );
		}

		$state = self::state();
		if ( $state && '' !== $this->lock_value ) {
			$state['status']     = 'failed';
			$state['last_error'] = $this->report['errors'][0]['code'];
			try {
				$this->save_state( $state );
			} catch ( Throwable $ignored ) {
				unset( $ignored );
			}
		}

		$this->report['status'] = 'error';
		$this->release_lock();
	}

	/**
	 * Adds an error.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param array  $data    Details.
	 */
	private function error( $code, $message, $data = array() ) {
		$this->report['errors'][] = array(
			'code'    => $code,
			'message' => $message,
			'data'    => $data,
		);
	}

	/**
	 * Adds a warning.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param array  $data    Details.
	 */
	private function warning( $code, $message, $data = array() ) {
		$this->report['warnings'][] = array(
			'code'    => $code,
			'message' => $message,
			'data'    => $data,
		);
	}

	/**
	 * Completes the report.
	 *
	 * @return array
	 */
	private function finish() {
		if ( ! $this->args['dry_run'] && in_array( $this->report['status'], array( 'complete', 'paused', 'error', 'rolled_back' ), true ) ) {
			$this->report['after'] = $this->inventory_quiet();
		}

		$this->report['changes']     = $this->changes;
		$this->report['state']       = self::state();
		$this->report['finished_at'] = gmdate( 'c' );
		unset( $this->report['state']['baseline'] );

		return $this->report;
	}

	/**
	 * Inventory without adding warnings again.
	 *
	 * @return array
	 */
	private function inventory_quiet() {
		$warnings  = $this->report['warnings'];
		$inventory = $this->inventory();

		$this->report['warnings'] = $warnings;

		return $inventory;
	}
}
