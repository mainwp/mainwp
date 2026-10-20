<?php
/**
 * MainWP Backup Fleet Health
 *
 * Fleet-wide backup-health summary: how many child sites have a recent
 * verified offsite backup versus those aging, stale or with no backup at
 * all. Rendered as a dashboard widget (four count cards) and as a compact
 * count-bar above the Manage Sites list. Both surfaces read the same
 * request-scoped health map and deep-link into the Manage Sites status
 * filter.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Fleet_Health
 */
class MainWP_Backup_Fleet_Health { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method get_class_name()
     *
     * @return string __CLASS__ Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Whether the backup-health surfaces should render at all. Gated on
     * native backups being enabled and the user holding execute_backups,
     * the same gate the backup column and snapshots page use.
     *
     * @return bool
     */
    public static function is_visible(): bool {
        return MainWP_Backup_Settings::is_native_backups_enabled()
            && \mainwp_current_user_can( 'dashboard', 'execute_backups' );
    }

    /**
     * Bucket display metadata, in display order. Single source so the cards
     * and the compact bar never drift. Each entry: color, Fomantic icon,
     * user label, description and the Manage Sites status-filter slug.
     *
     * @return array<int, array<string, string>>
     */
    private static function buckets(): array {
        return array(
            array(
                'key'         => MainWP_Backup_Snapshots::HEALTH_BUCKET_PROTECTED,
                'color'       => 'green',
                'icon'        => 'shield alternate',
                'label'       => esc_html__( 'Protected', 'mainwp' ),
                'description' => esc_html__( 'Recent verified offsite backup.', 'mainwp' ),
            ),
            array(
                'key'         => MainWP_Backup_Snapshots::HEALTH_BUCKET_AT_RISK,
                'color'       => 'orange',
                'icon'        => 'exclamation triangle',
                'label'       => esc_html__( 'At Risk', 'mainwp' ),
                'description' => esc_html__( 'Backup aging or offsite not verified.', 'mainwp' ),
            ),
            array(
                'key'         => MainWP_Backup_Snapshots::HEALTH_BUCKET_CRITICAL,
                'color'       => 'red',
                'icon'        => 'times circle',
                'label'       => esc_html__( 'Critical', 'mainwp' ),
                'description' => esc_html__( 'No recent backup in over 7 days.', 'mainwp' ),
            ),
            array(
                'key'         => MainWP_Backup_Snapshots::HEALTH_BUCKET_UNKNOWN,
                'color'       => 'grey',
                'icon'        => 'question circle',
                'label'       => esc_html__( 'No Backup', 'mainwp' ),
                'description' => esc_html__( 'No completed snapshot.', 'mainwp' ),
            ),
        );
    }

    /**
     * Count sites per bucket from the request-scoped health map.
     *
     * @return array<string, int> Count keyed by bucket key.
     */
    private static function count_buckets(): array {
        $counts = array(
            MainWP_Backup_Snapshots::HEALTH_BUCKET_PROTECTED => 0,
            MainWP_Backup_Snapshots::HEALTH_BUCKET_AT_RISK => 0,
            MainWP_Backup_Snapshots::HEALTH_BUCKET_CRITICAL => 0,
            MainWP_Backup_Snapshots::HEALTH_BUCKET_UNKNOWN => 0,
        );

        $map = MainWP_Backup_Snapshots::instance()->get_health_map_for_current_user();
        foreach ( $map as $bucket ) {
            if ( isset( $counts[ $bucket ] ) ) {
                ++$counts[ $bucket ];
            }
        }
        return $counts;
    }

    /**
     * Relative Manage Sites URL filtered to a backup-health bucket.
     *
     * @param string $bucket_key Bucket key.
     *
     * @return string
     */
    private static function filter_url( string $bucket_key ): string {
        return add_query_arg(
            array(
                'page'   => 'managesites',
                'backup_health' => $bucket_key,
            ),
            'admin.php'
        );
    }

    /**
     * Method render()
     *
     * Dashboard widget entry point.
     *
     * @return void
     */
    public static function render() {
        static::render_fleet_health();
    }

    /**
     * Render the four-card fleet backup-health summary widget.
     *
     * @return void
     */
    public static function render_fleet_health() {
        if ( ! static::is_visible() ) {
            return;
        }

        $counts = static::count_buckets();
        ?>
        <div class="mainwp-widget-header">
            <div class="ui grid">
                <div class="twelve wide column">
                    <h2 class="ui header handle-drag">
                        <?php esc_html_e( 'Backup Health', 'mainwp' ); ?>
                        <div class="sub header"><?php esc_html_e( 'Fleet-wide native backup protection.', 'mainwp' ); ?></div>
                    </h2>
                </div>
            </div>
        </div>
        <div class="mainwp-scrolly-overflow" id="mainwp-backup-health-details">
            <div class="ui two small cards" id="mainwp-backup-health-counts">
                <?php foreach ( static::buckets() as $bucket ) : ?>
                    <a class="ui card" href="<?php echo esc_url( static::filter_url( $bucket['key'] ) ); ?>" data-tooltip="<?php echo esc_attr( $bucket['description'] ); ?>" data-inverted="" data-position="bottom left">
                        <div class="content">
                            <div class="header">
                                <span class="ui large text"><i class="<?php echo esc_attr( $bucket['icon'] ); ?> <?php echo esc_attr( $bucket['color'] ); ?> icon"></i> <?php echo esc_html( MainWP_Utility::short_number_format( $counts[ $bucket['key'] ] ) ); ?></span>
                            </div>
                            <div class="description"><strong><?php echo esc_html( $bucket['label'] ); ?></strong><span class="screen-reader-text"> <?php echo esc_html( $bucket['description'] ); ?></span></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="ui two column grid mainwp-widget-footer">
            <div class="left aligned middle aligned column">
                <a href="<?php echo esc_url( 'admin.php?page=ManageBackupsSnapshots' ); ?>" class="ui mini basic button"><?php esc_html_e( 'Snapshots & Restore', 'mainwp' ); ?></a>
            </div>
            <div class="right aligned middle aligned column">
                <a href="<?php echo esc_url( 'admin.php?page=ManageBackups' ); ?>" class="ui mini basic button"><?php esc_html_e( 'Manage Backups', 'mainwp' ); ?></a>
            </div>
        </div>
        <?php
    }

    /**
     * Render the compact backup-health count-bar shown above the Manage
     * Sites list. Each label links to its status filter.
     *
     * @return void
     */
    public static function render_compact_bar() {
        if ( ! static::is_visible() ) {
            return;
        }

        $counts = static::count_buckets();
        ?>
        <div class="ui segment mainwp-backup-health-bar">
            <span class="ui small text"><strong><?php esc_html_e( 'Backup Health:', 'mainwp' ); ?></strong></span>
            <?php foreach ( static::buckets() as $bucket ) : ?>
                <a href="<?php echo esc_url( static::filter_url( $bucket['key'] ) ); ?>" class="ui <?php echo esc_attr( $bucket['color'] ); ?> label"><?php echo esc_html( $bucket['label'] ); ?><div class="detail"><?php echo esc_html( MainWP_Utility::short_number_format( $counts[ $bucket['key'] ] ) ); ?></div></a>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
