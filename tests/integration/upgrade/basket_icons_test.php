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

namespace block_sharing_cart\integration\upgrade;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

require_once(__DIR__ . '/../../../db/upgrade.php');

/**
 * Tests the Sharing Cart basket icon setting migration.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_block_sharing_cart_upgrade')]
final class basket_icons_test extends \advanced_testcase {
    /**
     * Reset plugin configuration between migration scenarios.
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        require_once($CFG->dirroot . '/lib/upgradelib.php');
        $this->resetAfterTest();
    }

    /**
     * Run the migration from the immediately preceding plugin version.
     */
    private function run_upgrade(): void {
        set_config('version', 2026110602, 'block_sharing_cart');
        \xmldb_block_sharing_cart_upgrade(2026110602);
    }

    /**
     * Previously enabled icons are disabled and trigger the explanatory notice.
     */
    public function test_enabled_icons_are_disabled_and_notice_is_marked_pending(): void {
        set_config('show_sharing_cart_basket', 1, 'block_sharing_cart');
        unset_config('basket_icons_notice_pending', 'block_sharing_cart');

        $this->run_upgrade();

        self::assertEquals(0, get_config('block_sharing_cart', 'show_sharing_cart_basket'));
        self::assertEquals(1, get_config('block_sharing_cart', 'basket_icons_notice_pending'));
    }

    /**
     * An explicitly disabled setting stays disabled without showing a migration notice.
     */
    public function test_disabled_icons_remain_disabled_without_notice(): void {
        set_config('show_sharing_cart_basket', 0, 'block_sharing_cart');
        unset_config('basket_icons_notice_pending', 'block_sharing_cart');

        $this->run_upgrade();

        self::assertEquals(0, get_config('block_sharing_cart', 'show_sharing_cart_basket'));
        self::assertFalse(get_config('block_sharing_cart', 'basket_icons_notice_pending'));
    }

    /**
     * An unset setting uses the old on-by-default behavior and is migrated accordingly.
     */
    public function test_unset_setting_is_treated_as_previously_enabled(): void {
        unset_config('show_sharing_cart_basket', 'block_sharing_cart');
        unset_config('basket_icons_notice_pending', 'block_sharing_cart');

        $this->run_upgrade();

        self::assertEquals(0, get_config('block_sharing_cart', 'show_sharing_cart_basket'));
        self::assertEquals(1, get_config('block_sharing_cart', 'basket_icons_notice_pending'));
    }
}
