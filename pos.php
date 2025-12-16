<?php
// pos.php

require_once __DIR__ . '/includes/db_connect.php';

session_start();
date_default_timezone_set('Asia/Bangkok');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// Replace existing restore_receipt_cart_to_session_cart() with this version.
// Place it after session_start() and after includes/db_connect.php so $conn is available.

function restore_receipt_cart_to_session_cart(array $receipt_cart): array {
    global $conn; // uses DB connection to look up missing image/temp_sku/barcode
    $newCart = [];

    foreach ($receipt_cart as $idx => $it) {
        // sale_items rows often have: product_id, quantity, price_sold, product_name_th, product_name_en
        $product_id = $it['product_id'] ?? $it['id'] ?? null;

        // stable key: numeric product_id if possible, otherwise custom_<idx>
        $cart_key = is_numeric($product_id) ? (int)$product_id : ('custom_' . $idx);

        $quantity = isset($it['quantity']) ? max(1, intval($it['quantity'])) : 1;

        // prefer price_sold (from sale_items), fall back to price or 0.00
        if (isset($it['price_sold'])) {
            $price = floatval($it['price_sold']);
        } elseif (isset($it['price'])) {
            $price = floatval($it['price']);
        } else {
            $price = 0.00;
        }

        $name_en = $it['product_name_en'] ?? $it['name_en'] ?? $it['name'] ?? '';
        $name_th = $it['product_name_th'] ?? $it['name_th'] ?? $it['name'] ?? '';

        $temp_sku = $it['temp_sku'] ?? '';
        $barcode = $it['barcode'] ?? '';
        $image = '';

        // If this came from a real product (numeric id), try to get canonical image & codes from DB
        if (is_numeric($product_id) && intval($product_id) > 0 && isset($conn) && $conn) {
            $pid = intval($product_id);
            $stmt = $conn->prepare("SELECT image, temp_sku, barcode FROM products WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $pid);
                if ($stmt->execute()) {
                    $stmt->bind_result($db_image, $db_code, $db_barcode);
                    if ($stmt->fetch()) {
                        // normalize image: remove leading "uploads/" if present
                        $db_image = (string)($db_image ?? '');
                        $db_image = preg_replace('#^uploads/#i', '', trim($db_image));
                        $image = $db_image;
                        $temp_sku = $temp_sku ?: ($db_code ?? '');
                        $barcode = $barcode ?: ($db_barcode ?? '');
                    }
                }
                $stmt->close();
            }
        } else {
            // For custom items, try to use any image/temp_sku provided in the receipt row (if any)
            $raw_img = $it['image'] ?? '';
            $raw_img = preg_replace('#^uploads/#i', '', trim((string)$raw_img));
            $image = $raw_img;
        }

        $newCart[$cart_key] = [
            'id' => $cart_key,
            'name_en' => $name_en,
            'name_th' => $name_th,
            'temp_sku' => $temp_sku,
            'price' => $price,
            'quantity' => $quantity,
            'in_stock' => $it['in_stock'] ?? 0,
            'barcode' => $barcode,
            // store image WITHOUT leading "uploads/" (cart rendering prepends "uploads/")
            'image' => $image,
            'custom' => !is_numeric($product_id)
        ];
    }

    return $newCart;
}




// --- POST handlers for resume / start new sale (must be before other POST logic) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['resume_last_sale'])) {
      if (!empty($_SESSION['last_sale_data']) && !empty($_SESSION['last_sale_data']['cart']) && is_array($_SESSION['last_sale_data']['cart'])) {
        $_SESSION['cart'] = restore_receipt_cart_to_session_cart($_SESSION['last_sale_data']['cart']);
        $_SESSION['discount_percent'] = $_SESSION['last_sale_data']['discount_percent'] ?? 0.00;
        $_SESSION['discount_amount']  = $_SESSION['last_sale_data']['discount_amount'] ?? 0.00;
        $_SESSION['payment_method']   = $_SESSION['last_sale_data']['payment_method'] ?? 'cash';
      }
      header("Location: pos.php");
      exit;
    }
    if (isset($_POST['start_new_sale'])) {
        $_SESSION['cart'] = [];
        $_SESSION['discount_percent'] = 0.00;
        $_SESSION['discount_amount'] = 0.00;
        // keep last_sale_data for reprint/audit
        header("Location: pos.php");
        exit;
    }
}

// restore via GET (legacy shortcut) - normalize restored cart as well
if (isset($_GET['restore_last_sale']) && !empty($_SESSION['last_sale_data']) && !empty($_SESSION['last_sale_data']['cart']) && is_array($_SESSION['last_sale_data']['cart'])) {
  $_SESSION['cart'] = restore_receipt_cart_to_session_cart($_SESSION['last_sale_data']['cart']);
  $_SESSION['discount_percent'] = $_SESSION['last_sale_data']['discount_percent'] ?? 0.00;
  $_SESSION['discount_amount']  = $_SESSION['last_sale_data']['discount_amount'] ?? 0.00;
  $_SESSION['payment_method']   = $_SESSION['last_sale_data']['payment_method'] ?? 'cash';
  header("Location: pos.php"); exit;
}

if (isset($_GET['clear_search'])) {
    unset($_GET['product_search']);
}
$search_query = isset($_GET['product_search']) ? trim($_GET['product_search']) : '';

function product_img($img) {
    $img = trim($img);
    if ($img && file_exists(__DIR__ . "/uploads/$img")) return "uploads/$img";
    return "uploads/no-image.png";
}

// small helper to truncate labels to N chars (multibyte-safe)
if (!function_exists('crf_truncate_label')) {
    function crf_truncate_label($s, $len = 40) {
        $s = trim((string)$s);
        if (mb_strlen($s, 'UTF-8') <= $len) return $s;
        return mb_substr($s, 0, $len - 1, 'UTF-8') . '…';
    }
}

$staff_name = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$branch_id = isset($_SESSION['branch_id']) ? intval($_SESSION['branch_id']) : 1;
if (!$staff_name) {
    header("Location: login.php");
    exit;
}
$stmt = $conn->prepare("SELECT branch_name FROM branches WHERE id = ?");
$stmt->bind_param("i", $branch_id);
$stmt->execute();
$stmt->bind_result($branch_name);
$stmt->fetch();
$stmt->close();

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];
$cart = &$_SESSION['cart'];

if (!isset($_SESSION['held_bills']) || !is_array($_SESSION['held_bills'])) $_SESSION['held_bills'] = [];
$held_bills = &$_SESSION['held_bills'];

if (!isset($_SESSION['payment_method'])) $_SESSION['payment_method'] = 'cash';
// Prefer explicit button submit, then JS hidden field, then session
$payment_method = $_POST['payment_method'] ?? $_POST['payment_method_selected'] ?? $_SESSION['payment_method'] ?? 'cash';
$_SESSION['payment_method'] = $payment_method;

$error = '';
$success = '';
$show_receipt = false;
// --------- DISCOUNT SYSTEM (persisted in session) ---------
if (!isset($_SESSION['discount_percent'])) $_SESSION['discount_percent'] = 0.00;
if (!isset($_SESSION['discount_amount'])) $_SESSION['discount_amount'] = 0.00;

if (isset($_POST['set_discount_tier'])) {
    $discount_percent = floatval($_POST['set_discount_tier']);
    $discount_amount = 0.00;
    $_SESSION['discount_percent'] = $discount_percent;
    $_SESSION['discount_amount'] = $discount_amount;
} elseif (isset($_POST['reset_discount'])) {
    $discount_percent = 0.00;
    $discount_amount = 0.00;
    $_SESSION['discount_percent'] = $discount_percent;
    $_SESSION['discount_amount'] = $discount_amount;
} else {
    $discount_percent = isset($_POST['discount_percent']) ? floatval($_POST['discount_percent']) : $_SESSION['discount_percent'];
    $discount_amount = isset($_POST['discount_amount']) ? floatval($_POST['discount_amount']) : $_SESSION['discount_amount'];
    $_SESSION['discount_percent'] = $discount_percent;
    $_SESSION['discount_amount'] = $discount_amount;
}

$subtotal = 0.00;
foreach ($cart as $item) {
    $subtotal += ($item['price'] ?? 0) * ($item['quantity'] ?? 0);
}

// Suggested discount tiers
if ($subtotal >= 5000) {
    $suggested_discount = 15.0;
} elseif ($subtotal >= 2000) {
    $suggested_discount = 12.5;
} elseif ($subtotal >= 1000) {
    $suggested_discount = 10.0;
} elseif ($subtotal >= 500) {
    $suggested_discount = 7.5;
} else {
    $suggested_discount = 5.0;
}
$discount_buttons = [0, 5, 7.5, 10, 12.5, 15, 20, 25, 30];

$discount_amount_by_percent = $discount_percent > 0 ? ($subtotal * $discount_percent) / 100.0 : 0.00;
$total_discount = max($discount_amount, $discount_amount_by_percent);
$total_discount = min($total_discount, $subtotal);
$total = $subtotal - $total_discount;

// --------- PRODUCT QUICK KEYS & SEARCH ---------
$quick_key_products = [];
if ($result = $conn->query("SELECT * FROM products WHERE quick_key = 1 ORDER BY name_th LIMIT 24")) {
    while ($row = $result->fetch_assoc()) $quick_key_products[] = $row;
}
$product_search_results = [];
$search_query = isset($_GET['product_search']) ? trim($_GET['product_search']) : '';
$auto_add_enabled = isset($_GET['auto_add']) ? intval($_GET['auto_add']) : 1;

// --------- ADD CUSTOM ITEM ---------
if (isset($_POST['add_custom_item'])) {
    $custom_name = trim($_POST['custom_name'] ?? '');
    $custom_price = floatval($_POST['custom_price'] ?? 0);
    $custom_qty = max(1, intval($_POST['custom_qty'] ?? 1));
    if ($custom_name && $custom_price > 0 && $custom_qty > 0) {
        $custom_id = 'custom_' . substr(md5($custom_name . $custom_price . microtime(true)), 0, 8);
        $cart[$custom_id] = [
            'id' => $custom_id,
            'name_en' => $custom_name,
            'name_th' => $custom_name,
            'temp_sku' => '',
            'price' => $custom_price,
            'quantity' => $custom_qty,
            'in_stock' => 999999,
            'barcode' => '',
            'image' => '',
            'custom' => true
        ];
        $success = "เพิ่มรายการพิเศษ: {$custom_name} จำนวน {$custom_qty}";
    } else {
        $error = "กรุณากรอกชื่อสินค้า ราคา และจำนวนที่ถูกต้อง";
    }
}

// --------- SMART SEARCH/SCAN/ADD ---------
if (isset($_GET['product_search']) && $search_query !== '') {
    $q = $conn->real_escape_string($search_query);
    $sql_exact = "SELECT p.*, pb.quantity AS in_stock
        FROM products p
        INNER JOIN product_in_branch pb ON p.id = pb.product_id
        WHERE pb.branch_id = $branch_id
        AND (p.barcode = '$q' OR p.temp_sku = '$q')
        LIMIT 1";
    $exact_result = $conn->query($sql_exact);
    $exact_product = $exact_result ? $exact_result->fetch_assoc() : null;

    if ($auto_add_enabled && $exact_product && $exact_product['in_stock'] > 0) {
        $product_id = $exact_product['id'];
        if (isset($cart[$product_id])) {
            if ($cart[$product_id]['quantity'] < $exact_product['in_stock']) {
                $cart[$product_id]['quantity'] += 1;
                $success = "เพิ่ม {$exact_product['name_th']} x1";
            } else {
                $error = "สต็อกไม่เพียงพอสำหรับ " . htmlspecialchars($exact_product['name_th'] ?? '');
            }
        } else {
            $cart[$product_id] = [
                'id' => $exact_product['id'],
                'name_en' => $exact_product['name_en'],
                'name_th' => $exact_product['name_th'],
                'temp_sku' => $exact_product['temp_sku'],
                'price' => $exact_product['price'],
                'quantity' => 1,
                'in_stock' => $exact_product['in_stock'],
                'barcode' => $exact_product['barcode'],
                'image' => isset($exact_product['image']) ? ((is_null($exact_product['image']) ? '' : trim((string)$exact_product['image']))) : '',
            ];
            $success = "เพิ่ม {$exact_product['name_th']} x1";
        }
        header("Location: pos.php");
        exit;
    } else {
        $sql = "SELECT * FROM products WHERE (name_th LIKE '%$q%' OR name_en LIKE '%$q%' OR temp_sku LIKE '%$q%' OR barcode LIKE '%$q%') ORDER BY name_th LIMIT 32";
        if ($result = $conn->query($sql)) {
            while ($row = $result->fetch_assoc()) $product_search_results[] = $row;
        }
        if (empty($product_search_results)) {
            $error = "ไม่พบสินค้า";
        }
    }
}

if (isset($_POST['scan_code'])) {
    $code = $conn->real_escape_string(trim($_POST['scan_code']));
    if ($code !== '') {
        $product = $conn->query(
            "SELECT p.*, pb.quantity AS in_stock
             FROM products p
             INNER JOIN product_in_branch pb ON p.id = pb.product_id
             WHERE pb.branch_id = $branch_id AND (p.barcode = '$code' OR p.temp_sku = '$code')"
        )->fetch_assoc();
        if ($product && $product['in_stock'] > 0) {
            $product_id = $product['id'];
            if (isset($cart[$product_id])) {
                if ($cart[$product_id]['quantity'] < $product['in_stock']) {
                    $cart[$product_id]['quantity'] += 1;
                    $success = "เพิ่ม {$product['name_th']} x1";
                } else {
                    $error = "สต็อกไม่เพียงพอสำหรับ " . htmlspecialchars($product['name_th'] ?? '');
                }
            } else {
                $cart[$product_id] = [
                    'id' => $product['id'],
                    'name_en' => $product['name_en'],
                    'name_th' => $product['name_th'],
                    'temp_sku' => $product['temp_sku'],
                    'price' => $product['price'],
                    'quantity' => 1,
                    'in_stock' => $product['in_stock'],
                    'barcode' => $product['barcode'],
                    'image' => isset($product['image']) ? ((is_null($product['image']) ? '' : trim((string)$product['image']))) : '',
                ];
                $success = "เพิ่ม {$product['name_th']} x1";
            }
        } else {
            $error = "ไม่พบสินค้า หรือสต็อกหมด";
        }
    }
}

// --------- CART & BILL ACTIONS ---------
if (isset($_POST['remove_product_id'])) {
    $remove_id = $_POST['remove_product_id'];
    unset($cart[$remove_id]);
}
if (isset($_POST['clear_cart'])) {
    $cart = [];
}
if (isset($_POST['update_cart'])) {
    if (isset($_POST['quantities']) && is_array($_POST['quantities'])) {
        foreach ($_POST['quantities'] as $pid => $qty) {
            $pid = $pid;
            $qty = max(1, intval($qty));
            if (isset($cart[$pid])) {
                $cart[$pid]['quantity'] = $qty;
            }
        }
    }
}
if (isset($_POST['incdec_item_id'])) {
    $pid = $_POST['incdec_item_id'];
    $action = $_POST['incdec_action'] ?? '';
    if (isset($cart[$pid])) {
        if ($action === 'inc') {
            $cart[$pid]['quantity'] += 1;
        } elseif ($action === 'dec' && $cart[$pid]['quantity'] > 1) {
            $cart[$pid]['quantity'] -= 1;
        }
    }
}

// --------- HOLD BILL ---------
if (isset($_POST['hold_bill'])) {
    if (!empty($cart)) {
        $hold_id = uniqid('bill_');
        $held_bills[$hold_id] = [
            'cart' => $cart,
            'discount_percent' => $discount_percent,
            'discount_amount' => $discount_amount,
            'time' => date('H:i'),
            'staff' => $staff_name
        ];
        $cart = [];
        $discount_percent = 0.00;
        $discount_amount = 0.00;
        $success = "พักบิลสำเร็จ สามารถเรียกคืนได้จากรายการบิลที่พักไว้";
    } else {
        $error = "ไม่สามารถพักบิลที่ว่างเปล่าได้";
    }
}
if (isset($_POST['resume_bill_id'])) {
    $resume_id = $_POST['resume_bill_id'];
    if (isset($held_bills[$resume_id])) {
        $cart = $held_bills[$resume_id]['cart'];
        $discount_percent = $held_bills[$resume_id]['discount_percent'];
        $discount_amount = $held_bills[$resume_id]['discount_amount'];
        unset($held_bills[$resume_id]);
        $success = "เรียกคืนบิลที่พักไว้เรียบร้อย";
    } else {
        $error = "ไม่พบข้อมูลบิลที่พักไว้";
    }
}
if (isset($_POST['remove_bill_id'])) {
    $remove_id = $_POST['remove_bill_id'];
    unset($held_bills[$remove_id]);
    $success = "ลบบิลที่พักไว้แล้ว";
}

// --------- CALCULATE TOTALS ---------
$subtotal = 0.00;
foreach ($cart as $item) {
    $subtotal += ($item['price'] ?? 0) * ($item['quantity'] ?? 0);
}
$total_discount = 0.00;
if ($discount_percent > 0) {
    $total_discount = round($subtotal * ($discount_percent/100), 2);
} elseif ($discount_amount > 0) {
    $total_discount = min($discount_amount, $subtotal);
}
$total = $subtotal - $total_discount;

$allowed_methods = ['cash', 'promptpay', 'card'];
if (!in_array($payment_method, $allowed_methods)) $payment_method = 'cash';

// --------- CHECKOUT (STOCK, RECORD, RECEIPT) ---------
if (isset($_POST['checkout'])) {
  $payment_method = $_POST['payment_method_selected'] ?? $_POST['payment_method'] ?? $payment_method;
  $_SESSION['payment_method'] = $payment_method;

  $insufficient = [];
  foreach ($cart as $item) {
      if (!empty($item['custom'])) continue;
      $product_id = $item['id'];
      $qty_needed = $item['quantity'];
      $result = $conn->query("SELECT quantity FROM product_in_branch WHERE branch_id=$branch_id AND product_id=$product_id");
      $row = $result ? $result->fetch_assoc() : null;
      $instock = $row ? intval($row['quantity']) : 0;
      if ($instock < $qty_needed) {
          $insufficient[] = [
              'name' => $item['name_th'] ?? ($item['name_en'] ?? ''),
              'have' => $instock,
              'want' => $qty_needed
          ];
      }
  }

  if (!empty($insufficient)) {
    $msg = "สินค้าในสต็อกไม่เพียงพอ:<br>";
    foreach ($insufficient as $inf) {
        $msg .= htmlspecialchars($inf['name']) . " (มี $inf[have], ต้องการ $inf[want])<br>";
    }
    $error = $msg;
    $show_receipt = false;
  } else {
    $payment_method = $_POST['payment_method_selected'] ?? $_POST['payment_method'] ?? $payment_method;
    $_SESSION['payment_method'] = $payment_method;

    if ($payment_method === 'cash') {
        $cash_received_raw = $_POST['cash_received'] ?? '';
        $cash_received_raw = preg_replace('/[^\d\.\-]/', '', (string)$cash_received_raw);
        $cash_received = $cash_received_raw === '' ? 0.0 : floatval($cash_received_raw);
        $total_rounded = round($total, 2);
        if ($cash_received < $total_rounded) {
            $error = "รับเงินจากลูกค้าน้อยกว่าราคาสินค้ารวม กรุณาตรวจสอบ";
            $show_receipt = false;
        } else {
            $change = round($cash_received - $total_rounded, 2);
        }
    }

    // For promptpay we accept confirmation from JS modal via promptpay_confirm = '1'
    if ($payment_method === 'promptpay') {
        $pp_confirm = $_POST['promptpay_confirm'] ?? '0';
        if ($pp_confirm !== '1') {
            // If not confirmed by cashier, treat as not ready to complete checkout (prevent accidental submit).
            $error = "กรุณายืนยันการชำระเงิน PromptPay ก่อนกดชำระ";
            $show_receipt = false;
        }
    }

    if (empty($error)) {
      foreach ($cart as $item) {
          if (!empty($item['custom'])) continue;
          $product_id = $item['id'];
          $qty_needed = $item['quantity'];
          $conn->query("UPDATE product_in_branch SET quantity = quantity - $qty_needed WHERE branch_id=$branch_id AND product_id=$product_id");
      }

      $stmt = $conn->prepare("INSERT INTO sales (branch_id, sale_date, staff_name, total, payment_method, subtotal, discount, discount_percent) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?)");
      $stmt->bind_param("isdsddd", $branch_id, $staff_name, $total, $payment_method, $subtotal, $total_discount, $discount_percent);
      $stmt->execute();
      $sale_id = $stmt->insert_id;
      $stmt->close();

      // Insert sale_items (snapshotting)
      foreach ($cart as $item) {
        $is_custom = !empty($item['custom']) || (isset($item['id']) && !is_numeric($item['id']));
        if ($is_custom) {
            $product_id = isset($item['id']) ? $item['id'] : 0;
            $custom_name = trim($item['name_th'] ?? $item['name_en'] ?? $item['temp_sku'] ?? '');
            $stmt = $conn->prepare(
                "INSERT INTO sale_items (sale_id, product_id, quantity, price_sold, product_name_th, product_name_en)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("iiidss", $sale_id, $product_id, $item['quantity'], $item['price'], $custom_name, $custom_name);
            $stmt->execute();
            if ($stmt->errno) {
                error_log("sale_items insert error (custom): {$stmt->errno} {$stmt->error} sale_id={$sale_id} pid={$product_id}");
            }
            $stmt->close();
            continue;
        }

        $pid = intval($item['id']);
        $prodRow = null;
        $prodResult = $conn->query("SELECT receipt_name_th, receipt_name_en, name_th, name_en, temp_sku FROM products WHERE id = {$pid} LIMIT 1");
        if ($prodResult) {
            $prodRow = $prodResult->fetch_assoc();
        } else {
            error_log("products lookup failed for pid={$pid}: " . $conn->error);
        }

        $product_name_th = $prodRow['receipt_name_th'] ?? $prodRow['name_th'] ?? $prodRow['temp_sku'] ?? '';
        $product_name_en = $prodRow['receipt_name_en'] ?? $prodRow['name_en'] ?? $prodRow['temp_sku'] ?? '';

        $stmt = $conn->prepare(
            "INSERT INTO sale_items (sale_id, product_id, quantity, price_sold, product_name_th, product_name_en)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("iiidss", $sale_id, $pid, $item['quantity'], $item['price'], $product_name_th, $product_name_en);
        $stmt->execute();
        if ($stmt->errno) {
            error_log("sale_items insert error: {$stmt->errno} {$stmt->error} sale_id={$sale_id} pid={$pid}");
        }
        $stmt->close();
      }

      // Rebuild receipt cart from saved sale_items
      $receipt_cart = [];
      $res = $conn->query(
        "SELECT si.*,
                COALESCE(NULLIF(si.product_name_en, ''), p.name_en) AS name_en,
                COALESCE(NULLIF(si.product_name_th, ''), p.name_th) AS name_th,
                p.name_th AS product_name_from_product_th,
                p.name_en AS product_name_from_product_en
         FROM sale_items si
         LEFT JOIN products p ON p.id = si.product_id
         WHERE si.sale_id = " . intval($sale_id) . "
         ORDER BY si.id"
      );
      while ($r = $res->fetch_assoc()) {
          $receipt_cart[] = $r;
      }

      $receipt_data = [
          'id' => $sale_id,
          'date' => date('Y-m-d H:i'),
          'staff' => $staff_name,
          'branch' => $branch_name,
          'cart' => $receipt_cart,
          'subtotal' => $subtotal,
          'discount' => $total_discount,
          'discount_percent' => $discount_percent,
          'discount_amount' => $discount_amount,
          'total' => $total,
          'payment_method' => $payment_method,
          'cash_received' => ($payment_method === 'cash') ? ($cash_received ?? null) : null,
          'change' => ($payment_method === 'cash') ? (isset($change) ? max(0, $change) : 0) : null,
          'lang' => 'en'
      ];
      $_SESSION['last_sale_data'] = $receipt_data;
      $show_receipt = true;

      // clear cart for new sale
      $cart = [];
      $discount_percent = 0.00;
      $discount_amount = 0.00;
      $_SESSION['discount_percent'] = 0.00;
      $_SESSION['discount_amount'] = 0.00;
      $_SESSION['payment_method'] = 'cash';
      $success = "ขายสินค้าเรียบร้อย";
    }
  }
}

function generate_promptpay_qr_url($mobile, $amount) {
    $clean = preg_replace('/[^0-9]/', '', $mobile);
    $amount = number_format(floatval($amount ?? 0), 2, '.', '');
    return "https://promptpay.io/{$clean}/{$amount}.png";
}
function mask_promptpay_phone($mobile) {
    $clean = preg_replace('/[^0-9]/', '', $mobile);
    if (strlen($clean) === 10) {
        return substr($clean,0,3) . '-XXX-' . substr($clean,7);
    }
    return $clean;
}


// Set a central PromptPay tel (can be replaced from settings later)
$PROMPTPAY_TEL = '0828676775';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;500;700&family=Poppins:wght@400;500;600;700&family=Work+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Work+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <title>CRF POS system</title>
    <meta charset="utf-8">
    <style>
:root {
    --crf-blue-darkest: #6582A8;
    --crf-blue-medium: #B8CEEA;
    --crf-blue-lightest: #E3EAF4;
    --crf-taupe-darkest: #DEDDD4;
    --crf-taupe-medium: #EFEEE6;
    --crf-taupe-lightest: #F9F6F0;
    --crf-alert: #FFC461;
    --crf-white: #fff;
    --crf-success: #008800;
    --crf-danger: #FF734E;
    --crf-grey: #dee3ee;
    --crf-grey-lightest: #D3E3FB;
    --crf-green: #28a745;
    --crf-dark: #222222;
    --crf-blue-navy: #38475A;
}
body {

  font-family: 'Inter', sans-serif;

  margin: 0;
  color: #111; /* optional, clean readable text color */
  /*background-image: linear-gradient(to top, #F0F6FF 10%, #B8CEEA 100%);*/
  background:linear-gradient(180deg,#D2E5FF 0%,#EFF6FF 100%)
}

.logo{text-decoration: none;
    cursor: pointer;width:54px;height:54px;border-radius:50px;background:linear-gradient(135deg,#fff,#F3F8FF);display:flex;align-items:center;justify-content:center;color:black;font-weight:500;font-size:20px}



h1, h2, h3, h4, h5, h6 {
  font-family: 'Playfair Display', serif;
  font-weight: 600; /* adjust for stronger titles */
  color: #000; /* optional for darker, elegant headings */
}

/* Improve modal backdrop appearance (improved cash / QR backdrop) */
.modal-backdrop {
    position: fixed;
    z-index: 200000 !important;
    left: 0;
    top: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(0,0,0,0.45) !important;
    display: none;
}
.modal-backdrop.show {
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Middle links */
.nav-links {
    display: flex;
    gap: 20px;
}

.nav-button {
    padding: 8px 16px;
    background-color: #E1ECFC;
    color: #7893BD;
    border-radius: 50px;
    text-decoration: none;
    font-weight: 500;
    transition: background 0.3s;
}

.nav-button:hover {
    background-color: var(--crf-blue-medium);;
}

/* Right side user info */
.nav-user-wrap {
    text-align: right;
}

.nav-user-wrap h2 {
    font-size: 16px;
}

.nav-user {
    font-size: 14px;
    color: #666;
}

.nav-user { color:var(--crf-blue-darkest);
   font-size: 0.98em;}

/* ---- NEW 3-COLUMN LAYOUT ---- */
html, body {
  overflow-x: hidden;
}

.main-flex-3col {
  display: flex;
  height: calc(100vh - 55px);
  overflow: hidden;

  /* Desktop spacing */
  padding-right: 10px; 
}

side-panel,
  .cart-col,
  .payment-col {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    margin: 0;
    padding: 0;
  }



/* Side Panel container */
.side-panel {
  flex: 0 0 90px;
  min-width: 90px;
  max-width: 90px;
  display: flex;
  flex-direction: column;
  gap: 5px;
  padding: 0 7px;
  align-items: stretch;
}

/* Side Panel buttons base style */
.side-panel-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    background-color: #fff;
    border: 1px solid #B8CEEA;
    border-radius: 14px; /* match main UI radius */
    color: #575F69;
    font-size: 1.1em;
    font-weight: 300;
    padding: 12px 10px;
    box-shadow: 0 2px 8px rgba(60, 90, 130, 0.04);
    cursor: pointer;
    transition: background 0.16s, color 0.16s, transform 0.13s;
}

/* Hover and focus state */
.side-panel-btn:hover,
.side-panel-btn:focus {
    background: #E1ECFC;
    color: #3866a3;
    border: 1px solid #fff;
    transform: translateY(-1px);
}

/* Active / pressed state */
.side-panel-btn:active {
    background: #e3eaf4;
    color: #1d2b3a;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.08);
}

/* Ensure full width for buttons in forms */
.side-panel form .side-panel-btn {
    width: 100%;
}


/* Default desktop view */
.side-panel {
  flex: 0 0 90px;
  min-width: 90px;
  max-width: 90px;
}
/* ---- MOBILE LAYOUT (MERGED & FIXED) ---- */
@media (max-width: 1100px) {

/* Layout turns into vertical stack */
.main-flex-3col {
  flex-direction: column;
  padding-left: 12px;
  padding-right: 12px;
  gap:10px;
}

/* All columns go full width */
.side-panel,
.cart-col,
.payment-col {
  width: 100% !important;
  max-width: 100% !important;
  min-width: 0 !important;
  margin: 0;
  padding: 0;
}

/* Tablet and mobile adjustments */
/* Mobile Side Panel - horizontal pill buttons */
/* Mobile Side Panel styling */
.side-panel {
    flex-direction: row;
    justify-content: space-between;
    align-items: stretch;
    gap: 6px;
   
    order: -1; /* move above everything */
    background: transparent;
  }

  /* Mobile buttons */
  .side-panel button.side-panel-btn,
  .side-panel form .side-panel-btn {
    flex: 1;
    font-size: 0.95em;
    padding: 10px 10px;
    border-radius: 20px;
    border: 0.5px solid #fff;
    box-shadow: none;
    background-color: #fff;
    min-height: 50px;
  }

  .side-panel button.side-panel-btn:hover {
    background-color: #E1ECFC;      /* subtle hover */
    border: 1px solid #fff;
  }
}



/* MIDDLE COLUMN: Search + Cart */
.cart-col {
  background: rgba(255, 255, 255, 0.5);
  border: 1px solid #fff;

  padding: 12px;
  backdrop-filter: blur(5px); /* optional */
  /*border: 1px solid #B8CEEA;*/
  border-radius: 14px;
  display: flex;
  flex-direction: column;
  flex: 1 1 0;
  min-width: 0;
  max-width: 100%;
  height: 100dvh; /* modern safe viewport height (works better than 100vh on mobile) */
  overflow: hidden;
  padding: 0 8px;
  margin-right: 8px;
  margin-left:
  box-sizing: border-box;
}

/* Keep search panel fixed at top */
.cart-col .search-panel {
  flex: 0 0 auto;
  /*margin-bottom: 10px;
  position: sticky;
  top: 0;
  /*background: #fff;*/
  z-index: 5;
}

/* Make cart panel scrollable */
.cart-col .cart-panel {
  flex: 1 1 auto;
  overflow-y: auto;
  overflow-x: hidden;
  -webkit-overflow-scrolling: touch; /* smooth scroll for iOS */
  padding-bottom:180px;
}

/* Scrollbar styling */
.cart-col .cart-panel::-webkit-scrollbar {
  width: 8px;
  background-color:#D3E4FF;
}

cart-col .cart-panel::-webkit-scrollbar-thumb {
  background-color: #C0D2EF;
  border-radius: 4px;
}

cart-col .cart-panel::-webkit-scrollbar-thumb:hover {
  background-color: rgba(101, 130, 168, 0.6);
}

cart-col .cart-panel::-webkit-scrollbar-track {
  background-color: white;
}

/* --- Mobile tweak --- */
@media (max-width: 768px) {
  .cart-col {
    height: auto; /* allow full natural height on mobile */
  }

  .cart-col .cart-panel {
    max-height: calc(100dvh - 140px); /* ensure it still scrolls within screen */
  }
}


/* ---- PAYMENT COLUMN (final cleaned version) ---- */

/* RIGHT COLUMN: Payment */
.payment-col {
  flex: 1 1 0;               /* flex to take space */
  min-width: 0;
  max-width: 400px;          /* narrower than middle column on desktop */
  display: flex;
  flex-direction: column;
  overflow: hidden;          /* prevent double scrollbars */
  padding: 12px;             /* inner spacing */
  padding-right: 5px;        /* optional adjustment for scroll */
  padding-bottom: 100px;     /* space for pay button */
  background: rgba(255, 255, 255, 0.6);
  border: 0.5px solid #fff;
  border-radius: 14px;
  box-shadow: 0 4px 14px rgba(83, 100, 130, 0.08);
  margin-left: 8px;          /* spacing from middle column */
}

/* --- Mobile tweak --- */
@media (max-width: 1100px) {
  .payment-col {
    max-width: 100%;         /* full width on mobile */
    margin: 0;               /* reset margins */
    border-radius: 14px;     /* more rounded corners on mobile */
    background: rgba(142, 172, 222, 0.5);
    padding:8px;         /* optional: adjust inner padding */
  }
}

/* Scrollable inner content */
.payment-scroll {
  flex: 1;
  overflow-y: auto;
  padding-right: 6px;
  margin-bottom: 12px;
  padding-bottom: 90px;       /* matches pay button area */
}

/* Optional scrollbar styling */
payment-scroll::-webkit-scrollbar {
  width: 8px;
}

payment-scroll::-webkit-scrollbar-thumb {
  background: #c1d1f0;
  border-radius: 4px;
}

payment-scroll::-webkit-scrollbar-track {
  background: #f1f7ff;
  border-radius: 4px;
}

/* Payment panel container */
.payment-panel {
  flex: 1 1 0;
  min-width: 0;
  box-sizing: border-box;
}


quick-keys-panel {
    /*background: #ffffff;*/
    border-radius: 9px;
    margin-bottom: px;
}
payment-panel {
    background: ;
    /*border: 1px solid var(--crf-white);*/
    border-radius: 8px;
    /*box-shadow: 0 6px 24px 0 rgba(68, 96, 130, 0.10), 0 1.5px 4px 0 rgba(68, 96, 130, 0.09);*/
    padding: 10px 0px 0px 0px;
    
}

/* ---- End New Layout ---- */

.cart-panel {
    background: 
    border: 1px solid var(--crf-white);
    border-radius: 8px;
    /*box-shadow: 0 6px 24px 0 rgba(68, 96, 130, 0.10), 0 1.5px 4px 0 rgba(68, 96, 130, 0.09);*/
    padding: 4px;
    
}

@media (max-width: 1100px) {

.payment-col {
  width: 100% !important;
  max-width: 100% !important;
  overflow-x: hidden; /* prevent overflow */
}

/* FIX TABLE OVERFLOW */
.payment-col table {
  width: 100%;
  max-width: 100%;
  table-layout: fixed;
  word-wrap: break-word;
}

/* MAKE BUTTONS WRAP */
.discount-quick,
.payment-method-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.discount-quick button,
.payment-method-buttons button {
  max-width: 100%;
  white-space: normal; /* allow wrapping */
}

/* Ensure inner scrolling instead of stretching */
.payment-scroll {
  max-width: 100%;
  overflow-x: hidden;
}
}


.btn, .btn-custom-item, .btn-grey, .btn-blue, .qty-btn, .icon-btn, .disc-btn, .pay-btn {
    border-radius: 9px !important;
    font-family: inherit;
    font-weight: 500;
    font-size: 1em;
    transition: background .16s, color .16s;
    border: none;
    outline: none;
    cursor: pointer;
    font-weight:400;
}
.btn-small-edge {
    border-radius: 8px !important;
    font-family: inherit;
    font-weight: 500;
    font-size: 1em;
    transition: background .16s, color .16s;
    border: none;
    outline: none;
    cursor: poinåter;
    background-color:#F0F6FF;
}
.btn-pay {
   background: black;
    color: var(--crf-blue-lightest);
    padding: 15px 26px;
    transition: background .16s, color .16s;
    border: none;
    outline: none;
    cursor: pointer;
    border-color: var(--crf-blue-darkest);
}
.btn-pay:hover {
    background: var(--crf-blue-medium);
    color: var(--crf-blue-navy);
}
.btn {
    background: var(--crf-blue-darkest);
    color: var(--crf-white);
    padding: 8px 26px;
}
.btn:hover { background: var(--crf-white); color: var(--crf-blue-navy); }
.btn-custom-item {
    background: var(--crf-blue-medium);
    color: var(--crf-blue-navy);
    padding: 10px 36px;
    font-size: 1.11em;
    font-weight: bold;
    margin-bottom: 13px;
    margin-right: 8px;
    box-shadow: 0 2px 8px #b8ceea33;
}
.btn-custom-item:hover {
    background: var(--crf-blue-medium);
    color: var(--crf-blue-darkest);
}
.btn-grey {
    background: #F1F7FF;
    color: #444;
    padding: 8px 26px;
    border: 1px solid #ffffff;

}
.btn-grey:hover {
    background: var( --crf-blue-medium);
    color: var(--crf-blue-darkest);
}

.btn-white {
    background: var(--crf-white);
    color: var(--crf-blue-navy);
    padding: 8px 26px;
}
.btn-white:hover {
    background: var(--crf-blue-darkest);
    color: var(--crf-white);
    padding: 8px 26px;
}

btn-blue {
    background: var(--crf-blue-darkest);
    color: #fff;
    padding: 8px 26px;
}
.btn-light{
    background: #fff;
    color: #000000;
    padding: 8px 26px;
    border: 1px dashed #FFC461;
}

.btn-light:hover {
    background:#FFEED3;
    color: #FF9F00;
    padding: 8px 26px;
}



.btn-blue:hover {
    background: var(--crf-blue-medium);
    color: var(--crf-blue-darkest);
}
.pay-btn {
    padding: 12px 28px;
    margin-right:16px;
    font-size: 1.11em;
    font-weight: bold;
    border: 2px solid transparent;
    background: var(--crf-taupe-lightest);
    color: var(--crf-blue-darkest);
}
.pay-btn.selected, .pay-btn.qr.selected {
    background: #E1FBE9;
    color: #79EF9C;
    border-color: #79EF9C;
}

.pay-btn:last-child { margin-right:0;}
.disc-btn {
    border-radius: 9px !important;
  
    font-weight: 600;
    margin-right: 7px;
    margin-bottom: 7px;
    padding: 7px 22px;
    border: 2px solid var(--crf-blue-medium);
    background: var(--crf-blue-lightest);
    color: var(--crf-blue-darkest);
    transition: all .13s;
    opacity: 1;
}
.disc-btn.selected, .disc-btn:focus,
.disc-btn.recommended:not(.selected) {
    background: var(--crf-blue-darkest);
    color: #fff;
    border-color: var(--crf-blue-darkest);
    box-shadow: 0 0 0 2px var(--crf-blue-darkest, #6582A8)44;
    opacity: 1;
    
}
.disc-btn.muted:not(.selected):not(.recommended) {
    background: var(--crf-blue-lightest);
    color: #a2adc0;
    border-color: #dee3ee;
    opacity: 0.70;
    border: 1px solid red;
}
.icon-btn {
    background: none;
    color: var(--crf-danger);
    font-size: 1.5em;
    line-height: 1;
    padding: 2px 10px;
    margin: 0 1px;
    border-radius: 8px;
}
.icon-btn:hover {
    background: var(--crf-blue-lightest);
}
.qty-icon.minus { position: relative; top: 2px; }
.qty-icon.plus {}
.qty-btn {
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  border-radius: 50%;
  font-size: 1.4em;
  font-family: 'Segoe UI Mono', 'Consolas', 'Courier New', monospace;
}
.qty-icon {
  display: block;
  width: 100%;
  text-align: center;
  font-family: inherit;
  font-size: 1em;
  line-height: 1;
}
.qty-btn:hover {
    background: var(--crf-blue-darkest);
    color: #fff;
}
.qty-input {
    width: 42px;
    font-size: 1em;
    text-align: center;
    border: 1.5px solid var(--crf-blue-lightest);
    border-radius: 9px;
    margin: 0 2px;
    background: var(--crf-white);
    color: var(--crf-blue-darkest);
}
.cart-table {
  width: 100%;
  border-collapse: collapse;
  background: #ffffff;
  border: 1px solid #d7e3f0;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 2px 12px rgba(40, 70, 120, 0.1);
  animation: fadeInCart 0.4s ease;
}

/* Header */
.cart-table th {
  background: #f2f7fd;
  color: #1e2b3b;
  text-align: left;
  padding: 14px 18px;
  font-weight: 400;
  border-bottom: 1px solid #dbe4f1;
  letter-spacing: 0.02em;
  font-size: 1.2em;
}

/* Base cell style */
.cart-table td {
  color: #2e3d52;
  padding: 14px 18px;
  border-bottom: 1px solid #e6edf7;
  vertical-align: middle;
}

/* ✨ Alternating row shades */
.cart-table tr:nth-child(odd):not(.cart-total-row) {
  background: #f9fbfe;
}
cart-table tr:nth-child(even):not(.cart-total-row) {
  background: #ffffff;
}

/* Hover effect - soft blue tint */
.cart-table tr:hover:not(.cart-total-row) {
  background: #edf5ff;
  transition: background 0.18s ease;
}

/* Total row */
.cart-table .cart-total-row td {
  background: #f4f8ff;
  font-weight: 400;
  font-size: 1.1em;
  color: #444444;
  border-top: 1px solid #d7e3f0;
}

/* Product image — more square & elegant */
cart-table td img {
  width: 72px;
  height: 72px;
  object-fit: cover;
  border-radius: 10px;
  box-shadow: 0 2px 8px rgba(165, 190, 230, 0.4);
}

/* Icon (delete) button */
.icon-btn {
  background: #ffecec;
  color: #d82c2c;
  border: 1px solid #ffd6d6;
  padding: 6px 10px;
  border-radius: 8px;
  cursor: pointer;
  transition: background 0.18s ease, color 0.18s ease;
}
.icon-btn:hover {
  background: #ffdede;
  color: #b12222;
}

/* Quantity buttons */
.qty-btn {
  background: #f0f5fc;
  color: #3866a3;
  border: 1px solid #d7e3f0;
  padding: 5px 10px;
  border-radius: 8px;
  cursor: pointer;
  transition: background 0.18s ease, color 0.18s ease, border-color 0.18s;
}
.qty-btn:hover {
  background: #3866a3;
  color: #fff;
  border-color: #3866a3;
}

/* Quantity input */
.qty-input {
  width: 48px;
  text-align: center;
  background: #f9fbfe;
  color: #2e3d52;
  border: 1.5px solid #d7e3f0;
  border-radius: 8px;
  padding: 5px 0;
  transition: border-color 0.18s, box-shadow 0.18s;
}
.qty-input:focus {
  border-color: #5384ff;
  box-shadow: 0 0 0 2px rgba(83, 132, 255, 0.2);
  outline: none;
}

/* Subtle entry animation */
@keyframes fadeInCart {
  from { opacity: 0; transform: translateY(6px); }
  to { opacity: 1; transform: translateY(0); }
}

.section-title { font-size: 1.12em; font-weight: bold; color: var(--crf-blue-navy); margin-bottom: 8px;}
.success-msg { 
  
  display: inline; /* or inline-block */
  background-color: #E1FBE9;
  color: var(--crf-success);
  font-weight: 300;
  border-radius: 50px; 
  padding: 4px 10px;
  font-size:1.2em;
  margin-top: 30px;
  
border: 1px solid var(--crf-success);


}
.error-msg {
  display: inline; /* or inline-block */
  background-color: #FFECE7;
  color: var(--crf-danger);
  font-weight: 300;
  border-radius: 50px; 
  padding: 4px 10px;
  font-size:1.2em;
  border: 1px solid var(--crf-danger);}

.custom-item-label {
    font-size: 0.93em;
    color: var(--crf-blue-darkest);
    font-weight: 500;
    margin-left: 4px;
}
.cart-table-wrapper {
  width: 100%;
  overflow-x: auto;
  /*box-shadow: 0 4px 12px rgba(186, 194, 205, 0.3);*/
  border-radius: 3px;
  padding:0px
}
@media (max-width: 900px) {
  .cart-table th, .cart-table td {
    padding: 10px 7px;
    font-size: 0.98em;
  }
  .cart-table .qty-btn,
  .cart-table .icon-btn {
    min-width: 32px;
    height: 32px;
    font-size: 1em;
    padding: 4px 8px;
  }
  .cart-table .qty-input {
    width: 34px;
    font-size: 0.95em;
  }
}
@media (max-width: 700px) {
  .cart-table-wrapper {
    padding: 0 0px;
    border-radius: 0;
    box-shadow: none;
    margin: 0;
  }
  .cart-table {
    min-width: 420px;
    font-size: 0.96em;
  }
  .cart-table th, .cart-table td {
    padding: 8px 3px;
    font-size: 0.95em;
  }
}
@media (max-width: 500px) {
  .cart-table .qty-btn,
  .cart-table .icon-btn {
    min-width: 36px;
    height: 38px;
    font-size: 1.1em;
    padding: 6px 10px;
  }
  .cart-table .qty-input {
    width: 34px;
    font-size: 1em;
  }
}
.held-bill-list table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  background: #fff;
  border-radius: 9px;
  overflow: hidden;
  box-shadow: 0 2px 18px #b8ceea22;
  margin-bottom: 20px;
}
.held-bill-list th,
.held-bill-list td {
  padding: 12px 10px;
  text-align: center;
  font-size: 1em;
}
.held-bill-list th {
  background: #f2f6fb;
  color: #2b3d5c;
  font-weight: bold;
  border-bottom: 2px solid #e3eaf4;
  letter-spacing: 0.05em;
}
.held-bill-list tr {
  transition: background 0.12s;
}
.held-bill-list tr:hover {
  background: #f8fbff;
}
.held-bill-list tr:not(:last-child) td {
  border-bottom: 1px solid #e3eaf4;
}
.held-bill-list th:first-child { border-radius: 9px 0 0 0;}
.held-bill-list th:last-child { border-radius: 0 9px 0 0;}
.held-bill-row-actions button {
  margin: 0 2px;
  border-radius: 9px;
  min-width: 60px;
}
/* Modal styles */

.modal-content {
    background: #fff;
    border-radius: 13px;
    padding: 32px 38px 26px 38px;
    max-width: 520px;
    width: 97%;
    margin: 0 auto;
    position: relative;
    box-shadow: 0 18px 80px #0003, 0 2px 12px #b8ceea22;
    max-height: 90vh;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    transition: max-width .2s, padding .2s;
    max-width: 660px; /* was 520px or 440px before */
    width: 98%;
}
.modal-close-btn {
    position: absolute;
    right: 18px;
    top: 12px;
    font-size: 2em;
    color: #999;
    background: none;
    border: none;
    cursor: pointer;
    transition: color 0.15s;
}
.modal-close-btn:hover {
    color: #555;
}
.modal-title {
    font-size: 1.22em;
    font-weight: bold;
    margin-bottom: 18px;
    color: var(--crf-blue-darkest);
    text-align: left;
    letter-spacing: 0.01em;
}
.modal-form-row {
    margin-bottom: 14px;
}
.modal-form-row label {
    display: block;
    font-weight: 500;
    margin-bottom: 3px;
    color: #555;
}
.modal-form-row input[type="text"],
.modal-form-row input[type="number"] {
    width: 100%;
    padding: 7px 12px;
    font-size: 1.06em;
    border-radius: 9px;
    border: 1.2px solid var(--crf-blue-lightest);
    margin-top: 3px;
    background: #fff;
    color: var(--crf-blue-darkest);
}
.modal-form-actions {
    margin-top: 10px;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}
/* Modal table style for all modal tables */
.modal-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: #fff;
    border-radius: 9px;
    overflow: hidden;
    box-shadow: 0 2px 18px #b8ceea22;
    margin-bottom: 0;
}
.modal-table th, .modal-table td {
    padding: 13px 10px;
    text-align: center;
    font-size: 1em;
}
.modal-table th {
    background: #E3EAF4;
    color: #2b3d5c;
    font-weight: bold;
    border-bottom: 2px solid #e3eaf4;
    letter-spacing: 0.05em;
}
.modal-table th, .modal-table td {
    padding: 16px 14px; /* was 13px 10px */
}
.modal-table tr {
    transition: background 0.12s;
}
.modal-table tr:hover {
    background: #f8fbff;
}
.modal-table tr:not(:last-child) td {
    border-bottom: 1px solid #e3eaf4;
}
.modal-table th:first-child { border-radius: 9px 0 0 0;}
.modal-table th:last-child { border-radius: 0 9px 0 0;}
.modal-row-actions button {
    margin: 0 2px;
    border-radius: 9px;
    min-width: 60px;
}
@media (max-width: 700px) {
    .modal-content {
        max-width: 98vw;
        padding: 16px 6vw 18px 6vw;
        border-radius: 9px;
    }
    .modal-title {
        font-size: 1.09em;
        margin-bottom: 12px;
    }
    .modal-table th, .modal-table td {
        padding: 7px 4px;
        font-size: 0.96em;
    }
}




.promptpay-qr-box {
    background: none;
    padding: 18px 10px 11px 10px;
    border-radius: 9px;
    margin: 16px 0;
    text-align: center;
}
.promptpay-qr-box img {
    background:#fff;
    border-radius:12px;
    width: 180px;
    margin-bottom: 8px;
}
.pay-method-pick {
    margin-bottom: 16px;
}
.pay-method-label {
    font-size:1.15em;
    color:var(--crf-blue-darkest);
    font-weight:bold;
    margin-right:16px;
}
.receipt-print-btn {
    background: var(--crf-blue-darkest);
    color: #fff;
    font-size: 1.13em;
    padding: 11px 32px;
    border-radius: 9px;
    border: none;
    margin-top: 16px;
    font-weight: bold;
    cursor: pointer;
    transition: background .14s;
    box-shadow: 0 2px 8px #b8ceea33;
    display: block;
    margin-left: auto;
    margin-right: auto;
    margin-bottom: 10px;
}
.receipt-print-btn:hover {
    background: var(--crf-blue-medium);
    color: var(--crf-blue-darkest);
}
.receipt-logo {
    width: 92px;
    margin: 0 auto 4px auto;
    display: block;
}
.receipt-header {
    text-align: center;
    margin-bottom: 7px;
}
.receipt-section {
    margin: 11px 0 11px 0;
}
@media (max-width: 900px) {
    .container-flex { flex-direction: column; gap: 16px;}
    .modal-content { max-width: 95vw;}
}
@media print {
body * {
    visibility: hidden !important;
}

  #receiptModal, #receiptModal  {
    margin-left: auto !important;
    margin-right: auto !important;
  }

#receiptModal, #receiptModal * {
    visibility: visible !important;
}
#receiptModal {
    position: absolute !important;
    left: 0; top: 0; width: 100vw; height: 100vh;
    background: #fff !important;
    z-index: 99999 !important;
    display: block !important;
    align-items: flex-start !important;
    justify-content: flex-start !important;
    overflow: visible !important;
}
.modal-content {
    box-shadow: none !important;
    max-height: none !important;
    overflow: visible !important;
    background: #fff !important;
    width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    border-radius: 0 !important;
}
.modal-close-btn,
.receipt-print-btn {
    display: none !important;
}
}
/* SEARCH PANEL */
.search-panel {

  padding: 12px 6px 1px 4px;
  /*margin-bottom: 10px;*/
}

/* Search form styling */
.search-form {
  display: flex;
  flex-wrap: wrap;      /* allow wrapping if needed on small screens */
  gap: 8px;
  width: 100%;
}

.search-form input[type="text"] {
  flex: 1 1 60%;        /* take 70% of available width */
  min-width: 200px;     /* prevent it from shrinking too much */
  padding: 12px 15px;
  font-size: 1.1em;
  border-radius: 9px;
  border: 0.5px solid #B8CEEA;
  box-sizing: border-box;
  background-color: #fff;
}

.search-form input[type="text"]:focus {
  border-color: #ffffff;
  background: #F0F6FF;
}

.search-form .btn {
  background: #8EACDE;
  border: 1px solid #F0F6FF;
  border-radius: 10px;
  padding: 10px 16px;
  font-size: 1em;
  color: #fff;
  transition: background 0.2s;
}

search-form .btn:hover {
  background: #D8E8FF;
  color:#444444;
}

/* Checkbox label */
.search-form label {
  font-size: 0.9em;
  color: #1d2b3a;
  display: flex;
  align-items: center;
  gap: 5px;
}

/* Quick keys list matches same background */
.quick-keys-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 10px;
  /*background: #F7FAFF;*/
  border-radius: 10px;

  overflow-x: auto;
  
}

/* Buttons inside quick keys */
.quick-keys-list .btn,
.quick-keys-list .btn-small-edge {
  background: #fff;
  /*border: 0.5px solid #B8CEEA;*/
  border-radius: 7px;
  padding: 6px;
  transition: background 0.2s;
}

.quick-keys-list .btn:hover,
.quick-keys-list .btn-small-edge:hover {
  background: #EFF6FF;
}

.quick-keys-list img {
  width: 80px;
  height: 80px;
  object-fit: cover;
  border-radius: 8px;
  margin-bottom: 4px;
  background: #f4f4f4;
}

.quick-keys-list span {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 82px;
  font-size: 0.9em;
  font-weight:400;
}


discount-label { color:var(--crf-blue-navy); font-weight:500; font-size:1.06em;}
.discount-input { width:75px; font-size:1.09em; padding:7px 10px; border:1.5px solid #b8ceea77; border-radius:9px; margin:0 7px;}
.discount-quick { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 10px 0;}
.discount-btn {
padding:3px 10px;
background:var(--crf-blue-medium);
color:var(--crf-blue-darkest);
border:none;
border-radius:50px;
cursor:pointer;
font-weight:300;
font-size:1em;
margin-right:7px;
transition:all .15s;
}
.discount-btn.active {
background:#FFEED3 !important;
color:#FF9F00!important;
font-weight:600;
border: 0.5px solid #FF9F00 !important;

}
.discount-btn.suggested {
  background:#8EADDD !important;
color:#fff !important;
font-weight:400;
/*border: 1px solid #B8CEEA !important;
/*border:1px dotted #84EAFA !important;*/
}
.discount-btn.muted {
background: #e9f2fb !important;
color: #a4b3c2 !important;
border: 0.5px solid white !important;
font-weight:normal !important;
opacity: 1;
}
.discount-btn.reset { background:#FFECE7; color:#FF734E;border: 0.5px solid red;}
.discount-btn:hover, .discount-btn:focus {
filter: brightness(1.08);
}


/* Improved summary table (drop-in replacement) */
.summary-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  border-radius: 12px;
  margin-bottom: 24px;
  overflow: hidden;
  background: #ffffff;
  border: 1px solid rgba(227,238,255,1); /* crisper, no !important */
  box-shadow: 0 1px 2px rgba(16,24,40,0.04);
  font-family: inherit;
}

/* Alternating rows (avoid styling the total row) */
.summary-table tbody tr:nth-child(odd):not(.summary-total-row) td {
  background: #F8FAFF;
}
.summary-table tbody tr:nth-child(even):not(.summary-total-row) td {
  background: #ffffff;
}

/* Discount row - darker green for contrast */
.summary-table .discount-row td {
  background: #F1FFF5;
}
.summary-table .discount-row .amount-col-small {
  color: #196f3b; /* darker green to meet contrast */
  font-weight: 700;
}

/* Top corners */
.summary-table thead tr:first-child th:first-child,
.summary-table thead tr:first-child td:first-child {
  border-top-left-radius: 12px;
}
.summary-table thead tr:first-child th:last-child,
.summary-table thead tr:first-child td:last-child {
  border-top-right-radius: 12px;
}

/* Bottom corners for total row specifically in tfoot */
.summary-table tfoot .summary-total-row td:first-child {
  border-bottom-left-radius: 12px;
}
.summary-table tfoot .summary-total-row td:last-child {
  border-bottom-right-radius: 12px;
}

/* Base cells */
.summary-table td,
.summary-table th {
  padding: 14px 18px;
  color: #222;
  border-bottom: 1px solid rgba(227,238,255,1);
  font-size: 1.05em;
  vertical-align: middle;
  box-sizing: border-box;
}

/* Labels and numbers */
.summary-table .label-col-small { text-align: left; font-weight: 500; color: #333; }
.summary-table .amount-col-small,
.summary-table .amount-col { text-align: right; font-weight: 600; color: #333; min-width: 96px; }

/* Grand total styling in tfoot */
.summary-table tfoot .summary-total-row td {
  background: #E3EEFF;
  color: #000;
  font-weight: 700;
  font-size: 1.6em;
  text-align: right;
  padding: 20px 18px;
  border-bottom: none;
}
.summary-table tfoot .summary-total-row .label-col { text-align: left; font-size: 1.2em; font-weight: 600; }

/* Subtle glow (optional) and respect prefers-reduced-motion */
@keyframes subtleGlow {
  0% { text-shadow: 0 0 6px rgba(255,255,255,0.25); }
  50% { text-shadow: 0 0 12px rgba(255,255,255,0.5); }
  100% { text-shadow: 0 0 6px rgba(255,255,255,0.25); }
}
@media (prefers-reduced-motion: reduce) {
  .summary-table .summary-total-row td { animation: none !important; }
}

/* Visually hidden helper for screen readers */
.sr-only {
  position: absolute !important;
  width: 1px; height: 1px;
  padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0,0,0,0);
  white-space: nowrap; border: 0;
}


/* Discount visuals (recommended) */
.summary-table .discount-row td {
  background: #F1FFF5; /* existing gentle tint */
}

.summary-table .discount-amount {
  color: #196F3B;       /* main discount color (dark green) */
  font-weight: 700;
}

/* If you need extra contrast on small text, use .discount-amount--strong */
.summary-table .discount-amount--strong {
  color: #124B2C;       /* higher contrast dark green */
}

/* Percent / metadata (muted) */
.summary-table .discount-meta {
  color: #666666;
  font-weight: 500;
  margin-left: 8px;
  font-size: 0.92em;
}

/* Warning / manager override */
.summary-table .discount-warning {
  background: #FFF7ED;
  color: #D97706; /* amber/orange */
  font-weight: 700;
}

/* Mobile: (optional) sticky compact row can be implemented if desired
   Leave minimal styles so the table still renders safely on small screens */
@media (max-width: 720px) {
  .summary-table { font-size: 0.98em; }
}




.pay-section { margin-top:22px; display:flex; flex-direction:column; align-items:stretch; gap:12px;}
.btn-success { background: #6582A8; color:white; border: 0.5px solid white !important;}
.btn-success:hover { background: white; color: black;}
.row-flex {
  display: flex;
  gap: 20px;
  margin: 30px 30px 20px 30px;
}
/* บิลล่าสุดที่ขายไป บิลที่พักไว้ container */
.half-panel {
  flex: 1 1 0;
  min-width: 0;
  background: #F2F7FF;
  border-radius: 9px;
  box-shadow: 0 6px 24px 0 rgba(68, 96, 130, 0.10), 0 1.5px 4px 0 rgba(68, 96, 130, 0.09);
  padding: 18px 14px 14px 14px;
  display: flex;
  flex-direction: column;
  border: 1px solid #ffffff;
}
.panel-table-scroll {
  max-height: 650px; /* or higher */
  overflow-y: auto;
  border-radius: 9px;
  box-shadow: 0 1.5px 12px #b8ceea18;
  margin-bottom: 0;
  background: #fff;
}

@media (max-width: 900px) {
  .row-flex { flex-direction: column; gap: 18px; }
}
@media (max-width: 700px) {
  .side-panel.sales-history-panel,
  .side-panel.held-bill-panel {
    display: none !important;
  }
  .row-flex { margin: 0; }
}
@media (max-width: 900px) {
  .cart-payment-flex { flex-direction: column; gap: 16px; }
}
.held-bill-scroll {
  max-height: 100px; overflow-y:auto;
  overflow-y: auto;
  border-radius: 9px;
  box-shadow: 0 1.5px 12px #b8ceea18;
  margin-bottom: 12px;
  background: #fff;
}



/* Modal styles (for all modals) */
.modal-backdrop {
    position: fixed; z-index: 1000; left: 0; top: 0; width: 100vw; height: 100vh; background: #0006; display: none; align-items: center; justify-content: center;
}
.modal-content {
    background: #fff;
    border-radius: 13px;
    padding: 32px 38px 26px 38px;
    max-width: 760px; /* Increase this! */
    width: 98%;
    margin: 0 auto;
    position: relative;
    box-shadow: 0 18px 80px #0003, 0 2px 12px #b8ceea22;
    max-height: 90vh;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    transition: max-width .2s, padding .2s;
}
.modal-close-btn {
    position: absolute; right: 16px; top: 10px; font-size: 2em; color: #999; background: none; border: none; cursor: pointer;
}
.modal-title {
    font-size: 1.18em; font-weight: bold; margin-bottom: 18px; color: var(--crf-blue-darkest);
}
@media print {
    body * {
        visibility: hidden !important;
    }
    #receiptModal, #receiptModal * {
        visibility: visible !important;
    }
    #receiptModal {
        position: absolute !important;
        left: 0; top: 0; width: 100vw; height: 100vh;
        background: #fff !important;
        z-index: 99999 !important;
        display: block !important;
        align-items: flex-start !important;
        justify-content: flex-start !important;
        overflow: visible !important;
    }
    .modal-content {
        box-shadow: none !important;
        max-height: none !important;
        overflow: visible !important;
        background: #fff !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        border-radius: 0 !important;
    }
    .modal-close-btn,
    .receipt-print-btn {
        display: none !important;
    }
}

/* Fixed pay button at the bottom */
.pay-button-container {
  position: fixed;
  bottom: 16px; /* float a bit above the bottom */
  left: 0;
  width: 100%;
  z-index: 100;
  display: flex;
  justify-content: center; /* center the button horizontally */
  pointer-events: none; /* container doesn’t block clicks */
  background: transparent;
}
/* ---- PAY NOW BUTTON ---- */
/* Button styling */
.pay-now-btn {
  pointer-events: all;
  display: flex;
  align-items: center;           /* vertically center content */
  justify-content: space-between; /* spread items nicely */
  width: calc(100% - 32px);      /* 16px space left and right */
  font-size: 3em;                 /* slightly smaller for better fit */
  font-weight: 300;
  border: 2px solid #fff;
  border-radius: 20px; 
  background-image: linear-gradient(to right, #212121 20%, #454545 55%, #212121 100%);
  color: #fff;
  cursor: pointer;
  box-shadow: 0 5px 14px rgba(76, 103, 232, 0.35);
  transition: all 0.3s ease;
  padding: 15px 20px;             /* adjust horizontal padding */
  box-sizing: border-box;
}

/* Optional: space between icon, amount, and label */
.pay-now-btn .method-label {
  font-size: 0.6em;               /* smaller text for method */
  margin-left: 12px;
  white-space: nowrap;
}



.pay-now-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 7px 18px rgba(76, 103, 232, 0.45);
}

pay-now-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

pay-now-btn .method-label {
  display: block;
  font-size: 0.65em;
  font-weight: 400;
  opacity: 0.9;
}

/* --- Payment Method Buttons (Premium Soft-Gradient Blue – safe version) --- */
.payment-method-buttons {
    display: flex;
    gap: 8px;
    justify-content: space-between;
    flex-wrap: wrap;
    margin-bottom: 18px; /* your margin-bottom */
}

.payment-method {
    flex: 1;
    text-align: center;
    padding: 14px 12px;

    /* Premium glass gradient */
    background: linear-gradient(135deg, #E8F0FF 0%, #F7FAFF 100%);
    border: 1px solid rgba(106, 142, 199, 0.25);
    backdrop-filter: blur(6px);

    border-radius: 10px;
    font-size: 1.3em;
    font-weight: 400;

    cursor: pointer;
    color: #4A628A;

    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;

    transition: all 0.3s ease;
}

.payment-method .icon {
    font-size: 2.5em;
    margin-bottom: 6px;
}

.payment-method:hover {
    background: linear-gradient(135deg, #F5F9FF 0%, #E1EBFF 100%);
    border-color: rgba(106, 142, 199, 0.35);
    color: #2D466B;
}

.payment-method.active {
    background: linear-gradient(135deg, #CFE0FF 0%, #E6EEFF 100%);
    border-color: #ffffff;
    color: #2F4A72;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(110, 150, 210, 0.25);
}

/* --- MOBILE --- */
@media (max-width: 768px) {
    .payment-method-buttons {
        gap: 6px;
    }
    .payment-method {
        padding: 10px 8px;
        font-size: 1em;
    }
    .payment-method .icon {
        font-size: 2em;
    }
}

/************************************************************
   INDUSTRY-GRADE POS CASH PANEL (Frosted CRF Blue)
************************************************************/

/* --- Cash Panel Container --- */
#cash-panel-wrapper {
    padding: 18px;
    border-radius: 16px;
    margin-top: 15px;
    margin-bottom: 18px;
    background: rgba(238, 246, 255, 0.75);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(135, 167, 210, 0.35);
    box-shadow: 0 4px 14px rgba(120, 160, 210, 0.18);
}

/* Label “รับเงินมาเท่าไหร่ (บาท)” */
#cash-panel-wrapper label {
    font-weight: 400;
    font-size: 1.4em;
    color: #3A4D6F;
    margin-bottom: 10px;
    display: block;
}

/* --- Cash input row --- */
.cash-input-row {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 14px;
}

#cash_received {
    padding: 12px;
    font-size: 2em;       /* Bigger, readable */
    font-weight: 300;     /* Thin = Best accuracy */
    border-radius: 12px;
    width: 170px;
    background: #FFFFFF;
    color: #2B3F62;
    border: 1px solid #BFD4EE;
    box-shadow: inset 0 2px 4px rgba(90,120,170,0.1);
}

.cash-change-display {
    border: 1px solid #FFB54D;
    font-weight: 600;
    font-size: 1.4em;
    background-color: #FFF2D9;
    padding: 10px 14px;
    border-radius: 12px;
    color: #D97A00;
    white-space: nowrap;
}

/************************************************************
    QUICK CASH BUTTONS — PREMIUM VERSION
************************************************************/

.quick-cash-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
}

/* Main denominational button */
.quick-cash-btn {
    background: linear-gradient(135deg, #F3F8FF 0%, #FFFFFF 100%);
    border: 1px solid rgba(106, 142, 199, 0.35);
    color: #3C5785;

    font-size: 28px;     /* Bigger */
    font-weight: 300;    /* Thin */
    letter-spacing: 0.5px;

    padding: 12px 22px;
    border-radius: 12px;
    min-width: 90px;

    cursor: pointer;
    transition: all 0.25s ease;
    backdrop-filter: blur(4px);
    box-shadow: 0 2px 6px rgba(110, 150, 210, 0.15);
}

.quick-cash-btn:hover {
    background: linear-gradient(135deg, #E8F0FF 0%, #F7FAFF 100%);
    border-color: rgba(106, 142, 199, 0.55);
    box-shadow: 0 3px 8px rgba(110, 150, 210, 0.25);
    transform: translateY(-1px);
    color: #1F3A60;
}

/* “Clear” button */
.quick-cash-btn[data-clear] {
    background: linear-gradient(135deg, #FFE4E4 0%, #FFF2F2 100%);
    border: 1px solid rgba(200, 50, 50, 0.35);
    color: #C83232;
    font-weight: 500;
}

.quick-cash-btn[data-clear]:hover {
    background: linear-gradient(135deg, #FFD2D2 0%, #FFECEC 100%);
    border-color: rgba(200, 50, 50, 0.55);
    box-shadow: 0 3px 8px rgba(200, 50, 50, 0.25);
    transform: translateY(-1px);
}

/************************************************************
    MOBILE OPTIMIZATION
************************************************************/
@media (max-width: 768px) {
    #cash_received {
        font-size: 1.6em;
        width: 150px;
    }

    .quick-cash-btn {
        font-size: 22px;
        padding: 10px 16px;
        min-width: 70px;
    }

    .cash-change-display {
        font-size: 1.2em;
    }
}







.label-en {
  display: block;
  font-weight:700;
  font-size:0.98em;
  color:#111;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.label-th-block {
  display: block;
  font-size:0.86em;
  color:#666;
  margin-top:2px;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}

/* ensure both languages have consistent truncation visually via max width */
.receipt-item-row { display:flex; justify-content:space-between; align-items:flex-start; margin:6px 0; }
.receipt-item-row .left { flex:1; min-width:0; padding-right:10px; }

/* Print tweaks (80mm friendly) */
@media print {
  @page { size: auto; margin: 6mm; }
  body * { visibility: hidden !important; }
  #receiptModal, #receiptModal * { visibility: visible !important; }
  #receiptModal { position: absolute !important; left: 0; top: 0; width: 80mm !important; background:#fff !important; display:block !important; }
  .receipt-modal-content { width: 80mm !important; padding:6px !important; box-shadow:none !important; border-radius:0 !important; font-size:11px; line-height:1.12; }
  .modal-close-btn, .receipt-print-btn { display:none !important; }
  .label-en { font-size:11px; }
  .label-th-block { font-size:10px; color:#444; }
  .line-total { font-size:11px; font-weight:700; }
}
</style>
<script>
    // Confirmation helpers for receipt actions
    function confirmResumeLastSale(e){
      if (!confirm('Restore the last sale into the cart for editing? This will replace the current cart.')) {
        if (e && e.preventDefault) e.preventDefault();
        return false;
      }
      return true;
    }
    function confirmStartNewSale(e){
      if (!confirm('Start a new sale? This will clear the current cart.')) {
        if (e && e.preventDefault) e.preventDefault();
        return false;
      }
      return true;
    }

    // filterNumberInput - prevents non-numeric chars in price/qty fields
    function filterNumberInput(evt) {
        var char = String.fromCharCode(evt.which || evt.keyCode);
        if (!/[0-9.]/.test(char)) evt.preventDefault();
    }

    // Print helper that waits for images (QR/logo) to load before printing
    function printReceiptAfterImages(){
      var modal = document.getElementById('receiptModal');
      if (!modal) return;
      modal.style.display = 'flex';
      var imgs = Array.from(modal.querySelectorAll('img'));
      if (imgs.length === 0) { window.print(); return; }
      var total = imgs.length, done = 0;
      function maybePrint(){ if (++done >= total) setTimeout(function(){ window.print(); }, 120); }
      imgs.forEach(function(img){
        if (img.complete && img.naturalWidth !== 0) { maybePrint(); return; }
        img.addEventListener('load', maybePrint);
        img.addEventListener('error', maybePrint);
      });
    }

    function printReceipt() {
        // kept for compatibility, but prefer printReceiptAfterImages
        printReceiptAfterImages();
    }

    function openCustomItemModal() {
        var m = document.getElementById('customItemModal');
        if (!m) return;
        m.classList.add('show');
        setTimeout(function() {
            var el = document.getElementById('customItemName');
            if (el) el.focus();
        }, 150);
    }
    function closeCustomItemModal() {
        var m = document.getElementById('customItemModal');
        if (m) m.classList.remove('show');
        var f = document.getElementById('customItemForm'); if (f) f.reset();
    }

    function openSalesHistoryModal() { 
        var m = document.getElementById('salesHistoryModal');
        if (m) m.classList.add('show');
    }
    function closeSalesHistoryModal() { 
        var m = document.getElementById('salesHistoryModal');
        if (m) m.classList.remove('show');
    }
    function openHeldBillsModal() { 
        var m = document.getElementById('heldBillsModal');
        if (m) m.classList.add('show');
    }
    function closeHeldBillsModal() { 
        var m = document.getElementById('heldBillsModal');
        if (m) m.classList.remove('show');
    }
</script>
</head>
<body>
<div class="navbar-wrap">
    <nav class="top-navbar">
        <!-- Logo on the left -->
        <a href="index.php" class="logo">CRF</a>

        <!-- Middle links -->
        <div class="nav-links">
            <a href="products.php" class="nav-button">Products</a>
            <a href="orders.php" class="nav-button">Orders</a>
            <a href="reports.php" class="nav-button">Reports</a>
        </div>

        <!-- User info on the right -->
        <div class="nav-user-wrap">
            <h2 style="color:#444444; margin:0;">Welcome <?=htmlspecialchars($staff_name)?></h2>
            <div class="nav-user">สาขา: <?=htmlspecialchars($branch_name)?></div>
        </div>
    </nav>
</div>


    <div class="main-flex-3col">
    <!-- SIDE PANEL (LEFT COLUMN) -->
<div class="side-panel">
  <button type="button" class="side-panel-btn" onclick="openSalesHistoryModal()">⏰ 🧾<br> บิลล่าสุดวันนี้</button>
  <button type="button" class="side-panel-btn" onclick="openHeldBillsModal()">🔴 <br> บิลที่พักไว้ </button>
  <button type="button" class="side-panel-btn" onclick="openCustomItemModal()">✨✍️ <br> เพิ่มพิเศษ</button>
  <button class="side-panel-btn" onclick="openPage('sales-history')">📋 <br> ประวัติการขาย</button>
  <button class="side-panel-btn" onclick="openPage('products')">🛍️ <br> รายการสินค้า</button>
  <button class="side-panel-btn" onclick="openPage('reports')">📊 <br> รายงาน</button>
</div>

<script>
  function openPage(page) {
    switch (page) {
      case 'sales-history': window.location.href = 'sales_history.php'; break;
      case 'products': window.location.href = 'product_list.php?'; break;
      case 'reports': window.location.href = 'reports.html'; break;
      default: console.error('Invalid page specified');
    }
  }
</script>

<!-- SALES HISTORY MODAL -->
<div id="salesHistoryModal" class="modal-backdrop">
  <div class="modal-content">
    <button class="modal-close-btn" onclick="closeSalesHistoryModal()">&times;</button>
    <div class="modal-title">บิลล่าสุดที่ขายไป</div>
    <div class="panel-table-scroll">
      <?php include 'sales_history_table.php'; ?>
    </div>
  </div>
</div>

<!-- HELD BILL MODAL -->
<div id="heldBillsModal" class="modal-backdrop">
  <div class="modal-content">
    <button class="modal-close-btn" onclick="closeHeldBillsModal()">&times;</button>
    <div class="modal-title">🔴 บิลที่พักไว้</div>
    <div class="panel-table-scroll">
    <table class="modal-table">
        <tr>
          <th>เวลา</th>
          <th>จำนวนสินค้า</th>
          <th>โดย</th>
          <th>ส่วนลด</th>
          <th>ดำเนินการ</th>
        </tr>
        <?php foreach ($held_bills as $id => $bill): ?>
        <tr>
          <td><?= isset($bill['time']) ? htmlspecialchars($bill['time']) : '-' ?></td>
          <td><?= isset($bill['cart']) && is_array($bill['cart']) ? count($bill['cart']) : '0' ?></td>
          <td><?= isset($bill['staff']) ? htmlspecialchars($bill['staff']) : '-' ?></td>
          <td>
            <?php
            if (!empty($bill['discount_percent'])) {
              echo $bill['discount_percent'].'%';
            } elseif (!empty($bill['discount_amount'])) {
              echo number_format($bill['discount_amount'],2).'฿';
            } else {
              echo '0%';
            }
            ?>
          </td>
          <td class="held-bill-row-actions modal-row-actions">
            <form method="post" style="display:inline;">
              <input type="hidden" name="resume_bill_id" value="<?=$id?>">
              <button class="btn btn-blue" style="padding:5px 16px;font-size:0.95em;">เรียกคืน</button>
            </form>
            <form method="post" style="display:inline;">
              <input type="hidden" name="remove_bill_id" value="<?=$id?>">
              <button class="btn btn-grey" style="padding:5px 16px;font-size:0.95em;">ลบ</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
</div>

<!-- CUSTOM ITEM MODAL (single copy) -->
<div id="customItemModal" class="modal-backdrop" style="display:none;">
  <div class="modal-content">
    <button class="modal-close-btn" onclick="closeCustomItemModal()" title="ปิด">&times;</button>
    <div class="modal-title">เพิ่มรายการพิเศษ</div>
    <form id="customItemForm" method="post" action="pos.php" autocomplete="off">
      <div class="modal-form-row">
        <label for="customItemName">ชื่อสินค้า</label>
        <input type="text" id="customItemName" name="custom_name" required maxlength="60">
      </div>
      <div class="modal-form-row">
        <label for="customItemPrice">ราคา/หน่วย</label>
        <input type="number" id="customItemPrice" name="custom_price" step="0.01" min="0.01" onkeypress="filterNumberInput(event)" required>
      </div>
      <div class="modal-form-row">
        <label for="customItemQty">จำนวน</label>
        <input type="number" id="customItemQty" name="custom_qty" step="1" min="1" value="1" onkeypress="filterNumberInput(event)" required>
      </div>
      <div class="modal-form-actions">
        <button type="button" class="btn btn-grey" onclick="closeCustomItemModal()">ยกเลิก</button>
        <button type="submit" class="btn btn-custom-item" name="add_custom_item" value="1">+ เพิ่ม</button>
      </div>
    </form>
  </div>
</div>

<!-- MIDDLE COLUMN: Search + Quick Keys -->
<div class="cart-col">
    <div class="search-panel">
        <form method="get" action="pos.php" id="searchForm" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap; width:100%; margin-bottom: 10px;">
          <div style="position:relative; flex:1; min-width:180px;">
            <label for="product_search" class="visually-hidden" style="position:absolute;left:-9999px;">ค้นหา</label>
            <input id="product_search" name="product_search" type="search" value="<?= htmlspecialchars($search_query) ?>"
              placeholder="ค้นหา / รหัสสินค้า / QR (Enter เพื่อเพิ่ม)" autocomplete="off"
              style="width:100%; font-size:1.05em; padding:12px 40px 12px 14px; border-radius:9px; border:1px solid #ccc; box-sizing:border-box;" aria-label="ค้นหาสินค้า">
            <button type="submit" aria-label="ค้นหา" title="ค้นหา"
                    style="position:absolute; right:8px; top:50%; transform:translateY(-50%); border:none; background:transparent; cursor:pointer; font-size:1.05em; color:#666; padding:6px;">🔍</button>
          </div>

          <label style="white-space:nowrap;display:flex;align-items:center;font-size:0.9em;gap:6px;cursor:pointer;">
            <input type="checkbox" name="auto_add" value="1" <?= $auto_add_enabled ? 'checked' : '' ?> onchange="document.getElementById('searchForm').submit()" style="width:16px;height:16px;">
            <span>เพิ่มอัตโนมัติ</span>
          </label>

          <?php if (!empty($search_query)): ?>
            <button type="submit" name="clear_search" value="1" class="btn btn-white" style="padding:8px 12px; font-size:0.9em; white-space:nowrap; border-radius:6px;">
              ❌ ล้าง
            </button>
          <?php endif; ?>
        </form>

        <?php if ($success): ?><div class="success-msg"><?= $success ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error-msg"><?= $error ?></div><?php endif; ?>

        <!-- SEARCH RESULTS / QUICK KEYS -->
        <?php if ($search_query !== '' && !empty($product_search_results)): ?>
            <div style="margin-bottom:8px;margin-top:12px;">ผลการค้นหา:</div>
            <div class="quick-keys-list" style="display:flex;flex-wrap:nowrap;gap:10px;overflow-x:auto;padding-bottom:6px;">
                <?php foreach ($product_search_results as $p): ?>
                    <?php
                        $image = (string)($p['image'] ?? '');
                        $image = preg_replace('#^uploads/#i', '', $image);
                        $desc  = htmlspecialchars($p['description'] ?? '');
                        $price = number_format($p['price'] ?? 0, 2);
                        $name  = htmlspecialchars($p['name_th'] ?: $p['name_en']);
                        $code  = htmlspecialchars($p['temp_sku']);
                        $imgSrc = $image !== '' ? "uploads/$image" : 'uploads/no-image.png';
                    ?>
<div style="background:#fff;border-radius:10px;padding:6px 10px;min-width:220px;box-shadow:0 2px 4px rgba(0,0,0,0.1);display:flex;flex-direction:row;gap:8px;">
    <img src="<?= $imgSrc ?>" width="55" height="55" style="object-fit:cover;border-radius:7px;">
    <div style="flex:1;display:flex;flex-direction:column;">
        <div>
            <span style="font-size:0.9em;font-weight:600;line-height:1.1;"><?= $name ?></span><br>
            <span style="font-size:0.8em;color:#666;"><?= $code ?></span>
        </div>
        <div style="margin-top:6px;display:flex;gap:8px;align-items:center;">
            <button type="button" style="font-size:1.1em;color:#007bff;background:none;border:none;cursor:pointer;padding:0;"
                onclick="openModal('<?= addslashes($name) ?>','<?= addslashes($code) ?>','<?= $price ?>','<?= $imgSrc ?>','<?= addslashes($desc) ?>')">ℹ️</button>
            <form method="post" style="margin:0;">
                <input type="hidden" name="scan_code" value="<?= $code ?>">
                <button type="submit" style="background:#007bff;color:#fff;border:none;border-radius:6px;padding:5px 12px;font-size:0.85em;cursor:pointer;">เพิ่ม</button>
            </form>
        </div>
    </div>
</div>
                <?php endforeach; ?>
            </div>
        <?php elseif (!empty($quick_key_products)): ?>
            <div style="margin-bottom:8px;font-weight:bold;">สินค้าแนะนำ/ขายบ่อย:</div>
            <div class="quick-keys-list" style="display:flex;flex-wrap:nowrap;gap:10px;overflow-x:auto;padding-bottom:6px;">
                <?php foreach ($quick_key_products as $p): ?>
                    <?php
                        $image = (string)($p['image'] ?? '');
                        $image = preg_replace('#^uploads/#i', '', $image);
                        $imgSrc = $image !== '' ? "uploads/$image" : 'uploads/no-image.png';
                    ?>
                    <form method="post" style="display:flex;align-items:center;margin:0;">
                        <input type="hidden" name="scan_code" value="<?= htmlspecialchars($p['temp_sku']) ?>">
                        <button type="submit" style="display:flex;align-items:center;gap:8px;min-width:200px;background:#fff;border-radius:10px;padding:6px 10px;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                            <img src="<?= $imgSrc ?>" width="55" height="55" style="object-fit:cover;border-radius:7px;">
                            <div style="display:flex;flex-direction:column;text-align:left;">
                                <span style="font-size:0.9em;font-weight:600;"><?= htmlspecialchars($p['name_th'] ?: $p['name_en']) ?></span>
                                <span style="font-size:0.8em;color:#555;"><?= htmlspecialchars($p['temp_sku']) ?></span>
                            </div>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <div class="cart-panel">
        <form method="post" action="pos.php" id="discTierForm">
            <div class="cart-table-wrapper">
                <table class="cart-table">
                    <tr>
                        <th>สินค้า</th>
                        <th>จำนวน</th>
                        <th>ราคา</th>
                        <th>รวม</th>
                        <th>ลบ</th>
                    </tr>
                    <?php if (count($cart) > 0): ?>
                        <?php foreach ($cart as $item): ?>
                            <tr>
                                <td>
                                    <?php
                                        $cart_image = (string)($item['image'] ?? '');
                                        $cart_image = preg_replace('#^uploads/#i', '', $cart_image);
                                    ?>
                                    <img src="uploads/<?= $cart_image !== '' ? htmlspecialchars($cart_image) : 'no-image.png' ?>"
                                        width="64" height="64" style="object-fit:cover;border-radius:7px;background:#f4f4f4;margin-right:7px;vertical-align:middle;">
                                    <span style="vertical-align:middle;"><?=htmlspecialchars($item['name_th'] ?: $item['name_en'])?></span>
                                    <?php if (isset($item['custom']) && $item['custom']): ?>
                                        <span class="custom-item-label">★</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex;align-items:center;justify-content:center;">
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="incdec_item_id" value="<?=$item['id']?>">
                                            <input type="hidden" name="incdec_action" value="dec">
                                            <button type="submit" class="qty-btn" title="ลด" aria-label="ลด">
                                                <span class="qty-icon" style="display:inline-block;">
                                                    <svg width="18" height="18" viewBox="0 0 18 18"><rect x="3" y="8" width="12" height="2" rx="1" fill="currentColor"/></svg>
                                                </span>
                                            </button>
                                        </form>
                                        <input type="text" class="qty-input" value="<?=$item['quantity']?>" readonly style="margin:0 5px;">
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="incdec_item_id" value="<?=$item['id']?>">
                                            <input type="hidden" name="incdec_action" value="inc">
                                            <button type="submit" class="qty-btn" title="เพิ่ม" aria-label="เพิ่ม">
                                                <span class="qty-icon" style="display:inline-block;">
                                                    <svg width="18" height="18" viewBox="0 0 18 18">
                                                        <rect x="3" y="8" width="12" height="2" rx="1" fill="currentColor"/>
                                                        <rect x="8" y="3" width="2" height="12" rx="1" fill="currentColor"/>
                                                    </svg>
                                                </span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                                <td><?= number_format( ($item['price'] ?? ($item['price_sold'] ?? 0)), 2 ) ?></td>
                                <td><?= number_format( ( ($item['price'] ?? ($item['price_sold'] ?? 0)) * ($item['quantity'] ?? 1) ), 2 ) ?></td>
                                <td>
                                    <button type="submit" name="remove_product_id" value="<?=$item['id']?>" class="icon-btn" title="ลบ">
                                        &times;
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="cart-total-row">
                            <td colspan="3" style="text-align:right;">รวม</td>
                            <td colspan="2"><?=number_format($subtotal,2)?></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="5">-- ยังไม่มีสินค้าในตะกร้า --</td>
                        </tr>
                    <?php endif; ?>
                </table>

                <div style="display:flex;margin-bottom:8px;margin-top:10px;">
                    <form method="post" action="pos.php" style="margin-bottom:0;">
                        <button type="submit" name="hold_bill" value="1" style="margin-right:10px;" class="btn btn-light">✋🔴 พักบิล 📋</button>
                    </form>
                    <button type="submit" name="clear_cart" value="1" style="margin-right:10px;" class="btn btn-grey" style="font-size:0.98em;">🗑️ ล้างตะกร้า</button>
                    <button type="submit" name="update_cart" value="1" class="btn btn-grey" style="font-size:0.98em;">💾 อัปเดตจำนวน</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- RIGHT COLUMN: Payment/Discount -->
<div class="payment-col" id="payment-panel" data-promptpay-tel="<?= htmlspecialchars($PROMPTPAY_TEL) ?>" data-total="<?= htmlspecialchars(number_format($total, 2, '.', '')) ?>">
  <div class="payment-scroll">

  <table class="summary-table" role="table" aria-label="Order totals">
  <tbody>
    <tr>
      <td class="label-col-small">รวม / Sub Total</td>
      <td class="amount-col-small" data-amount="<?= (int) round(($subtotal ?? 0) * 100) ?>">
        <?= number_format($subtotal, 2) ?>
      </td>
    </tr>

    <tr class="discount-row" aria-describedby="discount-details">
  <td class="label-col-small">ส่วนลด / Discount</td>
  <td class="amount-col-small">
    <span class="discount-amount" aria-hidden="true">
      <?= $total_discount > 0 ? '-' . number_format($total_discount, 2) : number_format($total_discount, 2) ?> ฿
    </span>

    <?php if ($discount_percent > 0): ?>
      <span class="discount-meta">(<?= htmlspecialchars(number_format($discount_percent, 2)) ?>%)</span>
    <?php elseif ($discount_amount > 0): ?>
      <span class="discount-meta">(<?= htmlspecialchars(number_format($discount_amount, 2)) ?> ฿)</span>
    <?php endif; ?>

    <!-- Screen reader friendly details -->
    <div id="discount-details" class="sr-only">
      <?= $total_discount > 0 ? 'Discount applied: ' . (isset($discount_code) ? htmlspecialchars($discount_code) . ', ' : '') . number_format($total_discount,2) . ' baht off.' : 'No discount.' ?>
    </div>
  </td>
</tr>


  </tbody>

  <tfoot>
    <tr class="summary-total-row">
      <td class="label-col">รวมสุทธิ / Grand Total</td>
      <td class="amount-col" id="grand-total" data-total="<?= (int) round(($total ?? 0) * 100) ?>">
        <?= number_format($total ?? 0, 2) ?>
      </td>
    </tr>
  </tfoot>
</table>

<!-- Screen-reader live region for changes to totals -->
<div id="sr-grand-total" aria-live="polite" aria-atomic="true" class="sr-only">
  Grand total <?= number_format($total ?? 0, 2) ?>
</div>

    <div class="payment-panel">
<form method="post" action="pos.php" style="margin-bottom: 18px;">
  <div class="discount-quick" style="margin-bottom: 12px;">
    <?php 
    foreach ($discount_buttons as $btn): 
        $is_zero = ($btn == 0);
        $is_suggested = (abs($btn - $suggested_discount) < 0.001);
        $is_current = abs($btn - $discount_percent) < 0.001;
        $btn_class = "discount-btn";
        $btn_note = "";
        if ($is_current) {
            $btn_class .= " active";
            $btn_note = "<span style='font-size:0.87em; margin-left:7px;'>(ใช้อยู่)</span>";
        } elseif ($is_zero || $is_suggested) {
            $btn_class .= " suggested";
            $btn_note = "<span style='font-size:0.87em; margin-left:7px;'>(แนะนำ)</span>";
        } else {
            $btn_class .= " muted";
        }
    ?>
      <button type="submit" name="set_discount_tier" value="<?= $btn ?>" class="<?= $btn_class ?>">
        <?= $btn ?>% <?= $btn_note ?>
      </button>
    <?php endforeach; ?>
    <button type="submit" name="reset_discount" value="1" class="discount-btn reset">⏻ รีเซ็ตส่วนลด</button>
  </div>
</form>

   <!-- Payment Method Buttons -->
<div class="payment-method-buttons">
  <?php
  $payment_methods = [
      'cash' => ['label' => 'เงินสด / Cash', 'icon' => '💵'],
      'card' => ['label' => 'บัตร / Card', 'icon' => '💳'],
      'promptpay' => ['label' => 'แสกน / Scan QR', 'icon' => '📱']
  ];
  $selected_method = $_POST['payment_method'] ?? $_POST['payment_method_selected'] ?? $payment_method ?? 'cash';
  foreach ($payment_methods as $key => $info):
  ?>
    <button type="button" data-value="<?= htmlspecialchars($key) ?>" class="btn payment-method <?= $selected_method === $key ? 'active' : '' ?>">
        <span class="icon"><?= htmlspecialchars($info['icon']) ?></span>
        <span class="label"><?= htmlspecialchars($info['label']) ?></span>
    </button>
  <?php endforeach; ?>
</div>

<!-- PromptPay inline preview (hidden by default) -->
<div id="promptpayInline" style="display: <?= ($selected_method === 'promptpay') ? 'block' : 'none' ?>; text-align:center; margin-top:12px;">
  <div style="font-weight:700; margin-bottom:6px;">PromptPay — สแกนเพื่อชำระ</div>
  <img id="promptpayInlineImg" src="<?= htmlspecialchars(generate_promptpay_qr_url($PROMPTPAY_TEL, $total)) ?>" alt="PromptPay QR" style="width:140px; max-width:60%; background:#fff; padding:6px; border-radius:6px;">
  <div style="margin-top:6px; color:#666; font-size:0.92em;">Tel <?= htmlspecialchars(mask_promptpay_phone($PROMPTPAY_TEL)) ?> • ยอด <?= number_format($total,2) ?> ฿</div>
</div>




<!-- Checkout / Cash Input Section -->
<form method="post" action="pos.php" id="checkout_form">
<input type="hidden" name="promptpay_confirm" id="promptpay_confirm" value="0">
  <div class="pay-section">
    <div id="cash-panel-wrapper" style="display:<?= $selected_method === 'cash' ? 'block' : 'none' ?>;">
      <label for="cash_received">รับเงินมาเท่าไหร่ (บาท)</label>
      <div class="cash-input-row">
        <input type="number" id="cash_received" name="cash_received" min="<?= htmlspecialchars($total) ?>" step="0.01" oninput="calcCashChange()">
        <span class="cash-change-display">Change/เงินทอน: <span id="change_amount">0.00</span> ฿</span>
      </div>
      <div id="cashBreakdown" style="padding-bottom: 6px; font-size: 1.2em;color: #666666;"></div>
      <div class="quick-cash-buttons">
        <?php foreach([1,2,5,10,20,50,100,500,1000,2000] as $amt): ?>
          <button type="button" class="quick-cash-btn" data-value="<?=$amt?>"><?=$amt?> ฿</button>
        <?php endforeach; ?>
        <button type="button" class="quick-cash-btn" data-clear="1">ล้าง</button>
      </div>
    </div>

    <div class="pay-button-container">
      <button type="submit" name="checkout" id="pay_now_btn" class="pay-now-btn" <?= empty($cart) ? 'disabled' : '' ?>>
        💳 Pay ฿<?= number_format($total ?? 0, 2) ?><span class="method-label">(via <?= ucfirst($selected_method) ?>)</span>
      </button>
      <input type="hidden" name="payment_method_selected" id="payment_method_selected" value="<?= htmlspecialchars($selected_method) ?>">
    </div>
  </div>
</form>

<script>
/* Cash / payment UI wiring */
var cashNotes = [];

/*
  updateCashReceived()
  - Only overwrites the visible cash input when the cashier has used the quick-cash buttons
    (cashNotes.length > 0) or programmatically set notes.
  - This avoids clobbering a restored typed value on page load.
  - When quick-cash created a value we persist it to localStorage here.
*/
function updateCashReceived() {
    var totalCash = cashNotes.reduce((sum, v) => sum + v, 0);
    var cashInput = document.getElementById('cash_received');
    var breakdown = cashNotes.length ? 'รับเงิน: ' + cashNotes.map(v => v+'฿').join(' + ') + ' = <b>' + totalCash.toFixed(2) + '฿</b>' : '';
    var cb = document.getElementById('cashBreakdown');
    if (cb) cb.innerHTML = breakdown;

    // Only overwrite the input if we have cashNotes (the user used quick-cash).
    // This prevents overwriting restored typed values on load.
    if (cashInput && cashNotes.length > 0) {
        cashInput.value = totalCash.toFixed(2);
        // persist the value immediately so a reload or form submit doesn't lose it
        try {
          localStorage.setItem('pos_cash_received', String(Math.round(totalCash * 100)));
        } catch (e) { /* ignore storage errors */ }
    }
    calcCashChange();
}

// quick-cash buttons: if clearing, clear input + storage; otherwise push and update
document.querySelectorAll('.quick-cash-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
        if (this.getAttribute('data-clear') === "1") {
            cashNotes = [];
            // clear visible input and persisted storage explicitly
            var cashInput = document.getElementById('cash_received');
            if (cashInput) cashInput.value = '';
            try { localStorage.setItem('pos_cash_received', '0'); } catch(e){}
            // clear breakdown UI
            var cb = document.getElementById('cashBreakdown'); if (cb) cb.innerHTML = '';
            calcCashChange();
        } else {
            var val = parseFloat(this.getAttribute('data-value')) || 0;
            cashNotes.push(val);
            updateCashReceived();
        }
    });
});

var cashReceivedInput = document.getElementById('cash_received');
if (cashReceivedInput) {
    cashReceivedInput.addEventListener('input', function(){
        var v = parseFloat(this.value) || 0;
        // reflect typed value in cashNotes so quick-cash interactions behave consistently
        cashNotes = [v];
        // updateCashReceived will persist because cashNotes.length > 0
        updateCashReceived();
    });
}

function calcCashChange() {
    var total = <?= json_encode($total) ?>;
    var receivedEl = document.getElementById('cash_received');
    var received = receivedEl ? (parseFloat(receivedEl.value) || 0) : 0;
    var change = received - total;
    var el = document.getElementById('change_amount');
    if (el) el.innerText = (change > 0 ? change.toFixed(2) : "0.00");
}

<script>
(function(){
  var payButtons = document.querySelectorAll('.payment-method');
  var paymentHidden = document.getElementById('payment_method_selected');
  var promptpayInline = document.getElementById('promptpayInline');
  var promptpayInlineImg = document.getElementById('promptpayInlineImg');
  var panel = document.getElementById('payment-panel');
  var promptTel = panel ? panel.dataset.promptpayTel : '<?= htmlspecialchars($PROMPTPAY_TEL) ?>';
  function buildPromptPayUrl(tel, amount) {
    var clean = (''+tel).replace(/[^0-9]/g,'');
    amount = parseFloat(amount||0).toFixed(2);
    return 'https://promptpay.io/' + clean + '/' + amount + '.png';
  }

  payButtons.forEach(function(btn){
    btn.addEventListener('click', function() {
      var method = this.dataset.value || 'cash';
      if (paymentHidden) paymentHidden.value = method;
      payButtons.forEach(function(b){ b.classList.remove('active'); });
      this.classList.add('active');
      
      // Exclusive UI logic: show only relevant payment UI
      var cashPanel = document.getElementById('cash-panel-wrapper');
      if (cashPanel) cashPanel.style.display = (method === 'cash') ? 'block' : 'none';

      // Show/hide PromptPay QR when selected (exclusive)
      if (method === 'promptpay') {
        var total = (panel && panel.dataset.total) ? panel.dataset.total : ('<?= number_format($total, 2, '.', '') ?>');
        if (promptpayInline) {
          promptpayInline.style.display = 'block';
          if (promptpayInlineImg) promptpayInlineImg.src = buildPromptPayUrl(promptTel, total);
        }
      } else {
        if (promptpayInline) promptpayInline.style.display = 'none';
      }

      // Handle cash method UI refresh or persist cash value for non-cash methods
      if (method === 'cash') {
        // Refresh UI but don't overwrite restored typed values
        if (typeof updateCashReceived === 'function') updateCashReceived();
      } else {
        // Persist typed or quick-cash value when switching to non-cash payment methods
        try {
          var v = document.getElementById('cash_received');
          if (v) localStorage.setItem('pos_cash_received', String(Math.round((parseFloat(v.value)||0)*100)));
        } catch(e){}
      }
    });
  });

  var payNowBtn = document.getElementById('pay_now_btn');
  var checkoutForm = document.getElementById('checkout_form');
  
  function closePromptpayModal() {
    var modal = document.getElementById('promptpayModal');
    if (modal) modal.classList.remove('show');
  }
  
  function openPromptpayModal() {
    var existing = document.getElementById('promptpayModal');
    if (existing) { 
      existing.classList.add('show');
      return; 
    }
    var modal = document.createElement('div');
    modal.id = 'promptpayModal';
    modal.className = 'modal-backdrop show';
    modal.innerHTML = '<div class="modal-content" style="max-width:360px;text-align:center;">' +
      '<button class="modal-close-btn" onclick="closePromptpayModal()">&times;</button>' +
      '<div style="font-weight:700;margin-bottom:8px;">PromptPay — สแกนเพื่อชำระ</div>' +
      '<div id="pp_qr_holder" style="padding:8px 0;"><img id="pp_qr_img" src="" alt="PromptPay QR" style="width:220px;max-width:96%;background:#fff;padding:8px;border-radius:8px;"></div>' +
      '<div id="pp_meta" style="color:#666;margin-top:8px;"></div>' +
      '<div style="margin-top:14px;padding:12px;background:#f9f9f9;border-radius:8px;">' +
        '<label style="display:flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;font-size:0.95em;">' +
          '<input type="checkbox" id="pp_payment_confirmed_checkbox" style="width:18px;height:18px;cursor:pointer;">' +
          '<span>✅ ยืนยันว่าได้รับเงินแล้ว (Confirm Payment Received)</span>' +
        '</label>' +
      '</div>' +
      '<div style="margin-top:14px;display:flex;gap:8px;justify-content:center;">' +
        '<button id="pp_confirm_paid" class="btn btn-custom-item" disabled>ยืนยันรับเงิน</button>' +
        '<button id="pp_close" class="btn btn-grey">ปิด</button>' +
      '</div>' +
    '</div>';
    document.body.appendChild(modal);

    var total = (panel && panel.dataset.total) ? panel.dataset.total : ('<?= number_format($total, 2, '.', '') ?>');
    var img = modal.querySelector('#pp_qr_img');
    var meta = modal.querySelector('#pp_meta');
    img.src = buildPromptPayUrl(promptTel, total);
    if (meta) meta.innerHTML = 'Tel <?= htmlspecialchars(mask_promptpay_phone($PROMPTPAY_TEL)) ?> • Amount ' + parseFloat(total).toFixed(2) + ' ฿';

    // Enable confirm button only when checkbox is checked
    var checkbox = modal.querySelector('#pp_payment_confirmed_checkbox');
    var confirmBtn = modal.querySelector('#pp_confirm_paid');
    if (checkbox && confirmBtn) {
      checkbox.addEventListener('change', function() {
        confirmBtn.disabled = !this.checked;
      });
    }

    modal.querySelector('#pp_close').addEventListener('click', function(){ closePromptpayModal(); });
    modal.querySelector('#pp_confirm_paid').addEventListener('click', function(){
      var fld = document.getElementById('promptpay_confirm');
      if (fld) fld.value = '1';
      var hidden = document.getElementById('payment_method_selected');
      if (hidden) hidden.value = 'promptpay';
      closePromptpayModal();
      checkoutForm.submit();
    });
  }

  if (payNowBtn && checkoutForm) {
    payNowBtn.addEventListener('click', function(e){
      var selected = (document.getElementById('payment_method_selected') || {}).value || 'cash';
      if (selected === 'promptpay') {
        e.preventDefault();
        openPromptpayModal();
      }
    });
  }
})();
</script>

<!-- Product Info Modal -->
<div id="productInfoModal" class="modal-backdrop" style="display:none;">
    <div style="background:#fff;padding:20px;border-radius:10px;max-width:400px;width:90%;position:relative;">
        <button onclick="closeModal()" style="position:absolute;top:10px;right:10px;font-size:1.2em;border:none;background:none;cursor:pointer;">&times;</button>
        <img id="modalImage" src="" width="100%" style="object-fit:cover;border-radius:7px;margin-bottom:10px;">
        <h3 id="modalName"></h3>
        <p><strong>Code:</strong> <span id="modalCode"></span></p>
        <p><strong>Price:</strong> <span id="modalPrice"></span></p>
        <p id="modalDesc" style="white-space:normal;"></p>
    </div>
</div>

<script>
function openModal(name, code, price, image, desc) {
    document.getElementById('modalName').textContent = name;
    document.getElementById('modalCode').textContent = code;
    document.getElementById('modalPrice').textContent = price;
    document.getElementById('modalDesc').textContent = desc || '';
    document.getElementById('modalImage').src = image || 'uploads/no-image.png';
    var m = document.getElementById('productInfoModal');
    if (m) m.classList.add('show');
}
function closeModal() {
    var m = document.getElementById('productInfoModal');
    if (m) m.classList.remove('show');
}
</script>

<!-- RECEIPT MODAL -->
<div id="receiptModal" class="modal-backdrop" style="display:none;">
  <div class="modal-content receipt-modal-content" style="width:440px; max-width:97%; padding:20px; box-sizing:border-box;">
    <button class="modal-close-btn" onclick="closeReceiptModal()" title="ปิด" style="font-size:1.2em;">&times;</button>
    <div id="printable-receipt" style="font-family: 'Helvetica Neue', Arial, sans-serif; color:#222;">
      <div style="text-align:center; margin-bottom:8px;">
        <img src="uploads/logo-black.png" alt="Logo" style="width:72px;height:auto;margin:0 auto 6px;display:block;">
        <div style="font-size:1.05em; font-weight:700; color:#111;">CRF SHOP</div>
        <div style="font-size:0.86em; color:#666; line-height:1.15; margin-top:4px;">
          Room no 001, Section 11, soi 12/1, Lat Yao, Chatuchak, Bangkok 10900
        </div>
        <div style="margin-top:8px; font-size:0.92em;">
          <div>Sale No.: <?= htmlspecialchars($receipt_data['id'] ?? '') ?></div>
          <div>Date/Time: <?= htmlspecialchars($receipt_data['date'] ?? '') ?></div>
          <div>Cashier: <?= htmlspecialchars($receipt_data['staff'] ?? '') ?></div>
        </div>
      </div>

      <hr style="border:none;border-top:1px dashed #ccc;margin:10px 0;">

      <!-- Items -->
<div style="font-size:0.95em;">
<?php
$__crf_receipt_max_chars = 40;
foreach (($receipt_data['cart'] ?? []) as $item):
    $label_en = trim((string)($item['name_en'] ?? $item['product_name_en'] ?? $item['product_name_from_product_en'] ?? $item['temp_sku'] ?? ''));
    $label_th = trim((string)($item['name_th'] ?? $item['product_name_th'] ?? $item['product_name_from_product_th'] ?? $item['temp_sku'] ?? ''));
    if ($label_en === '') $label_en = $label_th ?: ($item['temp_sku'] ?? '');
    if ($label_th === '') $label_th = $label_en;

    $display_en = crf_truncate_label($label_en, $__crf_receipt_max_chars);
    $display_th = crf_truncate_label($label_th, $__crf_receipt_max_chars);

    $qty = (int)($item['quantity'] ?? 1);
    $unit_price = number_format($item['price'] ?? ($item['price_sold'] ?? 0), 2);
    $line_total = number_format((($item['price'] ?? ($item['price_sold'] ?? 0)) * $qty), 2);
?>
  <div class="receipt-item-row">
    <div class="left">
      <div class="label-en"><?= htmlspecialchars($display_en, ENT_QUOTES, 'UTF-8') ?></div>
      <?php if ($display_th !== ''): ?>
        <div class="label-th-block"><?= htmlspecialchars($display_th, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
    </div>
    <div style="text-align:right; min-width:110px; margin-left:10px;">
      <div style="font-size:0.95em; color:#111;"><?= $qty ?> x <?= number_format((float)$unit_price,2) ?></div>
      <div class="line-total" style="font-weight:700; font-size:0.95em; margin-top:4px; color:#111;"><?= number_format((float)$line_total,2) ?> ฿</div>
    </div>
  </div>
<?php endforeach; ?>
</div>

      <hr style="border:none;border-top:1px dashed #ccc;margin:10px 0;">

      <!-- Payment block -->
      <div style="font-size:0.94em; margin-bottom:8px;">
        <?php if (($receipt_data['payment_method'] ?? '') === 'cash'):
            $received = $receipt_data['cash_received'] ?? null;
            $change = $receipt_data['change'] ?? null;
        ?>
          <div style="display:flex;justify-content:space-between;margin:4px 0;"><div>Payment</div><div>Cash</div></div>
          <div style="display:flex;justify-content:space-between;margin:4px 0;"><div>Received</div><div><?= $received !== null ? number_format((float)$received,2).' ฿' : '-' ?></div></div>
          <div style="display:flex;justify-content:space-between;margin:4px 0;"><div>Change</div><div><?= $change !== null ? number_format((float)$change,2).' ฿' : '0.00 ฿' ?></div></div>

        <?php elseif (($receipt_data['payment_method'] ?? '') === 'promptpay'):
            $pp_tel = $PROMPTPAY_TEL;
        ?>
          <div style="text-align:center; margin-bottom:6px;">
            <div style="font-weight:700; margin-bottom:6px;">PromptPay / Scan to pay</div>
            <img src="<?= htmlspecialchars(generate_promptpay_qr_url($pp_tel, $receipt_data['total'] ?? 0)) ?>" alt="PromptPay QR" style="width:140px; max-width:60%; background:#fff; padding:6px; border-radius:6px;">
            <div style="margin-top:6px; color:#666; font-size:0.92em;">Tel <?= htmlspecialchars(mask_promptpay_phone($pp_tel)) ?> • Amount <?= number_format($receipt_data['total'] ?? 0,2) ?> ฿</div>
          </div>

        <?php else: ?>
          <div style="display:flex;justify-content:space-between;margin:4px 0;"><div>Payment</div><div>Card</div></div>
          <div style="margin-top:6px;color:#666;font-size:0.9em;">Paid by Card</div>
        <?php endif; ?>
      </div>

      <hr style="border:none;border-top:1px dashed #eee;margin:10px 0;">

      <div style="font-size:0.95em; margin-bottom:8px;">
        <div style="display:flex;justify-content:space-between;margin:4px 0;">
          <div style="color:#555;"><?= $lbl['subtotal'] ?? 'Subtotal' ?></div>
          <div style="color:#111;"><?= number_format($receipt_data['subtotal'] ?? 0,2) ?> ฿</div>
        </div>
        <div style="display:flex;justify-content:space-between;margin:4px 0;">
          <div style="color:#555;">
            <?= $lbl['discount'] ?? 'Discount' ?>
            <?php $p = $receipt_data['discount_percent'] ?? 0; if ($p > 0): ?> (<?= number_format($p,2) ?>%)<?php endif; ?>
          </div>
          <div style="color:#111;">-<?= number_format($receipt_data['discount'] ?? 0,2) ?> ฿</div>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:8px;padding-top:8px;border-top:1px solid #eee;font-weight:700;font-size:1.05em;">
          <div><?= $lbl['grand_total'] ?? 'Total' ?></div>
          <div><?= number_format($receipt_data['total'] ?? 0,2) ?> ฿</div>
        </div>
      </div>

      <hr style="border:none;border-top:1px dashed #eee;margin:10px 0;">

      <div style="text-align:center; font-size:0.92em; color:#666; margin-top:6px;">
        <div><?= $lbl['thankyou'] ?? 'Thank you for your purchase!' ?></div>
        <div style="margin-top:8px; font-size:0.78em; color:#999;">This receipt is the official proof of purchase. Please keep it for returns.</div>
      </div>
    </div>

    <div style="text-align:center; margin-top:12px;">
      <button class="receipt-print-btn btn" onclick="printReceiptAfterImages()" style="padding:8px 18px;margin-right:8px;">🖨️ Print</button>
      <button class="btn btn-grey" onclick="closeReceiptModal()">Close</button>

      <!-- Edit recent sale / Start new sale -->
      <div style="margin-top:12px;">
        <form method="post" style="display:inline-block; margin-right:8px;" onsubmit="return confirmResumeLastSale(event);">
          <button type="submit" name="resume_last_sale" class="btn btn-blue" style="padding:8px 14px; font-size:0.98em;">
            ✏️ Edit recent sale
          </button>
        </form>

        <form method="post" style="display:inline-block; margin-left:8px;" onsubmit="return confirmStartNewSale(event);">
          <button type="submit" name="start_new_sale" class="btn btn-grey" style="padding:8px 14px; font-size:0.98em;">
            ➕ Start new sale
          </button>
        </form>
      </div>

    </div>
  </div>
</div>

<style>
.receipt-modal-content { max-width:440px; border-radius:10px; box-shadow:0 12px 40px rgba(0,0,0,0.15); background:#fff; }

/* PromptPay modal tweaks */
#promptpayModal .modal-content { text-align:center; }
#promptpayModal img { box-shadow: 0 6px 20px rgba(0,0,0,0.12); }
</style>

<script>
function closeReceiptModal(){
  var m = document.getElementById('receiptModal');
  if (!m) return;
  m.classList.remove('show');
}
<?php if (!empty($show_receipt)): ?>
document.addEventListener('DOMContentLoaded', function(){
  var m = document.getElementById('receiptModal');
  if (m) m.classList.add('show');
  // optional: don't auto print here to let cashier choose
});
<?php endif; ?>
// ensure custom modal overlay click and ESC key close
document.addEventListener('keydown', function(e) { if (e.key === "Escape") closeCustomItemModal(); });
var cm = document.getElementById('customItemModal'); if (cm) cm.addEventListener('click', function(e){ if (e.target === this) closeCustomItemModal(); });


/* Small initializer: sync visible total labels from data-* minor-unit attributes.
   Non-destructive: only updates text content and the Pay button label for clarity. */
   (function () {
  function formatMinor(amountMinor) {
    // Keep simple format to match server output (two decimals) and append currency sign as in your UI
    return (amountMinor / 100).toFixed(2) + ' ฿';
  }

  function updateSummaryUI() {
    var grand = document.getElementById('grand-total');
    if (!grand) return;
    var totalMinor = parseInt(grand.dataset.total || '0', 10);
    var formatted = formatMinor(totalMinor);
    // Update the grand total cell display
    grand.textContent = formatted;

    // Update screen-reader region
    var sr = document.getElementById('sr-grand-total');
    if (sr) sr.textContent = 'Grand total ' + formatted;

    // Update Pay button text (keeps server-side disabled/enabled state)
    var payBtn = document.getElementById('pay_now_btn');
    if (payBtn) {
      // preserve inner span.method-label if present
      var methodLabel = payBtn.querySelector('.method-label');
      payBtn.innerHTML = '💳 Pay ' + formatted;
      if (methodLabel) payBtn.appendChild(methodLabel);
    }

    // If you later add a mobile sticky total, populate it here (id="sticky-total")
    var sticky = document.getElementById('sticky-total');
    if (sticky) sticky.textContent = formatted;
  }

  // run after DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', updateSummaryUI);
  } else {
    updateSummaryUI();
  }
})();

(function(){
  // Key name (make unique per user/branch if desired)
  var LS_KEY_CASH = 'pos_cash_received';
  var LS_KEY_LAST_TOTAL = 'pos_last_total_minor';

  function readMinorFromDataset() {
    var g = document.getElementById('grand-total');
    if (!g) return 0;
    return parseInt(g.dataset.total || '0', 10);
  }

  function parseCashInputValue() {
    var el = document.getElementById('cash_received');
    if (!el) return 0;
    // ensure numeric, support "" => 0
    var n = parseFloat(String(el.value).replace(/[^\d\.\-]/g, '')) || 0;
    return Math.round(n * 100);
  }

  function formatMinorToDisplay(minor) {
    return (minor / 100).toFixed(2);
  }

  function saveCashToStorage(minor) {
    try { localStorage.setItem(LS_KEY_CASH, String(minor)); } catch(e) {}
  }
  function loadCashFromStorage() {
    try { var v = localStorage.getItem(LS_KEY_CASH); return v ? parseInt(v,10) : null; } catch(e){ return null; }
  }
  function saveLastTotal(minor) {
    try { localStorage.setItem(LS_KEY_LAST_TOTAL, String(minor)); } catch(e) {}
  }
  function loadLastTotal() {
    try { var v = localStorage.getItem(LS_KEY_LAST_TOTAL); return v ? parseInt(v,10) : null; } catch(e){ return null; }
  }

  // Banner helper
  function showBanner(message, type) {
    // type could be 'warn' or 'info'
    var id = 'pos-notice-banner';
    var exist = document.getElementById(id);
    if (!exist) {
      var b = document.createElement('div');
      b.id = id;
      b.setAttribute('role','status');
      b.setAttribute('aria-live','polite');
      b.style.cssText = 'padding:10px 14px;border-radius:8px;margin-bottom:10px;font-weight:600;';
      document.getElementById('cash-panel-wrapper')?.insertAdjacentElement('afterbegin', b);
      exist = b;
    }
    exist.textContent = message;
    exist.style.background = (type === 'warn') ? '#FFF4E5' : '#E8F7FF';
    exist.style.color = (type === 'warn') ? '#9C4B00' : '#103E6A';
    // auto-hide after 6s for info; keep warn until next change
    if (type !== 'warn') {
      clearTimeout(exist._timeout);
      exist._timeout = setTimeout(function(){ if (exist) exist.remove(); }, 6000);
    }
  }

  // Recalculate change using the total in #grand-total and current cash input
  function recalcChangeAndUI() {
    var totalMinor = readMinorFromDataset();
    var cashMinor = parseCashInputValue();
    var changeMinor = Math.max(0, cashMinor - totalMinor);
    // update change display (exists)
    var changeEl = document.getElementById('change_amount');
    if (changeEl) changeEl.innerText = formatMinorToDisplay(changeMinor);

    // screen reader announcement for insufficient cash
    var sr = document.getElementById('sr-grand-total');
    if (sr) {
      if (cashMinor === 0) {
        sr.textContent = 'No cash entered.';
      } else if (cashMinor < totalMinor) {
        sr.textContent = 'Insufficient cash: need ' + formatMinorToDisplay((totalMinor - cashMinor)) + ' baht more.';
      } else {
        sr.textContent = 'Change ' + formatMinorToDisplay(changeMinor) + ' baht.';
      }
    }

    // Save cash and last total for persistence
    saveCashToStorage(cashMinor);
    saveLastTotal(totalMinor);

    // If cash was entered but now insufficient, show a prominent warning banner
    if (cashMinor > 0 && cashMinor < totalMinor) {
      showBanner('Cash entered is less than new total. Please collect additional cash or update payment method.', 'warn');
    } else {
      // remove warn banner if previously shown and now OK
      var b = document.getElementById('pos-notice-banner');
      if (b && b.textContent && b.textContent.indexOf('less than new total') !== -1) b.remove();
    }
  }

  // Restore cash from storage into the input (non-destructive)
  function restoreCashIfAny() {
    var stored = loadCashFromStorage();
    if (stored === null) return;
    var el = document.getElementById('cash_received');
    if (!el) return;
    // Only restore when input empty or when stored differs and input is empty
    var current = parseCashInputValue();
    if ((current === 0 || el.value === '') && stored !== 0) {
      el.value = formatMinorToDisplay(stored);
      // reflect restored typed value in cashNotes so updateCashReceived won't overwrite it
      cashNotes = [stored / 100];
    }
    recalcChangeAndUI();
  }

  // On cash input change -> save & recalc (the input listener above already calls updateCashReceived)
  var cashInput = document.getElementById('cash_received');
  if (cashInput) {
    cashInput.addEventListener('input', function(e){
      // normalize numeric format (optional)
      var v = this.value;
      // allow empty, numeric and decimal
      var parsed = parseFloat(v) || 0;
      // Save as minor
      saveCashToStorage(Math.round(parsed * 100));
      recalcChangeAndUI();
    });
  }

  // When payment-method buttons are clicked, preserve cash (existing wiring calls submit on non-cash).
  // We only ensure value preserved and recalc when toggling.
  document.querySelectorAll('.payment-method').forEach(function(btn){
    btn.addEventListener('click', function(){
      // small delay to allow existing handlers to run
      setTimeout(recalcChangeAndUI, 80);
    });
  });

  // Detect total changes across reloads or after server update:
  // store the last total on page load, and if it differs from previously stored total, notify cashier
  document.addEventListener('DOMContentLoaded', function(){
    // restore cash (must run after DOM ready)
    restoreCashIfAny();

    var currentTotal = readMinorFromDataset();
    var previousTotal = loadLastTotal();
    if (previousTotal !== null && previousTotal !== currentTotal) {
      // total changed since last stored value (e.g., cashier added product or server recalced)
      if (parseCashInputValue() > 0) {
        // show info and recalc
        showBanner('Cart changed — recalculated change based on new total.', 'info');
      }
    }
    // save current total as baseline
    saveLastTotal(currentTotal);
  });

  // Add a small Clear button next to the cash input (non-invasive)
  (function attachClearButton(){
    var wrapper = document.getElementById('cash-panel-wrapper');
    var cashEl = document.getElementById('cash_received');
    if (!wrapper || !cashEl) return;
    var clearId = 'pos-clear-cash-btn';
    if (document.getElementById(clearId)) return; // already added

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.id = clearId;
    btn.textContent = 'Clear';
    btn.title = 'Clear cash input';
    btn.style.cssText = 'margin-left:8px;padding:8px 10px;border-radius:8px;border:1px solid #ccc;background:#fff;cursor:pointer;';
    btn.addEventListener('click', function(){
      cashEl.value = '';
      saveCashToStorage(0);
      cashNotes = [];
      recalcChangeAndUI();
      // remove any warning banner
      var b = document.getElementById('pos-notice-banner'); if (b) b.remove();
    });

    // place after cash input field
    var parent = cashEl.parentNode;
    if (parent) parent.appendChild(btn);
  })();

  // Public utility for external code to trigger a recalc if needed
  window.posRecalcChange = recalcChangeAndUI;

})();
</script>


<script>
(function(){
  // single canonical key used everywhere
  var LS_KEY_CASH = 'pos_cash_received';

  function readStoredMinor() {
    try { var v = localStorage.getItem(LS_KEY_CASH); return v ? parseInt(v,10) : null; } catch(e){ return null; }
  }
  function writeStoredMinor(minor) {
    try { localStorage.setItem(LS_KEY_CASH, String(minor)); } catch(e){}
  }

  function parseCashInputValueMinor() {
    var el = document.getElementById('cash_received');
    if (!el) return 0;
    var n = parseFloat(String(el.value).replace(/[^\d\.\-]/g,'')) || 0;
    return Math.round(n*100);
  }
  function setCashInputFromMinor(minor) {
    var el = document.getElementById('cash_received');
    if (!el) return;
    el.value = (minor/100).toFixed(2);
    // update change display if calcCashChange exists
    if (typeof calcCashChange === 'function') calcCashChange();
  }

  // Save current visible value (if any) to storage
  function persistCurrentInput() {
    var m = parseCashInputValueMinor();
    writeStoredMinor(m);
  }

  // Restore stored value if input is empty (do not clobber typed values)
  function restoreIfEmpty() {
    var stored = readStoredMinor();
    if (stored === null) return;
    var el = document.getElementById('cash_received');
    if (!el) return;
    // only restore if user hasn't typed something (empty or "0" considered empty)
    var currentMinor = parseCashInputValueMinor();
    if (currentMinor === 0 && stored !== 0 && (el.value === '' || el.value === '0' || el.value === '0.00')) {
      setCashInputFromMinor(stored);
    }
  }

  // wire input change -> persist immediately
  document.addEventListener('DOMContentLoaded', function(){
    var el = document.getElementById('cash_received');
    if (el) {
      el.addEventListener('input', function(){
        var minor = parseCashInputValueMinor();
        writeStoredMinor(minor);
        if (typeof calcCashChange === 'function') calcCashChange();
      });
    }

    // quick-cash buttons often call updateCashReceived() which writes storage; ensure they still persist:
    document.querySelectorAll('.quick-cash-btn').forEach(function(b){
      b.addEventListener('click', function(){
        // Delay briefly because the page's quick-cash handler sets the input first
        setTimeout(function(){
          persistCurrentInput();
        }, 30);
      });
    });

    // payment-method buttons: before submitting for non-cash, persist typed value
    document.querySelectorAll('.payment-method').forEach(function(btn){
      btn.addEventListener('click', function(){
        // Persist typed/quick-cash before any form submit handler runs
        persistCurrentInput();
        // If your code submits the form on non-cash, it will reload; stored value will be available on reload.
      });
    });

    // Now restore after page has finished initializing other UI (so we don't get clobbered).
    // Run after a small timeout to ensure other scripts already ran.
    setTimeout(function(){
      restoreIfEmpty();
    }, 50);
  });

  // Expose helpers for debugging
  window.posCashStore = {
    read: readStoredMinor,
    write: writeStoredMinor,
    persistCurrent: persistCurrentInput,
    restoreIfEmpty: restoreIfEmpty
  };
})();
</script>
</body>
</html>
