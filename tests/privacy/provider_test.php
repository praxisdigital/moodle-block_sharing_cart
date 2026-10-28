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

namespace block_sharing_cart\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\user_preference;
use core_privacy\local\request\writer;

/**
 * Tests the Sharing Cart privacy provider's user-preference support.
 *
 * @package   block_sharing_cart
 * @copyright moxis
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The preference is declared in plugin metadata.
     *
     * @return void
     */
    public function test_get_metadata_declares_basket_notice_preference(): void {
        $collection = provider::get_metadata(new collection('block_sharing_cart'));
        $preferences = array_filter(
            $collection->get_collection(),
            static fn($type): bool => $type instanceof user_preference
        );

        self::assertCount(1, $preferences);
        $preference = reset($preferences);
        self::assertSame('block_sharing_cart_basket_notice_dismissed', $preference->get_name());
        self::assertSame(
            'privacy:metadata:preference:block_sharing_cart_basket_notice_dismissed',
            $preference->get_summary()
        );
    }

    /**
     * A saved dismissal preference is exported for its user.
     *
     * @return void
     */
    public function test_export_user_preferences_exports_basket_notice_dismissal(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        set_user_preference('block_sharing_cart_basket_notice_dismissed', '1', $user);

        provider::export_user_preferences($user->id);

        $writer = writer::with_context(\context_system::instance());
        $preferences = $writer->get_user_preferences('block_sharing_cart');
        self::assertSame('1', $preferences->block_sharing_cart_basket_notice_dismissed->value);
        $description = $preferences->block_sharing_cart_basket_notice_dismissed->description;
        self::assertSame(
            get_string('privacy:preference:block_sharing_cart_basket_notice_dismissed', 'block_sharing_cart'),
            $description
        );
        self::assertStringNotContainsString('[[', $description);
    }

    /**
     * No preference is exported when the user has not dismissed the notice.
     *
     * @return void
     */
    public function test_export_user_preferences_skips_unset_preference(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        provider::export_user_preferences($user->id);

        $writer = writer::with_context(\context_system::instance());
        self::assertFalse($writer->has_any_data());
    }
}
