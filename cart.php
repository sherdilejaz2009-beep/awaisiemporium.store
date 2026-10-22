<!-- <?php
/* ============================================================
   AWAISI EMPORIUM — PREMIUM CART + CHECKOUT + RECEIPT
   ------------------------------------------------------------
   CHANGE HERE:
   1. Store name       -> $store_name
   2. Currency         -> $currency
   3. Phone / email    -> $store_phone / $store_email
   4. Delivery fee     -> $delivery_fee
   ============================================================ */

session_start();
require_once "connection.php";

/* ============================================================
   STORE SETTINGS — EASY TO CHANGE
   ============================================================ */
$store_name  = "awaisiempo";
$currency    = "PKR";
$store_phone = "+92 300 0000000";
$store_email = "support@awaisiemporium.com";
$delivery_fee = 0; // Change to your delivery charge, e.g. 250

/* ============================================================
   HELPERS
   ============================================================ */
function ae_cart_escape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function ae_cart_image($image)
{
    $image = trim((string)$image);

    if ($image === "") {
        return "";
    }

    if (
        str_starts_with($image, "http://") ||
        str_starts_with($image, "https://")
    ) {
        return $image;
    }

    if (str_starts_with($image, "uploads/")) {
        return $image;
    }

    return "uploads/products/" . $image;
}

function ae_cart_money($amount)
{
    return number_format((float)$amount, 2);
}

/* ============================================================
   CART INITIALIZATION
   ============================================================ */
if (!isset($_SESSION["ae_cart"]) || !is_array($_SESSION["ae_cart"])) {
    $_SESSION["ae_cart"] = [];
}

/* ============================================================
   CART ACTIONS
   ============================================================ */

/* Add a product directly from another page if needed. */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_to_cart"])) {

    $product_id = (int)($_POST["product_id"] ?? 0);

    if ($product_id > 0) {

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, name, description, price, category, image
             FROM products
             WHERE id = ?
             LIMIT 1"
        );

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $product_id);
            mysqli_stmt_execute($stmt);

            $result = mysqli_stmt_get_result($stmt);
            $product = mysqli_fetch_assoc($result);

            if ($product) {

                if (isset($_SESSION["ae_cart"][$product_id])) {
                    $_SESSION["ae_cart"][$product_id]["quantity"]++;
                } else {
                    $_SESSION["ae_cart"][$product_id] = [
                        "id"       => (int)$product["id"],
                        "name"     => $product["name"],
                        "price"    => (float)$product["price"],
                        "category" => $product["category"],
                        "image"    => $product["image"],
                        "quantity" => 1
                    ];
                }

                $_SESSION["ae_cart_message"] =
                    $product["name"] . " added to your cart.";
            }

            mysqli_stmt_close($stmt);
        }
    }

    header("Location: cart.php");
    exit;
}

/* Update quantities. */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_cart"])) {

    $quantities = $_POST["quantity"] ?? [];

    if (is_array($quantities)) {
        foreach ($quantities as $product_id => $quantity) {

            $product_id = (int)$product_id;
            $quantity   = (int)$quantity;

            if ($product_id <= 0) {
                continue;
            }

            if ($quantity <= 0) {
                unset($_SESSION["ae_cart"][$product_id]);
                continue;
            }

            if (isset($_SESSION["ae_cart"][$product_id])) {
                $_SESSION["ae_cart"][$product_id]["quantity"] = min($quantity, 99);
            }
        }
    }

    $_SESSION["ae_cart_message"] = "Cart updated successfully.";

    header("Location: cart.php");
    exit;
}

/* Remove one product. */
if (isset($_GET["remove"])) {

    $remove_id = (int)$_GET["remove"];

    if ($remove_id > 0) {
        unset($_SESSION["ae_cart"][$remove_id]);
    }

    header("Location: cart.php");
    exit;
}

/* Clear the complete cart. */
if (isset($_GET["clear"]) && $_GET["clear"] === "1") {

    $_SESSION["ae_cart"] = [];
    $_SESSION["ae_cart_message"] = "Your cart is now empty.";

    header("Location: cart.php");
    exit;
}

/* ============================================================
   CREATE ORDER TABLES IF THEY DO NOT EXIST
   ============================================================ */
mysqli_query(
    $conn,
    "CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_number VARCHAR(50) NOT NULL UNIQUE,
        customer_name VARCHAR(150) NOT NULL,
        customer_email VARCHAR(150) DEFAULT '',
        customer_phone VARCHAR(50) NOT NULL,
        customer_address TEXT NOT NULL,
        payment_method VARCHAR(50) NOT NULL DEFAULT 'Cash on Delivery',
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
        delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

mysqli_query(
    $conn,
    "CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        product_name VARCHAR(150) NOT NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        quantity INT NOT NULL DEFAULT 1,
        line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (order_id),
        INDEX (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

/* ============================================================
   CHECKOUT
   ============================================================ */
$checkout_error = "";
$receipt = $_SESSION["ae_receipt"] ?? null;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["place_order"])) {

    $customer_name    = trim($_POST["customer_name"] ?? "");
    $customer_email   = trim($_POST["customer_email"] ?? "");
    $customer_phone   = trim($_POST["customer_phone"] ?? "");
    $customer_address = trim($_POST["customer_address"] ?? "");
    $payment_method   = trim($_POST["payment_method"] ?? "Cash on Delivery");

    if (empty($_SESSION["ae_cart"])) {
        $checkout_error = "Your cart is empty. Please add a product first.";
    } elseif ($customer_name === "" || $customer_phone === "" || $customer_address === "") {
        $checkout_error = "Please fill in your name, phone number and delivery address.";
    } elseif ($customer_email !== "" && !filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
        $checkout_error = "Please enter a valid email address.";
    } else {

        /* Calculate totals from the session cart. */
        $subtotal = 0;

        foreach ($_SESSION["ae_cart"] as $item) {
            $quantity = max(1, (int)($item["quantity"] ?? 1));
            $price    = (float)($item["price"] ?? 0);
            $subtotal += $price * $quantity;
        }

        $total = $subtotal + (float)$delivery_fee;

        /* Unique receipt/order number. */
        $order_number = "AE-" . date("YmdHis") . "-" . random_int(100, 999);

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO orders
            (order_number, customer_name, customer_email, customer_phone,
             customer_address, payment_method, subtotal, delivery_fee, total, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        );

        if ($stmt) {

            mysqli_stmt_bind_param(
                $stmt,
                "ssssssddd",
                $order_number,
                $customer_name,
                $customer_email,
                $customer_phone,
                $customer_address,
                $payment_method,
                $subtotal,
                $delivery_fee,
                $total
            );

            if (mysqli_stmt_execute($stmt)) {

                $order_id = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                /* Save every purchased item. */
                $item_stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO order_items
                    (order_id, product_id, product_name, price, quantity, line_total)
                    VALUES (?, ?, ?, ?, ?, ?)"
                );

                foreach ($_SESSION["ae_cart"] as $item) {

                    $product_id = (int)($item["id"] ?? 0);
                    $name       = (string)($item["name"] ?? "Product");
                    $price      = (float)($item["price"] ?? 0);
                    $quantity   = max(1, (int)($item["quantity"] ?? 1));
                    $line_total = $price * $quantity;

                    if ($item_stmt) {
                        mysqli_stmt_bind_param(
                            $item_stmt,
                            "iisdid",
                            $order_id,
                            $product_id,
                            $name,
                            $price,
                            $quantity,
                            $line_total
                        );
                        mysqli_stmt_execute($item_stmt);
                    }
                }

                if ($item_stmt) {
                    mysqli_stmt_close($item_stmt);
                }

                /* Get exact database timestamp for the receipt. */
                $saved_order = null;

                $receipt_stmt = mysqli_prepare(
                    $conn,
                    "SELECT id, order_number, customer_name, customer_email,
                            customer_phone, customer_address, payment_method,
                            subtotal, delivery_fee, total, status, created_at
                     FROM orders
                     WHERE id = ?
                     LIMIT 1"
                );

                if ($receipt_stmt) {
                    mysqli_stmt_bind_param($receipt_stmt, "i", $order_id);
                    mysqli_stmt_execute($receipt_stmt);
                    $receipt_result = mysqli_stmt_get_result($receipt_stmt);
                    $saved_order = mysqli_fetch_assoc($receipt_result);
                    mysqli_stmt_close($receipt_stmt);
                }

                $receipt_items = [];

                $receipt_items_stmt = mysqli_prepare(
                    $conn,
                    "SELECT product_name, price, quantity, line_total
                     FROM order_items
                     WHERE order_id = ?
                     ORDER BY id ASC"
                );

                if ($receipt_items_stmt) {
                    mysqli_stmt_bind_param($receipt_items_stmt, "i", $order_id);
                    mysqli_stmt_execute($receipt_items_stmt);
                    $receipt_items_result = mysqli_stmt_get_result($receipt_items_stmt);

                    while ($row = mysqli_fetch_assoc($receipt_items_result)) {
                        $receipt_items[] = $row;
                    }

                    mysqli_stmt_close($receipt_items_stmt);
                }

                $_SESSION["ae_receipt"] = [
                    "order" => $saved_order,
                    "items" => $receipt_items
                ];

                $_SESSION["ae_cart"] = [];
                $_SESSION["ae_cart_message"] = "Order placed successfully.";

                header("Location: cart.php?receipt=1");
                exit;

            } else {
                $checkout_error = "Order could not be placed. Please try again.";
                mysqli_stmt_close($stmt);
            }

        } else {
            $checkout_error = "Database is not ready for orders. Please check your connection.php file.";
        }
    }
}

/* ============================================================
   CURRENT CART TOTALS
   ============================================================ */
$cart_items = $_SESSION["ae_cart"];
$cart_count = 0;
$subtotal = 0;

foreach ($cart_items as $item) {
    $quantity = max(1, (int)($item["quantity"] ?? 1));
    $price    = (float)($item["price"] ?? 0);

    $cart_count += $quantity;
    $subtotal += $price * $quantity;
}

$grand_total = $subtotal + (float)$delivery_fee;

$cart_message = $_SESSION["ae_cart_message"] ?? "";
unset($_SESSION["ae_cart_message"]);

$show_receipt = isset($_GET["receipt"]) && $_GET["receipt"] === "1" && !empty($receipt);

?> -->

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Cart & Checkout | Awaisi Emporium</title>

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
        /* ====================================================
           PREMIUM CART THEME
        ==================================================== */
        :root {
            --ae-maroon: #6e1830;
            --ae-deep-maroon: #42101f;
            --ae-champagne: #d8b56a;
            --ae-champagne-soft: #f0dca8;
            --ae-ivory: #fffdf8;
            --ae-cream: #f7eee2;
            --ae-text: #321923;
            --ae-muted: #806a70;
            --ae-border: #eadbd0;
            --ae-white: #ffffff;
            --ae-shadow: 0 20px 55px rgba(66, 16, 31, 0.10);
            --ae-radius: 24px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: "DM Sans", sans-serif;
            background:
                radial-gradient(circle at 8% 10%, rgba(216, 181, 106, 0.13), transparent 25%),
                radial-gradient(circle at 92% 18%, rgba(110, 24, 48, 0.10), transparent 28%),
                var(--ae-ivory);
            color: var(--ae-text);
            min-height: 100vh;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input,
        textarea,
        select {
            font: inherit;
        }

        button {
            cursor: pointer;
        }

        .AEC_container {
            width: min(1180px, calc(100% - 34px));
            margin: 0 auto;
        }

        /* ====================================================
           HERO
        ==================================================== */
        .AEC_hero {
            position: relative;
            overflow: hidden;
            padding: 72px 0 52px;
            background:
                linear-gradient(135deg, rgba(66, 16, 31, 0.98), rgba(110, 24, 48, 0.94));
            color: var(--ae-white);
        }

        .AEC_hero::before {
            content: "";
            position: absolute;
            width: 360px;
            height: 360px;
            border-radius: 50%;
            right: -130px;
            top: -180px;
            background: rgba(216, 181, 106, 0.18);
        }

        .AEC_hero::after {
            content: "";
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            left: -100px;
            bottom: -130px;
            background: rgba(255, 255, 255, 0.06);
        }

        .AEC_hero_inner {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 30px;
        }

        .AEC_kicker {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            color: var(--ae-champagne-soft);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        .AEC_hero h1 {
            font-family: "Playfair Display", serif;
            font-size: clamp(42px, 6vw, 72px);
            line-height: 0.98;
            margin-bottom: 18px;
        }

        .AEC_hero p {
            max-width: 660px;
            color: rgba(255,255,255,0.78);
            line-height: 1.8;
        }

        .AEC_cart_pill {
            min-width: 145px;
            padding: 18px 20px;
            border: 1px solid rgba(216, 181, 106, 0.42);
            background: rgba(255,255,255,0.07);
            border-radius: 20px;
            backdrop-filter: blur(12px);
            text-align: center;
        }

        .AEC_cart_pill i {
            color: var(--ae-champagne-soft);
            font-size: 24px;
            margin-bottom: 8px;
        }

        .AEC_cart_pill strong,
        .AEC_cart_pill span {
            display: block;
        }

        .AEC_cart_pill strong {
            font-size: 22px;
        }

        .AEC_cart_pill span {
            margin-top: 3px;
            font-size: 12px;
            color: rgba(255,255,255,0.68);
        }

        /* ====================================================
           MESSAGES
        ==================================================== */
        .AEC_message,
        .AEC_error {
            margin: 28px auto 0;
            width: min(1180px, calc(100% - 34px));
            padding: 15px 18px;
            border-radius: 15px;
            font-weight: 600;
        }

        .AEC_message {
            background: #f1f8f2;
            border: 1px solid #cfe6d1;
            color: #315c38;
        }

        .AEC_error {
            background: #fff1f1;
            border: 1px solid #efcaca;
            color: #8c2d38;
        }

        /* ====================================================
           MAIN LAYOUT
        ==================================================== */
        .AEC_main {
            padding: 44px 0 85px;
        }

        .AEC_layout {
            display: grid;
            grid-template-columns: minmax(0, 1.55fr) minmax(320px, 0.85fr);
            gap: 26px;
            align-items: start;
        }

        .AEC_panel {
            background: rgba(255,255,255,0.92);
            border: 1px solid var(--ae-border);
            border-radius: var(--ae-radius);
            box-shadow: var(--ae-shadow);
            overflow: hidden;
        }

        .AEC_panel_header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 24px 26px;
            border-bottom: 1px solid var(--ae-border);
        }

        .AEC_panel_header h2 {
            font-family: "Playfair Display", serif;
            font-size: 26px;
        }

        .AEC_panel_header span {
            color: var(--ae-muted);
            font-size: 13px;
        }

        /* ====================================================
           CART ITEMS
        ==================================================== */
        .AEC_items {
            padding: 10px 26px 26px;
        }

        .AEC_item {
            display: grid;
            grid-template-columns: 105px minmax(0, 1fr) auto;
            gap: 18px;
            align-items: center;
            padding: 20px 0;
            border-bottom: 1px solid var(--ae-border);
        }

        .AEC_item:last-child {
            border-bottom: 0;
        }

        .AEC_item_image {
            width: 105px;
            height: 105px;
            border-radius: 18px;
            overflow: hidden;
            background: var(--ae-cream);
            border: 1px solid var(--ae-border);
            display: grid;
            place-items: center;
            color: var(--ae-maroon);
        }

        .AEC_item_image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .AEC_item_category {
            display: block;
            color: var(--ae-maroon);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            margin-bottom: 7px;
        }

        .AEC_item h3 {
            font-family: "Playfair Display", serif;
            font-size: 22px;
            margin-bottom: 7px;
        }

        .AEC_item_price {
            color: var(--ae-muted);
            font-size: 13px;
        }

        .AEC_item_right {
            text-align: right;
        }

        .AEC_line_total {
            display: block;
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 12px;
        }

        .AEC_quantity {
            display: inline-flex;
            align-items: center;
            border: 1px solid var(--ae-border);
            border-radius: 12px;
            overflow: hidden;
            background: var(--ae-ivory);
        }

        .AEC_quantity input {
            width: 50px;
            padding: 9px 4px;
            border: 0;
            outline: 0;
            text-align: center;
            color: var(--ae-text);
            background: transparent;
        }

        .AEC_quantity button {
            width: 35px;
            height: 38px;
            border: 0;
            background: transparent;
            color: var(--ae-maroon);
        }

        .AEC_remove {
            display: inline-flex;
            margin-top: 9px;
            color: #9a5260;
            font-size: 12px;
            font-weight: 700;
        }

        .AEC_empty {
            padding: 70px 25px;
            text-align: center;
        }

        .AEC_empty_icon {
            width: 78px;
            height: 78px;
            margin: 0 auto 18px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--ae-cream);
            color: var(--ae-maroon);
            font-size: 28px;
        }

        .AEC_empty h3 {
            font-family: "Playfair Display", serif;
            font-size: 28px;
            margin-bottom: 9px;
        }

        .AEC_empty p {
            color: var(--ae-muted);
            margin-bottom: 22px;
        }

        .AEC_btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 9px;
            min-height: 46px;
            padding: 0 18px;
            border: 0;
            border-radius: 13px;
            background: var(--ae-maroon);
            color: var(--ae-white);
            font-weight: 700;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .AEC_btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 25px rgba(110,24,48,0.18);
        }

        .AEC_btn_secondary {
            background: var(--ae-cream);
            color: var(--ae-maroon);
        }

        /* ====================================================
           SUMMARY
        ==================================================== */
        .AEC_summary {
            position: sticky;
            top: 24px;
        }

        .AEC_summary_body {
            padding: 25px;
        }

        .AEC_total_line {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 11px 0;
            color: var(--ae-muted);
        }

        .AEC_total_line strong {
            color: var(--ae-text);
        }

        .AEC_grand {
            margin-top: 10px;
            padding-top: 18px;
            border-top: 1px dashed var(--ae-border);
            font-size: 22px;
            color: var(--ae-maroon);
        }

        .AEC_summary .AEC_btn {
            width: 100%;
            margin-top: 16px;
        }

        .AEC_trust {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 9px;
            margin-top: 18px;
        }

        .AEC_trust div {
            padding: 12px 7px;
            border-radius: 13px;
            background: var(--ae-cream);
            text-align: center;
        }

        .AEC_trust i {
            display: block;
            color: var(--ae-maroon);
            margin-bottom: 5px;
        }

        .AEC_trust span {
            display: block;
            color: var(--ae-muted);
            font-size: 10px;
            line-height: 1.35;
        }

        /* ====================================================
           CHECKOUT
        ==================================================== */
        .AEC_checkout {
            margin-top: 26px;
        }

        .AEC_form {
            padding: 25px;
        }

        .AEC_form_grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .AEC_field.full {
            grid-column: 1 / -1;
        }

        .AEC_field label {
            display: block;
            margin-bottom: 7px;
            font-size: 12px;
            font-weight: 700;
            color: var(--ae-muted);
        }

        .AEC_field input,
        .AEC_field textarea,
        .AEC_field select {
            width: 100%;
            border: 1px solid var(--ae-border);
            border-radius: 13px;
            outline: none;
            padding: 13px 14px;
            background: var(--ae-ivory);
            color: var(--ae-text);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .AEC_field textarea {
            min-height: 105px;
            resize: vertical;
        }

        .AEC_field input:focus,
        .AEC_field textarea:focus,
        .AEC_field select:focus {
            border-color: var(--ae-champagne);
            box-shadow: 0 0 0 4px rgba(216,181,106,0.12);
        }

        .AEC_checkout_note {
            margin-top: 15px;
            padding: 13px 14px;
            border-radius: 13px;
            background: var(--ae-cream);
            color: var(--ae-muted);
            font-size: 12px;
            line-height: 1.6;
        }

        /* ====================================================
           RECEIPT MODAL / PRINT CHIT
        ==================================================== */
        .AEC_receipt_overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 25px;
            background: rgba(50,25,35,0.68);
            backdrop-filter: blur(9px);
            overflow-y: auto;
        }

        .AEC_receipt_window {
            width: min(570px, 100%);
            animation: AEC_receipt_in 0.28s ease;
        }

        @keyframes AEC_receipt_in {
            from {
                opacity: 0;
                transform: translateY(18px) scale(0.98);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .AEC_receipt_actions {
            display: flex;
            justify-content: flex-end;
            gap: 9px;
            margin-bottom: 12px;
        }

        .AEC_receipt {
            position: relative;
            background: #fff;
            color: #222;
            padding: 34px;
            border-radius: 8px;
            box-shadow: 0 30px 80px rgba(0,0,0,0.24);
        }

        .AEC_receipt::after {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            bottom: -10px;
            height: 20px;
            background:
                linear-gradient(135deg, transparent 7px, #fff 0) 0 0 / 14px 20px repeat-x;
        }

        .AEC_receipt_brand {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 1px dashed #bbb;
        }

        .AEC_receipt_brand .ae_mark {
            width: 52px;
            height: 52px;
            margin: 0 auto 9px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: var(--ae-maroon);
            color: var(--ae-champagne-soft);
            font-weight: 800;
        }

        .AEC_receipt_brand h2 {
            font-family: "Playfair Display", serif;
            font-size: 27px;
        }

        .AEC_receipt_brand p {
            color: #777;
            font-size: 11px;
            margin-top: 4px;
        }

        .AEC_receipt_meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            padding: 17px 0;
            border-bottom: 1px dashed #bbb;
            font-size: 12px;
        }

        .AEC_receipt_meta strong,
        .AEC_receipt_meta span {
            display: block;
        }

        .AEC_receipt_meta span {
            color: #777;
            margin-bottom: 3px;
        }

        .AEC_receipt_table {
            width: 100%;
            border-collapse: collapse;
            margin: 18px 0;
            font-size: 12px;
        }

        .AEC_receipt_table th,
        .AEC_receipt_table td {
            padding: 9px 3px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        .AEC_receipt_table th:last-child,
        .AEC_receipt_table td:last-child {
            text-align: right;
        }

        .AEC_receipt_totals {
            border-top: 1px dashed #bbb;
            padding-top: 10px;
        }

        .AEC_receipt_total_line {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 12px;
        }

        .AEC_receipt_total_line.grand {
            font-size: 18px;
            font-weight: 800;
            color: var(--ae-maroon);
            padding-top: 11px;
        }

        .AEC_receipt_customer {
            margin-top: 18px;
            padding: 13px;
            border-radius: 8px;
            background: #faf6f0;
            font-size: 11px;
            line-height: 1.7;
        }

        .AEC_receipt_footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 17px;
            border-top: 1px dashed #bbb;
            color: #777;
            font-size: 11px;
            line-height: 1.6;
        }

        /* ====================================================
           RESPONSIVE
        ==================================================== */
        @media (max-width: 900px) {
            .AEC_layout {
                grid-template-columns: 1fr;
            }

            .AEC_summary {
                position: static;
            }
        }

        @media (max-width: 680px) {
            .AEC_hero {
                padding: 54px 0 42px;
            }

            .AEC_hero_inner {
                display: block;
            }

            .AEC_cart_pill {
                margin-top: 25px;
                width: 150px;
            }

            .AEC_item {
                grid-template-columns: 78px minmax(0, 1fr);
            }

            .AEC_item_image {
                width: 78px;
                height: 78px;
            }

            .AEC_item_right {
                grid-column: 2;
                text-align: left;
            }

            .AEC_form_grid {
                grid-template-columns: 1fr;
            }

            .AEC_field.full {
                grid-column: auto;
            }

            .AEC_receipt {
                padding: 23px 18px;
            }

            .AEC_receipt_meta {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 450px) {
            .AEC_container,
            .AEC_message,
            .AEC_error {
                width: min(100% - 22px, 1180px);
            }

            .AEC_panel_header,
            .AEC_items,
            .AEC_form,
            .AEC_summary_body {
                padding-left: 16px;
                padding-right: 16px;
            }

            .AEC_trust {
                grid-template-columns: 1fr;
            }

            .AEC_receipt_overlay {
                padding: 10px;
            }
        }

        /* ====================================================
           PRINT RECEIPT ONLY
        ==================================================== */
        @media print {
            body * {
                visibility: hidden !important;
            }

            .AEC_receipt_overlay,
            .AEC_receipt_overlay * {
                visibility: visible !important;
            }

            .AEC_receipt_overlay {
                position: static;
                display: block;
                padding: 0;
                background: #fff;
                overflow: visible;
            }

            .AEC_receipt_window {
                width: 100%;
            }

            .AEC_receipt_actions {
                display: none !important;
            }

            .AEC_receipt {
                width: 80mm;
                margin: 0 auto;
                padding: 7mm;
                box-shadow: none;
                border-radius: 0;
            }

            .AEC_receipt::after {
                display: none;
            }
        }
    </style>
</head>

<body>

<?php include "header.php"; ?>

<!-- ========================================================
     PREMIUM CART HERO
========================================================= -->
<section class="AEC_hero">
    <div class="AEC_container AEC_hero_inner">

        <div>
            <span class="AEC_kicker">
                <i class="fa-solid fa-bag-shopping"></i>
                Your Private Shopping Bag
            </span>

            <h1>Your Cart</h1>

            <p>
                Review your selected products, confirm your details,
                place your order and receive a clean printable receipt.
            </p>
        </div>

        <div class="AEC_cart_pill">
            <i class="fa-solid fa-basket-shopping"></i>
            <strong><?php echo $cart_count; ?></strong>
            <span>Item<?php echo $cart_count === 1 ? "" : "s"; ?> in bag</span>
        </div>

    </div>
</section>

<?php if ($cart_message !== ""): ?>
    <div class="AEC_message">
        <i class="fa-solid fa-circle-check"></i>
        <?php echo ae_cart_escape($cart_message); ?>
    </div>
<?php endif; ?>

<?php if ($checkout_error !== ""): ?>
    <div class="AEC_error">
        <i class="fa-solid fa-circle-exclamation"></i>
        <?php echo ae_cart_escape($checkout_error); ?>
    </div>
<?php endif; ?>

<main class="AEC_main">
    <div class="AEC_container">

        <?php if (!empty($cart_items)): ?>

            <div class="AEC_layout">

                <!-- CART -->
                <section class="AEC_panel">

                    <div class="AEC_panel_header">
                        <div>
                            <h2>Selected Items</h2>
                            <span><?php echo $cart_count; ?> item(s) ready for checkout</span>
                        </div>

                        <a href="cart.php?clear=1" class="AEC_remove">
                            <i class="fa-solid fa-trash-can"></i>
                            Clear cart
                        </a>
                    </div>

                    <form method="post">

                        <div class="AEC_items">

                            <?php foreach ($cart_items as $item): ?>

                                <?php
                                $item_id = (int)($item["id"] ?? 0);
                                $item_name = (string)($item["name"] ?? "Product");
                                $item_category = (string)($item["category"] ?? "Collection");
                                $item_price = (float)($item["price"] ?? 0);
                                $item_quantity = max(1, (int)($item["quantity"] ?? 1));
                                $item_image = ae_cart_image($item["image"] ?? "");
                                $item_total = $item_price * $item_quantity;
                                ?>

                                <article class="AEC_item">

                                    <div class="AEC_item_image">
                                        <?php if ($item_image !== ""): ?>
                                            <img
                                                src="<?php echo ae_cart_escape($item_image); ?>"
                                                alt="<?php echo ae_cart_escape($item_name); ?>"
                                            >
                                        <?php else: ?>
                                            <i class="fa-solid fa-box-open"></i>
                                        <?php endif; ?>
                                    </div>

                                    <div>
                                        <span class="AEC_item_category">
                                            <?php echo ae_cart_escape($item_category); ?>
                                        </span>

                                        <h3>
                                            <?php echo ae_cart_escape($item_name); ?>
                                        </h3>

                                        <span class="AEC_item_price">
                                            <?php echo $currency; ?> <?php echo ae_cart_money($item_price); ?> each
                                        </span>
                                    </div>

                                    <div class="AEC_item_right">
                                        <strong class="AEC_line_total">
                                            <?php echo $currency; ?> <?php echo ae_cart_money($item_total); ?>
                                        </strong>

                                        <div class="AEC_quantity">
                                            <button
                                                type="button"
                                                onclick="changeQuantity(<?php echo $item_id; ?>, -1)"
                                                aria-label="Decrease quantity"
                                            >
                                                <i class="fa-solid fa-minus"></i>
                                            </button>

                                            <input
                                                type="number"
                                                min="1"
                                                max="99"
                                                name="quantity[<?php echo $item_id; ?>]"
                                                id="quantity_<?php echo $item_id; ?>"
                                                value="<?php echo $item_quantity; ?>"
                                            >

                                            <button
                                                type="button"
                                                onclick="changeQuantity(<?php echo $item_id; ?>, 1)"
                                                aria-label="Increase quantity"
                                            >
                                                <i class="fa-solid fa-plus"></i>
                                            </button>
                                        </div>

                                        <a
                                            href="cart.php?remove=<?php echo $item_id; ?>"
                                            class="AEC_remove"
                                        >
                                            Remove
                                        </a>
                                    </div>

                                </article>

                            <?php endforeach; ?>

                            <button type="submit" name="update_cart" class="AEC_btn AEC_btn_secondary">
                                <i class="fa-solid fa-rotate"></i>
                                Update Cart
                            </button>

                        </div>

                    </form>

                </section>

                <!-- SUMMARY -->
                <aside class="AEC_panel AEC_summary">

                    <div class="AEC_panel_header">
                        <div>
                            <h2>Order Summary</h2>
                            <span>Before final confirmation</span>
                        </div>
                    </div>

                    <div class="AEC_summary_body">

                        <div class="AEC_total_line">
                            <span>Subtotal</span>
                            <strong><?php echo $currency; ?> <?php echo ae_cart_money($subtotal); ?></strong>
                        </div>

                        <div class="AEC_total_line">
                            <span>Delivery</span>
                            <strong>
                                <?php echo $delivery_fee > 0 ? $currency . " " . ae_cart_money($delivery_fee) : "FREE"; ?>
                            </strong>
                        </div>

                        <div class="AEC_total_line AEC_grand">
                            <span>Total</span>
                            <strong><?php echo $currency; ?> <?php echo ae_cart_money($grand_total); ?></strong>
                        </div>

                        <a href="#checkout" class="AEC_btn">
                            <i class="fa-solid fa-lock"></i>
                            Continue to Checkout
                        </a>

                        <div class="AEC_trust">
                            <div>
                                <i class="fa-solid fa-shield-halved"></i>
                                <span>Secure Order</span>
                            </div>
                            <div>
                                <i class="fa-solid fa-receipt"></i>
                                <span>Instant Receipt</span>
                            </div>
                            <div>
                                <i class="fa-solid fa-headset"></i>
                                <span>Support Ready</span>
                            </div>
                        </div>

                    </div>

                </aside>

            </div>

            <!-- CHECKOUT -->
            <section class="AEC_panel AEC_checkout" id="checkout">

                <div class="AEC_panel_header">
                    <div>
                        <h2>Checkout Details</h2>
                        <span>These details will appear on the receipt.</span>
                    </div>
                </div>

                <form method="post" class="AEC_form">

                    <div class="AEC_form_grid">

                        <div class="AEC_field">
                            <label for="customer_name">Customer Name *</label>
                            <input
                                type="text"
                                id="customer_name"
                                name="customer_name"
                                placeholder="Your full name"
                                required
                            >
                        </div>

                        <div class="AEC_field">
                            <label for="customer_phone">Phone Number *</label>
                            <input
                                type="tel"
                                id="customer_phone"
                                name="customer_phone"
                                placeholder="03XX XXXXXXX"
                                required
                            >
                        </div>

                        <div class="AEC_field">
                            <label for="customer_email">Email Address</label>
                            <input
                                type="email"
                                id="customer_email"
                                name="customer_email"
                                placeholder="you@example.com"
                            >
                        </div>

                        <div class="AEC_field">
                            <label for="payment_method">Payment Method</label>
                            <select id="payment_method" name="payment_method">
                                <option value="Cash on Delivery">Cash on Delivery</option>
                            </select>
                        </div>

                        <div class="AEC_field full">
                            <label for="customer_address">Complete Delivery Address *</label>
                            <textarea
                                id="customer_address"
                                name="customer_address"
                                placeholder="House / street / area / city / province"
                                required
                            ></textarea>
                        </div>

                    </div>

                    <div class="AEC_checkout_note">
                        <i class="fa-solid fa-circle-info"></i>
                        Your order will be saved with an order number, exact date,
                        database time, customer details, purchased items and total amount.
                        After placing the order, a printable receipt will appear automatically.
                    </div>

                    <button type="submit" name="place_order" class="AEC_btn" style="margin-top:18px; width:100%;">
                        <i class="fa-solid fa-check"></i>
                        Place Order & Generate Receipt
                    </button>

                </form>

            </section>

        <?php else: ?>

            <section class="AEC_panel AEC_empty">
                <div class="AEC_empty_icon">
                    <i class="fa-solid fa-bag-shopping"></i>
                </div>

                <h3>Your shopping bag is empty</h3>

                <p>
                    Add something beautiful from the Awaisi Emporium collection
                    and it will appear here.
                </p>

                <a href="products.php" class="AEC_btn">
                    <i class="fa-solid fa-arrow-left"></i>
                    Continue Shopping
                </a>
            </section>

        <?php endif; ?>

    </div>
</main>

<?php include "footer.php"; ?>

<?php if ($show_receipt && !empty($receipt["order"])): ?>

    <?php $order = $receipt["order"]; ?>

    <div class="AEC_receipt_overlay" id="receiptOverlay">

        <div class="AEC_receipt_window">

            <div class="AEC_receipt_actions">
                <button type="button" class="AEC_btn" onclick="printReceipt()">
                    <i class="fa-solid fa-print"></i>
                    Print Receipt
                </button>

                <button type="button" class="AEC_btn AEC_btn_secondary" onclick="closeReceipt()">
                    <i class="fa-solid fa-xmark"></i>
                    Close
                </button>
            </div>

            <div class="AEC_receipt" id="printReceipt">

                <div class="AEC_receipt_brand">
                    <div class="ae_mark">AE</div>
                    <h2><?php echo ae_cart_escape($store_name); ?></h2>
                    <p>PREMIUM FAMILY STORE</p>
                </div>

                <div class="AEC_receipt_meta">
                    <div>
                        <span>Receipt / Order No.</span>
                        <strong><?php echo ae_cart_escape($order["order_number"]); ?></strong>
                    </div>

                    <div>
                        <span>Date & Time</span>
                        <strong><?php echo date("d M Y, h:i A", strtotime($order["created_at"])); ?></strong>
                    </div>

                    <div>
                        <span>Status</span>
                        <strong><?php echo ae_cart_escape(ucfirst($order["status"])); ?></strong>
                    </div>

                    <div>
                        <span>Payment</span>
                        <strong><?php echo ae_cart_escape($order["payment_method"]); ?></strong>
                    </div>
                </div>

                <table class="AEC_receipt_table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Total</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($receipt["items"] as $receipt_item): ?>
                            <tr>
                                <td>
                                    <?php echo ae_cart_escape($receipt_item["product_name"]); ?><br>
                                    <small><?php echo $currency; ?> <?php echo ae_cart_money($receipt_item["price"]); ?> each</small>
                                </td>
                                <td><?php echo (int)$receipt_item["quantity"]; ?></td>
                                <td><?php echo $currency; ?> <?php echo ae_cart_money($receipt_item["line_total"]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="AEC_receipt_totals">
                    <div class="AEC_receipt_total_line">
                        <span>Subtotal</span>
                        <strong><?php echo $currency; ?> <?php echo ae_cart_money($order["subtotal"]); ?></strong>
                    </div>

                    <div class="AEC_receipt_total_line">
                        <span>Delivery</span>
                        <strong>
                            <?php echo (float)$order["delivery_fee"] > 0
                                ? $currency . " " . ae_cart_money($order["delivery_fee"])
                                : "FREE"; ?>
                        </strong>
                    </div>

                    <div class="AEC_receipt_total_line grand">
                        <span>Grand Total</span>
                        <strong><?php echo $currency; ?> <?php echo ae_cart_money($order["total"]); ?></strong>
                    </div>
                </div>

                <div class="AEC_receipt_customer">
                    <strong>Customer Details</strong><br>
                    <?php echo ae_cart_escape($order["customer_name"]); ?><br>
                    <?php echo ae_cart_escape($order["customer_phone"]); ?><br>
                    <?php if (trim($order["customer_email"]) !== ""): ?>
                        <?php echo ae_cart_escape($order["customer_email"]); ?><br>
                    <?php endif; ?>
                    <?php echo nl2br(ae_cart_escape($order["customer_address"])); ?>
                </div>

                <div class="AEC_receipt_footer">
                    Thank you for shopping with <?php echo ae_cart_escape($store_name); ?>.<br>
                    <?php echo ae_cart_escape($store_phone); ?> · <?php echo ae_cart_escape($store_email); ?><br>
                    Please keep this receipt for your order reference.
                </div>

            </div>

        </div>

    </div>

<?php endif; ?>

<script>
/* ============================================================
   QUANTITY CONTROLS
   ============================================================ */
function changeQuantity(productId, change) {

    const input = document.getElementById("quantity_" + productId);

    if (!input) {
        return;
    }

    let value = parseInt(input.value || "1", 10);
    value += change;
    value = Math.max(1, Math.min(99, value));

    input.value = value;
}

/* ============================================================
   RECEIPT
   ============================================================ */
function printReceipt() {
    window.print();
}

function closeReceipt() {
    const overlay = document.getElementById("receiptOverlay");

    if (overlay) {
        overlay.remove();
    }

    window.history.replaceState({}, document.title, "cart.php");
}

/* Escape closes the receipt. */
document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
        closeReceipt();
    }
});
</script>

</body>
</html>
