<?php
session_start();
session_destroy();
header('Location: /smartgate/login.php');
exit;
