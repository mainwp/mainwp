<?php
/**
 * Remote MCP module job tick.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the `mainwp_remote_mcp_job_tick` event. One event per job runs
 * one entry, and the runner schedules the next.
 *
 * The hook is added on every boot whatever is_active() says: a tick has to run while the kill
 * switch is on, so the job it finds is cancelled rather than left running.
 */
class MCP_Job_Cron implements Hook_Provider {

    /**
     * Cron hook; its one argument is the job id.
     *
     * @var string
     */
    const HOOK = 'mainwp_remote_mcp_job_tick';

    /**
     * Module services.
     *
     * @var Remote_MCP_Services
     */
    private $services;

    /**
     * Constructor.
     *
     * @param Remote_MCP_Services $services Module services.
     */
    public function __construct( Remote_MCP_Services $services ) {
        $this->services = $services;
    }

    /**
     * Add the tick handler.
     *
     * @param Remote_MCP_Services $services Module services.
     */
    public function register_hooks( Remote_MCP_Services $services ): void {
        $this->services = $services;
        add_action( self::HOOK, array( $this, 'run' ), 10, 1 );
    }

    /**
     * Run one tick.
     *
     * @param mixed $job_id Job id from the event arguments.
     */
    public function run( $job_id ): void {
        if ( ! is_numeric( $job_id ) || (int) $job_id <= 0 ) {
            return;
        }
        // mainwp.php skips MainWP_System on a SecuPress scan request, which anyone can fake on
        // wp-cron.php too. The activity log never loads then, so leave the tick for the next normal
        // cron run; WP-Cron already removed this event.
        if ( null === \MainWP\Dashboard\MainWP_System::instance() ) {
            self::schedule( (int) $job_id );
            return;
        }
        $this->services->get( 'job_runner' )->tick( (int) $job_id );
    }

    /**
     * Schedule a job's next tick.
     *
     * @param int $job_id Job id.
     * @return bool Whether a tick is scheduled.
     */
    public static function schedule( int $job_id ): bool {
        if ( false !== wp_next_scheduled( self::HOOK, array( $job_id ) ) ) {
            return true;
        }
        return true === wp_schedule_single_event( time(), self::HOOK, array( $job_id ) );
    }

    /**
     * Remove a job's pending tick.
     *
     * @param int $job_id Job id.
     */
    public static function unschedule( int $job_id ): void {
        wp_clear_scheduled_hook( self::HOOK, array( $job_id ) );
    }
}
