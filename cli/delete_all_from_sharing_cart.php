<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * delete_all_from_sharing_cart.php
 *
 * @package    block_sharing_cart
 * @copyright  moxis
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');

global $CFG, $DB;
require_once($CFG->libdir . "/clilib.php");

// Supported options.
$long = ['execute' => false, 'help' => false];
$short = ['e' => 'execute', 'h' => 'help'];

// CLI options.
[$options, $unrecognized] = cli_get_params($long, $short);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help = <<<EOT
Deletes all items from sharing cart.

  This script deletes all items from sharing cart for all users.

Options:
  -h, --help    Print out this help.
  -e, --execute Run the deletion
                If not specified only check and report problems to STDERR.

Usage:
  - Only report:    \$ sudo -u www-data /usr/bin/php blocks/sharing_cart/cli/delete_all_from_sharing_cart.php
  - Report and fix: \$ sudo -u www-data /usr/bin/php blocks/sharing_cart/cli/delete_all_from_sharing_cart.php -e
EOT;

    cli_writeln($help);
    die;
}

$isdryrun = $options['execute'] === false;

cli_heading('Checking amount of items in sharing cart across all users');
$basefactory = \block_sharing_cart\app\factory::make();

$itemcount = $basefactory->item()->repository()->get_count();
cli_writeln("Found {$itemcount} items in the sharing cart.");

if ($isdryrun) {
    die();
}

if ($itemcount === 0) {
    cli_writeln("Nothing to delete. Aborting...");
    die();
}

cli_heading('Proceeding with deletion of all items in sharing cart across all users');

$faileddeletions = 0;

$records = $DB->get_recordset($basefactory->item()->repository()->get_table(), fields: 'id');
foreach ($records as $record) {
    try {
        cli_writeln("Deleting item with id {$record->id} from sharing cart...");
        $basefactory->item()->repository()->delete_by_id($record->id);
    } catch (\Exception $e) {
        cli_writeln(
            "Failed to delete item with id {$record->id} from sharing cart." .
            " Error: {$e->getMessage()} Trace: {$e->getTraceAsString()}"
        );
        $faileddeletions++;
    }
}
$records->close();

cli_writeln("Deletion process completed. {$faileddeletions} items couldn't be deleted.");
