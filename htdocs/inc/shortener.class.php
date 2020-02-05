<?php
class Shortener {
  private $dbFile = '/config/shortener.db';
  private $dbConn;
  private $allowsHashChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  private $memcachedHost;
  public $memcachedConn;
  public $pageLimit = 20;

  public function __construct($requireConfigured = true, $requireValidSession = true, $requireAdmin = true, $requireIndex = false) {
    session_start([
      'save_path' => '/config/sessions',
      'name' => '_sess_shortener',
      'gc_probability' => 1,
      'gc_divisor' => 1000,
      'gc_maxlifetime' => 60 * 60 * 24 * 7,
      'cookie_lifetime' => 60 * 60 * 24 * 7,
      'cookie_secure' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['REQUEST_SCHEME'] == 'https' ? true : false,
      'cookie_httponly' => true,
      'use_strict_mode' => true
    ]);

    if (is_writable($this->dbFile)) {
      $this->connectDb();
    } elseif (is_writable(dirname($this->dbFile))) {
      $this->connectDb();
      $this->initDb();
    }

    $this->memcachedHost = getenv('MEMCACHED_HOST');
    $this->connectMemcached();

    if ($this->isConfigured()) {
      if ($this->isValidSession()) {
        if (($requireAdmin && !$this->isAdmin()) || $requireIndex) {
          header('Location: index.php');
          exit;
        }
      } elseif ($requireValidSession) {
        header('Location: login.php');
        exit;
      }
    } elseif ($requireConfigured) {
      header('Location: setup.php');
      exit;
    }
  }

  private function connectDb() {
    if ($this->dbConn = new SQLite3($this->dbFile)) {
      $this->dbConn->busyTimeout(500);
      $this->dbConn->exec('PRAGMA journal_mode = WAL');
      return true;
    }
    return false;
  }

  private function initDb() {
    $query = <<<EOQ
CREATE TABLE IF NOT EXISTS `config` (
  `config_id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `key` TEXT NOT NULL UNIQUE,
  `value` TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `username` TEXT NOT NULL UNIQUE,
  `password` TEXT NOT NULL,
  `first_name` TEXT NOT NULL,
  `last_name` TEXT,
  `role` TEXT NOT NULL,
  `begin` INTEGER,
  `end` INTEGER,
  `disabled` INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS `events` (
  `event_id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `date` INTEGER DEFAULT (STRFTIME('%s', 'now')),
  `user_id` INTEGER,
  `action` TEXT,
  `message` BLOB,
  `remote_addr` INTEGER
);
CREATE TABLE IF NOT EXISTS `urls` (
  `url_id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `app_id` INTEGER NOT NULL,
  `url` TEXT NOT NULL,
  `hash` TEXT,
  `checksum` TEXT NOT NULL UNIQUE,
  `begin` INTEGER,
  `end` INTEGER,
  `disabled` INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS `resolves` (
  `resolve_id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `date` INTEGER DEFAULT (STRFTIME('%s', 'now')),
  `url_id` INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS `apps` (
  `app_id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `name` TEXT NOT NULL,
  `token` TEXT NOT NULL UNIQUE,
  `begin` INTEGER,
  `end` INTEGER,
  `disabled` INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS `calls` (
  `call_id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `date` INTEGER DEFAULT (STRFTIME('%s', 'now')),
  `app_id` INTEGER NOT NULL,
  `action` TEXT,
  `message` BLOB,
  `remote_addr` INTEGER
);
EOQ;
    if ($this->dbConn->exec($query)) {
      $hashChars = str_shuffle($this->allowsHashChars);
      $query = <<<EOQ
INSERT
INTO `config` (`key`, `value`)
VALUES ('hashChars', '{$hashChars}');
EOQ;
      if ($this->dbConn->exec($query)) {
        return true;
      }
    }
    return false;
  }

  private function connectMemcached() {
    if ($this->memcachedConn = new Memcached()) {
      $this->memcachedConn->addServer($this->memcachedHost, 11211);
      return true;
    }
    return false;
  }

  public function isConfigured() {
    if ($this->getObjectCount('users')) {
      return true;
    }
    return false;
  }

  public function isValidSession() {
    if (array_key_exists('authenticated', $_SESSION) && $this->isValidObject('user_id', $_SESSION['user_id'])) {
      return true;
    }
    return false;
  }

  public function isAdmin() {
    $user_id = $_SESSION['user_id'];
    $query = <<<EOQ
SELECT COUNT(*)
FROM `users`
WHERE `user_id` = '{$user_id}'
AND `role` = 'admin';
EOQ;
    if ($this->dbConn->querySingle($query)) {
      return true;
    }
    return false;
  }

  public function isValidCredentials($username, $password) {
    $username = $this->dbConn->escapeString($username);
    $query = <<<EOQ
SELECT `password`
FROM `users`
WHERE `username` = '{$username}'
EOQ;
    if (password_verify($password, $this->dbConn->querySingle($query))) {
      return true;
    }
    return false;
  }

  public function isValidObject($type, $value) {
    $type = $this->dbConn->escapeString($type);
    $value = $this->dbConn->escapeString($value);
    switch ($type) {
      case 'username':
      case 'user_id':
        $table = 'users';
        break;
      case 'checksum':
      case 'hash':
      case 'url_id':
        $table = 'urls';
        break;
      case 'token':
      case 'app_id':
        $table = 'apps';
        break;
    }
    $query = <<<EOQ
SELECT COUNT(*)
FROM `{$table}`
WHERE `{$type}` = '{$value}'
AND (`begin` IS NULL OR `begin` < STRFTIME('%s', 'now', 'localtime'))
AND (`end` IS NULL OR `end` > STRFTIME('%s', 'now', 'localtime'))
AND NOT `disabled`;
EOQ;
    if ($this->dbConn->querySingle($query)) {
      return true;
    }
    return false;
  }

  public function resolveObject($type, $value) {
    $type = $this->dbConn->escapeString($type);
    $value = $this->dbConn->escapeString($value);
    switch ($type) {
      case 'token':
        $column = 'app_id';
        $table = 'apps';
        break;
    }
    $query = <<<EOQ
SELECT `{$column}`
FROM `{$table}`
WHERE `{$type}` = '{$value}';
EOQ;
    if ($object_id = $this->dbConn->querySingle($query)) {
      return $object_id;
    }
    return false;
  }

  public function authenticateSession($username, $password) {
    if ($this->isValidCredentials($username, $password)) {
      $username = $this->dbConn->escapeString($username);
      $query = <<<EOQ
SELECT `user_id`
FROM `users`
WHERE `username` = '{$username}';
EOQ;
      if ($user_id = $this->dbConn->querySingle($query)) {
        $_SESSION['authenticated'] = true;
        $_SESSION['user_id'] = $user_id;
        return true;
      }
    }
    return false;
  }

  public function deauthenticateSession() {
    if (session_destroy()) {
      return true;
    }
    return false;
  }

  public function getConfig($key) {
    $key = $this->dbConn->escapeString($key);
    $query = <<<EOQ
SELECT `value`
FROM `config`
WHERE `key` = '{$key}';
EOQ;
    if ($value = $this->dbConn->querySingle($query)) {
      return $value;
    }
    return false;
  }

  public function createUser($username, $password, $first_name, $last_name = null, $role, $begin = null, $end = null) {
    $username = $this->dbConn->escapeString($username);
    $query = <<<EOQ
SELECT COUNT(*)
FROM `users`
WHERE `username` = '{$username}';
EOQ;
    if (!$this->dbConn->querySingle($query)) {
      $password = password_hash($password, PASSWORD_DEFAULT);
      $first_name = $this->dbConn->escapeString($first_name);
      $last_name = $this->dbConn->escapeString($last_name);
      $role = $this->dbConn->escapeString($role);
      $begin = $this->dbConn->escapeString($begin);
      $end = $this->dbConn->escapeString($end);
      $query = <<<EOQ
INSERT
INTO `users` (`username`, `password`, `first_name`, `last_name`, `role`, `begin`, `end`)
VALUES ('{$username}', '{$password}', '{$first_name}', '{$last_name}', '{$role}', STRFTIME('%s', '{$begin}'), STRFTIME('%s', '{$end}'));
EOQ;
      if ($this->dbConn->exec($query)) {
        return true;
      }
    }
    return false;
  }

  public function createApp($name, $token = null, $begin = null, $end = null) {
    $token = !$token ? bin2hex(random_bytes(8)) : $this->dbConn->escapeString($token);
    $query = <<<EOQ
SELECT COUNT(*)
FROM `apps`
WHERE `token` = '{$token}';
EOQ;
    if (!$this->dbConn->querySingle($query)) {
      $name = $this->dbConn->escapeString($name);
      $begin = $this->dbConn->escapeString($begin);
      $end = $this->dbConn->escapeString($end);
      $query = <<<EOQ
INSERT
INTO `apps` (`name`, `token`, `begin`, `end`)
VALUES ('{$name}', '{$token}', STRFTIME('%s','{$begin}'), STRFTIME('%s','{$end}'));
EOQ;
      if ($this->dbConn->exec($query)) {
        return true;
      }
    }
    return false;
  }

  public function updateUser($user_id, $username, $password = null, $first_name, $last_name = null, $role, $begin = null, $end = null) {
    $user_id = $this->dbConn->escapeString($user_id);
    $username = $this->dbConn->escapeString($username);
    $query = <<<EOQ
SELECT COUNT(*)
FROM `users`
WHERE `user_id` != '{$user_id}'
AND `username` = '{$username}';
EOQ;
    if (!$this->dbConn->querySingle($query)) {
      $passwordQuery = null;
      if (!empty($password)) {
        $password = password_hash($password, PASSWORD_DEFAULT);
        $passwordQuery = <<<EOQ
  `password` = '{$password}',
EOQ;
      }
      $first_name = $this->dbConn->escapeString($first_name);
      $last_name = $this->dbConn->escapeString($last_name);
      $role = $this->dbConn->escapeString($role);
      $begin = $this->dbConn->escapeString($begin);
      $end = $this->dbConn->escapeString($end);
      $query = <<<EOQ
UPDATE `users`
SET
  `username` = '{$username}',
{$passwordQuery}
  `first_name` = '{$first_name}',
  `last_name` = '{$last_name}',
  `role` = '{$role}',
  `begin` = STRFTIME('%s', '{$begin}'),
  `end` = STRFTIME('%s', '{$end}')
WHERE `user_id` = '{$user_id}';
EOQ;
      if ($this->dbConn->exec($query)) {
        return true;
      }
    }
    return false;
  }

  public function updateApp($app_id, $name, $token, $begin, $end) {
    $app_id = $this->dbConn->escapeString($app_id);
    $token = $this->dbConn->escapeString($token);
    $query = <<<EOQ
SELECT COUNT(*)
FROM `apps`
WHERE `app_id` != '{$app_id}'
AND `token` = '{$token}';
EOQ;
    if (!$this->dbConn->querySingle($query)) {
      $name = $this->dbConn->escapeString($name);
      $begin = $this->dbConn->escapeString($begin);
      $end = $this->dbConn->escapeString($end);
      $query = <<<EOQ
UPDATE `apps`
SET
  `name` = '{$name}',
  `token` = '{$token}',
  `begin` = STRFTIME('%s', '{$begin}'),
  `end` = STRFTIME('%s', '{$end}')
WHERE `app_id` = '{$app_id}';
EOQ;
      if ($this->dbConn->exec($query)) {
        return true;
      }
    }
    return false;
  }

  public function modifyObject($action, $type, $value, $extra_type = null, $extra_value = null) {
    $type = $this->dbConn->escapeString($type);
    $value = $this->dbConn->escapeString($value);
    $extra_type = $this->dbConn->escapeString($extra_type);
    $extra_value = $this->dbConn->escapeString($extra_value);
    switch ($type) {
      case 'username':
      case 'user_id':
        $table = 'users';
        $extra_table = 'events';
        break;
      case 'checksum':
      case 'hash':
      case 'url_id':
        $table = 'urls';
        $extra_table = 'resolves';
        break;
      case 'token':
      case 'app_id':
        $table = 'apps';
        $extra_table = 'calls';
        break;
    }
    switch ($action) {
      case 'enable':
        $query = <<<EOQ
UPDATE `{$table}`
SET `disabled` = '0'
WHERE `{$type}` = '{$value}';
EOQ;
        break;
      case 'disable':
        $query = <<<EOQ
UPDATE `{$table}`
SET `disabled` = '1'
WHERE `{$type}` = '{$value}';
EOQ;
        break;
      case 'delete':
        $query = <<<EOQ
DELETE
FROM `{$table}`
WHERE `{$type}` = '{$value}';
DELETE
FROM `{$extra_table}`
WHERE `{$type}` = '{$value}';
EOQ;
        break;
    }
    if ($this->dbConn->exec($query)) {
      return true;
    }
    return false;
  }

  public function getObjects($type) {
    switch ($type) {
      case 'users':
        $query = <<<EOQ
SELECT `user_id`, `username`, `first_name`, `last_name`, `role`, `begin`, `end`, `disabled`
FROM `users`
ORDER BY `last_name`, `first_name`;
EOQ;
        break;
      case 'apps':
        $query = <<<EOQ
SELECT `app_id`, `name`, `token`, `begin`, `end`, `disabled`
FROM `apps`
ORDER BY `name`;
EOQ;
        break;
    }
    if ($objects = $this->dbConn->query($query)) {
      $output = [];
      while ($object = $objects->fetchArray(SQLITE3_ASSOC)) {
        $output[] = $object;
      }
      return $output;
    }
    return false;
  }

  public function getObjectDetails($type, $value) {
    $value = $this->dbConn->escapeString($value);
    switch ($type) {
      case 'user':
        $query = <<<EOQ
SELECT `user_id`, `username`, `first_name`, `last_name`, `role`, STRFTIME('%Y-%m-%dT%H:%M', `begin`, 'unixepoch') AS `begin`, STRFTIME('%Y-%m-%dT%H:%M', `end`, 'unixepoch') AS `end`, `disabled`
FROM `users`
WHERE `user_id` = '{$value}';
EOQ;
        break;
      case 'app':
        $query = <<<EOQ
SELECT `app_id`, `name`, `token`, STRFTIME('%Y-%m-%dT%H:%M', `begin`, 'unixepoch') AS `begin`, STRFTIME('%Y-%m-%dT%H:%M', `end`, 'unixepoch') AS `end`, `disabled`
FROM `apps`
WHERE `app_id` = '{$value}';
EOQ;
        break;
    }
    if ($object = $this->dbConn->querySingle($query, true)) {
      return $object;
    }
    return false;
  }

  public function getObjectCount($type) {
    $type = $this->dbConn->escapeString($type);
    $query = <<<EOQ
SELECT COUNT(*)
FROM `{$type}`;
EOQ;
    if ($count = $this->dbConn->querySingle($query)) {
      return $count;
    }
    return false;
  }

  public function putEvent($user_id, $action, $message = []) {
    $user_id = $this->dbConn->escapeString($user_id);
    $action = $this->dbConn->escapeString($action);
    $message = $this->dbConn->escapeString(json_encode($message));
    $remote_addr = ip2long(array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']);
    $query = <<<EOQ
INSERT
INTO `events` (`user_id`, `action`, `message`, `remote_addr`)
VALUES ('{$user_id}', '{$action}', '{$message}', '{$remote_addr}');
EOQ;
    if ($this->dbConn->exec($query)) {
      return true;
    }
    return false;
  }

  public function putCall($token, $action, $message = []) {
    $app_id = $this->resolveObject('token', $token);
    $action = $this->dbConn->escapeString($action);
    $message = $this->dbConn->escapeString(json_encode($message));
    $remote_addr = ip2long(array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']);
    $query = <<<EOQ
INSERT
INTO `calls` (`app_id`, `action`, `message`, `remote_addr`)
VALUES ('{$app_id}', '{$action}', '{$message}', '{$remote_addr}');
EOQ;
    if ($this->dbConn->exec($query)) {
      return true;
    }
    return false;
  }

  public function getEvents($page = 1) {
    $start = ($page - 1) * $this->pageLimit;
    $query = <<<EOQ
SELECT `event_id`, STRFTIME('%s', `date`, 'unixepoch') AS `date`, `user_id`, `first_name`, `last_name`, `action`, `message`, `remote_addr`, `disabled`
FROM `events`
LEFT JOIN `users` USING (`user_id`)
ORDER BY `date` DESC
LIMIT {$start}, {$this->pageLimit};
EOQ;
    if ($events = $this->dbConn->query($query)) {
      $output = [];
      while ($event = $events->fetchArray(SQLITE3_ASSOC)) {
        $output[] = $event;
      }
      return $output;
    }
    return false;
  }

  public function createShortURL($token, $url) {
    $app_id = $this->resolveObject('token', $token);
    $url = $this->dbConn->escapeString($url);
    $checksum = sha1($url);
    $query = <<<EOQ
INSERT OR IGNORE
INTO `urls` (`app_id`, `url`, `checksum`)
VALUES ('{$app_id}', '{$url}', '{$checksum}');
EOQ;
    if ($this->dbConn->exec($query)) {
      if ($url_id = $this->dbConn->lastInsertRowID()) {
        $hash = $this->generateHash($url_id);
        $query = <<<EOQ
UPDATE `urls`
SET `hash` = '{$hash}'
WHERE `url_id` = '{$url_id}';
EOQ;
        if ($this->dbConn->exec($query)) {
          return ['hash' => $hash, 'new' => 1];
        }
      } else {
        $query = <<<EOQ
SELECT `url_id`, `hash`
FROM `urls`
WHERE `checksum` = '{$checksum}';
EOQ;
        if ($url = $this->dbConn->querySingle($query, true)) {
          return ['hash' => $url['hash'], 'new' => 0];
        }
      }
    }
    return false;
  }

  public function resolveShortURL($hash) {
    $hash = $this->dbConn->escapeString($hash);
    $query = <<<EOQ
SELECT `url_id`, `url`
FROM `urls`
WHERE `hash` = '{$hash}'
AND (`begin` IS NULL OR `begin` < STRFTIME('%s', 'now', 'localtime'))
AND (`end` IS NULL OR `end` > STRFTIME('%s', 'now', 'localtime'))
AND NOT `disabled`;
EOQ;
    if ($url = $this->dbConn->querySingle($query, true)) {
      $query = <<<EOQ
INSERT
INTO `resolves` (`url_id`)
VALUES ({$url['url_id']})
EOQ;
      if ($this->dbConn->exec($query)) {
        return $url['url'];
      }
    }
    return false;
  }


  public function generateHash($value, $base = 62) {
    $hashChars = $this->getConfig('hashChars');
    $string = '';
    while ($value > 0) {
      $i = $value % $base;
      $string = $hashChars[$i] . $string;
      $value = ($value - $i) / $base;
    }
    return $string;
  }

  public function decodeHash($string, $base = 62) {
    $hashChars = $this->getConfig('hashChars');
    $length = strlen($string);
    $value = 0;
    for ($i = 0; $i < $length; $i++) {
      $value += strpos($hashChars, $string[$i]) * pow($base, $length - $i - 1);
    }
    return $value;
  }
}
?>
