<?php

class behat_block_sharing_cart_generator extends behat_generator_base
{
    protected function get_creatable_entities(): array {
        return [
            'sharing_cart_items' => [
                'singular' => 'sharing_cart_item',
                'datagenerator' => 'sharing_cart_item',
                'required' => ['user_id','parent_item_name','type','name']
            ],
        ];
    }
}