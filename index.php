<?php

require_once 'config/config.php';

if (!$_SESSION['UserId']){
    header("Location: member_login.php");
    exit();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    <?php include 'layout/nav.php'; ?>
</body>
</html>