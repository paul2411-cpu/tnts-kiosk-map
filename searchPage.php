<?php
// TNTS Kiosk Search Page
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TANZA National Trade School - Search</title>
    <link rel="stylesheet" href="searchPage.css">
    <script src="js/on-screen-keyboard.js"></script>
</head>
<body>
    <div class="kiosk-container">
        <div class="search-content">
            <h1 class="page-title">Search</h1>
            <p class="subtitle">Search for facilities, courses, and events.</p>
            <div class="search-box">
                <input type="text" class="search-input" placeholder="Type here to search...">
                <button class="search-button">
                    <div class="search-icon icon-search"></div>
                </button>
            </div>
        </div>
        <?php include 'nav.php'; ?>
    </div>
    <script src="kiosk.js"></script>
</body>
</html>
