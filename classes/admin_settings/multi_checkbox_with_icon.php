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

namespace block_sharing_cart\admin_settings;

// @codeCoverageIgnoreEnd


/**
 * Class admin_settings\multi_checkbox_with_icon for the Sharing Cart block.
 *
 * @package   block_sharing_cart
 * @copyright 2021 Praxis <moodle@praxis.dk>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class multi_checkbox_with_icon extends \admin_setting_configmulticheckbox {
    protected array $icons;

    public function __construct(
        string $name,
        string $visiblename,
        string $description,
        ?array $defaultsetting,
        array $choices,
        array $icons
    ) {
        $this->icons = $icons;
        parent::__construct($name, $visiblename, $description, $defaultsetting, $choices);
    }

    public function output_html($data, $query = ''): string {
        if (empty($this->choices) || !$this->load_choices()) {
            return '';
        }
        $default = $this->get_defaultsetting();
        if (is_null($default)) {
            $default = [];
        }
        if (is_null($data)) {
            $data = [];
        }
        $options = [];
        $defaults = [];
        foreach ($this->choices as $key => $description) {
            if (!empty($data[$key])) {
                $checked = 'checked="checked"';
            } else {
                $checked = '';
            }
            if (!empty($default[$key])) {
                $defaults[] = $description;
            }

            $options[] = '<input type="checkbox" id="' . $this->get_id(
                ) . '_' . $key . '" name="' . $this->get_full_name(
                ) . '[' . $key . ']" value="1" ' . $checked . ' class="mr-1"/>' . '<label for="' . $this->get_id(
                ) . '_' . $key . '">' . $this->icons[$key] . highlightfast($query, $description) . '</label>';
        }

        if (is_null($default)) {
            $defaultinfo = null;
        } else {
            if (!empty($defaults)) {
                $defaultinfo = implode(', ', $defaults);
            } else {
                $defaultinfo = get_string('none');
            }
        }

        $return = '<div class="form-multicheckbox">';
        $return .= '<input type="hidden" name="' . $this->get_full_name(
            ) . '[xxxxx]" value="1" />'; // something must be submitted even if nothing selected
        if ($options) {
            $return .= '<ul>';
            foreach ($options as $option) {
                $return .= '<li>' . $option . '</li>';
            }
            $return .= '</ul>';
        }
        $return .= '</div>';

        return format_admin_setting(
            $this,
            $this->visiblename,
            $return,
            $this->description,
            false,
            '',
            $defaultinfo,
            $query
        );
    }
}
