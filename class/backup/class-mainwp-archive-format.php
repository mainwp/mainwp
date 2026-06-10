<?php
/**
 * MainWP Archive Format enum.
 *
 * The archive container formats a child site can produce. The string
 * values are the wire format sent in the ext parameter of backup
 * requests and stored in backup settings, so they must not change.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Enum MainWP_Archive_Format
 */
enum MainWP_Archive_Format: string {

    case Zip    = 'zip';
    case TarGz  = 'tar.gz';
    case TarBz2 = 'tar.bz2';
    case Tar    = 'tar';
}
