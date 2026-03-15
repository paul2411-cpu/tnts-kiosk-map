<?php
// require_once __DIR__ . "/inc/db.php";

// $username = "admin";
// $password = "admin123";
// $full_name = "Super Admin";
// $role = "superadmin";

// $hash = password_hash($password, PASSWORD_DEFAULT);

// $stmt = $conn->prepare("INSERT INTO admin_users (username, password_hash, full_name, role) VALUES (?,?,?,?)");
// if (!$stmt) {
//   die("Prepare failed: " . htmlspecialchars($conn->error));
// }

// $stmt->bind_param("ssss", $username, $hash, $full_name, $role);

// if ($stmt->execute()) {
//   echo "Seeded admin user successfully.<br>";
//   echo "Username: admin<br>Password: admin123<br>";
//   echo "<b>DELETE seed_admin.php now.</b>";
// } else {
//   echo "Error: " . htmlspecialchars($stmt->error);
// }

// $stmt->close();
