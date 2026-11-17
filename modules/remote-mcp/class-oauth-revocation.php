<?php
/**
 * Remote MCP revocation triggers.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * User lifecycle and home-change triggers. Each one bumps a generation and revokes the grants it
 * covers through the store, which also ends their approvals and jobs. The panel's own
 * triggers (manual revoke, disconnect, toggle off) live in Remote_MCP_Admin.
 *
 * These run whether or not the connector is on, the kill switch included: forced off keeps grants
 * live for when it's lifted, so a password change in the meantime must still end them.
 */
final class OAuth_Revocation implements Hook_Provider {

    /**
     * When the home address last changed under live connections, for the panel's reconnect line.
     *
     * @var string
     */
    const OPTION_HOME_CHANGED = 'mainwp_remote_mcp_home_changed';

    /**
     * Services.
     *
     * @var Remote_MCP_Services
     */
    private $services;

    /**
     * Whether wp_insert_user() is creating a user right now: it fires wp_set_password for the
     * first password, which is no change.
     *
     * @var bool
     */
    private $inserting = false;

    /**
     * Users already bumped in this request, by reason. One change can fire two hooks
     * (wp_update_user() fires wp_set_password and profile_update; set_role() fires
     * remove_user_role and set_user_role).
     *
     * @var array
     */
    private $bumped = array();

    /**
     * Constructor.
     *
     * @param Remote_MCP_Services $services Services.
     */
    public function __construct( Remote_MCP_Services $services ) {
        $this->services = $services;
    }

    /**
     * Add the trigger hooks.
     *
     * @param Remote_MCP_Services $services Services.
     */
    public function register_hooks( Remote_MCP_Services $services ): void {
        add_action( 'deleted_user', array( $this, 'on_deleted_user' ) );
        add_action( 'remove_user_from_blog', array( $this, 'on_removed_from_blog' ) );
        add_action( 'revoked_super_admin', array( $this, 'on_revoked_super_admin' ) );
        add_filter( 'wp_pre_insert_user_data', array( $this, 'on_pre_insert_user' ), 10, 2 );
        add_action( 'user_register', array( $this, 'on_user_registered' ) );
        add_action( 'wp_set_password', array( $this, 'on_set_password' ), 10, 2 );
        add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
        add_action( 'set_user_role', array( $this, 'on_set_user_role' ), 10, 3 );
        add_action( 'remove_user_role', array( $this, 'on_remove_user_role' ), 10, 2 );
        add_action( 'update_option_home', array( $this, 'on_home_updated' ), 10, 2 );
        add_action( 'init', array( $this, 'retry_failed_invalidations' ) );
        add_action( 'init', array( $this, 'check_bound_home' ) );
    }

    /**
     * A deleted user.
     *
     * @param mixed $user_id User id.
     */
    public function on_deleted_user( $user_id ): void {
        $this->revoke_user( (int) $user_id, 'user_deleted' );
    }

    /**
     * A user removed from the blog; kept for parity with the REST API's own triggers.
     *
     * @param mixed $user_id User id.
     */
    public function on_removed_from_blog( $user_id ): void {
        $this->revoke_user( (int) $user_id, 'removed_from_blog' );
    }

    /**
     * A user who lost super admin.
     *
     * @param mixed $user_id User id.
     */
    public function on_revoked_super_admin( $user_id ): void {
        $this->revoke_user( (int) $user_id, 'super_admin_revoked' );
    }

    /**
     * Note whether the next wp_set_password is a new user's first password.
     *
     * @param mixed $data   User data.
     * @param mixed $update Whether an existing user is updated.
     * @return mixed
     */
    public function on_pre_insert_user( $data, $update = false ) {
        $this->inserting = ! $update;
        return $data;
    }

    /**
     * A new user is in place.
     */
    public function on_user_registered(): void {
        $this->inserting = false;
    }

    /**
     * A password set through wp_set_password() or wp_update_user().
     *
     * @param mixed $password Unused.
     * @param mixed $user_id  User id.
     */
    public function on_set_password( $password, $user_id = 0 ): void {
        unset( $password );
        if ( $this->inserting ) {
            return;
        }
        $this->revoke_user( (int) $user_id, 'password_changed' );
    }

    /**
     * A profile update that changed the stored password hash without wp_set_password().
     *
     * @param mixed $user_id       User id.
     * @param mixed $old_user_data User before the update.
     */
    public function on_profile_update( $user_id, $old_user_data = null ): void {
        $user = get_userdata( (int) $user_id );
        if ( ! $user || ! is_object( $old_user_data ) || ! isset( $old_user_data->user_pass ) ) {
            return;
        }
        if ( ! hash_equals( (string) $old_user_data->user_pass, (string) $user->user_pass ) ) {
            $this->revoke_user( (int) $user_id, 'password_changed' );
        }
    }

    /**
     * A role change: revoke when a role that granted manage_options is gone and nothing else grants it.
     *
     * @param mixed $user_id   User id.
     * @param mixed $role      New role.
     * @param mixed $old_roles Previous roles.
     */
    public function on_set_user_role( $user_id, $role = '', $old_roles = array() ): void {
        unset( $role );
        if ( $this->roles_grant_manage_options( (array) $old_roles ) ) {
            $this->revoke_if_cap_lost( (int) $user_id );
        }
    }

    /**
     * One role removed.
     *
     * @param mixed $user_id User id.
     * @param mixed $role    Removed role.
     */
    public function on_remove_user_role( $user_id, $role = '' ): void {
        if ( $this->roles_grant_manage_options( array( (string) $role ) ) ) {
            $this->revoke_if_cap_lost( (int) $user_id );
        }
    }

    /**
     * The home option changed.
     *
     * @param mixed $old_value Previous home.
     * @param mixed $new_value New home.
     */
    public function on_home_updated( $old_value, $new_value ): void {
        // No bound home means the connector was never turned on here, so no connection exists.
        if ( '' === (string) get_option( Remote_MCP_Settings::OPTION_BOUND_HOME, '' ) ) {
            return;
        }
        if ( Remote_MCP_Urls::canonical_base( (string) $old_value ) !== Remote_MCP_Urls::canonical_base( (string) $new_value ) ) {
            $this->home_changed( (string) $new_value );
        }
    }

    /**
     * Per-request compare of the canonical home with the one the connector was enabled under:
     * WP_HOME in wp-config.php changes home without any option hook. Only while the stored toggle
     * is on, so a site with the connector off pays no extra option read.
     */
    public function check_bound_home(): void {
        if ( ! Remote_MCP_Settings::enabled() ) {
            return;
        }
        $bound = (string) get_option( Remote_MCP_Settings::OPTION_BOUND_HOME, '' );
        if ( '' === $bound ) {
            return;
        }
        $current = (string) get_option( 'home' );
        if ( self::binding( $current ) !== $bound ) {
            $this->home_changed( $current );
        }
    }

    /**
     * The value a home is bound under. An HTTP home has no canonical base, so it is bound as
     * unservable: the compare stays on, revokes once for that home, and again on the move back.
     *
     * @param string $home Home value.
     * @return string
     */
    private static function binding( string $home ): string {
        return Remote_MCP_Urls::canonical_base( $home ) ?? 'unservable:' . $home;
    }

    /**
     * When the home last changed, or null.
     *
     * @return int|null
     */
    public static function home_changed_at(): ?int {
        $at = get_option( self::OPTION_HOME_CHANGED );
        return is_numeric( $at ) && (int) $at > 0 ? (int) $at : null;
    }

    /**
     * Clear the reconnect line once someone has seen it.
     */
    public static function clear_home_changed(): void {
        delete_option( self::OPTION_HOME_CHANGED );
    }

    /**
     * End every connection after a home change and rebind to the new home.
     *
     * @param string $new_home New home value.
     */
    private function home_changed( string $new_home ): void {
        $store = $this->store();
        if ( ! $store ) {
            return;
        }
        if ( null === $store->bump_site_generation( 'home_changed' ) ) {
            $this->set_pending( 'home', 0, true );
            return;
        }
        $this->set_pending( 'home', 0, false );
        update_option( self::OPTION_HOME_CHANGED, time(), false );
        // Rebinding stops the per-request compare from revoking connections made under the new home.
        update_option( Remote_MCP_Settings::OPTION_BOUND_HOME, self::binding( $new_home ), false );
    }

    /**
     * Revoke a user's connections once they no longer hold manage_options.
     *
     * @param int $user_id User id.
     */
    private function revoke_if_cap_lost( int $user_id ): void {
        $user = get_userdata( $user_id );
        if ( $user && ! user_can( $user, 'manage_options' ) ) {
            $this->revoke_user( $user_id, 'cap_lost' );
        }
    }

    /**
     * Whether any of these roles carries manage_options.
     *
     * @param array $roles Role names.
     * @return bool
     */
    private function roles_grant_manage_options( array $roles ): bool {
        foreach ( $roles as $name ) {
            $role = get_role( (string) $name );
            if ( $role && $role->has_cap( 'manage_options' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * User trigger: bump the user's generation, then revoke their grants.
     *
     * @param int    $user_id User id.
     * @param string $reason  Revoke reason.
     */
    private function revoke_user( int $user_id, string $reason ): void {
        $store = $this->store();
        if ( $user_id <= 0 || ! $store || isset( $this->bumped[ $reason ][ $user_id ] ) ) {
            return;
        }
        if ( null === $store->bump_user_generation( $user_id, $reason ) ) {
            $this->set_pending( $reason, $user_id, true );
            return;
        }
        $this->bumped[ $reason ][ $user_id ] = true;
        $this->set_pending( $reason, $user_id, false );
    }

    /**
     * Retry failed lifecycle writes even when their original hooks will not fire again.
     */
    public function retry_failed_invalidations(): void {
        foreach ( self::pending_invalidations() as $reason => $users ) {
            if ( 'home' === $reason ) {
                $this->home_changed( (string) get_option( 'home' ) );
                continue;
            }
            foreach ( array_keys( (array) $users ) as $user_id ) {
                $this->revoke_user( (int) $user_id, (string) $reason );
            }
        }
    }

    /**
     * Pending retries share the notice option so uninstall removes them too.
     *
     * @return array
     */
    public static function pending_invalidations(): array {
        $state = get_option( Remote_MCP_Notices::OPTION_STATE, array() );
        return is_array( $state ) && is_array( $state['invalidations'] ?? null ) ? $state['invalidations'] : array();
    }

    /**
     * Keep a failed trigger until both the generation and revocation persist.
     *
     * @param string $reason  Trigger reason.
     * @param int    $user_id User id, or zero for a home change.
     * @param bool   $pending Whether a retry is needed.
     */
    private function set_pending( string $reason, int $user_id, bool $pending ): void {
        $state   = get_option( Remote_MCP_Notices::OPTION_STATE, array() );
        $state   = is_array( $state ) ? $state : array();
        $retries = self::pending_invalidations();
        if ( $pending ) {
            $retries[ $reason ][ $user_id ] = true;
        } else {
            unset( $retries[ $reason ][ $user_id ] );
            if ( empty( $retries[ $reason ] ) ) {
                unset( $retries[ $reason ] );
            }
        }
        if ( $retries ) {
            $state['invalidations'] = $retries;
        } else {
            unset( $state['invalidations'] );
        }
        update_option( Remote_MCP_Notices::OPTION_STATE, $state, false );
    }

    /**
     * Installed storage remains available for invalidation while the home is HTTP.
     *
     * @return OAuth_Store|null
     */
    private function store(): ?OAuth_Store {
        return $this->services->installed_store();
    }
}
