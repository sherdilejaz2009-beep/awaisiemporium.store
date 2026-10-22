<!-- <?php
/* ============================================================
   AWAISI EMPORIUM
   FILE: products.php
   PURPOSE: Dynamic public products page

   CODE ORGANIZATION:
   1. PHP / Database Logic
   2. Header Include
   3. CSS — all page styling
   4. HTML — page structure
   5. Footer Include
   6. JavaScript — page interactions
   ============================================================ */

/* ============================================================
   1. PHP / DATABASE LOGIC
   ============================================================ */

session_start();
require_once "connection.php";

/* ---------- Cart Session ---------- */
if (!isset($_SESSION["ae_cart"]) || !is_array($_SESSION["ae_cart"])) {
    $_SESSION["ae_cart"] = [];
}

/* ---------- Helper: Escape Output ---------- */
function ae_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

/* ---------- Helper: Product Image Path ---------- */
function ae_product_image($image)
{
    $image = trim((string) $image);

    if ($image === "") {
        return "";
    }

    if (preg_match("~^https?://~i", $image)) {
        return $image;
    }

    if (strpos($image, "uploads/") === 0) {
        return $image;
    }

    return "uploads/products/" . ltrim($image, "/");
}

/* ---------- Helper: Safe Category ID ---------- */
function ae_category_id($name)
{
    $name = strtolower(trim((string) $name));
    $name = preg_replace("/[^a-z0-9]+/i", "-", $name);
    return trim($name, "-");
}

/* ---------- Add Product To Cart ---------- */
$cart_message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_to_cart"])) {

    $product_id = (int) ($_POST["product_id"] ?? 0);

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
            $product = $result ? mysqli_fetch_assoc($result) : null;

            mysqli_stmt_close($stmt);

            if ($product) {

                $already_in_cart = false;

                foreach ($_SESSION["ae_cart"] as &$cart_item) {
                    if ((int) ($cart_item["id"] ?? 0) === (int) $product["id"]) {
                        $cart_item["qty"] = (int) ($cart_item["qty"] ?? 1) + 1;
                        $already_in_cart = true;
                        break;
                    }
                }

                unset($cart_item);

                if (!$already_in_cart) {
                    $_SESSION["ae_cart"][] = [
                        "id"          => (int) $product["id"],
                        "name"        => $product["name"],
                        "description" => $product["description"],
                        "price"       => (float) $product["price"],
                        "category"    => $product["category"],
                        "image"       => $product["image"],
                        "qty"         => 1
                    ];
                }

                $cart_message = $product["name"] . " added to cart.";
            }
        }
    }
}

/* ---------- Load Categories ---------- */
$categories = [];
$category_keys = [];

$category_result = mysqli_query(
    $conn,
    "SELECT id, name, description, image
     FROM categories
     WHERE TRIM(name) <> ''
     ORDER BY name ASC, id ASC"
);

if ($category_result) {
    while ($row = mysqli_fetch_assoc($category_result)) {
        $category_name = trim((string) ($row["name"] ?? ""));
        $category_key = strtolower(preg_replace("/\s+/", " ", $category_name));

        if ($category_name === "") {
            continue;
        }

        /*
         * Keep only ONE button/section for the same category.
         * This prevents duplicate categories from appearing when
         * the dashboard contains duplicate category rows.
         */
        if (isset($category_keys[$category_key])) {
            continue;
        }

        $category_keys[$category_key] = true;

        $row["name"] = $category_name;
        $categories[] = $row;
    }
}

/* Fallback categories for a fresh database. */
if (empty($categories)) {
    $default_categories = [
        "Cosmetics",
        "Accessories",
        "Perfumes",
        "Perfume Testers"
    ];

    foreach ($default_categories as $category_name) {
        $categories[] = [
            "id"          => 0,
            "name"        => $category_name,
            "description" => "Premium collection at Awaisi Emporium.",
            "image"       => ""
        ];
    }
}

/* ---------- Load Products ---------- */
$products = [];

$product_result = mysqli_query(
    $conn,
    "SELECT id, name, description, price, category, image, created_at
     FROM products
     ORDER BY id DESC"
);

if ($product_result) {
    while ($row = mysqli_fetch_assoc($product_result)) {
        $products[] = $row;
    }
}

/* ---------- Selected Category ---------- */
$selected_category = trim($_GET["category"] ?? "");
$selected_category_name = "";

if ($selected_category !== "") {
    foreach ($categories as $category) {
        if (strcasecmp($category["name"], $selected_category) === 0) {
            $selected_category_name = $category["name"];
            break;
        }
    }
}

/* Invalid category = show all products. */
if ($selected_category !== "" && $selected_category_name === "") {
    $selected_category = "";
}

/* ---------- Cart Count ---------- */
$cart_count = 0;

foreach ($_SESSION["ae_cart"] as $cart_item) {
    $cart_count += max(1, (int) ($cart_item["qty"] ?? 1));
}

/* ---------- Product Counts Per Category ---------- */
$category_counts = [];

foreach ($categories as $category) {
    $count = 0;

    foreach ($products as $product) {
        if (
            strcasecmp(
                trim($product["category"] ?? ""),
                trim($category["name"])
            ) === 0
        ) {
            $count++;
        }
    }

    $category_counts[$category["name"]] = $count;
}

/* ---------- Build Visible Category Groups ---------- */
$visible_categories = [];

foreach ($categories as $category) {

    if (
        $selected_category_name !== "" &&
        strcasecmp($selected_category_name, $category["name"]) !== 0
    ) {
        continue;
    }

    $category_products = [];

    foreach ($products as $product) {
        if (
            strcasecmp(
                trim($product["category"] ?? ""),
                trim($category["name"])
            ) === 0
        ) {
            /* Show a maximum of 6 cards in every category. */
            if (count($category_products) >= 6) {
                break;
            }

            $category_products[] = $product;
        }
    }

    /* On All Products, hide empty categories. */
    if ($selected_category_name === "" && empty($category_products)) {
        continue;
    }

    $visible_categories[] = [
        "category" => $category,
        "products" => $category_products
    ];
}
?> 

<?php include "header.php"; ?>

<!-- ============================================================
     2. CSS
     All CSS for THIS page is kept in this one CSS block.
     Nothing is mixed with the HTML below.
     ============================================================ -->

<style>

/* ---------- GLOBAL ---------- */
.AEP_products_page {
    --ae-maroon: #6e1830;
    --ae-deep: #42101f;
    --ae-gold: #d8b56a;
    --ae-gold-light: #f1d99a;
    --ae-ivory: #fffdf8;
    --ae-cream: #f6eee3;
    --ae-text: #321923;
    --ae-muted: #806a70;
    --ae-border: #eadbd0;

    background: var(--ae-ivory);
    color: var(--ae-text);
    padding-bottom: 70px;
}

.AEP_container {
    width: min(1180px, calc(100% - 40px));
    margin: 0 auto;
}

.AEP_products_page a {
    text-decoration: none;
}

/* ---------- HERO ---------- */
.AEP_hero {
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, var(--ae-deep), var(--ae-maroon));
    color: #ffffff;
    padding: 80px 0;
}

.AEP_hero_grid {
    position: relative;
    z-index: 2;
    display: grid;
    grid-template-columns: 1fr 280px;
    align-items: center;
    gap: 50px;
}

.AEP_hero_copy {
    max-width: 760px;
}

.AEP_kicker,
.AEP_label {
    display: block;
    color: var(--ae-gold-light);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 2px;
    text-transform: uppercase;
}

.AEP_hero h1 {
    margin: 12px 0 18px;
    font-family: "Playfair Display", Georgia, serif;
    font-size: clamp(42px, 6vw, 72px);
    line-height: 1.03;
}

.AEP_hero h1 span {
    color: var(--ae-gold-light);
}

.AEP_hero p {
    max-width: 680px;
    margin: 0;
    color: rgba(255, 255, 255, 0.78);
    line-height: 1.8;
    font-size: 15px;
}

.AEP_actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 28px;
}

.AEP_btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 46px;
    padding: 12px 18px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 800;
    transition: 0.2s ease;
}

.AEP_btn_primary {
    background: linear-gradient(135deg, var(--ae-gold), var(--ae-gold-light));
    color: var(--ae-deep);
}

.AEP_btn_primary:hover {
    transform: translateY(-2px);
}

.AEP_btn_outline {
    border: 1px solid rgba(255, 255, 255, 0.28);
    color: #ffffff;
    background: rgba(255, 255, 255, 0.06);
}

.AEP_btn_outline:hover {
    background: rgba(255, 255, 255, 0.12);
}

.AEP_hero_mark {
    width: 220px;
    height: 220px;
    margin-left: auto;
    border: 1px solid rgba(241, 217, 154, 0.5);
    border-radius: 50%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    box-shadow: inset 0 0 0 12px rgba(255, 255, 255, 0.03);
}

.AEP_hero_mark strong {
    font-family: "Playfair Display", Georgia, serif;
    font-size: 60px;
    color: var(--ae-gold-light);
}

.AEP_hero_mark span {
    margin-top: 4px;
    font-size: 9px;
    letter-spacing: 2px;
    font-weight: 800;
}

/* ---------- CATEGORY FILTER ---------- */
.AEP_category_area {
    padding: 55px 0 20px;
}

.AEP_heading {
    display: flex;
    justify-content: space-between;
    align-items: end;
    gap: 30px;
    margin-bottom: 25px;
}

.AEP_heading h2,
.AEP_section_heading h2 {
    margin: 8px 0 0;
    font-family: "Playfair Display", Georgia, serif;
    font-size: 34px;
    color: var(--ae-deep);
}

.AEP_heading p {
    max-width: 500px;
    margin: 0;
    color: var(--ae-muted);
    line-height: 1.7;
    font-size: 13px;
}

.AEP_filter {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.AEP_filter a {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 10px 15px;
    border: 1px solid var(--ae-border);
    border-radius: 999px;
    background: #ffffff;
    color: var(--ae-muted);
    font-size: 12px;
    font-weight: 800;
    transition: 0.2s ease;
}

.AEP_filter a:hover,
.AEP_filter a.active {
    border-color: var(--ae-maroon);
    background: var(--ae-maroon);
    color: #ffffff;
}

/* ---------- COLLECTION ---------- */
.AEP_collection {
    padding: 38px 0;
}

.AEP_section_heading {
    display: flex;
    justify-content: space-between;
    align-items: end;
    gap: 25px;
    margin-bottom: 22px;
}

.AEP_section_heading p {
    max-width: 700px;
    margin: 9px 0 0;
    color: var(--ae-muted);
    font-size: 13px;
    line-height: 1.7;
}

.AEP_count {
    white-space: nowrap;
    padding: 8px 12px;
    border-radius: 999px;
    background: var(--ae-cream);
    color: var(--ae-maroon);
    font-size: 11px;
    font-weight: 800;
}

/* ---------- PRODUCT GRID ---------- */
.AEP_grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 22px;
}

.AEP_card {
    overflow: hidden;
    border: 1px solid var(--ae-border);
    border-radius: 18px;
    background: #ffffff;
    box-shadow: 0 10px 30px rgba(66, 16, 31, 0.06);
    transition: 0.25s ease;
}

.AEP_card:hover {
    transform: translateY(-5px);
    box-shadow: 0 18px 40px rgba(66, 16, 31, 0.12);
}

/* ---------- PRODUCT IMAGE ---------- */
.AEP_image_box {
    position: relative;
    height: 280px;
    overflow: hidden;
    background: #f5ede4;
}

.AEP_image_box img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: cover;
}

.AEP_no_image {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #b28a55;
    gap: 8px;
}

.AEP_no_image i {
    font-size: 30px;
}

.AEP_category_badge {
    position: absolute;
    top: 13px;
    left: 13px;
    padding: 7px 10px;
    border-radius: 999px;
    background: rgba(66, 16, 31, 0.94);
    color: var(--ae-gold-light);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1px;
    text-transform: uppercase;
}

/* ---------- PRODUCT INFORMATION ---------- */
.AEP_product_info {
    padding: 19px;
}

.AEP_product_meta {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 7px;
    color: #9a713d;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1px;
    text-transform: uppercase;
}

.AEP_product_meta small {
    color: #a18e93;
    font-size: 9px;
    letter-spacing: 0;
    text-transform: none;
}

.AEP_product_info h3 {
    margin: 0 0 8px;
    font-family: "Playfair Display", Georgia, serif;
    color: var(--ae-deep);
    font-size: 22px;
}

.AEP_product_description {
    min-height: 48px;
    margin: 0;
    color: var(--ae-muted);
    font-size: 11px;
    line-height: 1.7;
}

.AEP_product_footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-top: 17px;
    padding-top: 14px;
    border-top: 1px solid var(--ae-border);
}

.AEP_price {
    color: var(--ae-maroon);
    font-size: 18px;
    font-weight: 900;
}

.AEP_price small {
    margin-right: 3px;
    font-size: 9px;
}

/* ---------- PRODUCT ACTIONS ---------- */
.AEP_product_actions {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    justify-content: flex-end;
}

.AEP_action_button {
    border: 0;
    border-radius: 9px;
    padding: 10px 12px;
    font-size: 10px;
    font-weight: 800;
    cursor: pointer;
    transition: 0.2s ease;
}

.AEP_action_button:hover {
    transform: translateY(-1px);
}

.AEP_cart_button {
    background: var(--ae-maroon);
    color: #ffffff;
}

.AEP_order_button {
    background: var(--ae-gold);
    color: var(--ae-deep);
}

/* ---------- EMPTY STATE ---------- */
.AEP_empty {
    padding: 70px 25px;
    border: 1px dashed #d7c4b4;
    border-radius: 18px;
    background: #ffffff;
    text-align: center;
}

.AEP_empty i {
    margin-bottom: 13px;
    color: var(--ae-gold);
    font-size: 34px;
}

.AEP_empty h3 {
    margin: 0 0 8px;
    font-family: "Playfair Display", Georgia, serif;
    color: var(--ae-deep);
    font-size: 27px;
}

.AEP_empty p {
    max-width: 600px;
    margin: 0 auto 20px;
    color: var(--ae-muted);
    font-size: 12px;
    line-height: 1.7;
}

/* ---------- TRUST ---------- */
.AEP_trust {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-top: 30px;
}

.AEP_trust_item {
    display: flex;
    flex-direction: column;
    gap: 5px;
    padding: 18px;
    border: 1px solid var(--ae-border);
    border-radius: 14px;
    background: #ffffff;
}

.AEP_trust_item i {
    margin-bottom: 3px;
    color: var(--ae-gold);
    font-size: 18px;
}

.AEP_trust_item strong {
    color: var(--ae-deep);
    font-size: 12px;
}

.AEP_trust_item span {
    color: var(--ae-muted);
    font-size: 10px;
    line-height: 1.6;
}

/* ---------- CART NOTICE ---------- */
.AEP_cart_notice {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 9999;
    display: flex;
    align-items: center;
    gap: 10px;
    max-width: calc(100% - 40px);
    padding: 13px 16px;
    border-radius: 11px;
    background: var(--ae-deep);
    color: #ffffff;
    box-shadow: 0 15px 35px rgba(66, 16, 31, 0.2);
    font-size: 12px;
}

.AEP_cart_notice i {
    color: var(--ae-gold-light);
}

.AEP_cart_notice a {
    color: var(--ae-gold-light);
    font-weight: 800;
}

/* ---------- RESPONSIVE ---------- */
@media (max-width: 980px) {
    .AEP_hero_grid {
        grid-template-columns: 1fr;
    }

    .AEP_hero_mark {
        margin: 0;
        width: 170px;
        height: 170px;
    }

    .AEP_grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .AEP_trust {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 700px) {
    .AEP_container {
        width: min(100% - 24px, 1180px);
    }

    .AEP_hero {
        padding: 55px 0;
    }

    .AEP_heading,
    .AEP_section_heading {
        align-items: flex-start;
        flex-direction: column;
    }

    .AEP_grid {
        grid-template-columns: 1fr;
    }

    .AEP_trust {
        grid-template-columns: 1fr;
    }

    .AEP_product_footer {
        align-items: flex-start;
        flex-direction: column;
    }

    .AEP_product_actions {
        justify-content: flex-start;
    }

    .AEP_cart_notice {
        left: 12px;
        right: 12px;
        bottom: 12px;
        max-width: none;
    }
}

</style>

<!-- ============================================================
     3. HTML / PAGE STRUCTURE
     ============================================================ -->

<main class="AEP_products_page">

    <!-- ---------- HERO ---------- -->
    <section class="AEP_hero">
        <div class="AEP_container AEP_hero_grid">

            <div class="AEP_hero_copy">
                <span class="AEP_kicker">AWAISI EMPORIUM</span>

                <h1>
                    Curated Products.<br>
                    <span>Beautifully Presented.</span>
                </h1>

                <p>
                    Discover products added directly from our store system.
                    Every product stays inside its correct category.
                </p>

                <div class="AEP_actions">
                    <a href="#categories" class="AEP_btn AEP_btn_primary">
                        <i class="fa-solid fa-compass"></i>
                        Explore Categories
                    </a>

                    <a href="cart.php" class="AEP_btn AEP_btn_outline">
                        <i class="fa-solid fa-bag-shopping"></i>
                        Cart (<?php echo (int) $cart_count; ?>)
                    </a>
                </div>
            </div>

            <div class="AEP_hero_mark">
                <strong>AE</strong>
                <span>AWAISI EMPORIUM</span>
            </div>

        </div>
    </section>

    <!-- ---------- CATEGORY FILTER ---------- -->
    <section class="AEP_container AEP_category_area" id="categories">

        <div class="AEP_heading">
            <div>
                <span class="AEP_label">SHOP BY CATEGORY</span>
                <h2>Find Your Collection</h2>
            </div>

            <p>
                Choose a category to display only products belonging to that category.
            </p>
        </div>

        <div class="AEP_filter">

            <a
                href="products.php"
                class="<?php echo $selected_category === "" ? "active" : ""; ?>"
            >
                All Products
            </a>

            <?php foreach ($categories as $category): ?>

                <a
                    href="products.php?category=<?php echo urlencode($category["name"]); ?>"
                    class="<?php echo ($selected_category_name !== "" && strcasecmp($selected_category_name, $category["name"]) === 0) ? "active" : ""; ?>"
                >
                    <?php echo ae_escape($category["name"]); ?>
                    <span>
                        (<?php echo min(6, (int) ($category_counts[$category["name"]] ?? 0)); ?>)
                    </span>
                </a>

            <?php endforeach; ?>

        </div>

    </section>

    <!-- ---------- PRODUCT COLLECTIONS ---------- -->
    <section class="AEP_container">

        <?php if (empty($visible_categories) && $selected_category_name === ""): ?>

            <div class="AEP_empty">
                <i class="fa-solid fa-box-open"></i>

                <h3>No Products Added Yet</h3>

                <p>
                    Add a product from Dashboard → Products → Add Product.
                    It will appear here automatically.
                </p>

                <a href="index.php" class="AEP_btn AEP_btn_primary">
                    <i class="fa-solid fa-store"></i>
                    Back To Store
                </a>
            </div>

        <?php endif; ?>

        <?php foreach ($visible_categories as $group): ?>

            <?php
            $category = $group["category"];
            $category_products = $group["products"];
            $category_id = ae_category_id($category["name"]);
            ?>

            <section
                class="AEP_collection"
                id="<?php echo ae_escape($category_id); ?>"
            >

                <!-- ---------- COLLECTION HEADER ---------- -->
                <div class="AEP_section_heading">

                    <div>
                        <span class="AEP_label">AWAISI COLLECTION</span>

                        <h2>
                            <?php echo ae_escape($category["name"]); ?>
                        </h2>

                        <p>
                            <?php
                            echo ae_escape(
                                $category["description"] ?: "Premium products selected for Awaisi Emporium."
                            );
                            ?>
                        </p>
                    </div>

                    <span class="AEP_count">
                        <?php echo count($category_products); ?>
                        Product<?php echo count($category_products) === 1 ? "" : "s"; ?>
                    </span>

                </div>

                <!-- ---------- PRODUCT CARDS ---------- -->
                <div class="AEP_grid">

                    <?php foreach ($category_products as $product): ?>

                        <?php
                        $image = ae_product_image($product["image"] ?? "");
                        $product_id = (int) $product["id"];
                        ?>

                        <article class="AEP_card">

                            <!-- PRODUCT IMAGE -->
                            <div class="AEP_image_box">

                                <?php if ($image !== ""): ?>

                                    <img
                                        src="<?php echo ae_escape($image); ?>"
                                        alt="<?php echo ae_escape($product["name"]); ?>"
                                        loading="lazy"
                                    >

                                <?php else: ?>

                                    <div class="AEP_no_image">
                                        <i class="fa-regular fa-image"></i>
                                        <span>No Image</span>
                                    </div>

                                <?php endif; ?>

                                <span class="AEP_category_badge">
                                    <?php echo ae_escape($product["category"]); ?>
                                </span>

                            </div>

                            <!-- PRODUCT DETAILS -->
                            <div class="AEP_product_info">

                                <div class="AEP_product_meta">
                                    <span>
                                        <?php echo ae_escape($product["category"]); ?>
                                    </span>

                                    <small>
                                        ID #<?php echo $product_id; ?>
                                    </small>
                                </div>

                                <h3>
                                    <?php echo ae_escape($product["name"]); ?>
                                </h3>

                                <p class="AEP_product_description">
                                    <?php echo ae_escape($product["description"]); ?>
                                </p>

                                <div class="AEP_product_footer">

                                    <strong class="AEP_price">
                                        <small>PKR</small>
                                        <?php echo number_format((float) $product["price"], 2); ?>
                                    </strong>

                                    <div class="AEP_product_actions">

                                        <!-- ADD TO CART -->
                                        <form method="POST">
                                            <input
                                                type="hidden"
                                                name="product_id"
                                                value="<?php echo $product_id; ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="add_to_cart"
                                                class="AEP_action_button AEP_cart_button"
                                            >
                                                <i class="fa-solid fa-bag-shopping"></i>
                                                Add To Cart
                                            </button>
                                        </form>

                                        <!-- DIRECT ORDER -->
                                        <a
                                            href="order.php?id=<?php echo $product_id; ?>"
                                            class="AEP_action_button AEP_order_button"
                                        >
                                            <i class="fa-solid fa-bolt"></i>
                                            Order Now
                                        </a>

                                    </div>

                                </div>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            </section>

        <?php endforeach; ?>

        <!-- ---------- EMPTY SELECTED CATEGORY ---------- -->
        <?php if ($selected_category_name !== "" && empty($visible_categories)): ?>

            <div class="AEP_empty">
                <i class="fa-solid fa-box-open"></i>

                <h3>
                    <?php echo ae_escape($selected_category_name); ?> Is Empty
                </h3>

                <p>
                    Add a product in this exact category from the dashboard
                    and it will appear here automatically.
                </p>

                <a href="products.php" class="AEP_btn AEP_btn_primary">
                    <i class="fa-solid fa-arrow-left"></i>
                    View All Products
                </a>
            </div>

        <?php endif; ?>

    </section>

    <!-- ---------- STORE FEATURES ---------- -->
    <section class="AEP_container AEP_trust">

        <div class="AEP_trust_item">
            <i class="fa-solid fa-database"></i>
            <strong>Live Database</strong>
            <span>Products are loaded directly from XAMPP/MySQL.</span>
        </div>

        <div class="AEP_trust_item">
            <i class="fa-solid fa-layer-group"></i>
            <strong>Correct Category</strong>
            <span>Every product stays inside its saved category.</span>
        </div>

        <div class="AEP_trust_item">
            <i class="fa-solid fa-bag-shopping"></i>
            <strong>Working Cart</strong>
            <span>Products can be added to the customer cart.</span>
        </div>

        <div class="AEP_trust_item">
            <i class="fa-solid fa-mobile-screen"></i>
            <strong>Responsive</strong>
            <span>The layout adapts to desktop, tablet and mobile.</span>
        </div>

    </section>

</main>

<?php include "footer.php"; ?>

<?php if ($cart_message !== ""): ?>

    <div class="AEP_cart_notice" id="AEP_cart_notice">
        <i class="fa-solid fa-circle-check"></i>

        <span>
            <?php echo ae_escape($cart_message); ?>
        </span>

        <a href="cart.php">View Cart</a>
    </div>

<?php endif; ?>

<!-- ============================================================
     4. JAVASCRIPT
     All JavaScript for THIS page is kept in this one block.
     ============================================================ -->

<script>

/* ---------- Auto Hide Cart Message ---------- */
const aeCartNotice = document.getElementById("AEP_cart_notice");

if (aeCartNotice) {
    window.setTimeout(function () {
        aeCartNotice.style.opacity = "0";
        aeCartNotice.style.transform = "translateY(10px)";
        aeCartNotice.style.transition = "0.25s ease";

        window.setTimeout(function () {
            aeCartNotice.remove();
        }, 300);
    }, 3500);
}

</script>
