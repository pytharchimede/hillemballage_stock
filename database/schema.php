<?php
// Schéma cible unique. Ajouter/modifier ici pour que migrate.php synchronise.
// Format: table => [ 'columns' => [col => definition], 'indexes' => [...]]
// definition: type, nullable(bool), default(mixed), extra(string), length(int), unsigned(bool)
// Types supportés: int, bigint, varchar, text, decimal, datetime, date, tinyint
return [
    'depots' => [
        'columns' => [
            'id' => ['type' => 'int', 'unsigned' => true, 'extra' => 'AUTO_INCREMENT'],
            'name' => ['type' => 'varchar', 'length' => 150],
            'code' => ['type' => 'varchar', 'length' => 50],
            'is_main' => ['type' => 'tinyint', 'unsigned' => true, 'default' => 0],
            'manager_user_id' => ['type' => 'int', 'unsigned' => true, 'nullable' => true],
            'manager_name' => ['type' => 'varchar', 'length' => 120, 'nullable' => true],
            'phone' => ['type' => 'varchar', 'length' => 40, 'nullable' => true],
            'address' => ['type' => 'varchar', 'length' => 255, 'nullable' => true],
            'latitude' => ['type' => 'decimal', 'length' => '10,7', 'nullable' => true],
            'longitude' => ['type' => 'decimal', 'length' => '10,7', 'nullable' => true],
            'created_at' => ['type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'datetime', 'nullable' => true],
        ],
        'indexes' => ['PRIMARY KEY (`id`)', 'UNIQUE KEY `depots_code_unique` (`code`)', 'KEY `depots_manager_fk` (`manager_user_id`)']
    ],
    'users' => [
        'columns' => [
            'id' => ['type' => 'int', 'unsigned' => true, 'extra' => 'AUTO_INCREMENT'],
            'name' => ['type' => 'varchar', 'length' => 120],
            'email' => ['type' => 'varchar', 'length' => 150],
            'password_hash' => ['type' => 'varchar', 'length' => 255],
            'role' => ['type' => 'varchar', 'length' => 40], // admin, gerant, livreur
            'depot_id' => ['type' => 'int', 'unsigned' => true, 'nullable' => true],
            'api_token' => ['type' => 'varchar', 'length' => 64, 'nullable' => true],
            'permissions' => ['type' => 'text', 'nullable' => true],
            'created_at' => ['type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'datetime', 'nullable' => true],
        ],
        'indexes' => ['PRIMARY KEY (`id`)', 'UNIQUE KEY `users_email_unique` (`email`)', 'KEY `users_depot_fk` (`depot_id`)']
    ],
    'products' => [
        'columns' => [
            'id' => ['type' => 'int', 'unsigned' => true, 'extra' => 'AUTO_INCREMENT'],
            'name' => ['type' => 'varchar', 'length' => 150],
            'sku' => ['type' => 'varchar', 'length' => 60],
            'unit_price' => ['type' => 'int'], // prix unitaire (entier pour simplifier)
            'description' => ['type' => 'text', 'nullable' => true],
            'image_path' => ['type' => 'varchar', 'length' => 255, 'nullable' => true],
            'active' => ['type' => 'tinyint', 'unsigned' => true, 'default' => 1],
            'created_at' => ['type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'datetime', 'nullable' => true],
        ],
        'indexes' => ['PRIMARY KEY (`id`)', 'UNIQUE KEY `products_sku_unique` (`sku`)']
    ],
    'stocks' => [ // stock courant par depot & produit
        'columns' => [
            'id' => ['type' => 'int', 'unsigned' => true, 'extra' => 'AUTO_INCREMENT'],
            'depot_id' => ['type' => 'int', 'unsigned' => true],
            'product_id' => ['type' => 'int', 'unsigned' => true],
            'quantity' => ['type' => 'int', 'default' => 0],
            'created_at' => ['type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'datetime', 'nullable' => true],
        ],
        'indexes' => ['PRIMARY KEY (`id`)', 'UNIQUE KEY `stocks_unique` (`depot_id`,`product_id`)', 'KEY `stocks_product_fk` (`product_id`)']
    ],
    'clients' => [
        'columns' => [
            'id' => ['type' => 'int', 'unsigned' => true, 'extra' => 'AUTO_INCREMENT'],
            'name' => ['type' => 'varchar', 'length' => 150],
            'phone' => ['type' => 'varchar', 'length' => 40, 'nullable' => true],
            'address' => ['type' => 'varchar', 'length' => 255, 'nullable' => true],
            'latitude' => ['type' => 'decimal', 'length' => '10,7', 'nullable' => true],
            'longitude' => ['type' => 'decimal', 'length' => '10,7', 'nullable' => true],
            'photo_path' => ['type' => 'varchar', 'length' => 255, 'nullable' => true],
            'created_at' => ['type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'datetime', 'nullable' => true],
        ],
        'indexes' => ['PRIMARY KEY (`id`)', 'KEY `clients_name_idx` (`name`)']
    ],
    'sales' => [ // vente à un client par un livreur
        'columns' => [
            'id' => ['type' => 'int', 'unsigned' => true, 'extra' => 'AUTO_INCREMENT'],
            'client_id' => ['type' => 'int', 'unsigned' => true],
            'user_id' => ['type' => 'int', 'unsigned' => true], // livreur
            'depot_id' => ['type' => 'int', 'unsigned' => true],
            'total_amount' => ['type' => 'int'],
            'amount_paid' => ['type' => 'int', 'default' => 0],
            'status' => ['type' => 'varchar', 'length' => 30, 'default' => 'pending'], // pending, paid, partial
            'sold_at' => ['type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'created_at' => ['type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'datetime', 'nullable' => true],
        ],
        'indexes' => ['PRIMARY KEY (`id`)', 'KEY `sales_client_fk` (`client_id`)', 'KEY `sales_user_fk` (`user_id`)', 'KEY `sales_depot_fk` (`depot_id`)']
    ],
    'sale_items' => [ // lignes produits
        'columns' => [
            'id' => ['type' => 'int', 'unsigned' => true, 'extra' => 'AUTO_INCREMENT'],
            'sale_id' => ['type' => 'int', 'unsigned' => true],
            'product_id' => ['type' => 'int', 'unsigned' => true],
            'quantity' => ['type' => 'int'],
            'unit_price' => ['type' => 'int'],
            'subtotal' => ['type' => 'int'],
            'created_at' => ['type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ],
        'indexes' => ['PRIMARY KEY (`id`)', 'KEY `sale_items_sale_fk` (`sale_id`)', 'KEY `sale_items_product_fk` (`product_id`)']
    ],
    'payments' => [
        'columns' => [
            'id' => ['type' => 'int', 'unsigned' => true, 'extra' => 'AUTO_INCREMENT'],
            'sale_id' => ['type' => 'int', 'unsigned' => true],
            'amount' => ['type' => 'int'],
            'paid_at' => ['type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'created_at' => ['type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ],
        'indexes' => ['PRIMARY KEY (`id`)', 'KEY `payments_sale_fk` (`sale_id`)']
    ],
    'stock_movements' => [ // sorties / retours / transferts
        'columns' => [
            'id' => ['type' => 'int', 'unsigned' => true, 'extra' => 'AUTO_INCREMENT'],
            'depot_id' => ['type' => 'int', 'unsigned' => true],
            'product_id' => ['type' => 'int', 'unsigned' => true],
            'type' => ['type' => 'varchar', 'length' => 40], // in, out, return, transfer
            'quantity' => ['type' => 'int'],
            'related_sale_id' => ['type' => 'int', 'unsigned' => true, 'nullable' => true],
            'note' => ['type' => 'varchar', 'length' => 255, 'nullable' => true],
            'moved_at' => ['type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'created_at' => ['type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ],
        'indexes' => ['PRIMARY KEY (`id`)', 'KEY `stock_movements_depot_fk` (`depot_id`)', 'KEY `stock_movements_product_fk` (`product_id`)']
    ],
    // Commandes (approvisionnements) pour augmenter le stock
    'orders' => [
        'columns' => [
            'id' => ['type' => 'int', 'unsigned' => true, 'extra' => 'AUTO_INCREMENT'],
            'reference' => ['type' => 'varchar', 'length' => 60],
            'supplier' => ['type' => 'varchar', 'length' => 150, 'nullable' => true],
            'status' => ['type' => 'varchar', 'length' => 30, 'default' => 'pending'], // pending, received, partial
            'total_amount' => ['type' => 'int', 'default' => 0],
            'ordered_at' => ['type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'created_at' => ['type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'datetime', 'nullable' => true],
        ],
        'indexes' => ['PRIMARY KEY (`id`)', 'UNIQUE KEY `orders_ref_unique` (`reference`)']
    ],
    'order_items' => [
        'columns' => [
            'id' => ['type' => 'int', 'unsigned' => true, 'extra' => 'AUTO_INCREMENT'],
            'order_id' => ['type' => 'int', 'unsigned' => true],
            'product_id' => ['type' => 'int', 'unsigned' => true],
            'quantity' => ['type' => 'int'],
            'unit_cost' => ['type' => 'int'],
            'subtotal' => ['type' => 'int'],
            'created_at' => ['type' => 'datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ],
        'indexes' => ['PRIMARY KEY (`id`)', 'KEY `order_items_order_fk` (`order_id`)', 'KEY `order_items_product_fk` (`product_id`)']
    ],
];
