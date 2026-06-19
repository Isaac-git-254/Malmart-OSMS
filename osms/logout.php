<?php
session_start();
session_unset();   // Remove all active session global data values
session_destroy(); // Annihilate the background server session file storage reference
header('Location: index.php'); // Automatically loop back to the storefront view
exit;
