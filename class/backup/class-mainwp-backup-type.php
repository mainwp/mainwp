<?php
/**
 * MainWP Backup Type enum.
 *
 * The kinds of backup a child site can produce. The string values are
 * the wire format exchanged with child sites and stored in task rows,
 * so they must not change.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Enum MainWP_Backup_Type
 */
enum MainWP_Backup_Type: string {

    case Full        = 'full';
    case Database    = 'db';
    case Incremental = 'incremental';
}
