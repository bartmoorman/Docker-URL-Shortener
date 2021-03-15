<?php
require_once('../inc/shortener.class.php');
$shortener = new Shortener(false, false, false, false);

$output = $logFields = ['success' => null, 'message' => null];
$log = [];
$putEvent = true;

switch ($_REQUEST['func']) {
  case 'authenticateSession':
    if (!empty($_POST['username']) && !empty($_POST['password'])) {
      $output['success'] = $shortener->authenticateSession($_POST['username'], $_POST['password']);
      $log['username'] = $_POST['username'];
      usleep(rand(750000, 1000000));
    } else {
      header('HTTP/1.1 400 Bad Request');
      $output['success'] = false;
      $output['message'] = 'Missing arguments';
    }
    break;
  case 'createUser':
    if (!$shortener->isConfigured() || ($shortener->isValidSession() && $shortener->isAdmin())) {
      if (!empty($_POST['username']) && !empty($_POST['password']) && !empty($_POST['first_name']) && !empty($_POST['role'])) {
        $last_name = !empty($_POST['last_name']) ? $_POST['last_name'] : null;
        $begin = !empty($_POST['begin']) ? $_POST['begin'] : null;
        $end = !empty($_POST['end']) ? $_POST['end'] : null;
        $output['success'] = $shortener->createUser($_POST['username'], $_POST['password'], $_POST['first_name'], $last_name, $_POST['role'], $begin, $end);
      } else {
        header('HTTP/1.1 400 Bad Request');
        $output['success'] = false;
        $output['message'] = 'Missing arguments';
      }
    } else {
      header('HTTP/1.1 401 Unauthorized');
      $output['success'] = false;
      $output['message'] = 'Unauthorized';
    }
    break;
  case 'createApp':
    if ($shortener->isValidSession() && $shortener->isAdmin()) {
      if (!empty($_POST['name'])) {
        $token = isset($_POST['token']) ? $_POST['token'] : null;
        $begin = !empty($_POST['begin']) ? $_POST['begin'] : null;
        $end = !empty($_POST['end']) ? $_POST['end'] : null;
        $output['success'] = $shortener->createApp($_POST['name'], $token, $begin, $end);
      } else {
        header('HTTP/1.1 400 Bad Request');
        $output['success'] = false;
        $output['message'] = 'No name supplied';
      }
    } else {
      header('HTTP/1.1 401 Unauthorized');
      $output['success'] = false;
      $output['message'] = 'Unauthorized';
    }
    break;
  case 'updateUser':
    if ($shortener->isValidSession() && $shortener->isAdmin()) {
      if (!empty($_POST['user_id']) && !empty($_POST['username']) && !empty($_POST['first_name']) && !empty($_POST['role'])) {
        $password = !empty($_POST['password']) ? $_POST['password'] : null;
        $last_name = !empty($_POST['last_name']) ? $_POST['last_name'] : null;
        $begin = !empty($_POST['begin']) ? $_POST['begin'] : null;
        $end = !empty($_POST['end']) ? $_POST['end'] : null;
        $output['success'] = $shortener->updateUser($_POST['user_id'], $_POST['username'], $password, $_POST['first_name'], $last_name, $_POST['role'], $begin, $end);
        $log['user_id'] = $_POST['user_id'];
      } else {
        header('HTTP/1.1 400 Bad Request');
        $output['success'] = false;
        $output['message'] = 'Missing arguments';
      }
    } else {
      header('HTTP/1.1 401 Unauthorized');
      $output['success'] = false;
      $output['message'] = 'Unauthorized';
    }
    break;
  case 'updateApp':
    if ($shortener->isValidSession() && $shortener->isAdmin()) {
      if (!empty($_POST['app_id']) && !empty($_POST['name']) && !empty($_POST['token'])) {
        $begin = !empty($_POST['begin']) ? $_POST['begin'] : null;
        $end = !empty($_POST['end']) ? $_POST['end'] : null;
        $output['success'] = $shortener->updateApp($_POST['app_id'], $_POST['name'], $_POST['token'], $begin, $end);
        $log['app_id'] = $_POST['app_id'];
      } else {
        header('HTTP/1.1 400 Bad Request');
        $output['success'] = false;
        $output['message'] = 'Missing arguments';
      }
    } else {
      header('HTTP/1.1 401 Unauthorized');
      $output['success'] = false;
      $output['message'] = 'Unauthorized';
    }
    break;
  case 'modifyObject':
    if ($shortener->isValidSession() && $shortener->isAdmin()) {
      if (!empty($_POST['action']) && !empty($_POST['type']) && !empty($_POST['value'])) {
        $output['success'] = $shortener->modifyObject($_POST['action'], $_POST['type'], $_POST['value']);
        $log['action'] = $_POST['action'];
        $log['type'] = $_POST['type'];
        $log['value'] = $_POST['value'];
      } else {
        header('HTTP/1.1 400 Bad Request');
        $output['success'] = false;
        $output['message'] = 'Missing arguments';
      }
    } else {
      header('HTTP/1.1 401 Unauthorized');
      $output['success'] = false;
      $output['message'] = 'Unauthorized';
    }
    break;
  case 'getObjectDetails':
    if ($shortener->isValidSession() && $shortener->isAdmin()) {
      if (!empty($_REQUEST['type']) && !empty($_REQUEST['value'])) {
        if ($output['data'] = $shortener->getObjectDetails($_REQUEST['type'], $_REQUEST['value'])) {
          $output['success'] = true;
          $putEvent = false;
        } else {
          $output['success'] = false;
          $log['type'] = $_REQUEST['type'];
          $log['value'] = $_REQUEST['value'];
        }
      } else {
        header('HTTP/1.1 400 Bad Request');
        $output['success'] = false;
        $output['message'] = 'Missing arguments';
      }
    } else {
      header('HTTP/1.1 401 Unauthorized');
      $output['success'] = false;
      $output['message'] = 'Unauthorized';
    }
    break;
}

if ($putEvent) {
  $user_id = array_key_exists('authenticated', $_SESSION) ? $_SESSION['user_id'] : null;
  $shortener->putEvent($user_id, $_REQUEST['func'], array_merge(array_intersect_key($output, $logFields), $log));
}

header('Content-Type: application/json');
echo json_encode($output);
?>
