<?php

use block_sharing_cart\app\item\entity;

defined('MOODLE_INTERNAL') || die();

class block_sharing_cart_generator extends testing_data_generator
{

    private $created_items_ids_history = [];

    public function create_sharing_cart_item($sharing_cart_item) {
        global $DB;

        $time = time();
        $item = [
            'user_id' => $sharing_cart_item['user_id'],
            'file_id' => null,
            'parent_item_id' => empty($sharing_cart_item['parent_item_name']) ? null : $this->created_items_ids_history[$sharing_cart_item['parent_item_name']],
            'old_instance_id' => 1,
            'type' => $sharing_cart_item['type'],
            'name' => $sharing_cart_item['name'],
            'status' => 1,
            'version' => 3,
            'timecreated' => $time,
            'timemodified' => $time
        ];

        $this->created_items_ids_history[$sharing_cart_item['name']] = $DB->insert_record('block_sharing_cart_items', $item,true);

    }
}