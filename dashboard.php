<?php
/* ============================================================
   AWAISI EMPORIUM — FINAL ADMIN DASHBOARD
   ------------------------------------------------------------
   This file handles:
   • Products + Add/Edit/Delete
   • Categories + Add/Edit/Delete
   • Orders + status management + order details
   • Customers + customer history
   • Dashboard live statistics
   • Settings: General / Privacy / Help & Support
   • Login protection
   ------------------------------------------------------------
   CHANGE HERE:
   1. Store name      -> $store_name
   2. Currency        -> $default_currency
   3. Delivery fee    -> $default_delivery_fee
   4. Colours         -> CSS :root
============================================================ */

session_start();

if (
    !isset($_SESSION["admin_logged_in"]) ||
    $_SESSION["admin_logged_in"] !== true
) {
    header("Location: login.php");
    exit;
}

require_once "connection.php";

$store_name = "Awaisi Emporium";
$default_currency = "PKR";
$default_delivery_fee = 200;

function ae($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function go($url = "dashboard.php") {
    header("Location: " . $url);
    exit;
}

function flash($type, $message) {
    $_SESSION["ae_flash"] = [
        "type" => $type,
        "message" => $message
    ];
}

function table_exists($conn, $table) {
    $table = mysqli_real_escape_string($conn, $table);
    $q = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $q && mysqli_num_rows($q) > 0;
}

function column_exists($conn, $table, $column) {
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && mysqli_num_rows($q) > 0;
}

function ensure_column($conn, $table, $column, $definition) {
    if (table_exists($conn, $table) && !column_exists($conn, $table, $column)) {
        mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

/* ============================================================
   DATABASE TABLES
============================================================ */

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        description TEXT NOT NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        category VARCHAR(150) NOT NULL,
        image VARCHAR(255) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$categories_table_existed = table_exists($conn, "categories");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL UNIQUE,
        description TEXT NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        parent_category VARCHAR(150) DEFAULT '',
        image VARCHAR(255) DEFAULT '',
        is_store_category TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

ensure_column($conn, "categories", "price", "DECIMAL(12,2) NOT NULL DEFAULT 0");
ensure_column($conn, "categories", "parent_category", "VARCHAR(150) DEFAULT ''");
ensure_column($conn, "categories", "image", "VARCHAR(255) DEFAULT ''");
ensure_column($conn, "categories", "is_store_category", "TINYINT(1) NOT NULL DEFAULT 0");

/* Seed the six public categories only when this table is first created. */
if (!$categories_table_existed) {
    $initial_store_categories = ["Toys","Stuff Toys","Electric Cars","Crockery","School Bags","Picnic Bags"];
    foreach ($initial_store_categories as $cat_name) {
        $desc = "Premium " . $cat_name . " collection at Awaisi Emporium.";
        $stmt = mysqli_prepare($conn, "INSERT INTO categories (name, description, is_store_category) VALUES (?, ?, 1)");
        if ($stmt) { mysqli_stmt_bind_param($stmt, "ss", $cat_name, $desc); mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt); }
    }
}

/* Compatible with the cart.php structure already used by Awaisi Emporium. */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_number VARCHAR(60) NOT NULL UNIQUE,
        customer_name VARCHAR(150) NOT NULL,
        customer_email VARCHAR(150) DEFAULT '',
        customer_phone VARCHAR(50) NOT NULL,
        customer_address TEXT NOT NULL,
        payment_method VARCHAR(60) DEFAULT 'Cash on Delivery',
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
        delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT DEFAULT 0,
        product_name VARCHAR(150) NOT NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        quantity INT NOT NULL DEFAULT 1,
        line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(order_id),
        INDEX(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS store_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value LONGTEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* Existing older database compatibility. */
ensure_column($conn, "orders", "notes", "TEXT NULL");
ensure_column($conn, "orders", "updated_at", "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
ensure_column($conn, "order_items", "product_id", "INT DEFAULT 0");
ensure_column($conn, "order_items", "price", "DECIMAL(12,2) NOT NULL DEFAULT 0");
ensure_column($conn, "order_items", "line_total", "DECIMAL(12,2) NOT NULL DEFAULT 0");

/* If an older dashboard created total_amount, copy it into total. */
if (table_exists($conn, "orders") && column_exists($conn, "orders", "total_amount") && !column_exists($conn, "orders", "total")) {
    ensure_column($conn, "orders", "total", "DECIMAL(12,2) NOT NULL DEFAULT 0");
    mysqli_query($conn, "UPDATE orders SET total = total_amount WHERE total = 0");
}

/* ============================================================
   CATEGORY COLLECTIONS
============================================================ */
$store_categories = ["Toys","Stuff Toys","Electric Cars","Crockery","School Bags","Picnic Bags"];
$product_collection_categories = ["Cosmetics","Accessories","Perfumes","Perfume Testers"];

/* Mark the six public categories; never INSERT them here. */
foreach ($store_categories as $cat_name) {
    $stmt = mysqli_prepare($conn, "UPDATE categories SET is_store_category=1 WHERE name=?");
    if ($stmt) { mysqli_stmt_bind_param($stmt, "s", $cat_name); mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt); }
}

/* Product-only collections stay available to Product forms but do not appear in Categories. */
foreach ($product_collection_categories as $cat_name) {
    $find = mysqli_prepare($conn, "SELECT id FROM categories WHERE name=? LIMIT 1");
    $exists = false;
    if ($find) { mysqli_stmt_bind_param($find, "s", $cat_name); mysqli_stmt_execute($find); $r=mysqli_stmt_get_result($find); $exists=$r && mysqli_num_rows($r)>0; mysqli_stmt_close($find); }
    if (!$exists) {
        $desc="Premium " . $cat_name . " collection at Awaisi Emporium.";
        $insert=mysqli_prepare($conn,"INSERT INTO categories (name,description,is_store_category) VALUES (?, ?, 0)");
        if($insert){mysqli_stmt_bind_param($insert,"ss",$cat_name,$desc);mysqli_stmt_execute($insert);mysqli_stmt_close($insert);}
    }
}

/* ============================================================
   IMAGE UPLOAD
============================================================ */

$upload_folder = "uploads/products/";

function save_product_image($field, $old_image = "") {
    global $upload_folder;

    if (!isset($_FILES[$field]) || $_FILES[$field]["error"] !== UPLOAD_ERR_OK) {
        return $old_image;
    }

    $allowed = ["jpg", "jpeg", "png", "webp", "gif"];
    $extension = strtolower(pathinfo($_FILES[$field]["name"], PATHINFO_EXTENSION));

    if (!in_array($extension, $allowed, true)) {
        return $old_image;
    }

    if (!is_dir($upload_folder)) {
        mkdir($upload_folder, 0777, true);
    }

    $new_name = "product_" . date("YmdHis") . "_" . bin2hex(random_bytes(4)) . "." . $extension;

    if (move_uploaded_file($_FILES[$field]["tmp_name"], $upload_folder . $new_name)) {
        if ($old_image !== "" && is_file($upload_folder . $old_image)) {
            @unlink($upload_folder . $old_image);
        }
        return $new_name;
    }

    return $old_image;
}

function save_category_image($field, $old_image = "") {
    $folder = "uploads/categories/";
    if (!isset($_FILES[$field]) || $_FILES[$field]["error"] !== UPLOAD_ERR_OK) return $old_image;
    $allowed=["jpg","jpeg","png","webp","gif"];
    $ext=strtolower(pathinfo($_FILES[$field]["name"],PATHINFO_EXTENSION));
    if(!in_array($ext,$allowed,true)) return $old_image;
    if(!is_dir($folder)) mkdir($folder,0777,true);
    $name="category_".date("YmdHis")."_".bin2hex(random_bytes(4)).".".$ext;
    if(move_uploaded_file($_FILES[$field]["tmp_name"],$folder.$name)){
        if($old_image!=="" && is_file($folder.$old_image)) @unlink($folder.$old_image);
        return $name;
    }
    return $old_image;
}

/* ============================================================
   SETTINGS HELPERS
============================================================ */

function get_setting($conn, $key, $default = "") {
    $stmt = mysqli_prepare($conn, "SELECT setting_value FROM store_settings WHERE setting_key=? LIMIT 1");
    if (!$stmt) return $default;

    mysqli_stmt_bind_param($stmt, "s", $key);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return $row ? (string)$row["setting_value"] : $default;
}

function save_setting($conn, $key, $value) {
    $stmt = mysqli_prepare($conn, "
        INSERT INTO store_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)
    ");

    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, "ss", $key, $value);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}

/* ============================================================
   POST ACTIONS
============================================================ */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $form_type = $_POST["form_type"] ?? "";

    /* ---------- ADD PRODUCT ---------- */
    if ($form_type === "add_product") {

        $name = trim($_POST["name"] ?? "");
        $description = trim($_POST["description"] ?? "");
        $price = (float)($_POST["price"] ?? 0);
        $category = trim($_POST["category"] ?? "");

        if ($name === "" || $description === "" || $category === "") {
            flash("error", "Product name, description and category are required.");
            go("dashboard.php?page=add_product");
        }

        $image = save_product_image("image");

        $stmt = mysqli_prepare($conn, "
            INSERT INTO products (name, description, price, category, image)
            VALUES (?, ?, ?, ?, ?)
        ");

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ssdss", $name, $description, $price, $category, $image);

            if (mysqli_stmt_execute($stmt)) {
                flash("success", "Product added successfully. It is now available on the Products page.");
                mysqli_stmt_close($stmt);
                go("dashboard.php?page=products");
            }

            mysqli_stmt_close($stmt);
        }

        flash("error", "Product could not be added.");
        go("dashboard.php?page=add_product");
    }

    /* ---------- UPDATE PRODUCT ---------- */
    if ($form_type === "update_product") {

        $id = (int)($_POST["id"] ?? 0);
        $name = trim($_POST["name"] ?? "");
        $description = trim($_POST["description"] ?? "");
        $price = (float)($_POST["price"] ?? 0);
        $category = trim($_POST["category"] ?? "");

        $old_image = "";
        $find = mysqli_prepare($conn, "SELECT image FROM products WHERE id=? LIMIT 1");
        if ($find) {
            mysqli_stmt_bind_param($find, "i", $id);
            mysqli_stmt_execute($find);
            $r = mysqli_stmt_get_result($find);
            $row = $r ? mysqli_fetch_assoc($r) : null;
            $old_image = $row["image"] ?? "";
            mysqli_stmt_close($find);
        }

        $image = save_product_image("image", $old_image);

        $stmt = mysqli_prepare($conn, "
            UPDATE products
            SET name=?, description=?, price=?, category=?, image=?
            WHERE id=?
        ");

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ssdssi", $name, $description, $price, $category, $image, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            flash("success", "Product updated successfully.");
        }

        go("dashboard.php?page=products");
    }

    /* ---------- ADD CATEGORY ---------- */
    if ($form_type === "add_category") {
        $name=trim($_POST["name"]??""); $price=(float)($_POST["price"]??0); $parent_category=trim($_POST["parent_category"]??""); $description=trim($_POST["description"]??"");
        if($name==="" || $price<0 || $description===""){flash("error","Category name, price and description are required.");go("dashboard.php?page=add_category");}
        $check=mysqli_prepare($conn,"SELECT id FROM categories WHERE name=? LIMIT 1"); $exists=false;
        if($check){mysqli_stmt_bind_param($check,"s",$name);mysqli_stmt_execute($check);$r=mysqli_stmt_get_result($check);$exists=$r&&mysqli_num_rows($r)>0;mysqli_stmt_close($check);}
        if($exists){flash("error","This category already exists.");go("dashboard.php?page=add_category");}
        $image=save_category_image("image");
        $stmt=mysqli_prepare($conn,"INSERT INTO categories (name,description,price,parent_category,image,is_store_category) VALUES (?,?,?,?,?,1)");
        if($stmt){mysqli_stmt_bind_param($stmt,"ssdss",$name,$description,$price,$parent_category,$image);$ok=mysqli_stmt_execute($stmt);mysqli_stmt_close($stmt);if($ok){flash("success","Category added successfully.");go("dashboard.php?page=categories");}}
        flash("error","Category could not be added.");go("dashboard.php?page=add_category");
    }

    /* ---------- UPDATE CATEGORY ---------- */
    if ($form_type === "update_category") {
        $id=(int)($_POST["id"]??0);$name=trim($_POST["name"]??"");$price=(float)($_POST["price"]??0);$parent_category=trim($_POST["parent_category"]??"");$description=trim($_POST["description"]??"");
        if($id<=0||$name===""||$price<0||$description===""){flash("error","Please fill all required category fields.");go("dashboard.php?page=edit_category&id=".$id);}
        $old_image="";$find=mysqli_prepare($conn,"SELECT image FROM categories WHERE id=? LIMIT 1");
        if($find){mysqli_stmt_bind_param($find,"i",$id);mysqli_stmt_execute($find);$r=mysqli_stmt_get_result($find);$row=$r?mysqli_fetch_assoc($r):null;$old_image=$row["image"]??"";mysqli_stmt_close($find);}
        $image=save_category_image("image",$old_image);
        $stmt=mysqli_prepare($conn,"UPDATE categories SET name=?,description=?,price=?,parent_category=?,image=?,is_store_category=1 WHERE id=?");
        if($stmt){mysqli_stmt_bind_param($stmt,"ssdssi",$name,$description,$price,$parent_category,$image,$id);$ok=mysqli_stmt_execute($stmt);mysqli_stmt_close($stmt);if($ok){flash("success","Category updated successfully.");go("dashboard.php?page=categories");}}
        flash("error","Category could not be updated. The name may already exist.");go("dashboard.php?page=edit_category&id=".$id);
    }

    /* ---------- ORDER STATUS ---------- */
    if ($form_type === "update_order_status") {

        $order_id = (int)($_POST["order_id"] ?? 0);
        $status = $_POST["status"] ?? "pending";

        $valid_statuses = [
            "pending",
            "confirmed",
            "shipping",
            "delivered",
            "cancelled"
        ];

        if (in_array($status, $valid_statuses, true)) {
            $stmt = mysqli_prepare($conn, "UPDATE orders SET status=? WHERE id=?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "si", $status, $order_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                flash("success", "Order status updated.");
            }
        }

        go("dashboard.php?page=orders");
    }

    /* ---------- DELETE ORDER ---------- */
    if ($form_type === "delete_order") {

        $order_id = (int)($_POST["order_id"] ?? 0);

        mysqli_begin_transaction($conn);

        try {
            $stmt = mysqli_prepare($conn, "DELETE FROM order_items WHERE order_id=?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $order_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            $stmt = mysqli_prepare($conn, "DELETE FROM orders WHERE id=?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $order_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            mysqli_commit($conn);
            flash("success", "Order deleted.");
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            flash("error", "Order could not be deleted.");
        }

        go("dashboard.php?page=orders");
    }

    /* ---------- GENERAL SETTINGS ---------- */
    if ($form_type === "general_settings") {

        $fields = [
            "store_name" => trim($_POST["store_name"] ?? ""),
            "tagline" => trim($_POST["tagline"] ?? ""),
            "store_email" => trim($_POST["store_email"] ?? ""),
            "store_phone" => trim($_POST["store_phone"] ?? ""),
            "store_address" => trim($_POST["store_address"] ?? ""),
            "currency" => trim($_POST["currency"] ?? "PKR"),
            "delivery_fee" => trim($_POST["delivery_fee"] ?? "200")
        ];

        foreach ($fields as $key => $value) {
            save_setting($conn, $key, $value);
        }

        flash("success", "General settings saved.");
        go("dashboard.php?page=settings&section=general");
    }

    /* ---------- PRIVACY SETTINGS ---------- */
    if ($form_type === "privacy_settings") {

        save_setting($conn, "privacy_policy", trim($_POST["privacy_policy"] ?? ""));
        save_setting($conn, "terms_conditions", trim($_POST["terms_conditions"] ?? ""));
        save_setting($conn, "cookie_notice", trim($_POST["cookie_notice"] ?? ""));

        flash("success", "Privacy & legal settings saved.");
        go("dashboard.php?page=settings&section=privacy");
    }

    /* ---------- HELP SETTINGS ---------- */
    if ($form_type === "help_settings") {

        save_setting($conn, "support_email", trim($_POST["support_email"] ?? ""));
        save_setting($conn, "support_phone", trim($_POST["support_phone"] ?? ""));
        save_setting($conn, "support_whatsapp", trim($_POST["support_whatsapp"] ?? ""));
        save_setting($conn, "support_hours", trim($_POST["support_hours"] ?? ""));

        flash("success", "Help & Support settings saved.");
        go("dashboard.php?page=settings&section=help");
    }
}

/* ============================================================
   DELETE PRODUCT
============================================================ */

if (isset($_GET["delete_product"])) {

    $id = (int)$_GET["delete_product"];

    $stmt = mysqli_prepare($conn, "SELECT image FROM products WHERE id=? LIMIT 1");
    $image = "";

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        $image = $row["image"] ?? "";
        mysqli_stmt_close($stmt);
    }

    $stmt = mysqli_prepare($conn, "DELETE FROM products WHERE id=?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    if ($image !== "" && is_file($upload_folder . $image)) {
        @unlink($upload_folder . $image);
    }

    flash("success", "Product deleted.");
    go("dashboard.php?page=products");
}

/* ============================================================
   DELETE CATEGORY
============================================================ */

if (isset($_GET["delete_category"])) {
    $id=(int)$_GET["delete_category"];
    $check=mysqli_prepare($conn,"SELECT name,image FROM categories WHERE id=? LIMIT 1");
    $category_name="";$category_image="";
    if($check){mysqli_stmt_bind_param($check,"i",$id);mysqli_stmt_execute($check);$r=mysqli_stmt_get_result($check);$row=$r?mysqli_fetch_assoc($r):null;$category_name=$row["name"]??"";$category_image=$row["image"]??"";mysqli_stmt_close($check);}
    if($category_name===""){flash("error","Category not found.");go("dashboard.php?page=categories");}

    /* Delete all products belonging to this category so nothing remains on the frontend. */
    $images=[];
    $q=mysqli_prepare($conn,"SELECT image FROM products WHERE category=?");
    if($q){mysqli_stmt_bind_param($q,"s",$category_name);mysqli_stmt_execute($q);$r=mysqli_stmt_get_result($q);if($r)while($row=mysqli_fetch_assoc($r)){if(!empty($row["image"]))$images[]=$row["image"];}mysqli_stmt_close($q);}
    $del=mysqli_prepare($conn,"DELETE FROM products WHERE category=?");
    if($del){mysqli_stmt_bind_param($del,"s",$category_name);mysqli_stmt_execute($del);mysqli_stmt_close($del);}
    foreach($images as $img){if(is_file($upload_folder.$img))@unlink($upload_folder.$img);}

    if($category_image!=="" && is_file("uploads/categories/".$category_image))@unlink("uploads/categories/".$category_image);
    $del=mysqli_prepare($conn,"DELETE FROM categories WHERE id=?");
    if($del){mysqli_stmt_bind_param($del,"i",$id);mysqli_stmt_execute($del);mysqli_stmt_close($del);}
    flash("success","Category and all products inside it were deleted from the store.");
    go("dashboard.php?page=categories");
}

/* ============================================================
   CURRENT PAGE
============================================================ */

$current_page = $_GET["page"] ?? "dashboard";

$allowed_pages = [
    "dashboard",
    "products",
    "add_product",
    "edit_product",
    "categories",
    "add_category",
    "edit_category",
    "orders",
    "customers",
    "settings"
];

if (!in_array($current_page, $allowed_pages, true)) {
    $current_page = "dashboard";
}

$settings_section = $_GET["section"] ?? "general";

if (!in_array($settings_section, ["general", "privacy", "help"], true)) {
    $settings_section = "general";
}

/* ============================================================
   PAGE META
============================================================ */

$page_meta = [
    "dashboard" => [
        "title" => "Dashboard",
        "label" => "Business Overview",
        "description" => "Everything you need to manage Awaisi Emporium.",
        "icon" => "fa-chart-pie"
    ],
    "products" => [
        "title" => "Products",
        "label" => "Store Management",
        "description" => "Every product, category and price in one place.",
        "icon" => "fa-box-open"
    ],
    "add_product" => [
        "title" => "Add Product",
        "label" => "Product Management",
        "description" => "Add a product and it will appear on the public Products page.",
        "icon" => "fa-circle-plus"
    ],
    "edit_product" => [
        "title" => "Edit Product",
        "label" => "Product Management",
        "description" => "Update product information without rebuilding the page.",
        "icon" => "fa-pen-to-square"
    ],
    "categories" => [
        "title" => "Categories",
        "label" => "Store Management",
        "description" => "Organize products into clean collections.",
        "icon" => "fa-layer-group"
    ],
    "add_category" => [
        "title" => "Add Category",
        "label" => "Category Management",
        "description" => "Create a new collection for your store.",
        "icon" => "fa-folder-plus"
    ],
    "edit_category" => [
        "title" => "Edit Category",
        "label" => "Category Management",
        "description" => "Update your category details.",
        "icon" => "fa-pen-to-square"
    ],
    "orders" => [
        "title" => "Orders",
        "label" => "Order Management",
        "description" => "Review customers, items, payment, delivery and status.",
        "icon" => "fa-receipt"
    ],
    "customers" => [
        "title" => "Customers",
        "label" => "Customer Management",
        "description" => "See who purchased, how many orders they placed and their total.",
        "icon" => "fa-users"
    ],
    "settings" => [
        "title" => "Settings",
        "label" => "Store Management",
        "description" => "General settings, privacy and help & support.",
        "icon" => "fa-gear"
    ]
];

$page = $page_meta[$current_page];

/* ============================================================
   LIVE COUNTS
============================================================ */

function count_rows($conn, $table, $where = "") {
    if (!table_exists($conn, $table)) return 0;

    $query = "SELECT COUNT(*) AS total FROM `$table` $where";
    $result = mysqli_query($conn, $query);

    if (!$result) return 0;

    $row = mysqli_fetch_assoc($result);
    return (int)($row["total"] ?? 0);
}

$total_products = count_rows($conn, "products");
$total_categories = count_rows($conn, "categories", "WHERE is_store_category=1");
$total_orders = count_rows($conn, "orders");
$pending_orders = count_rows($conn, "orders", "WHERE status='pending'");
$confirmed_orders = count_rows($conn, "orders", "WHERE status='confirmed'");
$shipping_orders = count_rows($conn, "orders", "WHERE status='shipping'");
$delivered_orders = count_rows($conn, "orders", "WHERE status='delivered'");
$cancelled_orders = count_rows($conn, "orders", "WHERE status='cancelled'");

/* ============================================================
   DATA
============================================================ */

$products = [];
$categories = [];
$product_categories = [];
$orders = [];
$customers = [];
$edit_product = null;
$edit_category = null;
$view_order = null;
$view_items = [];

/* PRODUCTS */
if ($current_page === "products") {

    $q = mysqli_query($conn, "
        SELECT id,name,description,price,category,image,created_at
        FROM products
        ORDER BY id DESC
    ");

    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $products[] = $row;
        }
    }
}

/* STORE CATEGORIES */
if ($current_page === "categories") {
    $q=mysqli_query($conn,"SELECT id,name,description,price,parent_category,image,created_at FROM categories WHERE is_store_category=1 ORDER BY FIELD(name,'Toys','Stuff Toys','Electric Cars','Crockery','School Bags','Picnic Bags'),name ASC");
    if($q)while($row=mysqli_fetch_assoc($q))$categories[]=$row;
}

/* PRODUCT FORM CATEGORY OPTIONS */
if(in_array($current_page,["add_product","edit_product"],true)){
    $q=mysqli_query($conn,"SELECT name FROM categories WHERE is_store_category=1 UNION SELECT DISTINCT category AS name FROM products WHERE category IS NOT NULL AND category<>'' ORDER BY name ASC");
    if($q)while($row=mysqli_fetch_assoc($q))$product_categories[]=$row["name"];
}

/* EDIT PRODUCT */
if ($current_page === "edit_product") {

    $id = (int)($_GET["id"] ?? 0);

    $stmt = mysqli_prepare($conn, "
        SELECT id,name,description,price,category,image
        FROM products
        WHERE id=?
        LIMIT 1
    ");

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        $edit_product = $r ? mysqli_fetch_assoc($r) : null;
        mysqli_stmt_close($stmt);
    }
}

/* EDIT CATEGORY */
if ($current_page === "edit_category") {

    $id = (int)($_GET["id"] ?? 0);

    $stmt = mysqli_prepare($conn, "
        SELECT id,name,description,price,parent_category,image
        FROM categories
        WHERE id=?
        LIMIT 1
    ");

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        $edit_category = $r ? mysqli_fetch_assoc($r) : null;
        mysqli_stmt_close($stmt);
    }
}

/* ORDERS */
if ($current_page === "orders") {

    $filter = $_GET["status"] ?? "all";

    $valid_filters = [
        "all",
        "pending",
        "confirmed",
        "shipping",
        "delivered",
        "cancelled"
    ];

    if (!in_array($filter, $valid_filters, true)) {
        $filter = "all";
    }

    if ($filter === "all") {
        $q = mysqli_query($conn, "SELECT * FROM orders ORDER BY id DESC");
    } else {
        $stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE status=? ORDER BY id DESC");
        mysqli_stmt_bind_param($stmt, "s", $filter);
        mysqli_stmt_execute($stmt);
        $q = mysqli_stmt_get_result($stmt);
    }

    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $orders[] = $row;
        }
    }
}

/* VIEW ORDER */
if ($current_page === "orders" && isset($_GET["view"])) {

    $view_id = (int)$_GET["view"];

    $stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id=? LIMIT 1");

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $view_id);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        $view_order = $r ? mysqli_fetch_assoc($r) : null;
        mysqli_stmt_close($stmt);
    }

    if ($view_order && table_exists($conn, "order_items")) {

        $stmt = mysqli_prepare($conn, "
            SELECT product_name, price, quantity, line_total
            FROM order_items
            WHERE order_id=?
            ORDER BY id ASC
        ");

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $view_id);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);

            if ($r) {
                while ($row = mysqli_fetch_assoc($r)) {
                    $view_items[] = $row;
                }
            }

            mysqli_stmt_close($stmt);
        }
    }
}

/* CUSTOMERS: calculated directly from real orders */
if ($current_page === "customers") {

    $q = mysqli_query($conn, "
        SELECT
            customer_name,
            customer_phone,
            customer_email,
            customer_address,
            COUNT(*) AS total_orders,
            SUM(total) AS total_spent,
            MAX(created_at) AS last_order
        FROM orders
        GROUP BY customer_name, customer_phone, customer_email, customer_address
        ORDER BY last_order DESC
    ");

    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $customers[] = $row;
        }
    }
}

/* SETTINGS */
$setting_defaults = [
    "store_name" => $store_name,
    "tagline" => "Premium products. Thoughtful service.",
    "store_email" => "",
    "store_phone" => "",
    "store_address" => "",
    "currency" => $default_currency,
    "delivery_fee" => (string)$default_delivery_fee,
    "privacy_policy" => "We respect customer information and use it only for order processing and support.",
    "terms_conditions" => "Orders are subject to product availability and confirmation by Awaisi Emporium.",
    "cookie_notice" => "This store may use essential browser storage for cart and session functionality.",
    "support_email" => "",
    "support_phone" => "",
    "support_whatsapp" => "",
    "support_hours" => "Monday - Saturday, 10:00 AM - 8:00 PM"
];

$settings = [];
foreach ($setting_defaults as $key => $default) {
    $settings[$key] = get_setting($conn, $key, $default);
}

$currency = $settings["currency"] ?: $default_currency;

$flash = $_SESSION["ae_flash"] ?? null;
unset($_SESSION["ae_flash"]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?php echo ae($page["title"]); ?> | <?php echo ae($store_name); ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>

<style>
/* ============================================================
   CHANGE YOUR DASHBOARD COLOURS HERE
============================================================ */
:root{
    --maroon:#6e1830;
    --deep-maroon:#42101f;
    --dark-maroon:#260611;

    --champagne:#d8b56a;
    --champagne-light:#f2dca5;

    --ivory:#fffdf8;
    --cream:#f7eee2;
    --white:#ffffff;

    --text:#321923;
    --muted:#806a70;
    --border:#eadbd0;

    --success:#28734a;
    --danger:#a43748;

    --shadow:0 18px 50px rgba(66,16,31,.08);
}

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

html{
    scroll-behavior:smooth;
}

body{
    min-height:100vh;
    font-family:"DM Sans",Arial,sans-serif;
    color:var(--text);
    background:#f8f4ee;
}

a{
    color:inherit;
    text-decoration:none;
}

button,
input,
textarea,
select{
    font:inherit;
}

button{
    cursor:pointer;
}

/* ============================================================
   LAYOUT
============================================================ */

.dashboard-layout{
    min-height:100vh;
}

.sidebar{
    position:fixed;
    inset:0 auto 0 0;
    z-index:1000;

    width:265px;
    padding:25px 16px;

    overflow-y:auto;

    background:
        radial-gradient(circle at top right,rgba(216,181,106,.14),transparent 30%),
        linear-gradient(160deg,#42101f,#2b0713 65%,#20040d);

    border-right:1px solid rgba(216,181,106,.20);
    transition:transform .35s ease;
}

.sidebar-brand{
    display:flex;
    align-items:center;
    gap:12px;

    padding:0 10px 28px;
    border-bottom:1px solid rgba(242,220,165,.16);
}

.sidebar-logo{
    width:48px;
    height:48px;

    display:grid;
    place-items:center;

    border-radius:50%;
    border:1px solid var(--champagne);

    color:var(--champagne-light);

    background:linear-gradient(145deg,#7d2340,#260611);

    font:700 18px "Playfair Display",serif;
}

.sidebar-brand h2{
    color:var(--ivory);
    font:700 18px/1.2 "Playfair Display",serif;
}

.sidebar-brand span{
    display:block;
    margin-top:5px;

    color:var(--champagne-light);

    font-size:8px;
    font-weight:700;
    letter-spacing:1.7px;
    text-transform:uppercase;
}

.sidebar-label{
    margin:28px 12px 10px;

    color:#c9a77d;

    font-size:9px;
    font-weight:700;
    letter-spacing:1.8px;
    text-transform:uppercase;
}

.sidebar-menu{
    display:flex;
    flex-direction:column;
    gap:5px;
}

.sidebar-link,
.sidebar-dropdown-title{
    width:100%;

    display:flex;
    align-items:center;
    gap:13px;

    padding:13px;

    border:1px solid transparent;
    border-radius:11px;

    color:#eadbd0;

    font-size:12px;
    font-weight:600;

    cursor:pointer;

    transition:.3s ease;
}

.sidebar-link i,
.sidebar-dropdown-title > i:first-child{
    width:18px;
    color:var(--champagne);
    text-align:center;
}

.sidebar-link:hover,
.sidebar-link.active,
.sidebar-dropdown-title:hover{
    color:var(--champagne-light);
    background:rgba(216,181,106,.12);
    border-color:rgba(216,181,106,.20);
}

.sidebar-dropdown-title .arrow{
    margin-left:auto;
    color:#bfa090;
    font-size:9px;
    transition:transform .35s ease;
}

.sidebar-dropdown.open .arrow{
    transform:rotate(180deg);
}

.sidebar-submenu{
    display:grid;
    grid-template-rows:0fr;
    overflow:hidden;
    padding-left:29px;
    transition:grid-template-rows .4s ease;
}

.sidebar-submenu-inner{
    min-height:0;
}

.sidebar-dropdown.open .sidebar-submenu{
    grid-template-rows:1fr;
}

.sidebar-submenu a{
    display:flex;
    align-items:center;
    gap:9px;

    padding:10px 12px;
    margin-bottom:3px;

    border-radius:9px;

    color:#cdb8ae;
    font-size:11px;

    transition:.3s ease;
}

.sidebar-submenu a i{
    width:15px;
    color:var(--champagne);
    text-align:center;
}

.sidebar-submenu a:hover{
    color:var(--champagne-light);
    background:rgba(216,181,106,.10);
    padding-left:17px;
}

.sidebar-bottom{
    margin-top:30px;
}

.admin-profile{
    display:flex;
    align-items:center;
    gap:11px;

    padding:13px;
    margin-bottom:10px;

    border-radius:13px;

    background:rgba(255,255,255,.06);
    border:1px solid rgba(216,181,106,.15);
}

.admin-avatar{
    width:38px;
    height:38px;

    display:grid;
    place-items:center;

    border-radius:50%;

    color:var(--deep-maroon);

    background:linear-gradient(135deg,var(--champagne-light),var(--champagne));

    font-size:13px;
    font-weight:800;
}

.admin-profile strong{
    display:block;
    color:var(--ivory);
    font-size:12px;
}

.admin-profile span{
    display:block;
    margin-top:3px;
    color:#c9a77d;
    font-size:10px;
}

.logout-link{
    color:#e8b8bd!important;
}

/* ============================================================
   MAIN
============================================================ */

.main-content{
    width:calc(100% - 265px);
    margin-left:265px;

    min-height:100vh;
    padding:28px 35px 60px;
}

.mobile-topbar{
    display:none;
}

.premium-topbar{
    position:relative;

    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:25px;

    margin-bottom:28px;
    padding:24px 25px;

    border:1px solid var(--border);
    border-radius:18px;

    background:
        radial-gradient(circle at 90% 0%,rgba(216,181,106,.13),transparent 30%),
        linear-gradient(135deg,#fffdf8,#f9f2e9);

    box-shadow:0 12px 35px rgba(66,16,31,.055);
    overflow:hidden;
}

.premium-topbar::before{
    content:"";

    position:absolute;
    left:0;
    top:0;
    bottom:0;

    width:4px;

    background:linear-gradient(180deg,var(--champagne),var(--maroon));
}

.breadcrumb{
    display:flex;
    gap:9px;
    margin-bottom:12px;

    font-size:9px;
    font-weight:700;
}

.breadcrumb a{
    color:#9a713d;
}

.breadcrumb span{
    color:#806c71;
}

.page-title-row{
    display:flex;
    align-items:center;
    gap:15px;
}

.page-icon{
    width:50px;
    height:50px;
    flex:0 0 50px;

    display:grid;
    place-items:center;

    border:1px solid rgba(216,181,106,.38);
    border-radius:13px;

    color:var(--maroon);

    background:linear-gradient(145deg,#fff,#f6eadc);
}

.page-title-row small{
    display:block;
    margin-bottom:3px;

    color:#9a713d;

    font-size:8px;
    font-weight:800;
    letter-spacing:1.8px;
    text-transform:uppercase;
}

.page-title-row h1{
    color:var(--deep-maroon);
    font:700 clamp(27px,3vw,38px)/1 "Playfair Display",serif;
}

.page-title-row p{
    margin-top:7px;
    color:var(--muted);
    font-size:11px;
}

.store-button,
.primary-button,
.secondary-button,
.danger-button{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:9px;

    min-height:44px;
    padding:0 17px;

    border-radius:10px;

    font-size:10px;
    font-weight:700;

    cursor:pointer;
    transition:.3s ease;
}

.store-button,
.primary-button{
    color:var(--champagne-light);

    border:1px solid rgba(216,181,106,.28);

    background:linear-gradient(135deg,var(--deep-maroon),var(--maroon));
}

.secondary-button{
    color:var(--maroon);
    background:#fff;
    border:1px solid var(--border);
}

.danger-button{
    color:#fff;
    background:var(--danger);
    border:1px solid var(--danger);
}

.store-button:hover,
.primary-button:hover,
.secondary-button:hover,
.danger-button:hover{
    transform:translateY(-2px);
}

/* ============================================================
   FLASH
============================================================ */

.flash{
    padding:14px 16px;
    margin-bottom:20px;
    border-radius:11px;
    font-size:11px;
}

.flash.success{
    color:#245b39;
    background:#edf8f0;
    border:1px solid #cfe8d5;
}

.flash.error{
    color:#8b2938;
    background:#fff0f1;
    border:1px solid #f0c9ce;
}

/* ============================================================
   DASHBOARD STATS
============================================================ */

.stats-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:18px;
    margin-bottom:25px;
}

.stat-card{
    min-height:145px;
    padding:23px;

    border:1px solid var(--border);
    border-radius:17px;

    background:#fff;
    box-shadow:var(--shadow);

    transition:.3s ease;
}

.stat-card:hover{
    transform:translateY(-4px);
}

.stat-top{
    display:flex;
    justify-content:space-between;
    gap:15px;
}

.stat-title{
    color:#947b81;
    font-size:11px;
    font-weight:700;
}

.stat-icon{
    width:40px;
    height:40px;

    display:grid;
    place-items:center;

    border-radius:11px;

    color:var(--maroon);
    background:#f8eee1;
}

.stat-card h2{
    margin-top:17px;
    color:var(--deep-maroon);
    font:700 35px/1 "Playfair Display",serif;
}

.stat-card p{
    margin-top:9px;
    color:#aa9294;
    font-size:10px;
}

.status-strip{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:12px;
    margin-bottom:25px;
}

.status-card{
    padding:16px;
    border:1px solid var(--border);
    border-radius:14px;
    background:#fff;
}

.status-card span{
    display:block;
    color:var(--muted);
    font-size:9px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.8px;
}

.status-card strong{
    display:block;
    margin-top:7px;
    color:var(--deep-maroon);
    font:700 24px "Playfair Display",serif;
}

/* ============================================================
   WELCOME / QUICK
============================================================ */

.welcome{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:25px;

    padding:30px;
    margin-bottom:25px;

    border-radius:18px;
    border:1px solid rgba(216,181,106,.25);

    background:linear-gradient(120deg,#42101f,#6e1830,#42101f);
}

.welcome h2{
    color:var(--ivory);
    font:700 26px "Playfair Display",serif;
}

.welcome p{
    max-width:650px;
    margin-top:8px;
    color:#eadbd0;
    font-size:12px;
    line-height:1.7;
}

.welcome-mark{
    width:80px;
    height:80px;
    flex:0 0 80px;

    display:grid;
    place-items:center;

    border:1px solid rgba(216,181,106,.7);
    border-radius:50%;

    color:var(--champagne-light);
    font:700 25px "Playfair Display",serif;
}

.quick-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:15px;
}

.quick-card{
    padding:20px;
    border:1px solid var(--border);
    border-radius:14px;
    background:#fcf9f4;
    transition:.3s ease;
}

.quick-card:hover{
    transform:translateY(-3px);
    border-color:var(--champagne);
}

.quick-card i{
    color:var(--maroon);
    font-size:20px;
}

.quick-card h3{
    margin-top:12px;
    color:var(--deep-maroon);
    font:700 17px "Playfair Display",serif;
}

.quick-card p{
    margin:6px 0 15px;
    color:var(--muted);
    font-size:10px;
    line-height:1.6;
}

/* ============================================================
   WORKSPACE / PANELS
============================================================ */

.workspace{
    opacity:0;
    transform:translateY(20px);
    animation:workspaceOpen .55s cubic-bezier(.2,.8,.2,1) forwards;
}

@keyframes workspaceOpen{
    to{
        opacity:1;
        transform:translateY(0);
    }
}

.workspace-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    margin-bottom:18px;
}

.workspace-header h2{
    color:var(--deep-maroon);
    font:700 25px "Playfair Display",serif;
}

.workspace-header p{
    margin-top:5px;
    color:var(--muted);
    font-size:11px;
}

.workspace-actions{
    display:flex;
    gap:9px;
    flex-wrap:wrap;
}

.panel,
.form-card{
    border:1px solid var(--border);
    border-radius:17px;
    background:#fff;
    box-shadow:var(--shadow);
}

.panel{
    padding:23px;
}

.form-card{
    max-width:980px;
    padding:27px;
}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:17px;
}

.form-group{
    display:flex;
    flex-direction:column;
    gap:7px;
}

.form-group.full{
    grid-column:1/-1;
}

.form-group label{
    color:var(--deep-maroon);
    font-size:11px;
    font-weight:700;
}

.form-group input,
.form-group textarea,
.form-group select{
    width:100%;
    padding:13px 14px;
    outline:none;

    color:var(--text);

    border:1px solid var(--border);
    border-radius:10px;

    background:#fffdf9;
    transition:.25s ease;
}

.form-group textarea{
    min-height:125px;
    resize:vertical;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus{
    border-color:var(--champagne);
    box-shadow:0 0 0 3px rgba(216,181,106,.12);
}

.form-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:21px;
}

.help-text{
    color:var(--muted);
    font-size:10px;
    line-height:1.6;
}

/* ============================================================
   TABLES
============================================================ */

.table-wrap{
    overflow-x:auto;
}

.data-table{
    width:100%;
    min-width:760px;
    border-collapse:collapse;
}

.data-table th,
.data-table td{
    padding:14px 12px;
    text-align:left;
    border-bottom:1px solid #f0e5dc;
    font-size:11px;
    vertical-align:middle;
}

.data-table th{
    color:#947b81;
    font-size:9px;
    letter-spacing:.8px;
    text-transform:uppercase;
}

.data-table td{
    color:var(--muted);
}

.data-table tr:hover td{
    background:#fffcf7;
}

.product-thumb{
    width:54px;
    height:54px;
    object-fit:cover;

    display:block;

    border-radius:10px;
    border:1px solid var(--border);
    background:#f8eee1;
}

.thumb-empty{
    display:grid;
    place-items:center;
    color:var(--champagne);
    font:700 14px "Playfair Display",serif;
}

.category-pill,
.status-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;

    padding:6px 9px;
    border-radius:999px;

    font-size:8px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.5px;
}

.category-pill{
    color:var(--maroon);
    background:#f8eee1;
}

.status-pill.pending{
    color:#8a611d;
    background:#fff4d9;
}

.status-pill.confirmed{
    color:#315f8c;
    background:#eaf4ff;
}

.status-pill.shipping{
    color:#6d4c96;
    background:#f1eafd;
}

.status-pill.delivered{
    color:#28734a;
    background:#e8f7ed;
}

.status-pill.cancelled{
    color:#a43748;
    background:#fff0f1;
}

.action-row{
    display:flex;
    gap:7px;
    flex-wrap:wrap;
}

.small-action{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;

    min-height:34px;
    padding:0 10px;

    border:1px solid var(--border);
    border-radius:8px;

    color:var(--maroon);
    background:#fff;

    font-size:9px;
    font-weight:700;
}

.small-action:hover{
    border-color:var(--champagne);
    background:#fffaf2;
}

.small-action.danger{
    color:var(--danger);
}

.status-form select{
    min-height:34px;
    padding:0 9px;

    border:1px solid var(--border);
    border-radius:8px;

    color:var(--maroon);
    background:#fff;
    font-size:9px;
    font-weight:700;
}

.order-number{
    color:var(--deep-maroon);
    font-weight:800;
}

.order-customer strong{
    display:block;
    color:var(--text);
}

.order-customer span{
    display:block;
    margin-top:3px;
    font-size:9px;
}

.empty-state{
    text-align:center;
    padding:55px 20px;
    color:var(--muted);
}

.empty-state i{
    margin-bottom:13px;
    color:var(--champagne);
    font-size:32px;
}

.empty-state h3{
    color:var(--deep-maroon);
    font:700 22px "Playfair Display",serif;
}

.empty-state p{
    margin:7px 0 18px;
    font-size:11px;
}

/* ============================================================
   ORDER FILTERS / DETAIL
============================================================ */

.filter-bar{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.filter-link{
    padding:9px 12px;
    border:1px solid var(--border);
    border-radius:999px;
    background:#fff;
    color:var(--muted);
    font-size:9px;
    font-weight:800;
}

.filter-link.active,
.filter-link:hover{
    color:var(--champagne-light);
    border-color:var(--maroon);
    background:var(--maroon);
}

.order-detail{
    margin-top:20px;
}

.order-detail-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:15px;
    margin-bottom:20px;
}

.order-detail-head h3{
    color:var(--deep-maroon);
    font:700 26px "Playfair Display",serif;
}

.order-detail-head p{
    margin-top:5px;
    color:var(--muted);
    font-size:10px;
}

.detail-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:12px;
    margin-bottom:20px;
}

.detail-box{
    padding:15px;
    border:1px solid var(--border);
    border-radius:12px;
    background:#fffdf9;
}

.detail-box.full{
    grid-column:1/-1;
}

.detail-box span{
    display:block;
    color:#a0878b;
    font-size:8px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.8px;
}

.detail-box strong{
    display:block;
    margin-top:7px;
    color:var(--text);
    font-size:11px;
    line-height:1.6;
}

.order-items{
    width:100%;
    border-collapse:collapse;
}

.order-items th,
.order-items td{
    padding:12px;
    border-bottom:1px solid #f0e5dc;
    text-align:left;
    font-size:10px;
}

.order-items th{
    color:#947b81;
    font-size:8px;
    text-transform:uppercase;
}

.total-box{
    display:flex;
    justify-content:flex-end;
    margin-top:18px;
}

.total-box strong{
    color:var(--deep-maroon);
    font:700 24px "Playfair Display",serif;
}

/* ============================================================
   CUSTOMER CARDS
============================================================ */

.customer-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:15px;
}

.customer-card{
    padding:20px;
    border:1px solid var(--border);
    border-radius:15px;
    background:#fff;
    box-shadow:0 10px 30px rgba(66,16,31,.05);
}

.customer-avatar{
    width:45px;
    height:45px;

    display:grid;
    place-items:center;

    margin-bottom:13px;

    border-radius:50%;
    color:var(--deep-maroon);
    background:linear-gradient(135deg,var(--champagne-light),var(--champagne));

    font-weight:800;
}

.customer-card h3{
    color:var(--deep-maroon);
    font:700 18px "Playfair Display",serif;
}

.customer-card p{
    margin-top:5px;
    color:var(--muted);
    font-size:10px;
    line-height:1.6;
}

.customer-stats{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px;
    margin-top:15px;
}

.customer-stat{
    padding:10px;
    border-radius:9px;
    background:#faf5ee;
}

.customer-stat span{
    display:block;
    color:#9b8588;
    font-size:8px;
}

.customer-stat strong{
    display:block;
    margin-top:3px;
    color:var(--maroon);
    font-size:12px;
}

/* ============================================================
   SETTINGS
============================================================ */

.settings-nav{
    display:flex;
    gap:9px;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.settings-tab{
    display:inline-flex;
    align-items:center;
    gap:8px;

    padding:11px 14px;

    border:1px solid var(--border);
    border-radius:10px;

    color:var(--muted);
    background:#fff;

    font-size:9px;
    font-weight:800;
}

.settings-tab.active,
.settings-tab:hover{
    color:var(--champagne-light);
    border-color:var(--maroon);
    background:var(--maroon);
}

.settings-note{
    margin-bottom:18px;
    padding:14px 16px;

    border:1px solid rgba(216,181,106,.30);
    border-radius:11px;

    color:#6f5632;
    background:#fff9ed;

    font-size:10px;
    line-height:1.6;
}

/* ============================================================
   RESPONSIVE
============================================================ */

@media(max-width:1200px){
    .stats-grid{
        grid-template-columns:repeat(2,1fr);
    }

    .customer-grid{
        grid-template-columns:repeat(2,1fr);
    }

    .status-strip{
        grid-template-columns:repeat(3,1fr);
    }
}

@media(max-width:850px){

    .sidebar{
        transform:translateX(-100%);
    }

    .sidebar.open{
        transform:translateX(0);
    }

    .main-content{
        width:100%;
        margin-left:0;
        padding:18px;
    }

    .mobile-topbar{
        display:flex;
        align-items:center;
        justify-content:space-between;
        margin-bottom:18px;
    }

    .mobile-topbar strong{
        color:var(--deep-maroon);
        font:700 18px "Playfair Display",serif;
    }

    .mobile-menu-button{
        width:43px;
        height:43px;

        display:grid;
        place-items:center;

        border:1px solid var(--border);
        border-radius:11px;

        color:var(--maroon);
        background:#fff;
    }

    .premium-topbar{
        align-items:flex-start;
        flex-direction:column;
    }

    .store-button{
        width:100%;
    }

    .quick-grid{
        grid-template-columns:1fr;
    }

    .customer-grid{
        grid-template-columns:1fr;
    }
}

@media(max-width:600px){

    .main-content{
        padding:14px;
    }

    .stats-grid{
        grid-template-columns:1fr;
    }

    .status-strip{
        grid-template-columns:1fr 1fr;
    }

    .welcome{
        align-items:flex-start;
        flex-direction:column;
        padding:23px;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .form-group.full{
        grid-column:auto;
    }

    .workspace-header{
        align-items:flex-start;
        flex-direction:column;
    }

    .page-title-row{
        align-items:flex-start;
    }

    .page-icon{
        width:44px;
        height:44px;
        flex-basis:44px;
    }

    .detail-grid{
        grid-template-columns:1fr;
    }

    .detail-box.full{
        grid-column:auto;
    }
}
</style>
</head>

<body>

<div class="dashboard-layout">

<!-- ============================================================
     SIDEBAR
============================================================ -->

<aside class="sidebar" id="sidebar">

    <a href="dashboard.php" class="sidebar-brand">
        <div class="sidebar-logo">AE</div>

        <div>
            <h2><?php echo ae($store_name); ?></h2>
            <span>Luxury Admin Panel</span>
        </div>
    </a>

    <div class="sidebar-label">Main Menu</div>

    <nav class="sidebar-menu">

        <a
            href="dashboard.php"
            class="sidebar-link <?php echo $current_page === "dashboard" ? "active" : ""; ?>"
        >
            <i class="fa-solid fa-chart-pie"></i>
            Dashboard
        </a>

        <div class="sidebar-dropdown <?php echo in_array($current_page, ["products","add_product","edit_product"], true) ? "open" : ""; ?>">

            <div class="sidebar-dropdown-title">
                <i class="fa-solid fa-box-open"></i>
                <span>Products</span>
                <i class="fa-solid fa-chevron-down arrow"></i>
            </div>

            <div class="sidebar-submenu">
                <div class="sidebar-submenu-inner">

                    <a href="dashboard.php?page=products">
                        <i class="fa-solid fa-boxes-stacked"></i>
                        All Products
                    </a>

                    <a href="dashboard.php?page=add_product">
                        <i class="fa-solid fa-circle-plus"></i>
                        Add Product
                    </a>

                </div>
            </div>
        </div>

        <div class="sidebar-dropdown <?php echo in_array($current_page, ["categories","add_category","edit_category"], true) ? "open" : ""; ?>">

            <div class="sidebar-dropdown-title">
                <i class="fa-solid fa-layer-group"></i>
                <span>Categories</span>
                <i class="fa-solid fa-chevron-down arrow"></i>
            </div>

            <div class="sidebar-submenu">
                <div class="sidebar-submenu-inner">

                    <a href="dashboard.php?page=categories">
                        <i class="fa-solid fa-list"></i>
                        All Categories
                    </a>

                    <a href="dashboard.php?page=add_category">
                        <i class="fa-solid fa-folder-plus"></i>
                        Add Category
                    </a>

                </div>
            </div>
        </div>

        <a
            href="dashboard.php?page=orders"
            class="sidebar-link <?php echo $current_page === "orders" ? "active" : ""; ?>"
        >
            <i class="fa-solid fa-receipt"></i>
            Orders
        </a>

        <a
            href="dashboard.php?page=customers"
            class="sidebar-link <?php echo $current_page === "customers" ? "active" : ""; ?>"
        >
            <i class="fa-solid fa-users"></i>
            Customers
        </a>

        <div class="sidebar-label">Store Management</div>

        <div class="sidebar-dropdown <?php echo $current_page === "settings" ? "open" : ""; ?>">

            <div class="sidebar-dropdown-title">
                <i class="fa-solid fa-gear"></i>
                <span>Settings</span>
                <i class="fa-solid fa-chevron-down arrow"></i>
            </div>

            <div class="sidebar-submenu">
                <div class="sidebar-submenu-inner">

                    <a href="dashboard.php?page=settings&section=general">
                        <i class="fa-solid fa-sliders"></i>
                        General Settings
                    </a>

                    <a href="dashboard.php?page=settings&section=privacy">
                        <i class="fa-solid fa-shield-halved"></i>
                        Privacy & Legal
                    </a>

                    <a href="dashboard.php?page=settings&section=help">
                        <i class="fa-solid fa-headset"></i>
                        Help & Support
                    </a>

                </div>
            </div>
        </div>

        <a href="index.php" class="sidebar-link">
            <i class="fa-solid fa-store"></i>
            View Store
        </a>

    </nav>

    <div class="sidebar-bottom">

        <div class="admin-profile">

            <div class="admin-avatar">
                <?php echo strtoupper(substr((string)$admin_name, 0, 1)); ?>
            </div>

            <div>
                <strong><?php echo ae($admin_name); ?></strong>
                <span>Administrator</span>
            </div>

        </div>

        <a href="logout.php" class="sidebar-link logout-link">
            <i class="fa-solid fa-right-from-bracket"></i>
            Logout
        </a>

    </div>

</aside>

<!-- ============================================================
     MAIN CONTENT
============================================================ -->

<main class="main-content">

    <div class="mobile-topbar">

        <strong>Awaisi Emporium</strong>

        <button
            type="button"
            class="mobile-menu-button"
            id="mobileMenuButton"
        >
            <i class="fa-solid fa-bars"></i>
        </button>

    </div>

    <header class="premium-topbar">

        <div>

            <div class="breadcrumb">
                <a href="dashboard.php">Awaisi Emporium</a>
                <span>/</span>
                <span><?php echo ae($page["title"]); ?></span>
            </div>

            <div class="page-title-row">

                <div class="page-icon">
                    <i class="fa-solid <?php echo ae($page["icon"]); ?>"></i>
                </div>

                <div>

                    <small><?php echo ae($page["label"]); ?></small>

                    <h1><?php echo ae($page["title"]); ?></h1>

                    <p><?php echo ae($page["description"]); ?></p>

                </div>

            </div>

        </div>

        <a href="index.php" class="store-button">
            <i class="fa-solid fa-store"></i>
            View Store
        </a>

    </header>

    <?php if ($flash): ?>

        <div class="flash <?php echo ae($flash["type"]); ?>">
            <?php echo ae($flash["message"]); ?>
        </div>

    <?php endif; ?>

    <!-- ========================================================
         DASHBOARD HOME
    ========================================================= -->

    <?php if ($current_page === "dashboard"): ?>

        <section class="stats-grid">

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-title">Total Products</span>
                    <div class="stat-icon"><i class="fa-solid fa-box-open"></i></div>
                </div>
                <h2><?php echo $total_products; ?></h2>
                <p>Live products in your store</p>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-title">Categories</span>
                    <div class="stat-icon"><i class="fa-solid fa-layer-group"></i></div>
                </div>
                <h2><?php echo $total_categories; ?></h2>
                <p>Active store collections</p>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-title">Total Orders</span>
                    <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
                </div>
                <h2><?php echo $total_orders; ?></h2>
                <p>Orders received from customers</p>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-title">Shipping</span>
                    <div class="stat-icon"><i class="fa-solid fa-truck-fast"></i></div>
                </div>
                <h2><?php echo $shipping_orders; ?></h2>
                <p>Orders currently shipping</p>
            </div>

        </section>

        <section class="status-strip">

            <a href="dashboard.php?page=orders&status=pending" class="status-card">
                <span>Pending</span>
                <strong><?php echo $pending_orders; ?></strong>
            </a>

            <a href="dashboard.php?page=orders&status=confirmed" class="status-card">
                <span>Confirmed</span>
                <strong><?php echo $confirmed_orders; ?></strong>
            </a>

            <a href="dashboard.php?page=orders&status=shipping" class="status-card">
                <span>Shipping</span>
                <strong><?php echo $shipping_orders; ?></strong>
            </a>

            <a href="dashboard.php?page=orders&status=delivered" class="status-card">
                <span>Delivered</span>
                <strong><?php echo $delivered_orders; ?></strong>
            </a>

            <a href="dashboard.php?page=orders&status=cancelled" class="status-card">
                <span>Cancelled</span>
                <strong><?php echo $cancelled_orders; ?></strong>
            </a>

        </section>

        <section class="welcome">

            <div>
                <h2>Welcome to Awaisi Emporium</h2>

                <p>
                    Your store is connected to MySQL/XAMPP. Products added here
                    are saved in the products table and automatically become
                    available on the public Products page. Customer checkouts
                    are stored as orders and can be handled from this dashboard.
                </p>
            </div>

            <div class="welcome-mark">AE</div>

        </section>

        <section class="quick-grid">

            <div class="quick-card">
                <i class="fa-solid fa-box-open"></i>
                <h3>Products</h3>
                <p>View every product, category, price and image.</p>
                <a href="dashboard.php?page=products" class="primary-button">
                    Manage Products
                </a>
            </div>

            <div class="quick-card">
                <i class="fa-solid fa-receipt"></i>
                <h3>Orders</h3>
                <p>See new orders, customer information and delivery status.</p>
                <a href="dashboard.php?page=orders" class="primary-button">
                    Manage Orders
                </a>
            </div>

            <div class="quick-card">
                <i class="fa-solid fa-users"></i>
                <h3>Customers</h3>
                <p>See customer contacts, order count and total spending.</p>
                <a href="dashboard.php?page=customers" class="primary-button">
                    View Customers
                </a>
            </div>

        </section>

    <?php endif; ?>

    <!-- ========================================================
         PRODUCTS
    ========================================================= -->

    <?php if ($current_page === "products"): ?>

        <section class="workspace">

            <div class="workspace-header">

                <div>
                    <h2>All Products</h2>
                    <p>Products added here automatically appear on products.php.</p>
                </div>

                <a href="dashboard.php?page=add_product" class="primary-button">
                    <i class="fa-solid fa-plus"></i>
                    Add Product
                </a>

            </div>

            <div class="panel">

                <?php if (!$products): ?>

                    <div class="empty-state">
                        <i class="fa-solid fa-box-open"></i>
                        <h3>No Products Yet</h3>
                        <p>Add your first product from the button above.</p>
                        <a href="dashboard.php?page=add_product" class="primary-button">
                            Add Product
                        </a>
                    </div>

                <?php else: ?>

                    <div class="table-wrap">

                        <table class="data-table">

                            <thead>
                                <tr>
                                    <th>Image</th>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Price</th>
                                    <th>Added</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>

                            <tbody>

                            <?php foreach ($products as $product): ?>

                                <tr>

                                    <td>
                                        <?php if (!empty($product["image"])): ?>
                                            <img
                                                class="product-thumb"
                                                src="<?php echo ae($upload_folder . $product["image"]); ?>"
                                                alt="<?php echo ae($product["name"]); ?>"
                                            >
                                        <?php else: ?>
                                            <div class="product-thumb thumb-empty">AE</div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <strong style="color:var(--text);">
                                            <?php echo ae($product["name"]); ?>
                                        </strong>
                                        <br>
                                        <span class="help-text">
                                            <?php echo ae(mb_strimwidth($product["description"], 0, 75, "...")); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <a
                                            class="category-pill"
                                            href="products.php?category=<?php echo urlencode($product["category"]); ?>"
                                        >
                                            <?php echo ae($product["category"]); ?>
                                        </a>
                                    </td>

                                    <td>
                                        <?php echo ae($currency); ?>
                                        <?php echo number_format((float)$product["price"], 2); ?>
                                    </td>

                                    <td>
                                        <?php echo ae(date("d M Y", strtotime($product["created_at"]))); ?>
                                    </td>

                                    <td>

                                        <div class="action-row">

                                            <a
                                                class="small-action"
                                                href="products.php?category=<?php echo urlencode($product["category"]); ?>"
                                                target="_blank"
                                            >
                                                <i class="fa-solid fa-eye"></i>
                                                View
                                            </a>

                                            <a
                                                class="small-action"
                                                href="dashboard.php?page=edit_product&id=<?php echo (int)$product["id"]; ?>"
                                            >
                                                <i class="fa-solid fa-pen"></i>
                                                Edit
                                            </a>

                                            <a
                                                class="small-action danger"
                                                href="dashboard.php?page=products&delete_product=<?php echo (int)$product["id"]; ?>"
                                                onclick="return confirm('Delete this product?');"
                                            >
                                                <i class="fa-solid fa-trash"></i>
                                                Delete
                                            </a>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

        </section>

    <?php endif; ?>

    <!-- ========================================================
         ADD / EDIT PRODUCT
    ========================================================= -->

    <?php if ($current_page === "add_product" || $current_page === "edit_product"): ?>

        <section class="workspace">

            <?php if ($current_page === "edit_product" && !$edit_product): ?>

                <div class="panel empty-state">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <h3>Product Not Found</h3>
                    <p>The selected product does not exist.</p>
                    <a href="dashboard.php?page=products" class="primary-button">
                        Back to Products
                    </a>
                </div>

            <?php else: ?>

                <?php
                $editing = $current_page === "edit_product";
                $product_form = $editing ? $edit_product : [
                    "id" => "",
                    "name" => "",
                    "description" => "",
                    "price" => "",
                    "category" => "",
                    "image" => ""
                ];
                ?>

                <div class="workspace-header">

                    <div>
                        <h2><?php echo $editing ? "Edit Product" : "Add New Product"; ?></h2>
                        <p>
                            <?php echo $editing
                                ? "Update the product and its category."
                                : "Save the product once and it will appear on the public Products page."; ?>
                        </p>
                    </div>

                    <a href="dashboard.php?page=products" class="secondary-button">
                        <i class="fa-solid fa-arrow-left"></i>
                        Products
                    </a>

                </div>

                <form
                    class="form-card"
                    method="POST"
                    enctype="multipart/form-data"
                >

                    <input
                        type="hidden"
                        name="form_type"
                        value="<?php echo $editing ? "update_product" : "add_product"; ?>"
                    >

                    <?php if ($editing): ?>
                        <input
                            type="hidden"
                            name="id"
                            value="<?php echo (int)$product_form["id"]; ?>"
                        >
                    <?php endif; ?>

                    <div class="form-grid">

                        <div class="form-group">
                            <label>Product Name *</label>
                            <input
                                type="text"
                                name="name"
                                value="<?php echo ae($product_form["name"]); ?>"
                                placeholder="e.g. Premium Face Cream"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label>Price *</label>
                            <input
                                type="number"
                                name="price"
                                value="<?php echo ae($product_form["price"]); ?>"
                                min="0"
                                step="0.01"
                                placeholder="2500"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label>Category *</label>

                            <select name="category" required>

                                <option value="">Select Category</option>

                                <?php foreach ($product_categories as $category_name): ?>

                                    <option
                                        value="<?php echo ae($category_name); ?>"
                                        <?php echo $product_form["category"] === $category_name ? "selected" : ""; ?>
                                    >
                                        <?php echo ae($category_name); ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <span class="help-text">
                                The product is shown under this category only.
                                On products.php use the category filter to see it.
                            </span>
                        </div>

                        <div class="form-group">
                            <label>Product Image</label>

                            <input
                                type="file"
                                name="image"
                                accept=".jpg,.jpeg,.png,.webp,.gif"
                            >

                            <?php if ($editing && !empty($product_form["image"])): ?>
                                <span class="help-text">
                                    Current image: <?php echo ae($product_form["image"]); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group full">
                            <label>Product Description *</label>

                            <textarea
                                name="description"
                                placeholder="Write a premium product description..."
                                required
                            ><?php echo ae($product_form["description"]); ?></textarea>
                        </div>

                    </div>

                    <div class="form-actions">

                        <button type="submit" class="primary-button">
                            <i class="fa-solid fa-floppy-disk"></i>
                            <?php echo $editing ? "Update Product" : "Save Product"; ?>
                        </button>

                        <a href="dashboard.php?page=products" class="secondary-button">
                            Cancel
                        </a>

                    </div>

                </form>

            <?php endif; ?>

        </section>

    <?php endif; ?>

    <!-- ========================================================
         CATEGORIES
    ========================================================= -->

    <?php if ($current_page === "categories"): ?>
        <section class="workspace">
            <div class="workspace-header"><div><h2>All Categories</h2><p>Only active store categories are shown. Each category includes its products, price, image, description and actions.</p></div><a href="dashboard.php?page=add_category" class="primary-button"><i class="fa-solid fa-folder-plus"></i> Add Category</a></div>
            <?php if(!$categories): ?><div class="empty-state"><i class="fa-solid fa-layer-group"></i><h3>No Categories</h3><p>Add your first store category.</p></div><?php else: ?>
            <div class="category-admin-grid">
            <?php foreach($categories as $category): ?>
                <?php $cat_products=[];$cat_stmt=mysqli_prepare($conn,"SELECT id,name,description,price,image FROM products WHERE category=? ORDER BY id DESC");if($cat_stmt){mysqli_stmt_bind_param($cat_stmt,"s",$category["name"]);mysqli_stmt_execute($cat_stmt);$cr=mysqli_stmt_get_result($cat_stmt);if($cr)while($p=mysqli_fetch_assoc($cr))$cat_products[]=$p;mysqli_stmt_close($cat_stmt);} ?>
                <article class="category-admin-card">
                    <div class="category-admin-top"><div class="category-admin-image"><?php if(!empty($category["image"])): ?><img src="uploads/categories/<?php echo ae($category["image"]); ?>" alt="<?php echo ae($category["name"]); ?>"><?php else: ?><i class="fa-solid fa-layer-group"></i><?php endif; ?></div><div class="category-admin-heading"><span class="category-label">CATEGORY</span><h3><?php echo ae($category["name"]); ?></h3><div class="category-meta-line"><span><?php echo count($cat_products); ?> product<?php echo count($cat_products)===1?'':'s'; ?></span><strong>PKR <?php echo number_format((float)$category["price"],2); ?></strong></div></div></div>
                    <p class="category-description"><?php echo ae($category["description"]?:"Premium collection at Awaisi Emporium."); ?></p>
                    <?php if(!empty($category["parent_category"])): ?><div class="category-parent"><i class="fa-solid fa-folder-tree"></i> Parent: <?php echo ae($category["parent_category"]); ?></div><?php endif; ?>
                    <div class="category-actions"><a href="categories.php?category=<?php echo urlencode($category["name"]); ?>" target="_blank" class="small-action"><i class="fa-solid fa-eye"></i> View</a><a href="dashboard.php?page=edit_category&id=<?php echo (int)$category["id"]; ?>" class="small-action"><i class="fa-solid fa-pen"></i> Edit</a><a href="dashboard.php?page=categories&delete_category=<?php echo (int)$category["id"]; ?>" class="small-action danger" onclick="return confirm('Delete this category and all products inside it?');"><i class="fa-solid fa-trash"></i> Delete</a></div>
                    <div class="category-products-box"><div class="category-products-head"><span><i class="fa-solid fa-box-open"></i> Products in this category</span><a href="dashboard.php?page=add_product" class="category-add-product">+ Add Product</a></div>
                    <?php if($cat_products): ?><div class="category-product-list"><?php foreach($cat_products as $product): ?><div class="category-product-row"><div class="category-product-image"><?php if(!empty($product["image"])): ?><img src="uploads/products/<?php echo ae($product["image"]); ?>" alt="<?php echo ae($product["name"]); ?>"><?php else: ?><i class="fa-solid fa-box"></i><?php endif; ?></div><div class="category-product-info"><strong><?php echo ae($product["name"]); ?></strong><span><?php echo ae($product["description"]); ?></span></div><div class="category-product-price">PKR <?php echo number_format((float)$product["price"],2); ?></div><div class="category-product-actions"><a href="products.php?id=<?php echo (int)$product["id"]; ?>" target="_blank" title="View"><i class="fa-solid fa-eye"></i></a><a href="dashboard.php?page=edit_product&id=<?php echo (int)$product["id"]; ?>" title="Edit"><i class="fa-solid fa-pen"></i></a><a href="dashboard.php?page=products&delete_product=<?php echo (int)$product["id"]; ?>" title="Delete" onclick="return confirm('Delete this product?');"><i class="fa-solid fa-trash"></i></a></div></div><?php endforeach; ?></div><?php else: ?><div class="category-empty-products">No products added to this category yet.</div><?php endif; ?></div>
                </article>
            <?php endforeach; ?></div><?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- ========================================================
         ADD / EDIT CATEGORY
    ========================================================= -->

    <?php if ($current_page === "add_category" || $current_page === "edit_category"): ?>
        <section class="workspace">
            <?php $editing_category=$current_page==="edit_category";$category_form=$editing_category?$edit_category:["id"=>"","name"=>"","description"=>"","price"=>"","parent_category"=>"","image"=>""]; ?>
            <div class="workspace-header"><div><h2><?php echo $editing_category?"Edit Category":"Add New Category"; ?></h2><p>Add category name, price, parent category, image and description. It appears automatically on categories.php.</p></div><a href="dashboard.php?page=categories" class="secondary-button"><i class="fa-solid fa-arrow-left"></i> Categories</a></div>
            <form class="form-card" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="form_type" value="<?php echo $editing_category?"update_category":"add_category"; ?>">
                <?php if($editing_category): ?><input type="hidden" name="id" value="<?php echo (int)$category_form["id"]; ?>"><?php endif; ?>
                <div class="form-grid">
                    <div class="form-group"><label>Category Name *</label><input type="text" name="name" value="<?php echo ae($category_form["name"]); ?>" required></div>
                    <div class="form-group"><label>Price *</label><input type="number" name="price" value="<?php echo ae($category_form["price"]); ?>" min="0" step="0.01" required></div>
                    <div class="form-group"><label>Which Category / Parent</label><select name="parent_category"><option value="">Independent Collection</option><?php foreach($store_categories as $store_cat): ?><option value="<?php echo ae($store_cat); ?>" <?php echo strcasecmp((string)$category_form["parent_category"],$store_cat)===0?'selected':''; ?>><?php echo ae($store_cat); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Category Image</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif"><?php if(!empty($category_form["image"])): ?><small class="file-note">Current: <?php echo ae($category_form["image"]); ?></small><?php endif; ?></div>
                    <div class="form-group full"><label>Description *</label><textarea name="description" required><?php echo ae($category_form["description"]); ?></textarea></div>
                </div>
                <div class="form-actions"><button type="submit" class="primary-button"><i class="fa-solid fa-floppy-disk"></i> <?php echo $editing_category?"Update Category":"Save Category"; ?></button><a href="dashboard.php?page=categories" class="secondary-button">Cancel</a></div>
            </form>
        </section>
    <?php endif; ?>

    <!-- ========================================================
         ORDERS
    ========================================================= -->

    <?php if ($current_page === "orders"): ?>

        <?php $filter = $_GET["status"] ?? "all"; ?>

        <section class="workspace">

            <div class="workspace-header">

                <div>
                    <h2>Order Command Center</h2>
                    <p>
                        Every checkout from the customer side is stored in XAMPP/MySQL here.
                    </p>
                </div>

                <a href="dashboard.php?page=orders" class="secondary-button">
                    <i class="fa-solid fa-rotate"></i>
                    Refresh
                </a>

            </div>

            <div class="filter-bar">

                <?php
                $filters = [
                    "all" => "All Orders",
                    "pending" => "Pending",
                    "confirmed" => "Confirmed",
                    "shipping" => "Shipping",
                    "delivered" => "Delivered",
                    "cancelled" => "Cancelled"
                ];
                ?>

                <?php foreach ($filters as $key => $label): ?>

                    <a
                        class="filter-link <?php echo $filter === $key ? "active" : ""; ?>"
                        href="dashboard.php?page=orders&status=<?php echo urlencode($key); ?>"
                    >
                        <?php echo ae($label); ?>
                    </a>

                <?php endforeach; ?>

            </div>

            <div class="panel">

                <?php if (!$orders): ?>

                    <div class="empty-state">
                        <i class="fa-solid fa-receipt"></i>
                        <h3>No Orders Yet</h3>
                        <p>When a customer checks out, the order will appear here automatically.</p>
                        <a href="index.php" class="primary-button">
                            <i class="fa-solid fa-store"></i>
                            Open Store
                        </a>
                    </div>

                <?php else: ?>

                    <div class="table-wrap">

                        <table class="data-table">

                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Customer</th>
                                    <th>Total</th>
                                    <th>Payment</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Handle</th>
                                </tr>
                            </thead>

                            <tbody>

                            <?php foreach ($orders as $order): ?>

                                <tr>

                                    <td>
                                        <a
                                            class="order-number"
                                            href="dashboard.php?page=orders&view=<?php echo (int)$order["id"]; ?>"
                                        >
                                            <?php echo ae($order["order_number"]); ?>
                                        </a>
                                    </td>

                                    <td class="order-customer">
                                        <strong><?php echo ae($order["customer_name"]); ?></strong>
                                        <span><?php echo ae($order["customer_phone"]); ?></span>
                                    </td>

                                    <td>
                                        <?php echo ae($currency); ?>
                                        <?php echo number_format((float)($order["total"] ?? 0), 2); ?>
                                    </td>

                                    <td><?php echo ae($order["payment_method"]); ?></td>

                                    <td>
                                        <?php
                                        echo ae(
                                            date(
                                                "d M Y, h:i A",
                                                strtotime($order["created_at"])
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <span class="status-pill <?php echo ae($order["status"]); ?>">
                                            <?php echo ae(ucfirst($order["status"])); ?>
                                        </span>
                                    </td>

                                    <td>

                                        <div class="action-row">

                                            <a
                                                href="dashboard.php?page=orders&view=<?php echo (int)$order["id"]; ?>"
                                                class="small-action"
                                            >
                                                <i class="fa-solid fa-eye"></i>
                                                View
                                            </a>

                                            <form
                                                method="POST"
                                                class="status-form"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="form_type"
                                                    value="update_order_status"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="order_id"
                                                    value="<?php echo (int)$order["id"]; ?>"
                                                >

                                                <select
                                                    name="status"
                                                    onchange="this.form.submit()"
                                                    title="Change order status"
                                                >

                                                    <?php foreach ($filters as $status_key => $status_label): ?>

                                                        <?php if ($status_key !== "all"): ?>

                                                            <option
                                                                value="<?php echo ae($status_key); ?>"
                                                                <?php echo $order["status"] === $status_key ? "selected" : ""; ?>
                                                            >
                                                                <?php echo ae($status_label); ?>
                                                            </option>

                                                        <?php endif; ?>

                                                    <?php endforeach; ?>

                                                </select>

                                            </form>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

            <?php if ($view_order): ?>

                <section class="panel order-detail">

                    <div class="order-detail-head">

                        <div>
                            <h3>
                                Order #<?php echo ae($view_order["order_number"]); ?>
                            </h3>

                            <p>
                                Full customer, product and delivery information.
                            </p>
                        </div>

                        <span class="status-pill <?php echo ae($view_order["status"]); ?>">
                            <?php echo ae(ucfirst($view_order["status"])); ?>
                        </span>

                    </div>

                    <div class="detail-grid">

                        <div class="detail-box">
                            <span>Customer</span>
                            <strong><?php echo ae($view_order["customer_name"]); ?></strong>
                        </div>

                        <div class="detail-box">
                            <span>Phone</span>
                            <strong><?php echo ae($view_order["customer_phone"]); ?></strong>
                        </div>

                        <div class="detail-box">
                            <span>Email</span>
                            <strong><?php echo ae($view_order["customer_email"]); ?></strong>
                        </div>

                        <div class="detail-box">
                            <span>Payment</span>
                            <strong><?php echo ae($view_order["payment_method"]); ?></strong>
                        </div>

                        <div class="detail-box">
                            <span>Subtotal</span>
                            <strong>
                                <?php echo ae($currency); ?>
                                <?php echo number_format((float)$view_order["subtotal"], 2); ?>
                            </strong>
                        </div>

                        <div class="detail-box">
                            <span>Delivery Fee</span>
                            <strong>
                                <?php echo ae($currency); ?>
                                <?php echo number_format((float)$view_order["delivery_fee"], 2); ?>
                            </strong>
                        </div>

                        <div class="detail-box full">
                            <span>Delivery Address</span>
                            <strong>
                                <?php echo nl2br(ae($view_order["customer_address"])); ?>
                            </strong>
                        </div>

                        <div class="detail-box">
                            <span>Order Date</span>
                            <strong>
                                <?php echo ae(date("d M Y", strtotime($view_order["created_at"]))); ?>
                            </strong>
                        </div>

                        <div class="detail-box">
                            <span>Order Time</span>
                            <strong>
                                <?php echo ae(date("h:i A", strtotime($view_order["created_at"]))); ?>
                            </strong>
                        </div>

                        <div class="detail-box">
                            <span>Total</span>
                            <strong>
                                <?php echo ae($currency); ?>
                                <?php echo number_format((float)$view_order["total"], 2); ?>
                            </strong>
                        </div>

                    </div>

                    <h4 style="color:var(--deep-maroon);margin-bottom:10px;">
                        Purchased Products
                    </h4>

                    <?php if ($view_items): ?>

                        <div class="table-wrap">

                            <table class="order-items">

                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Unit Price</th>
                                        <th>Quantity</th>
                                        <th>Line Total</th>
                                    </tr>
                                </thead>

                                <tbody>

                                <?php foreach ($view_items as $item): ?>

                                    <tr>
                                        <td><?php echo ae($item["product_name"]); ?></td>
                                        <td><?php echo ae($currency); ?> <?php echo number_format((float)$item["price"],2); ?></td>
                                        <td><?php echo (int)$item["quantity"]; ?></td>
                                        <td><?php echo ae($currency); ?> <?php echo number_format((float)$item["line_total"],2); ?></td>
                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php else: ?>

                        <p class="help-text">
                            This order has no saved item rows.
                        </p>

                    <?php endif; ?>

                    <div class="total-box">
                        <strong>
                            Total:
                            <?php echo ae($currency); ?>
                            <?php echo number_format((float)$view_order["total"],2); ?>
                        </strong>
                    </div>

                    <div class="form-actions">

                        <a
                            href="dashboard.php?page=orders"
                            class="secondary-button"
                        >
                            <i class="fa-solid fa-arrow-left"></i>
                            Back to Orders
                        </a>

                        <form
                            method="POST"
                            onsubmit="return confirm('Delete this order permanently?');"
                        >
                            <input
                                type="hidden"
                                name="form_type"
                                value="delete_order"
                            >

                            <input
                                type="hidden"
                                name="order_id"
                                value="<?php echo (int)$view_order["id"]; ?>"
                            >

                            <button type="submit" class="danger-button">
                                <i class="fa-solid fa-trash"></i>
                                Delete Order
                            </button>
                        </form>

                    </div>

                </section>

            <?php endif; ?>

        </section>

    <?php endif; ?>

    <!-- ========================================================
         CUSTOMERS
    ========================================================= -->

    <?php if ($current_page === "customers"): ?>

        <section class="workspace">

            <div class="workspace-header">

                <div>
                    <h2>Customers</h2>
                    <p>
                        Customer records are automatically calculated from real orders.
                    </p>
                </div>

                <a href="dashboard.php?page=orders" class="secondary-button">
                    <i class="fa-solid fa-receipt"></i>
                    View Orders
                </a>

            </div>

            <div class="customer-grid">

                <?php if (!$customers): ?>

                    <div class="panel empty-state" style="grid-column:1/-1;">
                        <i class="fa-solid fa-users"></i>
                        <h3>No Customers Yet</h3>
                        <p>When the first customer places an order, their information will appear here.</p>
                    </div>

                <?php else: ?>

                    <?php foreach ($customers as $customer): ?>

                        <article class="customer-card">

                            <div class="customer-avatar">
                                <?php echo strtoupper(substr($customer["customer_name"], 0, 1)); ?>
                            </div>

                            <h3><?php echo ae($customer["customer_name"]); ?></h3>

                            <p>
                                <i class="fa-solid fa-phone"></i>
                                <?php echo ae($customer["customer_phone"]); ?>
                                <br>

                                <?php if ($customer["customer_email"] !== ""): ?>
                                    <i class="fa-solid fa-envelope"></i>
                                    <?php echo ae($customer["customer_email"]); ?>
                                    <br>
                                <?php endif; ?>

                                <i class="fa-solid fa-location-dot"></i>
                                <?php echo ae($customer["customer_address"]); ?>
                            </p>

                            <div class="customer-stats">

                                <div class="customer-stat">
                                    <span>Orders</span>
                                    <strong><?php echo (int)$customer["total_orders"]; ?></strong>
                                </div>

                                <div class="customer-stat">
                                    <span>Total Spent</span>
                                    <strong>
                                        <?php echo ae($currency); ?>
                                        <?php echo number_format((float)$customer["total_spent"],0); ?>
                                    </strong>
                                </div>

                            </div>

                            <p style="margin-top:12px;">
                                Last order:
                                <?php echo ae(date("d M Y, h:i A", strtotime($customer["last_order"]))); ?>
                            </p>

                        </article>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </section>

    <?php endif; ?>

    <!-- ========================================================
         SETTINGS
    ========================================================= -->

    <?php if ($current_page === "settings"): ?>

        <section class="workspace">

            <div class="settings-nav">

                <a
                    href="dashboard.php?page=settings&section=general"
                    class="settings-tab <?php echo $settings_section === "general" ? "active" : ""; ?>"
                >
                    <i class="fa-solid fa-sliders"></i>
                    General Settings
                </a>

                <a
                    href="dashboard.php?page=settings&section=privacy"
                    class="settings-tab <?php echo $settings_section === "privacy" ? "active" : ""; ?>"
                >
                    <i class="fa-solid fa-shield-halved"></i>
                    Privacy & Legal
                </a>

                <a
                    href="dashboard.php?page=settings&section=help"
                    class="settings-tab <?php echo $settings_section === "help" ? "active" : ""; ?>"
                >
                    <i class="fa-solid fa-headset"></i>
                    Help & Support
                </a>

            </div>

            <div class="settings-note">
                <i class="fa-solid fa-circle-info"></i>
                Only the selected settings area is opened. Changes are saved in
                the <strong>store_settings</strong> table in your XAMPP database.
            </div>

            <?php if ($settings_section === "general"): ?>

                <form method="POST" class="form-card">

                    <input
                        type="hidden"
                        name="form_type"
                        value="general_settings"
                    >

                    <div class="workspace-header">
                        <div>
                            <h2>General Settings</h2>
                            <p>Control your store identity and basic checkout values.</p>
                        </div>
                    </div>

                    <div class="form-grid">

                        <div class="form-group">
                            <label>Store Name</label>
                            <input
                                type="text"
                                name="store_name"
                                value="<?php echo ae($settings["store_name"]); ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Tagline</label>
                            <input
                                type="text"
                                name="tagline"
                                value="<?php echo ae($settings["tagline"]); ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Store Email</label>
                            <input
                                type="email"
                                name="store_email"
                                value="<?php echo ae($settings["store_email"]); ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Store Phone</label>
                            <input
                                type="text"
                                name="store_phone"
                                value="<?php echo ae($settings["store_phone"]); ?>"
                            >
                        </div>

                        <div class="form-group full">
                            <label>Store Address</label>
                            <input
                                type="text"
                                name="store_address"
                                value="<?php echo ae($settings["store_address"]); ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Currency</label>
                            <input
                                type="text"
                                name="currency"
                                value="<?php echo ae($settings["currency"]); ?>"
                                placeholder="PKR"
                            >
                        </div>

                        <div class="form-group">
                            <label>Default Delivery Fee</label>
                            <input
                                type="number"
                                name="delivery_fee"
                                value="<?php echo ae($settings["delivery_fee"]); ?>"
                                min="0"
                                step="1"
                            >
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="submit" class="primary-button">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Save General Settings
                        </button>
                    </div>

                </form>

            <?php endif; ?>

            <?php if ($settings_section === "privacy"): ?>

                <form method="POST" class="form-card">

                    <input
                        type="hidden"
                        name="form_type"
                        value="privacy_settings"
                    >

                    <div class="workspace-header">
                        <div>
                            <h2>Privacy & Legal</h2>
                            <p>Keep your customer-facing legal text organized.</p>
                        </div>
                    </div>

                    <div class="form-grid">

                        <div class="form-group full">
                            <label>Privacy Policy</label>
                            <textarea name="privacy_policy"><?php echo ae($settings["privacy_policy"]); ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label>Terms & Conditions</label>
                            <textarea name="terms_conditions"><?php echo ae($settings["terms_conditions"]); ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label>Cookie Notice</label>
                            <textarea name="cookie_notice"><?php echo ae($settings["cookie_notice"]); ?></textarea>
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="submit" class="primary-button">
                            <i class="fa-solid fa-shield-halved"></i>
                            Save Privacy & Legal
                        </button>

                        <a href="privacy-policy.php" target="_blank" class="secondary-button">
                            <i class="fa-solid fa-eye"></i>
                            Open Public Page
                        </a>
                    </div>

                </form>

            <?php endif; ?>

            <?php if ($settings_section === "help"): ?>

                <form method="POST" class="form-card">

                    <input
                        type="hidden"
                        name="form_type"
                        value="help_settings"
                    >

                    <div class="workspace-header">
                        <div>
                            <h2>Help & Support</h2>
                            <p>Set the support information customers can use.</p>
                        </div>
                    </div>

                    <div class="form-grid">

                        <div class="form-group">
                            <label>Support Email</label>
                            <input
                                type="email"
                                name="support_email"
                                value="<?php echo ae($settings["support_email"]); ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Support Phone</label>
                            <input
                                type="text"
                                name="support_phone"
                                value="<?php echo ae($settings["support_phone"]); ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>WhatsApp Number</label>
                            <input
                                type="text"
                                name="support_whatsapp"
                                value="<?php echo ae($settings["support_whatsapp"]); ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Support Hours</label>
                            <input
                                type="text"
                                name="support_hours"
                                value="<?php echo ae($settings["support_hours"]); ?>"
                            >
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="submit" class="primary-button">
                            <i class="fa-solid fa-headset"></i>
                            Save Help & Support
                        </button>

                        <a href="contact.php" target="_blank" class="secondary-button">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            Open Contact Page
                        </a>
                    </div>

                </form>

            <?php endif; ?>

        </section>

    <?php endif; ?>

</main>

</div>

<script>
/* ============================================================
   SIDEBAR DROPDOWNS
============================================================ */

document.querySelectorAll(".sidebar-dropdown-title").forEach(function(title){

    title.addEventListener("click", function(){

        const dropdown = title.closest(".sidebar-dropdown");

        document.querySelectorAll(".sidebar-dropdown").forEach(function(item){

            if(item !== dropdown){
                item.classList.remove("open");
            }

        });

        dropdown.classList.toggle("open");

    });

});

/* ============================================================
   MOBILE SIDEBAR
============================================================ */

const mobileButton = document.getElementById("mobileMenuButton");
const sidebar = document.getElementById("sidebar");

if(mobileButton && sidebar){

    mobileButton.addEventListener("click", function(){

        sidebar.classList.toggle("open");

        const icon = mobileButton.querySelector("i");

        if(sidebar.classList.contains("open")){

            icon.classList.remove("fa-bars");
            icon.classList.add("fa-xmark");

        }else{

            icon.classList.remove("fa-xmark");
            icon.classList.add("fa-bars");

        }

    });

}

/* ============================================================
   AUTO-HIDE FLASH
============================================================ */

setTimeout(function(){

    const flash = document.querySelector(".flash");

    if(flash){

        flash.style.transition = "opacity .5s ease, transform .5s ease";
        flash.style.opacity = "0";
        flash.style.transform = "translateY(-8px)";

    }

}, 4200);
</script>

</body>
</html>
