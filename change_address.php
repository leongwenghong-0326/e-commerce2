<?php
require_once 'config/config.php';

// Redirect if not logged in
if(!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$errors = [];
$success = "";

// -------------------------
// ADD NEW ADDRESS
// -------------------------
if(isset($_POST['save_address'])) {
    $line1    = trim($_POST['line1']);
    $line2    = trim($_POST['line2']);
    $city     = trim($_POST['city']);
    $state    = trim($_POST['state']);
    $postcode = trim($_POST['postcode']);

    if(empty($line1) || empty($city) || empty($state) || empty($postcode)) {
        $errors[] = "Address Line 1, City, State, and Postcode are required.";
    } else {
        // Check if user has any address to determine default
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM Addresses WHERE UserId = :userId");
        $stmtCount->execute([':userId'=>$userId]);
        $isDefault = ($stmtCount->fetchColumn() == 0) ? 1 : 0;

        $stmt = $pdo->prepare("
            INSERT INTO Addresses 
            (AddressId, UserId, AddressLine1, AddressLine2, City, State, Postcode, IsDefault)
            VALUES (UUID(), :userId, :line1, :line2, :city, :state, :postcode, :isDefault)
        ");
        $stmt->execute([
            ':userId'=>$userId,
            ':line1'=>$line1,
            ':line2'=>$line2,
            ':city'=>$city,
            ':state'=>$state,
            ':postcode'=>$postcode,
            ':isDefault'=>$isDefault
        ]);

        $success = "Address added successfully.";
    }
}

// -------------------------
// SET DEFAULT ADDRESS
// -------------------------
if(isset($_GET['set_default'])) {
    $addressId = $_GET['set_default'];

    $pdo->prepare("UPDATE Addresses SET IsDefault = 0 WHERE UserId = :userId")
        ->execute([':userId'=>$userId]);

    $pdo->prepare("UPDATE Addresses SET IsDefault = 1 WHERE AddressId = :id AND UserId = :userId")
        ->execute([':id'=>$addressId, ':userId'=>$userId]);

    $success = "Default address updated successfully.";
}

// -------------------------
// DELETE ADDRESS
// -------------------------
if(isset($_GET['delete'])) {
    $addressId = $_GET['delete'];
    $pdo->prepare("DELETE FROM Addresses WHERE AddressId = :id AND UserId = :userId")
        ->execute([':id'=>$addressId, ':userId'=>$userId]);

    $success = "Address deleted successfully.";
}

// -------------------------
// FETCH ALL ADDRESSES
// -------------------------
$stmt = $pdo->prepare("SELECT * FROM Addresses WHERE UserId = :userId ORDER BY IsDefault DESC");
$stmt->execute([':userId'=>$userId]);
$addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Addresses</title>
</head>
<body class="p-4">

<div class="container">
    <h2>Manage Addresses</h2>

    <?php if($errors): ?>
        <div class="alert alert-danger"><?php echo implode('<br>', $errors); ?></div>
    <?php endif; ?>

    <?php if($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <!-- ADD NEW ADDRESS -->
    <div class="card mb-4">
        <div class="card-header">Add New Address</div>
        <div class="card-body">
            <form method="post">
                <div class="mb-2">
                    <label>Address Line 1</label>
                    <input type="text" name="line1" class="form-control" required>
                </div>
                <div class="mb-2">
                    <label>Address Line 2 (Optional)</label>
                    <input type="text" name="line2" class="form-control">
                </div>
                <div class="mb-2">
                    <label>Postcode</label>
                    <input type="text" name="postcode" class="form-control" required>
                </div>
                <div class="mb-2">
                    <label>City</label>
                    <input type="text" name="city" class="form-control" required>
                </div>
                <div class="mb-2">
                    <label>State</label>
                    <input type="text" name="state" class="form-control" required>
                </div>
                <button type="submit" name="save_address" class="btn btn-primary">Save Address</button>
            </form>
        </div>
    </div>

    <!-- LIST OF ADDRESSES -->
    <div class="card">
        <div class="card-header">My Addresses</div>
        <div class="card-body">
            <?php if($addresses): ?>
                <?php foreach($addresses as $addr): ?>
                    <div class="border p-3 mb-3 rounded">
                        <?php
                        $parts = array_filter([
                            $addr['AddressLine1'] ?? null,
                            $addr['AddressLine2'] ?? null,
                            $addr['Postcode'] ?? null,
                            $addr['City'] ?? null,
                            $addr['State'] ?? null
                        ]);
                        $fullAddress = implode(', ', $parts);
                        ?>
                        <p><?php echo htmlspecialchars($fullAddress); ?></p>

                        <?php if($addr['IsDefault']): ?>
                            <span class="badge bg-success">Default</span>
                        <?php endif; ?>

                        <div class="mt-2">
                            <?php if(!$addr['IsDefault']): ?>
                                <a href="?set_default=<?php echo $addr['AddressId']; ?>" class="btn btn-sm btn-outline-primary">Set Default</a>
                            <?php endif; ?>
                            <a href="?delete=<?php echo $addr['AddressId']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this address?')">Delete</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-muted">No addresses added yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <a href="profile.php" class="btn btn-secondary mt-3">Back to Profile</a>
</div>

</body>
</html>