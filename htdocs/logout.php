<?php
require_once('inc/shortener.class.php');
$shortener = new Shortener(true, true, false, false);

if ($shortener->deauthenticateSession()) {
  header('Location: login.php');
} else {
  header('Location: index.php');
}
?>
