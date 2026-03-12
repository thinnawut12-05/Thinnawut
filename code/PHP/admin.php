<?php
session_start();
include 'db.php'; // ตรวจสอบว่า db.php เชื่อมต่อฐานข้อมูลเรียบร้อย

// --- ตรวจสอบการเชื่อมต่อฐานข้อมูล ---
if (!$conn) {
    die("❌ ไม่สามารถเชื่อมต่อฐานข้อมูลได้: " . mysqli_connect_error());
}
$conn->set_charset("utf8"); // ตั้งค่า charset

// --- ตรรกะการล็อกอิน ---
$error = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    $email = $_POST['email'] ?? '';
    $password_input = $_POST['password'] ?? '';

    $sql = "SELECT Email_Admin, Password, First_name, Last_name FROM admin WHERE Email_Admin=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $hashed_password_from_db = $row['Password'];

        if (password_verify($password_input, $hashed_password_from_db)) {
            // เข้าสู่ระบบสำเร็จ
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_email'] = $row['Email_Admin'];
            $_SESSION['admin_name'] = $row['First_name'] . " " . $row['Last_name'];

            header("Location: " . $_SERVER['PHP_SELF']); // รีเฟรชหน้า
            exit();
        } else {
            $error = "อีเมลหรือรหัสผ่านไม่ถูกต้อง!";
        }
    } else {
        $error = "อีเมลหรือรหัสผ่านไม่ถูกต้อง!";
    }
    $stmt->close();
}

// --- แสดงฟอร์มล็อกอินถ้ายังไม่ได้เข้าสู่ระบบ ---
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']):
?>
    <!DOCTYPE html>
    <html lang="th">

    <head>
        <meta charset="UTF-8">
        <title>Admin Login</title>
        <link rel="icon" type="image/png" href="../src/images/logo.png" />
        <style>
            body {
                font-family: sans-serif;
                background-color: #f2f2f2;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
            }

            .login-container {
                background-color: #fff;
                padding: 30px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                width: 350px;
                text-align: center;
            }

            h2 {
                color: #333;
                margin-bottom: 20px;
            }

            .form-group {
                margin-bottom: 15px;
                text-align: left;
            }

            label {
                display: block;
                margin-bottom: 5px;
                color: #555;
            }

            input[type="email"],
            input[type="password"] {
                width: calc(100% - 22px);
                padding: 10px;
                border: 1px solid #ccc;
                border-radius: 4px;
                box-sizing: border-box;
            }

            button {
                background-color: #007bff;
                color: white;
                padding: 10px 15px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                width: 100%;
                font-size: 16px;
                margin-top: 10px;
            }

            button:hover {
                background-color: #0056b3;
            }

            .error-message {
                color: #dc3545;
                margin-top: 10px;
            }
        </style>
    </head>

    <body>
        <div class="login-container">
            <h2>Admin Login</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <?php if ($error): ?>
                    <p class="error-message"><?= htmlspecialchars($error) ?></p>
                <?php endif; ?>
                <button type="submit" name="login">Login</button>
            </form>
        </div>
    </body>

    </html>
<?php
    exit();
endif;

// --- ตรรกะอัปเดตสถานะการจอง ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reservation_id'], $_POST['status'])) {
    $reservation_id = $_POST['reservation_id'];
    $status = $_POST['status'];
    $admin_email = $_SESSION['admin_email'] ?? NULL; // ดึงอีเมลแอดมินจาก session

    if ($status == 3) { // อนุมัติ: สถานะ 3 คือ "ยืนยันการจองและชำระเงินแล้ว"

        // --- 1. ดึง Receipt_Id จากตาราง reservation ---
        $receipt_id_for_update = null;
        $sql_get_receipt_id = "SELECT Receipt_Id FROM reservation WHERE Reservation_Id = ?";
        $stmt_get_receipt_id = $conn->prepare($sql_get_receipt_id);
        if ($stmt_get_receipt_id) {
            $stmt_get_receipt_id->bind_param("s", $reservation_id); // Reservation_Id เป็น varchar(10)
            $stmt_get_receipt_id->execute();
            $result_receipt_id = $stmt_get_receipt_id->get_result();
            if ($row_receipt_id = $result_receipt_id->fetch_assoc()) {
                $receipt_id_for_update = $row_receipt_id['Receipt_Id'];
            }
            $stmt_get_receipt_id->close();
        }

        // --- 2. อัปเดตสถานะในตาราง reservation และ Email_Admin ---
        $sql_update_reservation = "UPDATE reservation SET Booking_status_Id = ?, Email_Admin = ? WHERE Reservation_Id = ?";
        $stmt_update_reservation = $conn->prepare($sql_update_reservation);
        if ($stmt_update_reservation) {
            $stmt_update_reservation->bind_param("sss", $status, $admin_email, $reservation_id);
            $stmt_update_reservation->execute();
            $stmt_update_reservation->close();
        }

        // --- 3. อัปเดต Status และ Email_Admin ในตาราง receipt ถ้ามี Receipt_Id ---
        if ($receipt_id_for_update !== null && $receipt_id_for_update !== "") {
            // อัปเดต Status = 'Yes' และ Email_Admin
            $sql_update_receipt = "UPDATE receipt SET Status = 'Yes', Email_Admin = ? WHERE Receipt_Id = ?";
            $stmt_update_receipt = $conn->prepare($sql_update_receipt);
            if ($stmt_update_receipt) {
                $stmt_update_receipt->bind_param("si", $admin_email, $receipt_id_for_update); // s=Email_Admin, i=Receipt_Id
                $stmt_update_receipt->execute();
                // DEBUG
                // error_log("Update error: ".$stmt_update_receipt->error);
                // error_log("Affected rows: ".$stmt_update_receipt->affected_rows);
                $stmt_update_receipt->close();
            }
        }
    } else { // ปฏิเสธ (สถานะ 5)
        // สถานะ 5 คือ "ปฏิเสธการจอง"
        $sql_update = "UPDATE reservation SET Booking_status_Id = ? WHERE Reservation_Id = ?";
        $stmt_update = $conn->prepare($sql_update);
        if ($stmt_update) {
            $stmt_update->bind_param("ss", $status, $reservation_id);
            $stmt_update->execute();
            $stmt_update->close();
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']); // รีเฟรชหน้า
    exit();
}

// --- ดึงข้อมูล reservation พร้อมข้อมูลจาก receipt และ province ---
$sql_select = "SELECT 
                    r.Reservation_Id, 
                    r.Guest_name, 
                    r.Number_of_rooms,
                    r.Booking_date, 
                    r.Check_out_date,
                    r.Number_of_adults, 
                    r.Number_of_children,
                    r.Email_member, 
                    r.Booking_status_Id, 
                    r.Total_price, 
                    b.Booking_status_name, 
                    p.Province_name,
                    rc.Payment_image_file 
               FROM reservation r
               LEFT JOIN booking_status b ON r.Booking_status_Id = b.Booking_status_Id
               LEFT JOIN province p ON r.Province_Id = p.Province_Id
               LEFT JOIN receipt rc ON r.Receipt_Id = rc.Receipt_Id 
               ORDER BY r.Booking_time DESC";
$result = $conn->query($sql_select);
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - Dom Inn Hotel</title>
    <link rel="icon" type="image/png" href="../src/images/logo.png" />
    <style>
        /* CSS เบื้องต้น */
        body {
            font-family: "Segoe UI", sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 20px;
            color: #333;
        }

        h2 {
            color: #2c3e50;
            margin-bottom: 25px;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }

        .admin-navbar {
            background-color: #34495e;
            padding: 10px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .admin-navbar ul {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
        }

        .admin-navbar ul li {
            margin-right: 20px;
        }

        .admin-navbar ul li a {
            color: #ecf0f1;
            text-decoration: none;
            font-weight: bold;
            padding: 8px 12px;
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }

        .admin-navbar ul li a:hover,
        .admin-navbar ul li a.active {
            background-color: #1abc9c;
        }

        .welcome-text {
            color: #ecf0f1;
            font-weight: bold;
            margin-right: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        th,
        td {
            padding: 12px 15px;
            border: 1px solid #ddd;
            text-align: center;
            vertical-align: middle;
        }

        th {
            background: #3498db;
            color: #fff;
            font-weight: bold;
        }

        tr:nth-child(even) {
            background-color: #f8f8f8;
        }

        tr:hover {
            background-color: #f1f1f1;
        }

        .btn {
            padding: 8px 14px;
            border: none;
            border-radius: 5px;
            color: #fff;
            cursor: pointer;
            font-size: 0.9em;
            transition: background 0.3s ease;
            margin: 2px;
            display: inline-block;
            text-decoration: none;
        }

        .btn-approve {
            background: #2ecc71;
        }

        .btn-approve:hover {
            background: #27ae60;
        }

        .btn-reject {
            background: #e74c3c;
        }

        .btn-reject:hover {
            background: #c0392b;
        }

        .receipt-thumbnail {
            width: 70px;
            height: auto;
            border-radius: 5px;
            border: 1px solid #ddd;
            vertical-align: middle;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s ease;
        }

        .receipt-thumbnail:hover {
            transform: scale(1.05);
        }

        .status-text {
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
            color: #333;
            display: inline-block;
        }

        .status-3 {
            /* สถานะ: ชำระเงินสำเร็จแล้ว (จาก Booking_status_Id) */
            background-color: #2ecc71;
            color: #fff;
        }

        .status-2 {
            /* สถานะ: ชำระเงินสำเร็จรอตรวจสอบ (จาก Booking_status_Id) */
            background-color: #f39c12;
            color: #333;
        }

        .status-5 {
            /* สถานะ: ปฏิเสธ (จาก Booking_status_Id) */
            background-color: #e74c3c;
            color: #fff;
        }

        /* เพิ่ม style สำหรับสถานะ 1 (ยืนยันการจองและรอการชำระเงิน) ถ้ายังไม่มี */
        .status-1 {
            background-color: #a0a0a0;
            /* สีเทา */
            color: #fff;
        }

        .no-file-text {
            color: #333;
            font-weight: bold;
            font-size: 0.9em;
        }

        .logout-link {
            text-decoration: none;
            color: #e74c3c;
            font-weight: bold;
            padding: 8px 12px;
            border-radius: 5px;
            background-color: #fff;
            transition: background-color 0.3s ease;
            float: right;
            margin-top: 5px;
        }

        .logout-link:hover {
            background-color: #fdd;
        }

        .btn-back {
            display: inline-block;
            padding: 10px 20px;
            background-color: #6c757d;
            color: white;
            border-radius: 5px;
            text-decoration: none;
            text-align: center;
            transition: background-color 0.3s ease;
            margin-top: 20px;
        }

        .btn-back:hover {
            background-color: #5a6268;
        }
    </style>
</head>

<body>
    <div class="admin-navbar">
        <div class="welcome-text">ยินดีต้อนรับ, <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></div>
        <!-- <a href="index.php" class="logout-link">ออกจากระบบ</a> -->
        <a href="admin-home.php" class="btn-back">กลับหน้าผู้ดูแลระบบ</a>
    </div>

    <h2>ตรวจสอบหลักฐานการโอนเงิน</h2>
    <table>
        <thead>
            <tr>
                <th>รหัสจอง</th>
                <th>ชื่อผู้เข้าพัก</th>
                <th>จำนวนห้อง</th>
                <th>เช็คอิน</th>
                <th>เช็คเอาท์</th>
                <th>ผู้ใหญ่</th>
                <th>เด็ก</th>
                <th>อีเมลลูกค้า</th>
                <th>สาขา</th>
                <th>ราคารวม</th>
                <th>หลักฐานการโอน</th>
                <th>สถานะ</th>
                <th>การกระทำ</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['Reservation_Id']) ?></td>
                        <td><?= htmlspecialchars($row['Guest_name']) ?></td>
                        <td><?= htmlspecialchars($row['Number_of_rooms']) ?></td>
                        <td><?= htmlspecialchars($row['Booking_date']) ?></td>
                        <td><?= htmlspecialchars($row['Check_out_date']) ?></td>
                        <td><?= htmlspecialchars($row['Number_of_adults']) ?></td>
                        <td><?= htmlspecialchars($row['Number_of_children']) ?></td>
                        <td><?= htmlspecialchars($row['Email_member']) ?></td>
                        <td><?= htmlspecialchars($row['Province_name'] ?? 'ไม่ระบุ') ?></td>
                        <td><?= number_format($row['Total_price'] ?? 0, 2) ?></td>
                        <td>
                            <?php if (!empty($row['Payment_image_file'])): ?>
                                <?php
                                $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
                                $image_web_path = $base_url . "/Thinnawut/code/PHP/uploads/receipts/" . htmlspecialchars($row['Payment_image_file']);
                                ?>
                                <a href="<?= $image_web_path ?>" target="_blank">
                                    <img src="<?= $image_web_path ?>" alt="สลิปโอนเงิน" class="receipt-thumbnail">
                                </a>
                            <?php else: ?>
                                <span class="no-file-text">ไม่มีไฟล์</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $status_class = str_replace(' ', '-', strtolower($row['Booking_status_name'] ?? 'ไม่ทราบ')); ?>
                            <span class="status-text status-<?= htmlspecialchars($row['Booking_status_Id']) ?>">
                                <?= htmlspecialchars($row['Booking_status_name'] ?? 'ไม่ทราบ') ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            // แสดงปุ่มเฉพาะเมื่อสถานะเป็น 1 (ยืนยันการจองและรอการชำระเงิน) หรือ 2 (ชำระเงินสำเร็จรอตรวจสอบ)
                            if ($row['Booking_status_Id'] == 1 || $row['Booking_status_Id'] == 2): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="reservation_id" value="<?= htmlspecialchars($row['Reservation_Id']) ?>">
                                    <input type="hidden" name="status" value="3">
                                    <button type="submit" class="btn btn-approve">อนุมัติ</button>
                                </form>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="reservation_id" value="<?= htmlspecialchars($row['Reservation_Id']) ?>">
                                    <input type="hidden" name="status" value="5">
                                    <button type="submit" class="btn btn-reject">ปฏิเสธ</button>
                                </form>
                            <?php else: ?>
                                <span class="no-file-text">ดำเนินการแล้ว</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="13">ไม่พบข้อมูลการจองที่รอการตรวจสอบ</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>

</html>

<?php
$conn->close();
?>
