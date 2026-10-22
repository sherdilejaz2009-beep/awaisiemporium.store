<!-- <?php
/* =========================================================
   AWAISI EMPORIUM
   settings.php — FINAL PREMIUM VERSION

   Sections:
   1. General Settings
   2. Privacy Policy
   3. Help & Support

   Features:
   - Premium responsive UI
   - General settings saved in MySQL
   - Privacy policy / terms / cookie notice saved in MySQL
   - Help & Support form saved as support tickets
   - FAQ accordion
   - Only clicked settings section opens
   - Admin-only editing for store/privacy settings
   - Customer support remains available
   - Automatic database table creation
   ========================================================= */

session_start();

require_once "connection.php";

/* =========================================================
   CHANGE HERE — DEFAULT STORE INFORMATION
   ========================================================= */

$AE_DEFAULTS = [
    "site_name"        => "Awaisi Emporium",
    "tagline"          => "Quality Products. Trusted Service.",
    "email"            => "info@awaisiemporium.com",
    "phone"            => "+92 300 0000000",
    "address"          => "Mansehra, Khyber Pakhtunkhwa, Pakistan",
    "currency"         => "PKR",
    "orders_email"     => "orders@awaisiemporium.com",
    "support_email"    => "support@awaisiemporium.com",
    "maintenance_mode" => "0"
];


/* =========================================================
   HELPERS
   ========================================================= */

function AE_e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function AE_is_admin()
{
    return isset($_SESSION["admin_logged_in"])
        && $_SESSION["admin_logged_in"] === true;
}

function AE_get_setting($conn, $key, $default = "")
{
    $stmt = $conn->prepare(
        "SELECT setting_value
         FROM ae_settings
         WHERE setting_key = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param("s", $key);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    return $row ? $row["setting_value"] : $default;
}

function AE_save_setting($conn, $key, $value)
{
    $stmt = $conn->prepare(
        "INSERT INTO ae_settings
            (setting_key, setting_value)
         VALUES
            (?, ?)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value)"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ss", $key, $value);
    $ok = $stmt->execute();

    $stmt->close();

    return $ok;
}


/* =========================================================
   CREATE REQUIRED TABLES AUTOMATICALLY
   ========================================================= */

$conn->query("
    CREATE TABLE IF NOT EXISTS ae_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS ae_privacy (
        id INT PRIMARY KEY,
        policy_text LONGTEXT NULL,
        terms_text LONGTEXT NULL,
        cookie_notice TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS ae_support_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_name VARCHAR(150) NOT NULL,
        customer_email VARCHAR(190) NOT NULL,
        subject VARCHAR(200) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'Open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");


/* =========================================================
   INSERT DEFAULT PRIVACY CONTENT
   ========================================================= */

$conn->query("
    INSERT IGNORE INTO ae_privacy
        (id, policy_text, terms_text, cookie_notice)
    VALUES
        (
            1,
            'Awaisi Emporium respects your privacy. Customer information is used only for order processing, customer support, account communication and improving our services.',
            'These terms describe the basic conditions for using Awaisi Emporium services, placing orders and contacting customer support.',
            'We use essential website information to provide shopping, cart and support functionality.'
        )
");


/* =========================================================
   CURRENT SECTION
   ========================================================= */

$allowed_sections = [
    "general",
    "privacy",
    "help"
];

$current_section = $_GET["section"] ?? "general";

if (!in_array($current_section, $allowed_sections, true)) {
    $current_section = "general";
}


/* =========================================================
   LOAD GENERAL SETTINGS
   ========================================================= */

$site_name = AE_get_setting(
    $conn,
    "site_name",
    $AE_DEFAULTS["site_name"]
);

$tagline = AE_get_setting(
    $conn,
    "tagline",
    $AE_DEFAULTS["tagline"]
);

$site_email = AE_get_setting(
    $conn,
    "email",
    $AE_DEFAULTS["email"]
);

$phone = AE_get_setting(
    $conn,
    "phone",
    $AE_DEFAULTS["phone"]
);

$address = AE_get_setting(
    $conn,
    "address",
    $AE_DEFAULTS["address"]
);

$currency = AE_get_setting(
    $conn,
    "currency",
    $AE_DEFAULTS["currency"]
);

$orders_email = AE_get_setting(
    $conn,
    "orders_email",
    $AE_DEFAULTS["orders_email"]
);

$support_email = AE_get_setting(
    $conn,
    "support_email",
    $AE_DEFAULTS["support_email"]
);

$maintenance_mode = AE_get_setting(
    $conn,
    "maintenance_mode",
    $AE_DEFAULTS["maintenance_mode"]
);


/* =========================================================
   LOAD PRIVACY CONTENT
   ========================================================= */

$privacy = [
    "policy_text"   => "",
    "terms_text"    => "",
    "cookie_notice" => ""
];

$privacy_result = $conn->query("
    SELECT
        policy_text,
        terms_text,
        cookie_notice
    FROM ae_privacy
    WHERE id = 1
    LIMIT 1
");

if ($privacy_result && $privacy_result->num_rows > 0) {
    $privacy = $privacy_result->fetch_assoc();
}


/* =========================================================
   MESSAGES
   ========================================================= */

$success_message = "";
$error_message = "";


/* =========================================================
   SAVE GENERAL SETTINGS
   ========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && ($_POST["form_type"] ?? "") === "general"
) {

    $current_section = "general";

    if (!AE_is_admin()) {

        $error_message =
            "Administrator login is required to change general settings.";

    } else {

        $new_site_name = trim($_POST["site_name"] ?? "");
        $new_tagline = trim($_POST["tagline"] ?? "");
        $new_email = trim($_POST["email"] ?? "");
        $new_phone = trim($_POST["phone"] ?? "");
        $new_address = trim($_POST["address"] ?? "");
        $new_currency = trim($_POST["currency"] ?? "PKR");
        $new_orders_email = trim($_POST["orders_email"] ?? "");
        $new_support_email = trim($_POST["support_email"] ?? "");
        $new_maintenance = isset($_POST["maintenance_mode"]) ? "1" : "0";

        if ($new_site_name === "") {
            $new_site_name = $AE_DEFAULTS["site_name"];
        }

        if ($new_email !== "" &&
            !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {

            $error_message = "Please enter a valid store email.";

        } elseif (
            $new_orders_email !== "" &&
            !filter_var($new_orders_email, FILTER_VALIDATE_EMAIL)
        ) {

            $error_message = "Please enter a valid orders email.";

        } elseif (
            $new_support_email !== "" &&
            !filter_var($new_support_email, FILTER_VALIDATE_EMAIL)
        ) {

            $error_message = "Please enter a valid support email.";

        } else {

            AE_save_setting($conn, "site_name", $new_site_name);
            AE_save_setting($conn, "tagline", $new_tagline);
            AE_save_setting($conn, "email", $new_email);
            AE_save_setting($conn, "phone", $new_phone);
            AE_save_setting($conn, "address", $new_address);
            AE_save_setting($conn, "currency", $new_currency);
            AE_save_setting($conn, "orders_email", $new_orders_email);
            AE_save_setting($conn, "support_email", $new_support_email);
            AE_save_setting(
                $conn,
                "maintenance_mode",
                $new_maintenance
            );

            $site_name = $new_site_name;
            $tagline = $new_tagline;
            $site_email = $new_email;
            $phone = $new_phone;
            $address = $new_address;
            $currency = $new_currency;
            $orders_email = $new_orders_email;
            $support_email = $new_support_email;
            $maintenance_mode = $new_maintenance;

            $success_message =
                "General settings have been saved successfully.";
        }
    }
}


/* =========================================================
   SAVE PRIVACY SETTINGS
   ========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && ($_POST["form_type"] ?? "") === "privacy"
) {

    $current_section = "privacy";

    if (!AE_is_admin()) {

        $error_message =
            "Administrator login is required to edit privacy information.";

    } else {

        $new_policy = trim($_POST["policy_text"] ?? "");
        $new_terms = trim($_POST["terms_text"] ?? "");
        $new_cookie = trim($_POST["cookie_notice"] ?? "");

        $stmt = $conn->prepare("
            UPDATE ae_privacy
            SET
                policy_text = ?,
                terms_text = ?,
                cookie_notice = ?
            WHERE id = 1
        ");

        if ($stmt) {

            $stmt->bind_param(
                "sss",
                $new_policy,
                $new_terms,
                $new_cookie
            );

            if ($stmt->execute()) {

                $privacy["policy_text"] = $new_policy;
                $privacy["terms_text"] = $new_terms;
                $privacy["cookie_notice"] = $new_cookie;

                $success_message =
                    "Privacy information has been saved successfully.";

            } else {

                $error_message =
                    "Privacy information could not be saved.";
            }

            $stmt->close();

        } else {

            $error_message =
                "Privacy database query could not be prepared.";
        }
    }
}


/* =========================================================
   SUBMIT SUPPORT TICKET
   ========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && ($_POST["form_type"] ?? "") === "support"
) {

    $current_section = "help";

    $customer_name = trim($_POST["customer_name"] ?? "");
    $customer_email = trim($_POST["customer_email"] ?? "");
    $subject = trim($_POST["subject"] ?? "");
    $message = trim($_POST["message"] ?? "");

    if (
        $customer_name === ""
        || $customer_email === ""
        || $subject === ""
        || $message === ""
    ) {

        $error_message =
            "Please complete all support form fields.";

    } elseif (
        !filter_var($customer_email, FILTER_VALIDATE_EMAIL)
    ) {

        $error_message =
            "Please enter a valid email address.";

    } else {

        $stmt = $conn->prepare("
            INSERT INTO ae_support_tickets
                (
                    customer_name,
                    customer_email,
                    subject,
                    message
                )
            VALUES
                (?, ?, ?, ?)
        ");

        if ($stmt) {

            $stmt->bind_param(
                "ssss",
                $customer_name,
                $customer_email,
                $subject,
                $message
            );

            if ($stmt->execute()) {

                $success_message =
                    "Support request submitted successfully. Your request has been saved.";

                $_POST["customer_name"] = "";
                $_POST["customer_email"] = "";
                $_POST["subject"] = "";
                $_POST["message"] = "";

            } else {

                $error_message =
                    "Support request could not be submitted.";
            }

            $stmt->close();

        } else {

            $error_message =
                "Support form could not be prepared.";
        }
    }
}


/* =========================================================
   FAQ DATA
   ========================================================= */

$faqs = [

    [
        "question" => "How can I place an order?",
        "answer" =>
            "Open Products or Categories, choose a product and use the available shopping/cart option."
    ],

    [
        "question" => "How can I contact Awaisi Emporium?",
        "answer" =>
            "You can use the support form below or contact the store through the email and phone information shown above."
    ],

    [
        "question" => "How can I ask about a product?",
        "answer" =>
            "Enter the product name in the subject and explain your question clearly in the support message."
    ],

    [
        "question" => "Where can I read the privacy policy?",
        "answer" =>
            "Open the Privacy Policy section from the Settings menu. The privacy information is stored in the website database."
    ],

    [
        "question" => "Who can change General Settings?",
        "answer" =>
            "Only an authenticated administrator can save General Settings and Privacy Policy changes."
    ],

    [
        "question" => "What should I include in a support request?",
        "answer" =>
            "Include your name, email, a clear subject and enough information about your issue so the support team can understand it."
    ]

]; 


/* =========================================================
   HEADER
   ========================================================= */

include "header.php";

?>

<main class="AES_PAGE">

    <!-- =====================================================
         PREMIUM HERO
         ===================================================== -->

    <section class="AES_HERO">

        <div class="AES_HERO_GLOW AES_HERO_GLOW_ONE"></div>
        <div class="AES_HERO_GLOW AES_HERO_GLOW_TWO"></div>

        <div class="AES_HERO_CONTENT">

            <div class="AES_BADGE">
                <i class="fa-solid fa-crown"></i>
                AWAISI EMPORIUM
            </div>

            <h1>
                Settings
                <span>& Support</span>
            </h1>

            <p>
                Manage your store information, privacy,
                customer support and important website preferences
                from one premium control center.
            </p>

            <div class="AES_HERO_META">

                <div>
                    <i class="fa-solid fa-circle-check"></i>
                    Secure settings
                </div>

                <div>
                    <i class="fa-solid fa-database"></i>
                    Database connected
                </div>

                <div>
                    <i class="fa-solid fa-headset"></i>
                    Customer support
                </div>

            </div>

        </div>

        <div class="AES_HERO_ART">

            <div class="AES_HERO_RING AES_RING_ONE"></div>
            <div class="AES_HERO_RING AES_RING_TWO"></div>

            <div class="AES_HERO_CARD">

                <i class="fa-solid fa-sliders"></i>

                <span>
                    CONTROL
                </span>

                <strong>
                    CENTER
                </strong>

            </div>

        </div>

    </section>


    <!-- =====================================================
         MAIN SETTINGS AREA
         ===================================================== -->

    <section class="AES_LAYOUT">

        <!-- =================================================
             LEFT NAVIGATION
             ================================================= -->

        <aside class="AES_SIDE">

            <div class="AES_SIDE_TOP">

                <span>
                    SETTINGS MENU
                </span>

                <h2>
                    Control Center
                </h2>

                <p>
                    Select an option to open only that section.
                </p>

            </div>


            <button
                type="button"
                class="AES_NAV <?php echo $current_section === "general" ? "is-active" : ""; ?>"
                data-open="general"
            >

                <span class="AES_NAV_ICON">
                    <i class="fa-solid fa-gear"></i>
                </span>

                <span class="AES_NAV_TEXT">

                    <strong>
                        General Settings
                    </strong>

                    <small>
                        Store information
                    </small>

                </span>

                <i class="fa-solid fa-chevron-right AES_NAV_ARROW"></i>

            </button>


            <button
                type="button"
                class="AES_NAV <?php echo $current_section === "privacy" ? "is-active" : ""; ?>"
                data-open="privacy"
            >

                <span class="AES_NAV_ICON">
                    <i class="fa-solid fa-shield-halved"></i>
                </span>

                <span class="AES_NAV_TEXT">

                    <strong>
                        Privacy Policy
                    </strong>

                    <small>
                        Privacy & legal
                    </small>

                </span>

                <i class="fa-solid fa-chevron-right AES_NAV_ARROW"></i>

            </button>


            <button
                type="button"
                class="AES_NAV <?php echo $current_section === "help" ? "is-active" : ""; ?>"
                data-open="help"
            >

                <span class="AES_NAV_ICON">
                    <i class="fa-solid fa-headset"></i>
                </span>

                <span class="AES_NAV_TEXT">

                    <strong>
                        Help & Support
                    </strong>

                    <small>
                        FAQ & customer care
                    </small>

                </span>

                <i class="fa-solid fa-chevron-right AES_NAV_ARROW"></i>

            </button>


            <div class="AES_SIDE_FOOT">

                <i class="fa-solid fa-circle-info"></i>

                <span>
                    Changes to General Settings and Privacy
                    are protected by administrator login.
                </span>

            </div>

        </aside>


        <!-- =================================================
             RIGHT CONTENT
             ================================================= -->

        <div class="AES_CONTENT">


            <?php if ($success_message !== ""): ?>

                <div class="AES_ALERT AES_ALERT_SUCCESS">

                    <i class="fa-solid fa-circle-check"></i>

                    <span>
                        <?php echo AE_e($success_message); ?>
                    </span>

                    <button
                        type="button"
                        class="AES_ALERT_CLOSE"
                    >
                        <i class="fa-solid fa-xmark"></i>
                    </button>

                </div>

            <?php endif; ?>


            <?php if ($error_message !== ""): ?>

                <div class="AES_ALERT AES_ALERT_ERROR">

                    <i class="fa-solid fa-circle-exclamation"></i>

                    <span>
                        <?php echo AE_e($error_message); ?>
                    </span>

                    <button
                        type="button"
                        class="AES_ALERT_CLOSE"
                    >
                        <i class="fa-solid fa-xmark"></i>
                    </button>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 GENERAL SETTINGS
                 ================================================= -->

            <section
                class="AES_PANEL <?php echo $current_section === "general" ? "is-active" : ""; ?>"
                data-panel="general"
            >

                <div class="AES_PANEL_HEAD">

                    <div>

                        <span class="AES_KICKER">
                            STORE CONFIGURATION
                        </span>

                        <h2>
                            General Settings
                        </h2>

                        <p>
                            Control your store identity, contact
                            information, emails, currency and
                            maintenance status.
                        </p>

                    </div>

                    <div class="AES_PANEL_ICON">
                        <i class="fa-solid fa-gear"></i>
                    </div>

                </div>


                <?php if (!AE_is_admin()): ?>

                    <div class="AES_LOCK_NOTICE">

                        <div class="AES_LOCK_ICON">
                            <i class="fa-solid fa-lock"></i>
                        </div>

                        <div class="AES_LOCK_TEXT">

                            <strong>
                                Administrator access required
                            </strong>

                            <p>
                                You can view this page, but saving
                                General Settings requires admin login.
                            </p>

                        </div>

                        <a href="login.php">
                            Login
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>

                    </div>

                <?php endif; ?>


                <form method="POST" class="AES_FORM">

                    <input
                        type="hidden"
                        name="form_type"
                        value="general"
                    >


                    <!-- STORE IDENTITY -->

                    <div class="AES_BLOCK">

                        <div class="AES_BLOCK_HEAD">

                            <div class="AES_BLOCK_ICON">
                                <i class="fa-solid fa-store"></i>
                            </div>

                            <div>

                                <h3>
                                    Store Identity
                                </h3>

                                <p>
                                    The main identity of your ecommerce store.
                                </p>

                            </div>

                        </div>


                        <div class="AES_GRID">

                            <div class="AES_FIELD AES_FULL">

                                <label>
                                    Store Name
                                </label>

                                <div class="AES_INPUT_WRAP">

                                    <i class="fa-solid fa-shop"></i>

                                    <input
                                        type="text"
                                        name="site_name"
                                        value="<?php echo AE_e($site_name); ?>"
                                        placeholder="Awaisi Emporium"
                                    >

                                </div>

                            </div>


                            <div class="AES_FIELD AES_FULL">

                                <label>
                                    Store Tagline
                                </label>

                                <div class="AES_INPUT_WRAP">

                                    <i class="fa-solid fa-quote-left"></i>

                                    <input
                                        type="text"
                                        name="tagline"
                                        value="<?php echo AE_e($tagline); ?>"
                                        placeholder="Quality Products. Trusted Service."
                                    >

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- CONTACT DETAILS -->

                    <div class="AES_BLOCK">

                        <div class="AES_BLOCK_HEAD">

                            <div class="AES_BLOCK_ICON">
                                <i class="fa-solid fa-address-card"></i>
                            </div>

                            <div>

                                <h3>
                                    Contact Details
                                </h3>

                                <p>
                                    Information customers can use to reach your store.
                                </p>

                            </div>

                        </div>


                        <div class="AES_GRID">

                            <div class="AES_FIELD">

                                <label>
                                    Store Email
                                </label>

                                <div class="AES_INPUT_WRAP">

                                    <i class="fa-solid fa-envelope"></i>

                                    <input
                                        type="email"
                                        name="email"
                                        value="<?php echo AE_e($site_email); ?>"
                                        placeholder="info@example.com"
                                    >

                                </div>

                            </div>


                            <div class="AES_FIELD">

                                <label>
                                    Phone Number
                                </label>

                                <div class="AES_INPUT_WRAP">

                                    <i class="fa-solid fa-phone"></i>

                                    <input
                                        type="text"
                                        name="phone"
                                        value="<?php echo AE_e($phone); ?>"
                                        placeholder="+92 300 0000000"
                                    >

                                </div>

                            </div>


                            <div class="AES_FIELD AES_FULL">

                                <label>
                                    Store Address
                                </label>

                                <div class="AES_TEXTAREA_WRAP">

                                    <i class="fa-solid fa-location-dot"></i>

                                    <textarea
                                        name="address"
                                        rows="4"
                                        placeholder="Enter your store address"
                                    ><?php echo AE_e($address); ?></textarea>

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- EMAILS -->

                    <div class="AES_BLOCK">

                        <div class="AES_BLOCK_HEAD">

                            <div class="AES_BLOCK_ICON">
                                <i class="fa-solid fa-paper-plane"></i>
                            </div>

                            <div>

                                <h3>
                                    Store Emails
                                </h3>

                                <p>
                                    Keep orders and customer support organized.
                                </p>

                            </div>

                        </div>


                        <div class="AES_GRID">

                            <div class="AES_FIELD">

                                <label>
                                    Orders Email
                                </label>

                                <div class="AES_INPUT_WRAP">

                                    <i class="fa-solid fa-bag-shopping"></i>

                                    <input
                                        type="email"
                                        name="orders_email"
                                        value="<?php echo AE_e($orders_email); ?>"
                                        placeholder="orders@example.com"
                                    >

                                </div>

                            </div>


                            <div class="AES_FIELD">

                                <label>
                                    Support Email
                                </label>

                                <div class="AES_INPUT_WRAP">

                                    <i class="fa-solid fa-headset"></i>

                                    <input
                                        type="email"
                                        name="support_email"
                                        value="<?php echo AE_e($support_email); ?>"
                                        placeholder="support@example.com"
                                    >

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- SHOP PREFERENCES -->

                    <div class="AES_BLOCK">

                        <div class="AES_BLOCK_HEAD">

                            <div class="AES_BLOCK_ICON">
                                <i class="fa-solid fa-sliders"></i>
                            </div>

                            <div>

                                <h3>
                                    Shop Preferences
                                </h3>

                                <p>
                                    Control basic store behaviour.
                                </p>

                            </div>

                        </div>


                        <div class="AES_PREFERENCE_GRID">

                            <div class="AES_PREFERENCE">

                                <div>

                                    <strong>
                                        Currency
                                    </strong>

                                    <small>
                                        Default product price currency
                                    </small>

                                </div>

                                <select name="currency">

                                    <option
                                        value="PKR"
                                        <?php echo $currency === "PKR" ? "selected" : ""; ?>
                                    >
                                        PKR — Pakistani Rupee
                                    </option>

                                    <option
                                        value="USD"
                                        <?php echo $currency === "USD" ? "selected" : ""; ?>
                                    >
                                        USD — US Dollar
                                    </option>

                                    <option
                                        value="AED"
                                        <?php echo $currency === "AED" ? "selected" : ""; ?>
                                    >
                                        AED — UAE Dirham
                                    </option>

                                </select>

                            </div>


                            <div class="AES_PREFERENCE">

                                <div>

                                    <strong>
                                        Maintenance Mode
                                    </strong>

                                    <small>
                                        Use while updating the website
                                    </small>

                                </div>

                                <label class="AES_SWITCH">

                                    <input
                                        type="checkbox"
                                        name="maintenance_mode"
                                        <?php echo $maintenance_mode === "1" ? "checked" : ""; ?>
                                    >

                                    <span></span>

                                </label>

                            </div>

                        </div>

                    </div>


                    <div class="AES_FORM_ACTIONS">

                        <button
                            type="reset"
                            class="AES_BTN AES_BTN_LIGHT"
                        >
                            <i class="fa-solid fa-rotate-left"></i>
                            Reset
                        </button>

                        <button
                            type="submit"
                            class="AES_BTN AES_BTN_PRIMARY"
                            <?php echo !AE_is_admin() ? "disabled" : ""; ?>
                        >
                            <i class="fa-solid fa-floppy-disk"></i>
                            Save General Settings
                        </button>

                    </div>

                </form>

            </section>


            <!-- =================================================
                 PRIVACY POLICY
                 ================================================= -->

            <section
                class="AES_PANEL <?php echo $current_section === "privacy" ? "is-active" : ""; ?>"
                data-panel="privacy"
            >

                <div class="AES_PANEL_HEAD">

                    <div>

                        <span class="AES_KICKER">
                            PRIVACY & LEGAL
                        </span>

                        <h2>
                            Privacy Policy
                        </h2>

                        <p>
                            Manage your privacy policy, terms and
                            cookie information in one place.
                        </p>

                    </div>

                    <div class="AES_PANEL_ICON">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>

                </div>


                <?php if (!AE_is_admin()): ?>

                    <div class="AES_LOCK_NOTICE">

                        <div class="AES_LOCK_ICON">
                            <i class="fa-solid fa-lock"></i>
                        </div>

                        <div class="AES_LOCK_TEXT">

                            <strong>
                                Administrator access required for editing
                            </strong>

                            <p>
                                Privacy information is protected and can
                                only be changed after admin login.
                            </p>

                        </div>

                        <a href="login.php">
                            Login
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>

                    </div>

                <?php endif; ?>


                <form method="POST" class="AES_FORM">

                    <input
                        type="hidden"
                        name="form_type"
                        value="privacy"
                    >


                    <!-- PRIVACY POLICY -->

                    <div class="AES_BLOCK">

                        <div class="AES_BLOCK_HEAD">

                            <div class="AES_BLOCK_ICON">
                                <i class="fa-solid fa-user-shield"></i>
                            </div>

                            <div>

                                <h3>
                                    Privacy Policy
                                </h3>

                                <p>
                                    Explain how customer information is collected
                                    and used.
                                </p>

                            </div>

                        </div>


                        <div class="AES_FIELD AES_FULL">

                            <label>
                                Privacy Policy Content
                            </label>

                            <textarea
                                name="policy_text"
                                rows="12"
                                placeholder="Write your privacy policy..."
                            ><?php echo AE_e($privacy["policy_text"]); ?></textarea>

                        </div>

                    </div>


                    <!-- TERMS -->

                    <div class="AES_BLOCK">

                        <div class="AES_BLOCK_HEAD">

                            <div class="AES_BLOCK_ICON">
                                <i class="fa-solid fa-file-contract"></i>
                            </div>

                            <div>

                                <h3>
                                    Terms & Conditions
                                </h3>

                                <p>
                                    Basic conditions for using the store.
                                </p>

                            </div>

                        </div>


                        <div class="AES_FIELD AES_FULL">

                            <label>
                                Terms & Conditions
                            </label>

                            <textarea
                                name="terms_text"
                                rows="11"
                                placeholder="Write your terms and conditions..."
                            ><?php echo AE_e($privacy["terms_text"]); ?></textarea>

                        </div>

                    </div>


                    <!-- COOKIE NOTICE -->

                    <div class="AES_BLOCK">

                        <div class="AES_BLOCK_HEAD">

                            <div class="AES_BLOCK_ICON">
                                <i class="fa-solid fa-cookie-bite"></i>
                            </div>

                            <div>

                                <h3>
                                    Cookie Notice
                                </h3>

                                <p>
                                    Short customer-facing notice about essential cookies.
                                </p>

                            </div>

                        </div>


                        <div class="AES_FIELD AES_FULL">

                            <label>
                                Cookie Notice
                            </label>

                            <textarea
                                name="cookie_notice"
                                rows="6"
                                placeholder="Write your cookie notice..."
                            ><?php echo AE_e($privacy["cookie_notice"]); ?></textarea>

                        </div>

                    </div>


                    <!-- PRIVACY FEATURES -->

                    <div class="AES_FEATURE_GRID">

                        <div class="AES_FEATURE">

                            <div class="AES_FEATURE_ICON">
                                <i class="fa-solid fa-user-lock"></i>
                            </div>

                            <div>

                                <strong>
                                    Customer Privacy
                                </strong>

                                <p>
                                    Keep customer information protected
                                    and explain its purpose clearly.
                                </p>

                            </div>

                        </div>


                        <div class="AES_FEATURE">

                            <div class="AES_FEATURE_ICON">
                                <i class="fa-solid fa-database"></i>
                            </div>

                            <div>

                                <strong>
                                    Data Information
                                </strong>

                                <p>
                                    Tell customers what information is
                                    collected and how it is used.
                                </p>

                            </div>

                        </div>


                        <div class="AES_FEATURE">

                            <div class="AES_FEATURE_ICON">
                                <i class="fa-solid fa-file-circle-check"></i>
                            </div>

                            <div>

                                <strong>
                                    Policy Updates
                                </strong>

                                <p>
                                    Keep your store policy updated as
                                    your business processes change.
                                </p>

                            </div>

                        </div>

                    </div>


                    <div class="AES_FORM_ACTIONS">

                        <button
                            type="submit"
                            class="AES_BTN AES_BTN_PRIMARY"
                            <?php echo !AE_is_admin() ? "disabled" : ""; ?>
                        >
                            <i class="fa-solid fa-floppy-disk"></i>
                            Save Privacy Information
                        </button>

                    </div>

                </form>

            </section>


            <!-- =================================================
                 HELP & SUPPORT
                 ================================================= -->

            <section
                class="AES_PANEL <?php echo $current_section === "help" ? "is-active" : ""; ?>"
                data-panel="help"
            >

                <div class="AES_PANEL_HEAD">

                    <div>

                        <span class="AES_KICKER">
                            CUSTOMER CARE
                        </span>

                        <h2>
                            Help & Support
                        </h2>

                        <p>
                            Find quick answers or send a support request
                            directly to Awaisi Emporium.
                        </p>

                    </div>

                    <div class="AES_PANEL_ICON">
                        <i class="fa-solid fa-headset"></i>
                    </div>

                </div>


                <!-- CONTACT CARDS -->

                <div class="AES_SUPPORT_GRID">

                    <div class="AES_SUPPORT_CARD">

                        <div class="AES_SUPPORT_ICON">
                            <i class="fa-solid fa-envelope"></i>
                        </div>

                        <div>

                            <span>
                                EMAIL SUPPORT
                            </span>

                            <strong>
                                <?php echo AE_e($support_email); ?>
                            </strong>

                            <a href="mailto:<?php echo AE_e($support_email); ?>">
                                Send Email
                                <i class="fa-solid fa-arrow-right"></i>
                            </a>

                        </div>

                    </div>


                    <div class="AES_SUPPORT_CARD">

                        <div class="AES_SUPPORT_ICON">
                            <i class="fa-solid fa-phone"></i>
                        </div>

                        <div>

                            <span>
                                PHONE SUPPORT
                            </span>

                            <strong>
                                <?php echo AE_e($phone); ?>
                            </strong>

                            <a href="tel:<?php echo AE_e($phone); ?>">
                                Call Us
                                <i class="fa-solid fa-arrow-right"></i>
                            </a>

                        </div>

                    </div>


                    <div class="AES_SUPPORT_CARD">

                        <div class="AES_SUPPORT_ICON">
                            <i class="fa-solid fa-location-dot"></i>
                        </div>

                        <div>

                            <span>
                                STORE LOCATION
                            </span>

                            <strong>
                                <?php echo AE_e($address); ?>
                            </strong>

                            <a href="#AES_SUPPORT_FORM">
                                Open Form
                                <i class="fa-solid fa-arrow-down"></i>
                            </a>

                        </div>

                    </div>

                </div>


                <!-- FAQ -->

                <div class="AES_SUPPORT_SECTION">

                    <div class="AES_SECTION_TITLE">

                        <span>
                            QUICK ANSWERS
                        </span>

                        <h3>
                            Frequently Asked Questions
                        </h3>

                        <p>
                            Click any question to open its answer.
                        </p>

                    </div>


                    <div class="AES_FAQ">

                        <?php foreach ($faqs as $faq): ?>

                            <div class="AES_FAQ_ITEM">

                                <button
                                    type="button"
                                    class="AES_FAQ_QUESTION"
                                >

                                    <span>
                                        <?php echo AE_e($faq["question"]); ?>
                                    </span>

                                    <i class="fa-solid fa-plus"></i>

                                </button>

                                <div class="AES_FAQ_ANSWER">

                                    <p>
                                        <?php echo AE_e($faq["answer"]); ?>
                                    </p>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>


                <!-- SUPPORT FORM -->

                <div
                    class="AES_SUPPORT_SECTION"
                    id="AES_SUPPORT_FORM"
                >

                    <div class="AES_SECTION_TITLE">

                        <span>
                            CONTACT SUPPORT
                        </span>

                        <h3>
                            Send Us a Message
                        </h3>

                        <p>
                            Your support request will be stored in
                            the database for review.
                        </p>

                    </div>


                    <form method="POST" class="AES_FORM">

                        <input
                            type="hidden"
                            name="form_type"
                            value="support"
                        >


                        <div class="AES_GRID">

                            <div class="AES_FIELD">

                                <label>
                                    Your Name
                                </label>

                                <div class="AES_INPUT_WRAP">

                                    <i class="fa-solid fa-user"></i>

                                    <input
                                        type="text"
                                        name="customer_name"
                                        required
                                        value="<?php echo AE_e($_POST["customer_name"] ?? ""); ?>"
                                        placeholder="Enter your name"
                                    >

                                </div>

                            </div>


                            <div class="AES_FIELD">

                                <label>
                                    Your Email
                                </label>

                                <div class="AES_INPUT_WRAP">

                                    <i class="fa-solid fa-envelope"></i>

                                    <input
                                        type="email"
                                        name="customer_email"
                                        required
                                        value="<?php echo AE_e($_POST["customer_email"] ?? ""); ?>"
                                        placeholder="you@example.com"
                                    >

                                </div>

                            </div>


                            <div class="AES_FIELD AES_FULL">

                                <label>
                                    Subject
                                </label>

                                <div class="AES_INPUT_WRAP">

                                    <i class="fa-solid fa-tag"></i>

                                    <input
                                        type="text"
                                        name="subject"
                                        required
                                        value="<?php echo AE_e($_POST["subject"] ?? ""); ?>"
                                        placeholder="What do you need help with?"
                                    >

                                </div>

                            </div>


                            <div class="AES_FIELD AES_FULL">

                                <label>
                                    Message
                                </label>

                                <div class="AES_TEXTAREA_WRAP">

                                    <i class="fa-solid fa-message"></i>

                                    <textarea
                                        name="message"
                                        rows="8"
                                        required
                                        placeholder="Write your message here..."
                                    ><?php echo AE_e($_POST["message"] ?? ""); ?></textarea>

                                </div>

                            </div>

                        </div>


                        <div class="AES_FORM_ACTIONS">

                            <button
                                type="reset"
                                class="AES_BTN AES_BTN_LIGHT"
                            >
                                <i class="fa-solid fa-rotate-left"></i>
                                Clear
                            </button>

                            <button
                                type="submit"
                                class="AES_BTN AES_BTN_PRIMARY"
                            >
                                <i class="fa-solid fa-paper-plane"></i>
                                Send Support Request
                            </button>

                        </div>

                    </form>

                </div>


                <!-- EXTRA SUPPORT INFO -->

                <div class="AES_SUPPORT_BOTTOM">

                    <div class="AES_SUPPORT_BOTTOM_ICON">
                        <i class="fa-solid fa-circle-info"></i>
                    </div>

                    <div>

                        <strong>
                            For faster support
                        </strong>

                        <p>
                            Include the product name, order details or
                            a clear description of your issue.
                        </p>

                    </div>

                    <a href="contact.php">
                        Contact Page
                        <i class="fa-solid fa-arrow-right"></i>
                    </a>

                </div>

            </section>

        </div>

    </section>

</main>


<style>

/* =========================================================
   AWAISI EMPORIUM
   PREMIUM SETTINGS PAGE
   CHANGE COLORS HERE
   ========================================================= */

:root {

    --AES_MAROON: #6e1830;
    --AES_DEEP: #42101f;
    --AES_LIGHT_MAROON: #8d304b;

    --AES_GOLD: #d8b56a;
    --AES_CHAMPAGNE: #ead39d;

    --AES_IVORY: #fffdf8;
    --AES_CREAM: #f4ebdd;
    --AES_WHITE: #ffffff;

    --AES_TEXT: #321923;
    --AES_MUTED: #806a70;

    --AES_BORDER: #eadbd0;

    --AES_SHADOW:
        0 24px 70px rgba(66, 16, 31, 0.10);

}


/* =========================================================
   BASE
   ========================================================= */

.AES_PAGE {

    min-height: 100vh;

    background:
        linear-gradient(
            180deg,
            #fffdf8 0%,
            #f7efe4 100%
        );

    color: var(--AES_TEXT);

}


.AES_PAGE *,
.AES_PAGE *::before,
.AES_PAGE *::after {

    box-sizing: border-box;

}


.AES_PAGE button,
.AES_PAGE input,
.AES_PAGE textarea,
.AES_PAGE select {

    font: inherit;

}


/* =========================================================
   HERO
   ========================================================= */

.AES_HERO {

    position: relative;

    min-height: 430px;

    overflow: hidden;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 50px;

    padding:
        75px 7%;

    background:
        linear-gradient(
            135deg,
            #fffdf8 0%,
            #f6ecdf 100%
        );

    border-bottom:
        1px solid var(--AES_BORDER);

}


.AES_HERO_CONTENT {

    position: relative;

    z-index: 2;

    max-width: 760px;

}


.AES_BADGE {

    display: inline-flex;

    align-items: center;

    gap: 8px;

    padding:
        9px 15px;

    border:
        1px solid rgba(110, 24, 48, 0.15);

    border-radius: 50px;

    background:
        rgba(255,255,255,0.72);

    color:
        var(--AES_MAROON);

    font-size: 10px;

    font-weight: 900;

    letter-spacing: 2px;

}


.AES_BADGE i {

    color:
        var(--AES_GOLD);

}


.AES_HERO h1 {

    margin:
        20px 0 0;

    font-size:
        clamp(44px, 7vw, 82px);

    line-height: 0.98;

    letter-spacing: -4px;

}


.AES_HERO h1 span {

    display: block;

    color:
        var(--AES_MAROON);

}


.AES_HERO p {

    max-width: 680px;

    margin:
        24px 0 0;

    color:
        var(--AES_MUTED);

    font-size: 15px;

    line-height: 1.85;

}


.AES_HERO_META {

    display: flex;

    flex-wrap: wrap;

    gap: 10px;

    margin-top: 27px;

}


.AES_HERO_META div {

    display: inline-flex;

    align-items: center;

    gap: 8px;

    padding:
        9px 12px;

    border-radius: 10px;

    background:
        rgba(255,255,255,0.75);

    border:
        1px solid var(--AES_BORDER);

    color:
        var(--AES_MUTED);

    font-size: 10px;

    font-weight: 800;

}


.AES_HERO_META i {

    color:
        var(--AES_MAROON);

}


.AES_HERO_ART {

    position: relative;

    width: 350px;

    height: 350px;

    flex-shrink: 0;

    display: grid;

    place-items: center;

}


.AES_HERO_RING {

    position: absolute;

    border:
        1px solid rgba(110,24,48,0.15);

    border-radius: 50%;

}


.AES_RING_ONE {

    width: 290px;

    height: 290px;

}


.AES_RING_TWO {

    width: 220px;

    height: 220px;

    border-color:
        rgba(216,181,106,0.45);

}


.AES_HERO_CARD {

    position: relative;

    z-index: 2;

    width: 165px;

    height: 185px;

    display: flex;

    flex-direction: column;

    align-items: center;

    justify-content: center;

    gap: 6px;

    border:
        1px solid rgba(255,255,255,0.25);

    border-radius: 34px;

    background:
        linear-gradient(
            145deg,
            var(--AES_MAROON),
            var(--AES_DEEP)
        );

    color:
        var(--AES_WHITE);

    box-shadow:
        0 35px 80px rgba(66,16,31,0.25);

    transform:
        rotate(-4deg);

}


.AES_HERO_CARD i {

    margin-bottom: 8px;

    color:
        var(--AES_GOLD);

    font-size: 37px;

}


.AES_HERO_CARD span {

    font-size: 9px;

    letter-spacing: 3px;

    opacity: 0.7;

}


.AES_HERO_CARD strong {

    font-size: 17px;

    letter-spacing: 2px;

}


.AES_HERO_GLOW {

    position: absolute;

    border-radius: 50%;

    filter: blur(10px);

    pointer-events: none;

}


.AES_HERO_GLOW_ONE {

    width: 360px;

    height: 360px;

    top: -170px;

    right: 20%;

    background:
        rgba(216,181,106,0.18);

}


.AES_HERO_GLOW_TWO {

    width: 300px;

    height: 300px;

    bottom: -190px;

    left: 15%;

    background:
        rgba(110,24,48,0.09);

}


/* =========================================================
   MAIN LAYOUT
   ========================================================= */

.AES_LAYOUT {

    width: min(1450px, 100%);

    margin:
        0 auto;

    padding:
        55px 5% 90px;

    display: grid;

    grid-template-columns:
        305px minmax(0, 1fr);

    gap: 30px;

    align-items: start;

}


/* =========================================================
   SIDEBAR
   ========================================================= */

.AES_SIDE {

    position: sticky;

    top: 25px;

    padding:
        24px;

    border:
        1px solid var(--AES_BORDER);

    border-radius: 25px;

    background:
        rgba(255,255,255,0.86);

    box-shadow:
        var(--AES_SHADOW);

}


.AES_SIDE_TOP {

    padding:
        3px 7px 20px;

}


.AES_SIDE_TOP > span {

    color:
        var(--AES_GOLD);

    font-size: 9px;

    font-weight: 900;

    letter-spacing: 2px;

}


.AES_SIDE_TOP h2 {

    margin:
        7px 0 5px;

    font-size: 23px;

}


.AES_SIDE_TOP p {

    margin: 0;

    color:
        var(--AES_MUTED);

    font-size: 11px;

    line-height: 1.6;

}


/* =========================================================
   NAV BUTTON
   ========================================================= */

.AES_NAV {

    width: 100%;

    display: flex;

    align-items: center;

    gap: 12px;

    margin-bottom: 9px;

    padding:
        12px;

    border:
        1px solid transparent;

    border-radius: 16px;

    background:
        transparent;

    color:
        var(--AES_TEXT);

    text-align: left;

    cursor: pointer;

    transition:
        0.28s ease;

}


.AES_NAV:hover {

    background:
        var(--AES_CREAM);

    transform:
        translateX(2px);

}


.AES_NAV.is-active {

    background:
        linear-gradient(
            135deg,
            var(--AES_MAROON),
            var(--AES_DEEP)
        );

    color:
        white;

    box-shadow:
        0 15px 35px rgba(110,24,48,0.18);

}


.AES_NAV_ICON {

    width: 45px;

    height: 45px;

    display: grid;

    place-items: center;

    flex-shrink: 0;

    border-radius: 13px;

    background:
        var(--AES_CREAM);

    color:
        var(--AES_MAROON);

}


.AES_NAV.is-active .AES_NAV_ICON {

    background:
        rgba(255,255,255,0.12);

    color:
        var(--AES_GOLD);

}


.AES_NAV_TEXT {

    min-width: 0;

    display: flex;

    flex-direction: column;

    gap: 3px;

}


.AES_NAV_TEXT strong {

    font-size: 12px;

}


.AES_NAV_TEXT small {

    color:
        var(--AES_MUTED);

    font-size: 9px;

}


.AES_NAV.is-active .AES_NAV_TEXT small {

    color:
        rgba(255,255,255,0.65);

}


.AES_NAV_ARROW {

    margin-left: auto;

    font-size: 9px;

    opacity: 0.55;

}


.AES_SIDE_FOOT {

    display: flex;

    gap: 10px;

    margin-top: 20px;

    padding:
        14px;

    border-radius: 15px;

    background:
        var(--AES_CREAM);

    color:
        var(--AES_MUTED);

    font-size: 10px;

    line-height: 1.6;

}


.AES_SIDE_FOOT i {

    color:
        var(--AES_MAROON);

    margin-top: 2px;

}


/* =========================================================
   CONTENT
   ========================================================= */

.AES_CONTENT {

    min-width: 0;

}


.AES_PANEL {

    display: none;

    padding:
        31px;

    border:
        1px solid var(--AES_BORDER);

    border-radius: 27px;

    background:
        rgba(255,255,255,0.88);

    box-shadow:
        var(--AES_SHADOW);

}


.AES_PANEL.is-active {

    display: block;

    animation:
        AES_PANEL_IN 0.36s ease;

}


@keyframes AES_PANEL_IN {

    from {

        opacity: 0;

        transform:
            translateY(14px);

    }

    to {

        opacity: 1;

        transform:
            translateY(0);

    }

}


/* =========================================================
   PANEL HEADER
   ========================================================= */

.AES_PANEL_HEAD {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 25px;

    padding-bottom:
        25px;

    margin-bottom:
        26px;

    border-bottom:
        1px solid var(--AES_BORDER);

}


.AES_KICKER {

    color:
        var(--AES_GOLD);

    font-size: 9px;

    font-weight: 900;

    letter-spacing: 2px;

}


.AES_PANEL_HEAD h2 {

    margin:
        7px 0;

    font-size:
        clamp(27px, 4vw, 36px);

    letter-spacing:
        -1px;

}


.AES_PANEL_HEAD p {

    max-width: 650px;

    margin: 0;

    color:
        var(--AES_MUTED);

    font-size: 12px;

    line-height: 1.7;

}


.AES_PANEL_ICON {

    width: 65px;

    height: 65px;

    display: grid;

    place-items: center;

    flex-shrink: 0;

    border-radius: 20px;

    background:
        linear-gradient(
            145deg,
            var(--AES_MAROON),
            var(--AES_DEEP)
        );

    color:
        var(--AES_GOLD);

    font-size: 23px;

    box-shadow:
        0 15px 30px rgba(66,16,31,0.17);

}


/* =========================================================
   ALERTS
   ========================================================= */

.AES_ALERT {

    display: flex;

    align-items: center;

    gap: 12px;

    margin-bottom:
        18px;

    padding:
        15px 17px;

    border-radius:
        15px;

    font-size:
        12px;

}


.AES_ALERT_SUCCESS {

    background:
        #edf8f1;

    color:
        #23633a;

    border:
        1px solid #cce9d5;

}


.AES_ALERT_ERROR {

    background:
        #fff0f0;

    color:
        #8b2635;

    border:
        1px solid #efcaca;

}


.AES_ALERT_CLOSE {

    margin-left:
        auto;

    border: 0;

    background:
        transparent;

    color:
        inherit;

    cursor:
        pointer;

}


/* =========================================================
   LOCK NOTICE
   ========================================================= */

.AES_LOCK_NOTICE {

    display: flex;

    align-items: center;

    gap: 13px;

    margin-bottom:
        24px;

    padding:
        15px;

    border:
        1px solid var(--AES_BORDER);

    border-radius:
        16px;

    background:
        var(--AES_CREAM);

}


.AES_LOCK_ICON {

    width: 42px;

    height: 42px;

    display: grid;

    place-items: center;

    flex-shrink: 0;

    border-radius: 12px;

    background:
        var(--AES_MAROON);

    color:
        var(--AES_GOLD);

}


.AES_LOCK_TEXT {

    flex: 1;

}


.AES_LOCK_TEXT strong {

    font-size:
        12px;

}


.AES_LOCK_TEXT p {

    margin:
        4px 0 0;

    color:
        var(--AES_MUTED);

    font-size:
        10px;

    line-height:
        1.5;

}


.AES_LOCK_NOTICE > a {

    display: inline-flex;

    align-items: center;

    gap: 7px;

    padding:
        10px 14px;

    border-radius:
        10px;

    background:
        var(--AES_MAROON);

    color:
        white;

    text-decoration:
        none;

    font-size:
        10px;

    font-weight:
        900;

}


/* =========================================================
   FORM BLOCKS
   ========================================================= */

.AES_BLOCK {

    padding:
        25px 0;

    border-bottom:
        1px solid var(--AES_BORDER);

}


.AES_BLOCK:last-of-type {

    border-bottom:
        0;

}


.AES_BLOCK_HEAD {

    display:
        flex;

    gap:
        13px;

    align-items:
        flex-start;

    margin-bottom:
        20px;

}


.AES_BLOCK_ICON {

    width:
        43px;

    height:
        43px;

    display:
        grid;

    place-items:
        center;

    flex-shrink:
        0;

    border-radius:
        13px;

    background:
        var(--AES_CREAM);

    color:
        var(--AES_MAROON);

}


.AES_BLOCK_HEAD h3 {

    margin:
        0 0 4px;

    font-size:
        16px;

}


.AES_BLOCK_HEAD p {

    margin:
        0;

    color:
        var(--AES_MUTED);

    font-size:
        10px;

    line-height:
        1.6;

}


/* =========================================================
   FORM GRID
   ========================================================= */

.AES_GRID {

    display:
        grid;

    grid-template-columns:
        repeat(2, minmax(0, 1fr));

    gap:
        18px;

}


.AES_FULL {

    grid-column:
        1 / -1;

}


.AES_FIELD {

    min-width:
        0;

    display:
        flex;

    flex-direction:
        column;

    gap:
        7px;

}


.AES_FIELD > label {

    font-size:
        10px;

    font-weight:
        900;

}


.AES_INPUT_WRAP,
.AES_TEXTAREA_WRAP {

    position:
        relative;

}


.AES_INPUT_WRAP > i,
.AES_TEXTAREA_WRAP > i {

    position:
        absolute;

    top:
        15px;

    left:
        14px;

    z-index:
        2;

    color:
        var(--AES_MAROON);

    font-size:
        12px;

}


.AES_TEXTAREA_WRAP > i {

    top:
        16px;

}


.AES_INPUT_WRAP input,
.AES_FIELD > textarea,
.AES_TEXTAREA_WRAP textarea,
.AES_PREFERENCE select {

    width:
        100%;

    border:
        1px solid var(--AES_BORDER);

    outline:
        none;

    border-radius:
        12px;

    background:
        var(--AES_IVORY);

    color:
        var(--AES_TEXT);

    font-size:
        12px;

    transition:
        0.25s ease;

}


.AES_INPUT_WRAP input {

    height:
        47px;

    padding:
        0 14px 0 39px;

}


.AES_FIELD > textarea,
.AES_TEXTAREA_WRAP textarea {

    min-height:
        125px;

    padding:
        13px 14px 13px 39px;

    resize:
        vertical;

    line-height:
        1.7;

}


.AES_FIELD > textarea {

    padding:
        14px;

}


.AES_INPUT_WRAP input:focus,
.AES_FIELD > textarea:focus,
.AES_TEXTAREA_WRAP textarea:focus,
.AES_PREFERENCE select:focus {

    border-color:
        var(--AES_MAROON);

    box-shadow:
        0 0 0 3px rgba(110,24,48,0.07);

}


/* =========================================================
   PREFERENCES
   ========================================================= */

.AES_PREFERENCE_GRID {

    display:
        grid;

    grid-template-columns:
        repeat(2, minmax(0, 1fr));

    gap:
        16px;

}


.AES_PREFERENCE {

    display:
        flex;

    align-items:
        center;

    justify-content:
        space-between;

    gap:
        20px;

    padding:
        17px;

    border:
        1px solid var(--AES_BORDER);

    border-radius:
        16px;

    background:
        var(--AES_IVORY);

}


.AES_PREFERENCE strong {

    display:
        block;

    margin-bottom:
        5px;

    font-size:
        12px;

}


.AES_PREFERENCE small {

    display:
        block;

    color:
        var(--AES_MUTED);

    font-size:
        9px;

    line-height:
        1.5;

}


.AES_PREFERENCE select {

    max-width:
        210px;

    height:
        42px;

    padding:
        0 11px;

}


/* =========================================================
   SWITCH
   ========================================================= */

.AES_SWITCH {

    position:
        relative;

    width:
        52px;

    height:
        29px;

    flex-shrink:
        0;

}


.AES_SWITCH input {

    position:
        absolute;

    opacity:
        0;

}


.AES_SWITCH span {

    position:
        absolute;

    inset:
        0;

    border-radius:
        50px;

    background:
        #d8ceca;

    cursor:
        pointer;

    transition:
        0.25s ease;

}


.AES_SWITCH span::after {

    content:
        "";

    position:
        absolute;

    width:
        21px;

    height:
        21px;

    top:
        4px;

    left:
        4px;

    border-radius:
        50%;

    background:
        white;

    box-shadow:
        0 2px 7px rgba(0,0,0,0.16);

    transition:
        0.25s ease;

}


.AES_SWITCH input:checked + span {

    background:
        var(--AES_MAROON);

}


.AES_SWITCH input:checked + span::after {

    transform:
        translateX(23px);

}


/* =========================================================
   BUTTONS
   ========================================================= */

.AES_FORM_ACTIONS {

    display:
        flex;

    justify-content:
        flex-end;

    gap:
        10px;

    padding-top:
        25px;

}


.AES_BTN {

    min-height:
        44px;

    display:
        inline-flex;

    align-items:
        center;

    justify-content:
        center;

    gap:
        8px;

    padding:
        0 17px;

    border:
        0;

    border-radius:
        12px;

    cursor:
        pointer;

    font-size:
        11px;

    font-weight:
        900;

    transition:
        0.25s ease;

}


.AES_BTN_PRIMARY {

    background:
        var(--AES_MAROON);

    color:
        white;

}


.AES_BTN_PRIMARY:hover:not(:disabled) {

    background:
        var(--AES_DEEP);

    transform:
        translateY(-2px);

    box-shadow:
        0 12px 25px rgba(110,24,48,0.18);

}


.AES_BTN_LIGHT {

    background:
        var(--AES_CREAM);

    color:
        var(--AES_TEXT);

}


.AES_BTN:disabled {

    opacity:
        0.45;

    cursor:
        not-allowed;

}


/* =========================================================
   PRIVACY FEATURE CARDS
   ========================================================= */

.AES_FEATURE_GRID {

    display:
        grid;

    grid-template-columns:
        repeat(3, minmax(0, 1fr));

    gap:
        13px;

    margin-top:
        25px;

}


.AES_FEATURE {

    display:
        flex;

    gap:
        11px;

    padding:
        17px;

    border:
        1px solid var(--AES_BORDER);

    border-radius:
        16px;

    background:
        var(--AES_IVORY);

}


.AES_FEATURE_ICON {

    width:
        39px;

    height:
        39px;

    display:
        grid;

    place-items:
        center;

    flex-shrink:
        0;

    border-radius:
        11px;

    background:
        var(--AES_CREAM);

    color:
        var(--AES_MAROON);

}


.AES_FEATURE strong {

    display:
        block;

    margin-bottom:
        5px;

    font-size:
        11px;

}


.AES_FEATURE p {

    margin:
        0;

    color:
        var(--AES_MUTED);

    font-size:
        9px;

    line-height:
        1.6;

}


/* =========================================================
   SUPPORT CARDS
   ========================================================= */

.AES_SUPPORT_GRID {

    display:
        grid;

    grid-template-columns:
        repeat(3, minmax(0, 1fr));

    gap:
        14px;

    margin-bottom:
        35px;

}


.AES_SUPPORT_CARD {

    display:
        flex;

    gap:
        12px;

    padding:
        18px;

    border:
        1px solid var(--AES_BORDER);

    border-radius:
        17px;

    background:
        var(--AES_IVORY);

    transition:
        0.25s ease;

}


.AES_SUPPORT_CARD:hover {

    transform:
        translateY(-3px);

    box-shadow:
        0 12px 30px rgba(66,16,31,0.07);

}


.AES_SUPPORT_ICON {

    width:
        44px;

    height:
        44px;

    display:
        grid;

    place-items:
        center;

    flex-shrink:
        0;

    border-radius:
        13px;

    background:
        var(--AES_CREAM);

    color:
        var(--AES_MAROON);

}


.AES_SUPPORT_CARD > div:last-child {

    min-width:
        0;

}


.AES_SUPPORT_CARD span {

    display:
        block;

    margin-bottom:
        5px;

    color:
        var(--AES_MUTED);

    font-size:
        8px;

    font-weight:
        900;

    letter-spacing:
        1.5px;

}


.AES_SUPPORT_CARD strong {

    display:
        block;

    overflow-wrap:
        anywhere;

    font-size:
        11px;

}


.AES_SUPPORT_CARD a {

    display:
        inline-flex;

    align-items:
        center;

    gap:
        6px;

    margin-top:
        9px;

    color:
        var(--AES_MAROON);

    text-decoration:
        none;

    font-size:
        9px;

    font-weight:
        900;

}


/* =========================================================
   SUPPORT SECTION
   ========================================================= */

.AES_SUPPORT_SECTION {

    padding:
        30px 0;

    border-top:
        1px solid var(--AES_BORDER);

}


.AES_SECTION_TITLE > span {

    color:
        var(--AES_GOLD);

    font-size:
        9px;

    font-weight:
        900;

    letter-spacing:
        2px;

}


.AES_SECTION_TITLE h3 {

    margin:
        6px 0;

    font-size:
        23px;

}


.AES_SECTION_TITLE p {

    margin:
        0;

    color:
        var(--AES_MUTED);

    font-size:
        11px;

    line-height:
        1.6;

}


/* =========================================================
   FAQ
   ========================================================= */

.AES_FAQ {

    margin-top:
        20px;

    overflow:
        hidden;

    border:
        1px solid var(--AES_BORDER);

    border-radius:
        17px;

}


.AES_FAQ_ITEM + .AES_FAQ_ITEM {

    border-top:
        1px solid var(--AES_BORDER);

}


.AES_FAQ_QUESTION {

    width:
        100%;

    display:
        flex;

    align-items:
        center;

    justify-content:
        space-between;

    gap:
        15px;

    padding:
        17px;

    border:
        0;

    background:
        var(--AES_IVORY);

    color:
        var(--AES_TEXT);

    text-align:
        left;

    cursor:
        pointer;

    font-size:
        11px;

    font-weight:
        900;

}


.AES_FAQ_QUESTION i {

    color:
        var(--AES_MAROON);

    transition:
        0.25s ease;

}


.AES_FAQ_ITEM.is-open
.AES_FAQ_QUESTION i {

    transform:
        rotate(45deg);

}


.AES_FAQ_ANSWER {

    display:
        none;

    padding:
        0 17px 17px;

    background:
        var(--AES_IVORY);

}


.AES_FAQ_ITEM.is-open
.AES_FAQ_ANSWER {

    display:
        block;

    animation:
        AES_PANEL_IN 0.25s ease;

}


.AES_FAQ_ANSWER p {

    margin:
        0;

    color:
        var(--AES_MUTED);

    font-size:
        11px;

    line-height:
        1.7;

}


/* =========================================================
   SUPPORT BOTTOM
   ========================================================= */

.AES_SUPPORT_BOTTOM {

    display:
        flex;

    align-items:
        center;

    gap:
        13px;

    margin-top:
        18px;

    padding:
        18px;

    border-radius:
        17px;

    background:
        var(--AES_CREAM);

}


.AES_SUPPORT_BOTTOM_ICON {

    width:
        42px;

    height:
        42px;

    display:
        grid;

    place-items:
        center;

    flex-shrink:
        0;

    border-radius:
        12px;

    background:
        var(--AES_MAROON);

    color:
        var(--AES_GOLD);

}


.AES_SUPPORT_BOTTOM > div:nth-child(2) {

    flex:
        1;

}


.AES_SUPPORT_BOTTOM strong {

    font-size:
        12px;

}


.AES_SUPPORT_BOTTOM p {

    margin:
        4px 0 0;

    color:
        var(--AES_MUTED);

    font-size:
        9px;

    line-height:
        1.6;

}


.AES_SUPPORT_BOTTOM > a {

    display:
        inline-flex;

    align-items:
        center;

    gap:
        7px;

    padding:
        10px 14px;

    border-radius:
        10px;

    background:
        var(--AES_MAROON);

    color:
        white;

    text-decoration:
        none;

    font-size:
        9px;

    font-weight:
        900;

    white-space:
        nowrap;

}


/* =========================================================
   TABLET
   ========================================================= */

@media (max-width: 1100px) {

    .AES_LAYOUT {

        grid-template-columns:
            250px minmax(0, 1fr);

    }


    .AES_SUPPORT_GRID,
    .AES_FEATURE_GRID {

        grid-template-columns:
            1fr;

    }

}


/* =========================================================
   MOBILE
   ========================================================= */

@media (max-width: 820px) {

    .AES_HERO {

        min-height:
            auto;

        padding:
            60px 22px;

    }


    .AES_HERO_ART {

        display:
            none;

    }


    .AES_LAYOUT {

        display:
            block;

        padding:
            30px 20px 65px;

    }


    .AES_SIDE {

        position:
            static;

        margin-bottom:
            20px;

    }


    .AES_PANEL {

        padding:
            21px;

    }


    .AES_GRID,
    .AES_PREFERENCE_GRID {

        grid-template-columns:
            1fr;

    }


    .AES_FULL {

        grid-column:
            auto;

    }


    .AES_LOCK_NOTICE {

        align-items:
            flex-start;

        flex-wrap:
            wrap;

    }


    .AES_LOCK_TEXT {

        min-width:
            180px;

    }


    .AES_LOCK_NOTICE > a {

        margin-left:
            55px;

    }

}


/* =========================================================
   SMALL MOBILE
   ========================================================= */

@media (max-width: 520px) {

    .AES_HERO h1 {

        font-size:
            43px;

        letter-spacing:
            -2px;

    }


    .AES_HERO p {

        font-size:
            13px;

    }


    .AES_HERO_META {

        display:
            grid;

        grid-template-columns:
            1fr;

    }


    .AES_PANEL_HEAD {

        align-items:
            flex-start;

    }


    .AES_PANEL_ICON {

        width:
            50px;

        height:
            50px;

        border-radius:
            15px;

    }


    .AES_PANEL_HEAD h2 {

        font-size:
            27px;

    }


    .AES_FORM_ACTIONS {

        flex-direction:
            column-reverse;

    }


    .AES_BTN {

        width:
            100%;

    }


    .AES_SUPPORT_BOTTOM {

        align-items:
            flex-start;

        flex-wrap:
            wrap;

    }


    .AES_SUPPORT_BOTTOM > a {

        width:
            100%;

        justify-content:
            center;

    }

}

</style>


<script>

/* =========================================================
   SETTINGS PAGE JAVASCRIPT
   ========================================================= */

document.addEventListener(
    "DOMContentLoaded",
    function () {

        const navButtons =
            document.querySelectorAll(".AES_NAV");

        const panels =
            document.querySelectorAll(".AES_PANEL");


        /* =================================================
           OPEN ONLY THE CLICKED SETTINGS SECTION
           ================================================= */

        function openPanel(name, updateUrl = true) {

            navButtons.forEach(function (button) {

                button.classList.toggle(
                    "is-active",
                    button.dataset.open === name
                );

            });


            panels.forEach(function (panel) {

                panel.classList.toggle(
                    "is-active",
                    panel.dataset.panel === name
                );

            });


            if (updateUrl) {

                const newUrl =
                    window.location.pathname +
                    "?section=" +
                    encodeURIComponent(name);

                window.history.pushState(
                    {},
                    "",
                    newUrl
                );

            }

        }


        navButtons.forEach(function (button) {

            button.addEventListener(
                "click",
                function () {

                    openPanel(
                        this.dataset.open
                    );

                    window.scrollTo({
                        top:
                            document.querySelector(".AES_LAYOUT").offsetTop - 15,
                        behavior:
                            "smooth"
                    });

                }
            );

        });


        /* =================================================
           BROWSER BACK / FORWARD
           ================================================= */

        window.addEventListener(
            "popstate",
            function () {

                const params =
                    new URLSearchParams(
                        window.location.search
                    );

                const section =
                    params.get("section") || "general";

                openPanel(section, false);

            }
        );


        /* =================================================
           FAQ ACCORDION
           ================================================= */

        const faqItems =
            document.querySelectorAll(".AES_FAQ_ITEM");


        faqItems.forEach(function (item) {

            const question =
                item.querySelector(
                    ".AES_FAQ_QUESTION"
                );


            question.addEventListener(
                "click",
                function () {

                    faqItems.forEach(
                        function (otherItem) {

                            if (otherItem !== item) {

                                otherItem.classList.remove(
                                    "is-open"
                                );

                            }

                        }
                    );


                    item.classList.toggle(
                        "is-open"
                    );

                }
            );

        });


        /* =================================================
           ALERT CLOSE
           ================================================= */

        document.querySelectorAll(
            ".AES_ALERT_CLOSE"
        ).forEach(function (button) {

            button.addEventListener(
                "click",
                function () {

                    const alert =
                        this.closest(".AES_ALERT");

                    if (alert) {
                        alert.remove();
                    }

                }
            );

        });


        /* =================================================
           SMOOTH SUPPORT FORM LINK
           ================================================= */

        document.querySelectorAll(
            'a[href="#AES_SUPPORT_FORM"]'
        ).forEach(function (link) {

            link.addEventListener(
                "click",
                function (event) {

                    const target =
                        document.querySelector(
                            "#AES_SUPPORT_FORM"
                        );

                    if (!target) {
                        return;
                    }

                    event.preventDefault();

                    target.scrollIntoView({
                        behavior:
                            "smooth",
                        block:
                            "start"
                    });

                }
            );

        });

    }
);

</script>


<?php

/* =========================================================
   FOOTER
   ========================================================= */

include "footer.php";

?>
