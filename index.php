<?php
/* =============================================================
   KRONO — organiza tu tiempo
   Aplicación web de un solo archivo (PHP + SQLite)
   Instalable como app en iOS desde Safari/Chrome > Compartir >
   "Añadir a pantalla de inicio".
   ============================================================= */

declare(strict_types=1);
session_start();
date_default_timezone_set('Europe/Madrid');

/* Compatibilidad: algunos servidores no traen la extensión mbstring */
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
} else {
    function mb_strlen($s, $enc = null) {
        return strlen(preg_replace('/[\x80-\xBF]/', '', (string)$s));
    }
    function mb_substr($s, $start, $len = null, $enc = null) {
        $chars = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = $len === null ? array_slice($chars, $start) : array_slice($chars, $start, $len);
        return implode('', $out);
    }
}

define('KRONO_DB', __DIR__ . '/krono.sqlite');
define('KRONO_UPLOADS', __DIR__ . '/krono_files');

/* ---------- Base de datos ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO('sqlite:' . KRONO_DB, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $p): void {
    $p->exec("CREATE TABLE IF NOT EXISTS users(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL DEFAULT '',
        bio TEXT NOT NULL DEFAULT '',
        pass TEXT NOT NULL,
        avatar TEXT NOT NULL DEFAULT '',
        xp INTEGER NOT NULL DEFAULT 0,
        coins INTEGER NOT NULL DEFAULT 0,
        streak INTEGER NOT NULL DEFAULT 0,
        best_streak INTEGER NOT NULL DEFAULT 0,
        last_day TEXT NOT NULL DEFAULT '',
        created TEXT NOT NULL DEFAULT ''
    )");
    $p->exec("CREATE TABLE IF NOT EXISTS events(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid INTEGER NOT NULL,
        title TEXT NOT NULL,
        descr TEXT NOT NULL DEFAULT '',
        emoji TEXT NOT NULL DEFAULT '📌',
        color TEXT NOT NULL DEFAULT '#FC6C26',
        day TEXT NOT NULL,
        t1 TEXT NOT NULL DEFAULT '09:00',
        t2 TEXT NOT NULL DEFAULT '10:00',
        allday INTEGER NOT NULL DEFAULT 0,
        done INTEGER NOT NULL DEFAULT 0,
        remind INTEGER NOT NULL DEFAULT 10
    )");
    $p->exec("CREATE INDEX IF NOT EXISTS ix_ev ON events(uid,day)");
    $p->exec("CREATE TABLE IF NOT EXISTS slots(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid INTEGER NOT NULL,
        dow INTEGER NOT NULL,
        idx INTEGER NOT NULL,
        subject TEXT NOT NULL DEFAULT '',
        room TEXT NOT NULL DEFAULT '',
        emoji TEXT NOT NULL DEFAULT '📘',
        color TEXT NOT NULL DEFAULT '#FC6C26'
    )");
    $p->exec("CREATE UNIQUE INDEX IF NOT EXISTS ix_slot ON slots(uid,dow,idx)");
    $p->exec("CREATE TABLE IF NOT EXISTS periods(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid INTEGER NOT NULL,
        idx INTEGER NOT NULL,
        label TEXT NOT NULL,
        t1 TEXT NOT NULL,
        t2 TEXT NOT NULL,
        is_break INTEGER NOT NULL DEFAULT 0
    )");
    $p->exec("CREATE TABLE IF NOT EXISTS habits(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid INTEGER NOT NULL,
        title TEXT NOT NULL,
        emoji TEXT NOT NULL DEFAULT '✅',
        color TEXT NOT NULL DEFAULT '#FC6C26',
        target INTEGER NOT NULL DEFAULT 1,
        hour TEXT NOT NULL DEFAULT '',
        pos INTEGER NOT NULL DEFAULT 0,
        archived INTEGER NOT NULL DEFAULT 0
    )");
    $p->exec("CREATE TABLE IF NOT EXISTS logs(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid INTEGER NOT NULL,
        hid INTEGER NOT NULL,
        day TEXT NOT NULL,
        n INTEGER NOT NULL DEFAULT 0
    )");
    $p->exec("CREATE UNIQUE INDEX IF NOT EXISTS ix_log ON logs(hid,day)");
    $p->exec("CREATE TABLE IF NOT EXISTS notes(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid INTEGER NOT NULL,
        title TEXT NOT NULL DEFAULT 'Sin título',
        body TEXT NOT NULL DEFAULT '',
        tag TEXT NOT NULL DEFAULT '',
        cloud TEXT NOT NULL DEFAULT '',
        updated TEXT NOT NULL DEFAULT ''
    )");
    $p->exec("CREATE TABLE IF NOT EXISTS follows(
        a INTEGER NOT NULL, b INTEGER NOT NULL,
        PRIMARY KEY(a,b)
    )");
    $p->exec("CREATE TABLE IF NOT EXISTS focus(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid INTEGER NOT NULL,
        title TEXT NOT NULL DEFAULT 'Enfoque',
        t1 TEXT NOT NULL DEFAULT '16:00',
        t2 TEXT NOT NULL DEFAULT '18:00',
        days TEXT NOT NULL DEFAULT '1,2,3,4,5',
        active INTEGER NOT NULL DEFAULT 1
    )");
    $p->exec("CREATE TABLE IF NOT EXISTS prefs(
        uid INTEGER PRIMARY KEY, j TEXT NOT NULL DEFAULT '{}'
    )");
    $p->exec("CREATE TABLE IF NOT EXISTS cloud(
        uid INTEGER PRIMARY KEY,
        url TEXT NOT NULL DEFAULT '',
        user TEXT NOT NULL DEFAULT '',
        pass TEXT NOT NULL DEFAULT '',
        folder TEXT NOT NULL DEFAULT 'Krono'
    )");
    $p->exec("CREATE TABLE IF NOT EXISTS exams(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid INTEGER NOT NULL,
        subject TEXT NOT NULL,
        title TEXT NOT NULL DEFAULT '',
        day TEXT NOT NULL,
        notes TEXT NOT NULL DEFAULT '',
        emoji TEXT NOT NULL DEFAULT '📚',
        color TEXT NOT NULL DEFAULT '#D8452F'
    )");
    $p->exec("CREATE INDEX IF NOT EXISTS ix_exam ON exams(uid,day)");
    $p->exec("CREATE TABLE IF NOT EXISTS routine(
        uid INTEGER NOT NULL, dow INTEGER NOT NULL, idx INTEGER NOT NULL,
        title TEXT NOT NULL DEFAULT '', emoji TEXT NOT NULL DEFAULT '🕓', color TEXT NOT NULL DEFAULT '#8A5CD6',
        PRIMARY KEY(uid,dow,idx)
    )");
    $p->exec("CREATE TABLE IF NOT EXISTS push_subs(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid INTEGER NOT NULL,
        endpoint TEXT NOT NULL UNIQUE,
        p256dh TEXT NOT NULL,
        auth TEXT NOT NULL,
        created TEXT NOT NULL DEFAULT ''
    )");
    $p->exec("CREATE TABLE IF NOT EXISTS unlocked(
        uid INTEGER NOT NULL, badge TEXT NOT NULL, created TEXT NOT NULL DEFAULT '',
        PRIMARY KEY(uid,badge)
    )");
    // Columnas nuevas en usuarios (sin romper bases de datos ya creadas)
    $cols = [];
    foreach ($p->query("PRAGMA table_info(users)") as $c) $cols[] = $c['name'];
    if (!in_array('google_sub', $cols))  $p->exec("ALTER TABLE users ADD COLUMN google_sub TEXT NOT NULL DEFAULT ''");
    if (!in_array('ai_key', $cols))      $p->exec("ALTER TABLE users ADD COLUMN ai_key TEXT NOT NULL DEFAULT ''");
    if (!in_array('email', $cols))       $p->exec("ALTER TABLE users ADD COLUMN email TEXT NOT NULL DEFAULT ''");
    $hcols = [];
    foreach ($p->query("PRAGMA table_info(habits)") as $c) $hcols[] = $c['name'];
    if (!in_array('days', $hcols)) $p->exec("ALTER TABLE habits ADD COLUMN days TEXT NOT NULL DEFAULT ''"); // '' = todos los días
    $ecols = [];
    foreach ($p->query("PRAGMA table_info(events)") as $c) $ecols[] = $c['name'];
    if (!in_array('grp', $ecols)) $p->exec("ALTER TABLE events ADD COLUMN grp TEXT NOT NULL DEFAULT ''"); // agrupa eventos multi-día
}

define('CRON_SECRET', 'krono-' . substr(hash('sha256', 'krono-cron-salt-v1'), 0, 24));

/* ---------- Utilidades ---------- */
function uid(): int { return (int)($_SESSION['uid'] ?? 0); }
function need_auth(): int { $u = uid(); if (!$u) fail('Inicia sesión para continuar.', 401); return $u; }
function j($d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $m, int $code = 400): void { j(['ok' => false, 'error' => $m], $code); }
function body(): array {
    $raw = file_get_contents('php://input') ?: '';
    $d = json_decode($raw, true);
    return is_array($d) ? $d : $_POST;
}
function s(array $a, string $k, string $d = ''): string { return trim((string)($a[$k] ?? $d)); }
function n(array $a, string $k, int $d = 0): int { return (int)($a[$k] ?? $d); }
function today(): string { return date('Y-m-d'); }

const DEFAULT_PERIODS = [
    ['1ª hora',  '08:15', '09:10', 0],
    ['2ª hora',  '09:10', '10:05', 0],
    ['3ª hora',  '10:05', '11:00', 0],
    ['Recreo',   '11:00', '11:25', 1],
    ['4ª hora',  '11:25', '12:20', 0],
    ['5ª hora',  '12:20', '13:15', 0],
    ['6ª hora',  '13:15', '14:10', 0],
    ['7ª hora',  '14:10', '15:05', 0],
];

function seed_periods(int $u): void {
    $p = db();
    $c = (int)$p->query("SELECT COUNT(*) c FROM periods WHERE uid=$u")->fetch()['c'];
    if ($c > 0) return;
    $st = $p->prepare("INSERT INTO periods(uid,idx,label,t1,t2,is_break) VALUES(?,?,?,?,?,?)");
    foreach (DEFAULT_PERIODS as $i => $r) $st->execute([$u, $i, $r[0], $r[1], $r[2], $r[3]]);
}

/* ---------- Racha, XP y nivel ---------- */
function level_of(int $xp): array {
    $lvl = 1; $need = 100; $rest = $xp;
    while ($rest >= $need) { $rest -= $need; $lvl++; $need = (int)round($need * 1.35); }
    return ['level' => $lvl, 'into' => $rest, 'need' => $need];
}

function award(int $u, int $xp, int $coins = 0): void {
    db()->prepare("UPDATE users SET xp=MAX(0,xp+?), coins=MAX(0,coins+?) WHERE id=?")
        ->execute([$xp, $coins, $u]);
}

function touch_streak(int $u): void {
    $p = db();
    $r = $p->prepare("SELECT streak,best_streak,last_day FROM users WHERE id=?");
    $r->execute([$u]); $row = $r->fetch();
    if (!$row) return;
    $t = today();
    if ($row['last_day'] === $t) return;
    $yest = date('Y-m-d', strtotime('-1 day'));
    $st = ($row['last_day'] === $yest) ? (int)$row['streak'] + 1 : 1;
    $best = max((int)$row['best_streak'], $st);
    $p->prepare("UPDATE users SET streak=?,best_streak=?,last_day=? WHERE id=?")
      ->execute([$st, $best, $t, $u]);
    award($u, 0, 5 + min(20, $st));
}

function me_row(int $u): array {
    $st = db()->prepare("SELECT id,username,name,bio,avatar,xp,coins,streak,best_streak,created FROM users WHERE id=?");
    $st->execute([$u]); $r = $st->fetch() ?: [];
    if ($r) $r += level_of((int)$r['xp']);
    return $r;
}

function stats_of(int $u): array {
    $p = db();
    $h = (int)$p->query("SELECT COUNT(*) c FROM habits WHERE uid=$u AND archived=0")->fetch()['c'];
    $done = (int)$p->query("SELECT COUNT(*) c FROM logs l JOIN habits h ON h.id=l.hid WHERE l.uid=$u AND l.n>0")->fetch()['c'];
    $ev = (int)$p->query("SELECT COUNT(*) c FROM events WHERE uid=$u")->fetch()['c'];
    $nt = (int)$p->query("SELECT COUNT(*) c FROM notes WHERE uid=$u")->fetch()['c'];
    $fo = (int)$p->query("SELECT COUNT(*) c FROM follows WHERE b=$u")->fetch()['c'];
    $fi = (int)$p->query("SELECT COUNT(*) c FROM follows WHERE a=$u")->fetch()['c'];
    $ex = (int)$p->query("SELECT COUNT(*) c FROM exams WHERE uid=$u")->fetch()['c'];
    return ['habits' => $h, 'completed' => $done, 'events' => $ev, 'notes' => $nt,
            'followers' => $fo, 'following' => $fi, 'exams' => $ex];
}

/* =============================================================
   RANGOS (a partir del nivel)
   ============================================================= */
const RANKS = [
    ['Bronce', 1, 24, '🥉'], ['Plata', 25, 49, '🥈'], ['Oro', 50, 99, '🏆'],
    ['Platino', 100, 149, '💠'], ['Diamante', 150, 249, '💎'], ['Esmeralda', 250, 999999, '🟢'],
];
function rank_of(int $level): array {
    foreach (RANKS as $r) {
        [$name, $lo, $hi, $ic] = $r;
        if ($level >= $lo && $level <= $hi) {
            $span = max(1, $hi - $lo + 1);
            $tier = $name === 'Esmeralda' ? '' : ' ' . (intdiv(min($level - $lo, $span - 1), max(1, intdiv($span, 3))) + 1);
            $legend = $level >= 250;
            return ['name' => ($legend ? 'Leyenda' : $name) . ($legend ? '' : $tier), 'icon' => $legend ? '👑' : $ic, 'legend' => $legend];
        }
    }
    return ['name' => 'Bronce 1', 'icon' => '🥉', 'legend' => false];
}

/* =============================================================
   LOGROS (servidor: para avisar por push al conseguirlos)
   ============================================================= */
const ACHIEVEMENTS = [
    'first'    => ['🌱', 'Has completado tu primera tarea.'],
    'streak3'  => ['🔥', 'Llevas 3 días seguidos. ¡No lo sueltes!'],
    'streak7'  => ['⚡', 'Una semana entera de racha.'],
    'streak30' => ['💎', 'Un mes entero de racha. Brutal.'],
    'streak100'=> ['🌟', '100 días de racha. Eres una leyenda.'],
    'notes5'   => ['📝', 'Ya llevas 5 notas escritas.'],
    'notes20'  => ['📚', '20 notas. Eres un archivo andante.'],
    'events10' => ['🗓️', '10 cosas apuntadas en la agenda.'],
    'habits5'  => ['◎', 'Ya gestionas 5 hábitos a la vez.'],
    'check100' => ['💯', '100 hábitos marcados como hechos.'],
    'check500' => ['🏅', '500 hábitos completados. Una máquina.'],
    'social3'  => ['👥', 'Sigues a 3 colegas.'],
    'social10' => ['🎉', 'Sigues a 10 colegas.'],
    'lvl5'     => ['⭐', 'Nivel 5 alcanzado.'],
    'lvl10'    => ['👑', 'Nivel 10: ya eres Plata.'],
    'lvl25'    => ['🥈', 'Nivel 25 conseguido.'],
    'lvl50'    => ['🏆', 'Nivel 50: entras en Oro.'],
    'lvl100'   => ['💠', 'Nivel 100: Platino desbloqueado.'],
    'lvl250'   => ['🟢', 'Nivel 250. Rango Leyenda. Impresionante.'],
    'coins500' => ['🪙', '500 monedas ahorradas.'],
    'coins2000'=> ['🪙', '2000 monedas. Vaya banco.'],
    'exams1'   => ['📚', 'Tu primer examen apuntado con recordatorios.'],
    'cloud1'   => ['☁️', 'Primer archivo subido a tu nube personal.'],
    'focus60'  => ['🎯', 'Una hora entera en modo enfoque.'],
    'perfect'  => ['✨', 'Día perfecto: todos los hábitos completados.'],
];
function achievement_ok(string $k, array $me, array $st): bool {
    switch ($k) {
        case 'first':     return $st['completed'] >= 1;
        case 'streak3':   return $me['streak'] >= 3;
        case 'streak7':   return $me['streak'] >= 7;
        case 'streak30':  return $me['best_streak'] >= 30;
        case 'streak100': return $me['best_streak'] >= 100;
        case 'notes5':    return $st['notes'] >= 5;
        case 'notes20':   return $st['notes'] >= 20;
        case 'events10':  return $st['events'] >= 10;
        case 'habits5':   return $st['habits'] >= 5;
        case 'check100':  return $st['completed'] >= 100;
        case 'check500':  return $st['completed'] >= 500;
        case 'social3':   return $st['following'] >= 3;
        case 'social10':  return $st['following'] >= 10;
        case 'lvl5':      return $me['level'] >= 5;
        case 'lvl10':     return $me['level'] >= 10;
        case 'lvl25':     return $me['level'] >= 25;
        case 'lvl50':     return $me['level'] >= 50;
        case 'lvl100':    return $me['level'] >= 100;
        case 'lvl250':    return $me['level'] >= 250;
        case 'coins500':  return $me['coins'] >= 500;
        case 'coins2000': return $me['coins'] >= 2000;
        case 'exams1':    return $st['exams'] >= 1;
        default: return false;
    }
}

/* =============================================================
   TAREA PROGRAMADA (cron externo cada 1–5 minutos)
   Recorre todos los usuarios y dispara los avisos que tocan.
   ============================================================= */
function run_cron_tick(): int {
    $p = db(); $sent = 0;
    $t = today(); $now = date('H:i'); $nowM = (int)date('H') * 60 + (int)date('i'); $dow = (int)date('N'); // 1=lunes
    $users = $p->query("SELECT id,streak FROM users")->fetchAll();

    foreach ($users as $u) {
        $uid = (int)$u['id'];
        $fired = cron_seen($uid, $t);

        // Hábitos con hora, respetando los días programados
        $hs = $p->prepare("SELECT * FROM habits WHERE uid=? AND archived=0 AND hour<>''"); $hs->execute([$uid]);
        foreach ($hs->fetchAll() as $h) {
            $days = array_filter(explode(',', $h['days']));
            if ($days && !in_array((string)$dow, $days, true)) continue;
            if ($h['hour'] !== $now) continue;
            $lg = $p->prepare("SELECT n FROM logs WHERE hid=? AND day=?"); $lg->execute([$h['id'], $t]);
            if ((int)($lg->fetch()['n'] ?? 0) >= (int)$h['target']) continue;
            $key = 'h' . $h['id'];
            if (isset($fired[$key])) continue;
            push_to_user($uid, $h['emoji'] . ' ' . $h['title'], 'Toca hacerlo. Márcalo cuando lo tengas.', $key);
            cron_mark($uid, $t, $key); $sent++;
        }

        // Eventos de hoy con aviso
        $ev = $p->prepare("SELECT * FROM events WHERE uid=? AND day=? AND allday=0 AND remind>0 AND done=0"); $ev->execute([$uid, $t]);
        foreach ($ev->fetchAll() as $e) {
            [$hh, $mm] = array_map('intval', explode(':', $e['t1']));
            $target = $hh * 60 + $mm - (int)$e['remind'];
            if ($nowM < $target || $nowM > $target + 1) continue;
            $key = 'e' . $e['id'];
            if (isset($fired[$key])) continue;
            push_to_user($uid, $e['emoji'] . ' ' . $e['title'], 'Empieza a las ' . $e['t1'] . '.', $key);
            cron_mark($uid, $t, $key); $sent++;
        }

        // Exámenes: recordatorios crecientes en las dos últimas semanas
        $ex = $p->prepare("SELECT * FROM exams WHERE uid=? AND day>=?"); $ex->execute([$uid, $t]);
        foreach ($ex->fetchAll() as $x) {
            $days_left = (int)((strtotime($x['day']) - strtotime($t)) / 86400);
            if ($days_left > 14 || $now !== '18:00') continue;
            $key = 'x' . $x['id'] . '-' . $days_left;
            if (isset($fired[$key])) continue;
            $msg = $days_left === 0 ? 'Es hoy. Repaso final y a por ello.'
                 : ($days_left <= 3 ? 'Quedan ' . $days_left . ' días. Toca repasar ' . $x['subject'] . ' en serio.'
                 : 'Quedan ' . $days_left . ' días. Buen momento para empezar a estudiar ' . $x['subject'] . '.');
            push_to_user($uid, $x['emoji'] . ' Examen de ' . $x['subject'], $msg, $key);
            cron_mark($uid, $t, $key); $sent++;
        }

        // Racha nocturna
        $st = $p->prepare("SELECT * FROM habits WHERE uid=? AND archived=0"); $st->execute([$uid]);
        $hs2 = $st->fetchAll();
        if ($hs2 && $now === '21:30') {
            $lg = $p->prepare("SELECT hid,n FROM logs WHERE uid=? AND day=?"); $lg->execute([$uid, $t]);
            $done = []; foreach ($lg->fetchAll() as $r) $done[$r['hid']] = $r['n'];
            $falta = 0; foreach ($hs2 as $h) if (($done[$h['id']] ?? 0) < $h['target']) $falta++;
            $key = 'night';
            if ($falta > 0 && !isset($fired[$key])) {
                push_to_user($uid, '🔥 Tu racha de ' . $u['streak'] . ' días', 'Te queda' . ($falta === 1 ? '' : 'n') . ' ' . $falta . ($falta === 1 ? ' hábito' : ' hábitos') . ' por marcar hoy.', $key);
                cron_mark($uid, $t, $key); $sent++;
            }
        }

        // Aviso ocasional de "seguimos aquí" (aprox. una vez cada 4-6 días, a mediodía)
        if ($now === '13:00' && !isset($fired['heartbeat']) && (crc32($uid . $t) % 5 === 0)) {
            push_to_user($uid, '👋 Krono sigue aquí', 'Un recordatorio suave: revisa tu día cuando puedas.', 'heartbeat');
            cron_mark($uid, $t, 'heartbeat'); $sent++;
        }
    }
    return $sent;
}
function cron_seen(int $uid, string $day): array {
    $f = VAPID_DIR . "/fired-$uid-$day.json";
    if (!file_exists($f)) return [];
    return json_decode((string)file_get_contents($f), true) ?: [];
}
function cron_mark(int $uid, string $day, string $key): void {
    if (!is_dir(VAPID_DIR)) @mkdir(VAPID_DIR, 0770, true);
    $f = VAPID_DIR . "/fired-$uid-$day.json";
    $d = cron_seen($uid, $day); $d[$key] = 1;
    file_put_contents($f, json_encode($d));
    // limpia archivos de días anteriores
    foreach (glob(VAPID_DIR . "/fired-$uid-*.json") ?: [] as $old) {
        if (strpos($old, "-$day.json") === false && filemtime($old) < time() - 172800) @unlink($old);
    }
}
define('VAPID_DIR', __DIR__ . '/krono_vapid');

function vapid_keys(): array {
    if (!is_dir(VAPID_DIR)) @mkdir(VAPID_DIR, 0770, true);
    $priv = VAPID_DIR . '/private.pem';
    $pubFile = VAPID_DIR . '/public.txt';
    if (!file_exists($priv)) {
        $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$res) return ['ok' => false];
        openssl_pkey_export($res, $pem);
        file_put_contents($priv, $pem);
        $det = openssl_pkey_get_details($res);
        $x = $det['ec']['x']; $y = $det['ec']['y'];
        $pub = "\x04" . $x . $y; // punto EC sin comprimir
        file_put_contents($pubFile, b64url($pub));
    }
    return ['ok' => true, 'priv' => file_get_contents($priv), 'pub' => trim(file_get_contents($pubFile))];
}
function b64url(string $d): string { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function ub64url(string $d): string {
    $d = strtr($d, '-_', '+/'); $pad = strlen($d) % 4;
    if ($pad) $d .= str_repeat('=', 4 - $pad);
    return base64_decode($d);
}

function vapid_jwt(string $aud, string $subject = 'mailto:krono@example.com'): ?string {
    $vk = vapid_keys(); if (!$vk['ok']) return null;
    $header = b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = b64url(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $subject]));
    $signInput = $header . '.' . $payload;
    $pk = openssl_pkey_get_private($vk['priv']);
    if (!$pk) return null;
    openssl_sign($signInput, $sigDer, $pk, OPENSSL_ALGO_SHA256);
    // ECDSA DER -> raw r||s (64 bytes) que exige el estándar JWS
    $sig = der_to_rs($sigDer);
    return $signInput . '.' . b64url($sig);
}
function der_to_rs(string $der): string {
    $off = 2; $len = strlen($der);
    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        if ($der[$off] !== "\x02") break;
        $off++; $l = ord($der[$off]); $off++;
        $v = substr($der, $off, $l); $off += $l;
        $v = ltrim($v, "\x00");
        $v = str_pad($v, 32, "\x00", STR_PAD_LEFT);
        $parts[] = $v;
    }
    return ($parts[0] ?? str_repeat("\x00", 32)) . ($parts[1] ?? str_repeat("\x00", 32));
}

/** Cifra y envía una notificación push (RFC 8291 aes128gcm). Silencioso ante fallos individuales. */
function webpush_send(array $sub, array $payload): bool {
    $vk = vapid_keys(); if (!$vk['ok']) return false;
    $endpoint = $sub['endpoint'];
    $userPub = ub64url($sub['p256dh']);
    $userAuth = ub64url($sub['auth']);
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

    // Par de claves efímero para este mensaje
    $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$eph) return false;
    $det = openssl_pkey_get_details($eph);
    $ephPub = "\x04" . $det['ec']['x'] . $det['ec']['y'];

    // ECDH: secreto compartido con la clave pública del navegador
    $shared = ecdh_shared($eph, $userPub);
    if ($shared === null) return false;

    $salt = random_bytes(16);
    $authInfo = "WebPush: info\x00" . $userPub . $ephPub;
    $prk = hash_hkdf('sha256', $shared, 32, $authInfo, $userAuth);

    $cekInfo = "Content-Encoding: aes128gcm\x00";
    $cek = hash_hkdf('sha256', $prk, 16, $cekInfo, $salt);
    $nonceInfo = "Content-Encoding: nonce\x00";
    $nonce = hash_hkdf('sha256', $prk, 12, $nonceInfo, $salt);

    $padded = $body . "\x02"; // registro delimitador, sin relleno extra
    $cipher = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) return false;
    $cipher .= $tag;

    $header = $salt . pack('N', 4096) . chr(strlen($ephPub)) . $ephPub;
    $record = $header . $cipher;

    $aud = implode('/', array_slice(explode('/', $endpoint), 0, 3));
    $jwt = vapid_jwt($aud);
    if (!$jwt) return false;

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $record, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm',
            'TTL: 86400', 'Authorization: vapid t=' . $jwt . ', k=' . $vk['pub'],
        ],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 404 || $code === 410) { // suscripción caducada
        db()->prepare("DELETE FROM push_subs WHERE endpoint=?")->execute([$endpoint]);
    }
    return $code >= 200 && $code < 300;
}
function ecdh_shared($privKeyRes, string $rawPubPoint): ?string {
    // Reconstruye la clave pública ajena como recurso OpenSSL a partir del punto sin comprimir
    $x = substr($rawPubPoint, 1, 32); $y = substr($rawPubPoint, 33, 32);
    $der = ec_pub_to_der($x, $y);
    $pub = openssl_pkey_get_public($der);
    if (!$pub) return null;
    // openssl_pkey_derive existe desde PHP 8.1; si no, usamos un cálculo manual sería excesivo aquí
    if (function_exists('openssl_pkey_derive')) {
        $shared = openssl_pkey_derive($pub, $privKeyRes, 32);
        return $shared === false ? null : $shared;
    }
    return null;
}
function ec_pub_to_der(string $x, string $y): string {
    // SubjectPublicKeyInfo para una clave EC P-256 en formato DER, a partir del punto (x,y)
    $pointDer = "\x04" . $x . $y;
    $alg = "\x30\x13" . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    $bit = "\x03" . chr(strlen($pointDer) + 1) . "\x00" . $pointDer;
    $seq = "\x30" . chr(strlen($alg) + strlen($bit)) . $alg . $bit;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($seq), 64) . "-----END PUBLIC KEY-----\n";
}
function push_to_user(int $uid, string $title, string $body, string $tag = 'krono'): void {
    $st = db()->prepare("SELECT * FROM push_subs WHERE uid=?"); $st->execute([$uid]);
    foreach ($st->fetchAll() as $s) {
        webpush_send($s, ['title' => $title, 'body' => $body, 'tag' => $tag, 'icon' => '?r=icon&s=192']);
    }
}

/* =============================================================
   GOOGLE SIGN-IN — verificación del id_token sin librerías
   ============================================================= */
define('GOOGLE_CLIENT_ID', ''); // ← pon aquí tu Client ID de Google Cloud Console

function google_verify(string $idToken): ?array {
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) return null;
    [$h, $pld, $sig] = $parts;
    $header = json_decode(ub64url($h), true);
    $payload = json_decode(ub64url($pld), true);
    if (!$header || !$payload) return null;
    if (($payload['aud'] ?? '') !== GOOGLE_CLIENT_ID || GOOGLE_CLIENT_ID === '') return null;
    if (($payload['iss'] ?? '') !== 'https://accounts.google.com' && ($payload['iss'] ?? '') !== 'accounts.google.com') return null;
    if (($payload['exp'] ?? 0) < time()) return null;

    $jwks = @file_get_contents('https://www.googleapis.com/oauth2/v3/certs');
    if (!$jwks) return null;
    $keys = json_decode($jwks, true)['keys'] ?? [];
    $key = null;
    foreach ($keys as $k) if (($k['kid'] ?? '') === ($header['kid'] ?? '')) { $key = $k; break; }
    if (!$key) return null;

    $der = rsa_jwk_to_der($key['n'], $key['e']);
    $pub = openssl_pkey_get_public($der);
    if (!$pub) return null;
    $ok = openssl_verify($h . '.' . $pld, ub64url($sig), $pub, OPENSSL_ALGO_SHA256);
    return $ok === 1 ? $payload : null;
}
function rsa_jwk_to_der(string $n, string $e): string {
    $mod = ub64url($n); $exp = ub64url($e);
    $modEnc = "\x02" . asn1_len(strlen($mod) + 1) . "\x00" . $mod;
    $expEnc = "\x02" . asn1_len(strlen($exp)) . $exp;
    $seq = "\x30" . asn1_len(strlen($modEnc) + strlen($expEnc)) . $modEnc . $expEnc;
    $alg = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $bit = "\x03" . asn1_len(strlen($seq) + 1) . "\x00" . $seq;
    $outer = "\x30" . asn1_len(strlen($alg) + strlen($bit)) . $alg . $bit;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($outer), 64) . "-----END PUBLIC KEY-----\n";
}
function asn1_len(int $n): string {
    if ($n < 128) return chr($n);
    $b = ltrim(pack('N', $n), "\x00");
    return chr(0x80 | strlen($b)) . $b;
}

/* =============================================================
   RUTAS ESPECIALES: manifest y service worker
   ============================================================= */
$route = $_GET['r'] ?? '';
$self  = basename(__FILE__);

if ($route === 'manifest') {
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode([
        'name' => 'Krono', 'short_name' => 'Krono',
        'description' => 'Tu tiempo, organizado.',
        'start_url' => './' . $self, 'scope' => './',
        'display' => 'standalone', 'orientation' => 'portrait',
        'background_color' => '#FFF4D6', 'theme_color' => '#FC6C26',
        'icons' => [
            ['src' => './' . $self . '?r=icon&s=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ['src' => './' . $self . '?r=icon&s=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($route === 'icon') {
    $size = max(64, min(1024, (int)($_GET['s'] ?? 512)));
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=604800');
    echo krono_icon_png($size);
    exit;
}

/* Icono dibujado a mano en PNG puro: no necesita la extensión GD */
function krono_icon_png(int $n): string {
    $bg = [0xFC, 0x6C, 0x26];   // naranja
    $fg = [0xFF, 0xF4, 0xD6];   // vainilla
    $c = ($n - 1) / 2;
    $R = $n * 0.30;             // radio de la esfera
    $w = $n * 0.055;            // grosor del trazo
    $h1 = $R * 0.58;            // aguja larga (arriba)
    $h2 = $R * 0.46;            // aguja corta (derecha)

    // Distancia de un punto a un segmento, para dibujar las agujas
    $seg = function ($px, $py, $ax, $ay, $bx, $by) {
        $dx = $bx - $ax; $dy = $by - $ay;
        $len = $dx * $dx + $dy * $dy;
        $t = $len > 0 ? max(0, min(1, (($px - $ax) * $dx + ($py - $ay) * $dy) / $len)) : 0;
        return hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy));
    };

    $raw = '';
    for ($y = 0; $y < $n; $y++) {
        $raw .= "\x00";                       // filtro de fila: ninguno
        for ($x = 0; $x < $n; $x++) {
            $d  = abs(hypot($x - $c, $y - $c) - $R);          // borde del círculo
            $d1 = $seg($x, $y, $c, $c, $c, $c - $h1);          // aguja vertical
            $d2 = $seg($x, $y, $c, $c, $c + $h2, $c);          // aguja horizontal
            $m  = min($d, $d1, $d2);
            $a  = 1 - max(0, min(1, ($m - $w / 2) + 0.5));     // borde suavizado
            $raw .= chr((int)round($bg[0] + ($fg[0] - $bg[0]) * $a))
                  . chr((int)round($bg[1] + ($fg[1] - $bg[1]) * $a))
                  . chr((int)round($bg[2] + ($fg[2] - $bg[2]) * $a));
        }
    }

    $chunk = function (string $type, string $data) {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NN', $n, $n) . "\x08\x02\x00\x00\x00")
        . $chunk('IDAT', gzcompress($raw, 6))
        . $chunk('IEND', '');
}

if ($route === 'sw') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /');
    ?>
const CACHE = 'krono-v1';
self.addEventListener('install', e => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('fetch', e => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.searchParams.has('api')) return;
  e.respondWith(
    fetch(e.request).then(res => {
      const copy = res.clone();
      caches.open(CACHE).then(c => c.put(e.request, copy)).catch(()=>{});
      return res;
    }).catch(() => caches.match(e.request))
  );
});

// Notificaciones programadas desde la app
self.addEventListener('message', e => {
  const d = e.data || {};
  if (d.type === 'notify') {
    self.registration.showNotification(d.title || 'Krono', {
      body: d.body || '',
      icon: d.icon, badge: d.icon, tag: d.tag || 'krono',
      data: { url: d.url || './' },
      vibrate: d.silent ? undefined : [40, 30, 40],
      silent: !!d.silent
    });
  }
});

self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (_) { d = { title: 'Krono', body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'Krono', {
    body: d.body || '', icon: d.icon, badge: d.icon, data: { url: d.url || './' }
  }));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || './';
  e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
    for (const c of list) if ('focus' in c) return c.focus();
    return clients.openWindow(url);
  }));
});
    <?php
    exit;
}

/* =============================================================
   AMPLIACIÓN KRONO 1.2.0 (fusionada en este mismo archivo)
   ------------------------------------------------------------- */
define('K12_VERSION', '1.2.0');

/* Novedades que se le enseñan al usuario la primera vez que entra */
const K12_CHANGELOG = [
    ['🪙', 'Monedas y XP en pantalla', 'Ahora ves cuánto ganas: las monedas salen flotando y la XP sube en una barra arriba.'],
    ['🎉', 'Subidas de nivel', 'Cuando pasas de nivel te lo celebra en condiciones.'],
    ['📚', 'Exámenes', 'Un apartado propio para apuntarlos, con cuenta atrás y avisos.'],
    ['📒', 'Deberes', 'Como la agenda, pero para las tareas que te mandan en clase.'],
    ['🌆', 'Horario de tarde', 'Un segundo horario de 15:30 a 22:00, aparte del del instituto.'],
    ['🔗', 'Compartir y copiar horarios', 'Enseña el tuyo en tu perfil y cópiate el de tus colegas de un toque.'],
    ['🔒', 'Perfil público o privado', 'Tú decides qué se ve de ti.'],
    ['💬', 'Mensajes', 'Chatea con quien te sigue y sigues de vuelta.'],
    ['🏆', 'Ranking semanal', 'Cada semana se reparten premios entre los tres primeros.'],
    ['🔔', 'Campana de avisos', 'Seguidores nuevos y mensajes sin leer, nada más entrar.'],
    ['📷', 'Fotos en notas y en la nube', 'Guarda las fotos de los apuntes donde quieras.'],
    ['📲', 'Avisos con la app cerrada', 'Notificaciones reales, aunque no tengas Krono abierta.'],
];

/* -------------------------------------------------------------
   Tablas nuevas. Todo lo antiguo se queda como estaba.
   ------------------------------------------------------------- */
function k12_migrate(): void {
    $p = db();
    $p->exec("CREATE TABLE IF NOT EXISTS homework(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid INTEGER NOT NULL,
        subject TEXT NOT NULL DEFAULT '',
        title TEXT NOT NULL DEFAULT '',
        descr TEXT NOT NULL DEFAULT '',
        due TEXT NOT NULL,
        done INTEGER NOT NULL DEFAULT 0,
        emoji TEXT NOT NULL DEFAULT '📒',
        color TEXT NOT NULL DEFAULT '#3B6FD4',
        created TEXT NOT NULL DEFAULT ''
    )");
    $p->exec("CREATE INDEX IF NOT EXISTS ix_hw ON homework(uid,due)");

    /* Horario de tarde: tablas propias para no tocar las de la mañana */
    $p->exec("CREATE TABLE IF NOT EXISTS periods_pm(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid INTEGER NOT NULL, idx INTEGER NOT NULL,
        label TEXT NOT NULL DEFAULT '', t1 TEXT NOT NULL, t2 TEXT NOT NULL
    )");
    $p->exec("CREATE TABLE IF NOT EXISTS slots_pm(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid INTEGER NOT NULL, dow INTEGER NOT NULL, idx INTEGER NOT NULL,
        subject TEXT NOT NULL DEFAULT '', room TEXT NOT NULL DEFAULT '',
        emoji TEXT NOT NULL DEFAULT '🕓', color TEXT NOT NULL DEFAULT '#8A5CD6'
    )");
    $p->exec("CREATE UNIQUE INDEX IF NOT EXISTS ix_slotpm ON slots_pm(uid,dow,idx)");

    /* Mensajes */
    $p->exec("CREATE TABLE IF NOT EXISTS msgs(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        a INTEGER NOT NULL, b INTEGER NOT NULL,
        body TEXT NOT NULL DEFAULT '',
        created TEXT NOT NULL DEFAULT '',
        seen INTEGER NOT NULL DEFAULT 0
    )");
    $p->exec("CREATE INDEX IF NOT EXISTS ix_msg ON msgs(a,b,id)");

    /* Seguidores ya vistos, para saber cuáles son nuevos */
    $p->exec("CREATE TABLE IF NOT EXISTS seen_followers(
        uid INTEGER NOT NULL, fid INTEGER NOT NULL, PRIMARY KEY(uid,fid)
    )");

    /* Semana: base al empezar, histórico al cerrarla y premios repartidos */
    $p->exec("CREATE TABLE IF NOT EXISTS week_base(
        uid INTEGER NOT NULL, week TEXT NOT NULL,
        xp0 INTEGER NOT NULL DEFAULT 0, coins0 INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY(uid,week)
    )");
    $p->exec("CREATE TABLE IF NOT EXISTS week_hist(
        uid INTEGER NOT NULL, week TEXT NOT NULL,
        xp INTEGER NOT NULL DEFAULT 0, coins INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY(uid,week)
    )");
    $p->exec("CREATE TABLE IF NOT EXISTS week_prizes(
        uid INTEGER NOT NULL, week TEXT NOT NULL, pos INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY(uid,week)
    )");

    /* Columnas nuevas, sin romper lo que ya hay */
    $cols = [];
    foreach ($p->query("PRAGMA table_info(users)") as $c) $cols[] = $c['name'];
    if (!in_array('privado', $cols)) $p->exec("ALTER TABLE users ADD COLUMN privado INTEGER NOT NULL DEFAULT 0");
    if (!in_array('ver', $cols))     $p->exec("ALTER TABLE users ADD COLUMN ver TEXT NOT NULL DEFAULT ''");
    $ncols = [];
    foreach ($p->query("PRAGMA table_info(notes)") as $c) $ncols[] = $c['name'];
    if (!in_array('imgs', $ncols))   $p->exec("ALTER TABLE notes ADD COLUMN imgs TEXT NOT NULL DEFAULT ''");
}

/* Tramos de tarde por defecto */
const K12_PM = [
    ['Tarde 1', '15:30', '16:30'], ['Tarde 2', '16:30', '17:30'],
    ['Merienda', '17:30', '18:00'], ['Tarde 3', '18:00', '19:00'],
    ['Tarde 4', '19:00', '20:00'], ['Cena',     '20:00', '21:00'],
    ['Noche',   '21:00', '22:00'],
];
function k12_seed_pm(int $u): void {
    $p = db();
    $c = (int)$p->query("SELECT COUNT(*) c FROM periods_pm WHERE uid=$u")->fetch()['c'];
    if ($c > 0) return;
    $st = $p->prepare("INSERT INTO periods_pm(uid,idx,label,t1,t2) VALUES(?,?,?,?,?)");
    foreach (K12_PM as $i => $r) $st->execute([$u, $i, $r[0], $r[1], $r[2]]);
}

/* -------------------------------------------------------------
   Semana
   ------------------------------------------------------------- */
function k12_week(?int $offset = 0): string {
    $t = strtotime(($offset ? ($offset . ' week') : 'now'));
    return date('o-\WW', $t);
}
/** Abre la semana del usuario y, si ha cambiado, guarda lo que hizo la anterior. */
function k12_week_touch(int $u): void {
    $p = db(); $wk = k12_week();
    $q = $p->prepare("SELECT 1 FROM week_base WHERE uid=? AND week=?"); $q->execute([$u, $wk]);
    if ($q->fetch()) return;

    $me = $p->prepare("SELECT xp,coins FROM users WHERE id=?"); $me->execute([$u]);
    $r = $me->fetch(); if (!$r) return;

    // cerrar todas las semanas abiertas anteriores
    $ol = $p->prepare("SELECT week,xp0,coins0 FROM week_base WHERE uid=? AND week<?");
    $ol->execute([$u, $wk]);
    foreach ($ol->fetchAll() as $o) {
        $p->prepare("INSERT OR REPLACE INTO week_hist(uid,week,xp,coins) VALUES(?,?,?,?)")
          ->execute([$u, $o['week'], max(0, (int)$r['xp'] - (int)$o['xp0']), max(0, (int)$r['coins'] - (int)$o['coins0'])]);
    }
    $p->prepare("DELETE FROM week_base WHERE uid=? AND week<?")->execute([$u, $wk]);
    $p->prepare("INSERT OR REPLACE INTO week_base(uid,week,xp0,coins0) VALUES(?,?,?,?)")
      ->execute([$u, $wk, (int)$r['xp'], (int)$r['coins']]);
}
function k12_week_score(int $u): array {
    $p = db(); $wk = k12_week();
    $b = $p->prepare("SELECT xp0,coins0 FROM week_base WHERE uid=? AND week=?"); $b->execute([$u, $wk]);
    $base = $b->fetch();
    $m = $p->prepare("SELECT xp,coins FROM users WHERE id=?"); $m->execute([$u]);
    $now = $m->fetch() ?: ['xp' => 0, 'coins' => 0];
    if (!$base) return ['xp' => 0, 'coins' => 0];
    return ['xp' => max(0, (int)$now['xp'] - (int)$base['xp0']),
            'coins' => max(0, (int)$now['coins'] - (int)$base['coins0'])];
}

/* Círculo social: yo y a quien sigo */
function k12_circle(int $u): array {
    $ids = [$u];
    foreach (db()->query("SELECT b FROM follows WHERE a=" . $u) as $r) $ids[] = (int)$r['b'];
    return array_values(array_unique($ids));
}
function k12_mutual(int $a, int $b): bool {
    $p = db();
    $q = $p->prepare("SELECT (SELECT 1 FROM follows WHERE a=? AND b=?) x, (SELECT 1 FROM follows WHERE a=? AND b=?) y");
    $q->execute([$a, $b, $b, $a]); $r = $q->fetch();
    return !empty($r['x']) && !empty($r['y']);
}

/* Avisos pendientes: seguidores nuevos + mensajes sin leer */
function k12_badges(int $u): array {
    $p = db();
    $nf = $p->prepare("SELECT u.id,u.username,u.name,u.avatar FROM follows f
                       JOIN users u ON u.id=f.a
                       WHERE f.b=? AND f.a NOT IN (SELECT fid FROM seen_followers WHERE uid=?)
                       ORDER BY u.id DESC LIMIT 20");
    $nf->execute([$u, $u]);
    $new = $nf->fetchAll();
    $um = $p->prepare("SELECT COUNT(*) c FROM msgs WHERE b=? AND seen=0"); $um->execute([$u]);
    $msgs = (int)$um->fetch()['c'];
    return ['followers' => $new, 'msgs' => $msgs, 'total' => count($new) + $msgs];
}

/* -------------------------------------------------------------
   Rutas propias
   ------------------------------------------------------------- */
k12_migrate();

if (($_GET['r'] ?? '') === 'addon12') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache');
    echo k12_js();
    exit;
}

if (isset($_GET['api'])) { k12_api((string)$_GET['api']); }   // si no es suya, sigue el flujo normal

function k12_api(string $a): void {
    $k12 = ['k12_boot','k12_seen','k12_privacy','hw_list','hw_save','hw_done','hw_del',
            'pm_get','pm_slot','pm_periods','sched_share','sched_copy',
            'chats','chat_get','chat_send','rank_week','note_imgs','cloud_test','cloud_photo',
            'pub_profile','k12_ping'];
    if (!in_array($a, $k12, true)) return;

    $in = body(); $p = db();
    try {
    switch ($a) {

    /* ---- Arranque: versión, avisos y datos de cabecera ---- */
    case 'k12_boot': {
        $u = uid();
        if (!$u) j(['ok' => true, 'version' => K12_VERSION, 'changelog' => K12_CHANGELOG, 'auth' => false]);
        k12_week_touch($u); k12_seed_pm($u);
        $me = $p->prepare("SELECT privado,ver FROM users WHERE id=?"); $me->execute([$u]);
        $r = $me->fetch() ?: ['privado' => 0, 'ver' => ''];
        $nuevo = ($r['ver'] !== K12_VERSION);
        j(['ok' => true, 'auth' => true, 'version' => K12_VERSION,
           'changelog' => K12_CHANGELOG, 'nuevo' => $nuevo,
           'privado' => (int)$r['privado'], 'badges' => k12_badges($u),
           'cron' => ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
                     . '://' . ($_SERVER['HTTP_HOST'] ?? 'tu-dominio')
                     . ($_SERVER['SCRIPT_NAME'] ?? '/krono.php')
                     . '?api=cron_tick&secret=' . CRON_SECRET]);
    }

    /* Marcar las novedades como leídas */
    case 'k12_seen': {
        $u = need_auth();
        $p->prepare("UPDATE users SET ver=? WHERE id=?")->execute([K12_VERSION, $u]);
        j(['ok' => true]);
    }

    case 'k12_ping': {                       // refresca la campana
        $u = need_auth();
        j(['ok' => true, 'badges' => k12_badges($u)]);
    }

    /* Marcar seguidores nuevos como vistos */
    case 'k12_privacy': {
        $u = need_auth();
        if (isset($in['privado'])) {
            $p->prepare("UPDATE users SET privado=? WHERE id=?")->execute([n($in, 'privado') ? 1 : 0, $u]);
        }
        if (n($in, 'seen_followers')) {
            $p->prepare("INSERT OR IGNORE INTO seen_followers(uid,fid) SELECT ?, a FROM follows WHERE b=?")
              ->execute([$u, $u]);
        }
        $me = $p->prepare("SELECT privado FROM users WHERE id=?"); $me->execute([$u]);
        j(['ok' => true, 'privado' => (int)($me->fetch()['privado'] ?? 0), 'badges' => k12_badges($u)]);
    }

    /* ---- Deberes ---- */
    case 'hw_list': {
        $u = need_auth();
        $st = $p->prepare("SELECT * FROM homework WHERE uid=? ORDER BY done, due, id");
        $st->execute([$u]);
        j(['ok' => true, 'hw' => $st->fetchAll()]);
    }
    case 'hw_save': {
        $u = need_auth(); $id = n($in, 'id');
        $t = mb_substr(s($in, 'title'), 0, 80);
        if ($t === '') fail('Escribe qué hay que hacer.');
        $f = [mb_substr(s($in, 'subject'), 0, 40), $t, mb_substr(s($in, 'descr'), 0, 1000),
              s($in, 'due', today()), s($in, 'emoji', '📒'), s($in, 'color', '#3B6FD4')];
        if ($id) {
            $f[] = $id; $f[] = $u;
            $p->prepare("UPDATE homework SET subject=?,title=?,descr=?,due=?,emoji=?,color=? WHERE id=? AND uid=?")->execute($f);
        } else {
            array_unshift($f, $u); $f[] = date('c');
            $p->prepare("INSERT INTO homework(uid,subject,title,descr,due,emoji,color,created) VALUES(?,?,?,?,?,?,?,?)")->execute($f);
            $id = (int)$p->lastInsertId();
            award($u, 4, 2);
        }
        j(['ok' => true, 'id' => $id, 'me' => me_row($u)]);
    }
    case 'hw_done': {
        $u = need_auth(); $id = n($in, 'id'); $v = n($in, 'done');
        $p->prepare("UPDATE homework SET done=? WHERE id=? AND uid=?")->execute([$v, $id, $u]);
        award($u, $v ? 12 : -12, $v ? 5 : -5);
        if ($v) touch_streak($u);
        j(['ok' => true, 'me' => me_row($u)]);
    }
    case 'hw_del': {
        $u = need_auth();
        $p->prepare("DELETE FROM homework WHERE id=? AND uid=?")->execute([n($in, 'id'), $u]);
        j(['ok' => true]);
    }

    /* ---- Horario de tarde ---- */
    case 'pm_get': {
        $u = need_auth(); k12_seed_pm($u);
        $pe = $p->prepare("SELECT * FROM periods_pm WHERE uid=? ORDER BY idx"); $pe->execute([$u]);
        $sl = $p->prepare("SELECT * FROM slots_pm WHERE uid=?"); $sl->execute([$u]);
        j(['ok' => true, 'periods' => $pe->fetchAll(), 'slots' => $sl->fetchAll()]);
    }
    case 'pm_slot': {
        $u = need_auth(); $dow = n($in, 'dow'); $idx = n($in, 'idx');
        $sub = mb_substr(s($in, 'subject'), 0, 40);
        if ($sub === '') {
            $p->prepare("DELETE FROM slots_pm WHERE uid=? AND dow=? AND idx=?")->execute([$u, $dow, $idx]);
            j(['ok' => true]);
        }
        $p->prepare("INSERT INTO slots_pm(uid,dow,idx,subject,room,emoji,color) VALUES(?,?,?,?,?,?,?)
                     ON CONFLICT(uid,dow,idx) DO UPDATE SET subject=excluded.subject,room=excluded.room,emoji=excluded.emoji,color=excluded.color")
          ->execute([$u, $dow, $idx, $sub, mb_substr(s($in, 'room'), 0, 20), s($in, 'emoji', '🕓'), s($in, 'color', '#8A5CD6')]);
        j(['ok' => true]);
    }
    case 'pm_periods': {
        $u = need_auth();
        $rows = $in['periods'] ?? [];
        if (!is_array($rows) || !$rows) fail('No hay tramos que guardar.');
        $p->prepare("DELETE FROM periods_pm WHERE uid=?")->execute([$u]);
        $st = $p->prepare("INSERT INTO periods_pm(uid,idx,label,t1,t2) VALUES(?,?,?,?,?)");
        foreach (array_values($rows) as $i => $r) {
            $st->execute([$u, $i, mb_substr(s($r, 'label', 'Tramo'), 0, 20), s($r, 't1', '16:00'), s($r, 't2', '17:00')]);
        }
        j(['ok' => true]);
    }

    /* ---- Ver y copiar el horario de otra persona ---- */
    case 'sched_share': {
        $u = need_auth(); $id = n($in, 'id') ?: $u; $kind = n($in, 'kind');
        if ($id !== $u) {
            $q = $p->prepare("SELECT privado FROM users WHERE id=?"); $q->execute([$id]);
            $row = $q->fetch();
            if (!$row) fail('Ese perfil no existe.', 404);
            if ((int)$row['privado'] === 1) fail('Ese perfil es privado.', 403);
        }
        if ($kind) {
            k12_seed_pm($id);
            $pe = $p->prepare("SELECT * FROM periods_pm WHERE uid=? ORDER BY idx"); $pe->execute([$id]);
            $sl = $p->prepare("SELECT * FROM slots_pm WHERE uid=?"); $sl->execute([$id]);
        } else {
            $pe = $p->prepare("SELECT * FROM periods WHERE uid=? ORDER BY idx"); $pe->execute([$id]);
            $sl = $p->prepare("SELECT * FROM slots WHERE uid=?"); $sl->execute([$id]);
        }
        j(['ok' => true, 'periods' => $pe->fetchAll(), 'slots' => $sl->fetchAll(), 'kind' => $kind]);
    }
    case 'sched_copy': {
        $u = need_auth(); $id = n($in, 'id'); $kind = n($in, 'kind');
        if ($id === $u) fail('Ese horario ya es tuyo.');
        $q = $p->prepare("SELECT privado FROM users WHERE id=?"); $q->execute([$id]);
        $row = $q->fetch();
        if (!$row) fail('Ese perfil no existe.', 404);
        if ((int)$row['privado'] === 1) fail('Ese perfil es privado, no puedes copiar su horario.', 403);

        if ($kind) {
            $pe = $p->prepare("SELECT * FROM periods_pm WHERE uid=? ORDER BY idx"); $pe->execute([$id]);
            $sl = $p->prepare("SELECT * FROM slots_pm WHERE uid=?"); $sl->execute([$id]);
            $p->prepare("DELETE FROM periods_pm WHERE uid=?")->execute([$u]);
            $p->prepare("DELETE FROM slots_pm WHERE uid=?")->execute([$u]);
            $ip = $p->prepare("INSERT INTO periods_pm(uid,idx,label,t1,t2) VALUES(?,?,?,?,?)");
            foreach ($pe->fetchAll() as $r) $ip->execute([$u, $r['idx'], $r['label'], $r['t1'], $r['t2']]);
            $is = $p->prepare("INSERT INTO slots_pm(uid,dow,idx,subject,room,emoji,color) VALUES(?,?,?,?,?,?,?)");
            foreach ($sl->fetchAll() as $r) $is->execute([$u, $r['dow'], $r['idx'], $r['subject'], $r['room'], $r['emoji'], $r['color']]);
        } else {
            $pe = $p->prepare("SELECT * FROM periods WHERE uid=? ORDER BY idx"); $pe->execute([$id]);
            $sl = $p->prepare("SELECT * FROM slots WHERE uid=?"); $sl->execute([$id]);
            $p->prepare("DELETE FROM periods WHERE uid=?")->execute([$u]);
            $p->prepare("DELETE FROM slots WHERE uid=?")->execute([$u]);
            $ip = $p->prepare("INSERT INTO periods(uid,idx,label,t1,t2,is_break) VALUES(?,?,?,?,?,?)");
            foreach ($pe->fetchAll() as $r) $ip->execute([$u, $r['idx'], $r['label'], $r['t1'], $r['t2'], $r['is_break']]);
            $is = $p->prepare("INSERT INTO slots(uid,dow,idx,subject,room,emoji,color) VALUES(?,?,?,?,?,?,?)");
            foreach ($sl->fetchAll() as $r) $is->execute([$u, $r['dow'], $r['idx'], $r['subject'], $r['room'], $r['emoji'], $r['color']]);
        }
        award($u, 3, 1);
        j(['ok' => true]);
    }

    /* ---- Perfil público de otra persona ---- */
    case 'pub_profile': {
        $u = need_auth(); $id = n($in, 'id');
        $q = $p->prepare("SELECT id,username,name,bio,avatar,xp,coins,streak,best_streak,privado FROM users WHERE id=?");
        $q->execute([$id]); $w = $q->fetch();
        if (!$w) fail('Ese perfil no existe.', 404);
        $priv = (int)$w['privado'] === 1 && $id !== $u;
        $out = ['id' => (int)$w['id'], 'username' => $w['username'], 'name' => $w['name'],
                'bio' => $w['bio'], 'avatar' => $w['avatar'], 'privado' => $priv ? 1 : 0];
        $out += level_of((int)$w['xp']);
        $out['xp'] = (int)$w['xp']; $out['coins'] = (int)$w['coins'];
        $out['streak'] = (int)$w['streak']; $out['best_streak'] = (int)$w['best_streak'];
        $fo = $p->prepare("SELECT COUNT(*) c FROM follows WHERE b=?"); $fo->execute([$id]);
        $fi = $p->prepare("SELECT COUNT(*) c FROM follows WHERE a=?"); $fi->execute([$id]);
        $out['followers'] = (int)$fo->fetch()['c'];
        $out['following'] = (int)$fi->fetch()['c'];
        $out['mutual'] = k12_mutual($u, $id) ? 1 : 0;

        if (!$priv) {
            $h = $p->prepare("SELECT title,emoji,color,target,hour FROM habits WHERE uid=? AND archived=0 ORDER BY pos,id LIMIT 40");
            $h->execute([$id]); $out['habits'] = $h->fetchAll();
            $x = $p->prepare("SELECT subject,title,day,emoji,color FROM exams WHERE uid=? AND day>=? ORDER BY day LIMIT 30");
            $x->execute([$id, today()]); $out['exams'] = $x->fetchAll();
            $d = $p->prepare("SELECT subject,title,due,done FROM homework WHERE uid=? ORDER BY done,due LIMIT 30");
            $d->execute([$id]); $out['hw'] = $d->fetchAll();
        }
        j(['ok' => true, 'user' => $out]);
    }

    /* ---- Mensajes ---- */
    case 'chats': {
        $u = need_auth();
        $st = $p->prepare("SELECT u.id,u.username,u.name,u.avatar FROM follows f
                           JOIN users u ON u.id=f.b
                           WHERE f.a=? AND EXISTS(SELECT 1 FROM follows g WHERE g.a=u.id AND g.b=?)");
        $st->execute([$u, $u]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $m = $p->prepare("SELECT body,created,a FROM msgs WHERE (a=? AND b=?) OR (a=? AND b=?) ORDER BY id DESC LIMIT 1");
            $m->execute([$u, $r['id'], $r['id'], $u]);
            $last = $m->fetch();
            $r['last'] = $last ? mb_substr($last['body'], 0, 60) : '';
            $r['mine'] = $last ? ((int)$last['a'] === $u ? 1 : 0) : 0;
            $c = $p->prepare("SELECT COUNT(*) c FROM msgs WHERE a=? AND b=? AND seen=0");
            $c->execute([$r['id'], $u]);
            $r['unread'] = (int)$c->fetch()['c'];
        }
        usort($rows, fn($x, $y) => ($y['unread'] <=> $x['unread']));
        j(['ok' => true, 'chats' => $rows]);
    }
    case 'chat_get': {
        $u = need_auth(); $id = n($in, 'id');
        if (!k12_mutual($u, $id)) fail('Solo puedes escribirte con quien te sigue y sigues de vuelta.');
        $st = $p->prepare("SELECT * FROM msgs WHERE (a=? AND b=?) OR (a=? AND b=?) ORDER BY id DESC LIMIT 200");
        $st->execute([$u, $id, $id, $u]);
        $rows = array_reverse($st->fetchAll());
        $p->prepare("UPDATE msgs SET seen=1 WHERE a=? AND b=?")->execute([$id, $u]);
        $q = $p->prepare("SELECT id,username,name,avatar FROM users WHERE id=?"); $q->execute([$id]);
        j(['ok' => true, 'msgs' => $rows, 'who' => $q->fetch(), 'meid' => $u]);
    }
    case 'chat_send': {
        $u = need_auth(); $id = n($in, 'id');
        $b = mb_substr(trim((string)($in['body'] ?? '')), 0, 1000);
        if ($b === '') fail('El mensaje está vacío.');
        if (!k12_mutual($u, $id)) fail('Solo puedes escribirte con quien te sigue y sigues de vuelta.');
        $p->prepare("INSERT INTO msgs(a,b,body,created) VALUES(?,?,?,?)")->execute([$u, $id, $b, date('c')]);
        $me = me_row($u);
        if (function_exists('push_to_user')) {
            push_to_user($id, '💬 ' . $me['name'], mb_substr($b, 0, 120), 'msg-' . $u);
        }
        j(['ok' => true, 'id' => (int)$p->lastInsertId()]);
    }

    /* ---- Ranking semanal ---- */
    case 'rank_week': {
        $u = need_auth(); k12_week_touch($u);
        $ids = k12_circle($u);
        $inl = implode(',', array_map('intval', $ids));
        $wk = k12_week(); $prev = k12_week(-1);

        $rows = [];
        foreach ($p->query("SELECT id,username,name,avatar,xp FROM users WHERE id IN ($inl)") as $r) {
            $sc = k12_week_score((int)$r['id']);
            $r['wxp'] = $sc['xp']; $r['wcoins'] = $sc['coins'];
            $r += level_of((int)$r['xp']);
            $rows[] = $r;
        }
        usort($rows, fn($x, $y) => [$y['wxp'], $y['wcoins']] <=> [$x['wxp'], $x['wcoins']]);

        /* Premio de la semana pasada, una sola vez */
        $prize = null;
        $done = $p->prepare("SELECT 1 FROM week_prizes WHERE uid=? AND week=?"); $done->execute([$u, $prev]);
        if (!$done->fetch()) {
            $h = [];
            foreach ($p->query("SELECT uid,xp,coins FROM week_hist WHERE week='" . $prev . "' AND uid IN ($inl)") as $r)
                $h[(int)$r['uid']] = [(int)$r['xp'], (int)$r['coins']];
            if (isset($h[$u])) {
                $mine = $h[$u]; $pos = 1;
                foreach ($h as $k => $v) if ($k !== $u && ($v[0] > $mine[0] || ($v[0] === $mine[0] && $v[1] > $mine[1]))) $pos++;
                $tab = [1 => [150, 100], 2 => [100, 60], 3 => [60, 40]];
                [$gx, $gc] = $tab[$pos] ?? [20, 10];
                award($u, $gx, $gc);
                $p->prepare("INSERT OR IGNORE INTO week_prizes(uid,week,pos) VALUES(?,?,?)")->execute([$u, $prev, $pos]);
                $prize = ['pos' => $pos, 'xp' => $gx, 'coins' => $gc];
            } else {
                $p->prepare("INSERT OR IGNORE INTO week_prizes(uid,week,pos) VALUES(?,?,0)")->execute([$u, $prev]);
            }
        }
        j(['ok' => true, 'week' => $wk, 'board' => $rows, 'meid' => $u, 'prize' => $prize, 'me' => me_row($u)]);
    }

    /* ---- Fotos dentro de una nota ---- */
    case 'note_imgs': {
        $u = need_auth(); $id = n($in, 'id');
        if (!$id) fail('Guarda la nota antes de meterle fotos.');
        if (isset($in['imgs'])) {
            $jx = json_encode(array_values((array)$in['imgs']), JSON_UNESCAPED_SLASHES);
            if (strlen($jx) > 6000000) fail('Demasiadas fotos o demasiado pesadas.');
            $p->prepare("UPDATE notes SET imgs=? WHERE id=? AND uid=?")->execute([$jx, $id, $u]);
        }
        $st = $p->prepare("SELECT imgs FROM notes WHERE id=? AND uid=?"); $st->execute([$id, $u]);
        j(['ok' => true, 'imgs' => json_decode(($st->fetch()['imgs'] ?? '[]'), true) ?: []]);
    }

    /* ---- Diagnóstico de la nube (por qué no va EducaMadrid) ---- */
    case 'cloud_test': {
        $u = need_auth();
        $st = $p->prepare("SELECT * FROM cloud WHERE uid=?"); $st->execute([$u]);
        $c = $st->fetch();
        if (!$c || $c['url'] === '') fail('Primero rellena la dirección y el usuario.');
        if (!function_exists('curl_init')) fail('Este servidor no tiene cURL activado.');
        $base = rtrim($c['url'], '/');
        $ch = curl_init($base . '/');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PROPFIND', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $c['user'] . ':' . $c['pass'], CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Depth: 0', 'Content-Type: application/xml'],
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $diag = '';
        if ($code === 207 || $code === 200) $diag = 'Conexión correcta. La nube responde y acepta tu usuario.';
        elseif ($code === 401) $diag = 'Usuario o contraseña rechazados. Si tu Cloud tiene verificación en dos pasos, tienes que crear una "contraseña de aplicación" en Ajustes › Seguridad y poner esa aquí, no la tuya normal.';
        elseif ($code === 404) $diag = 'La dirección no existe. En EducaMadrid suele ser https://cloud.educa.madrid.org/remote.php/dav/files/TUUSUARIO (con tu usuario al final, sin barra).';
        elseif ($code === 405) $diag = 'La dirección responde pero no admite WebDAV. Te falta la parte /remote.php/dav/files/TUUSUARIO al final.';
        elseif ($code === 301 || $code === 302) $diag = 'La dirección redirige. Comprueba que empieza por https:// y que está escrita exacta.';
        elseif ($code === 0) $diag = 'No se ha podido conectar' . ($err ? ' (' . $err . ')' : '') . '. Puede que tu hosting bloquee las salidas a internet.';
        else $diag = 'La nube ha respondido con el código ' . $code . '.';
        j(['ok' => true, 'code' => $code, 'diag' => $diag]);
    }

    /* ---- Subir una foto suelta a la nube ---- */
    case 'cloud_photo': {
        $u = need_auth();
        $imgs = (array)($in['imgs'] ?? []);
        if (!$imgs) fail('No hay fotos que subir.');
        $st = $p->prepare("SELECT * FROM cloud WHERE uid=?"); $st->execute([$u]);
        $c = $st->fetch();
        if (!$c || $c['url'] === '' || $c['user'] === '') fail('Conecta tu nube en Más › Mi nube antes de subir nada.');
        $base = rtrim($c['url'], '/'); $dir = rawurlencode($c['folder'] ?: 'Krono');
        $auth = $c['user'] . ':' . $c['pass'];
        $ch = curl_init("$base/$dir");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'MKCOL', CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_USERPWD => $auth, CURLOPT_TIMEOUT => 20]);
        curl_exec($ch); curl_close($ch);

        $ok = 0; $last = 0;
        foreach ($imgs as $i => $img) {
            if (!preg_match('~^data:image/(\w+);base64,(.+)$~', (string)$img, $m)) continue;
            $data = base64_decode($m[2], true);
            if ($data === false) continue;
            $name = 'foto-' . date('Ymd-His') . '-' . ($i + 1) . '.' . ($m[1] === 'jpeg' ? 'jpg' : $m[1]);
            $ch = curl_init("$base/$dir/" . rawurlencode($name));
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => $data, CURLOPT_USERPWD => $auth, CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Expect:'],
            ]);
            curl_exec($ch);
            $last = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($last >= 200 && $last < 300) $ok++;
        }
        if (!$ok) fail('No se pudo subir ninguna foto (código ' . $last . '). Prueba el botón de comprobar conexión en Mi nube.');
        award($u, 4, 2);
        j(['ok' => true, 'subidas' => $ok, 'carpeta' => $c['folder'] ?: 'Krono']);
    }

    }
    } catch (Throwable $e) {
        fail('Error del módulo 1.2.0: ' . $e->getMessage(), 500);
    }
}

/* =============================================================
   JavaScript del módulo (se sirve en ?r=addon12)
   ============================================================= */
function k12_js1(): string { return <<<'JS'
/* KRONO 1.2.0 — ampliación del cliente */
(function(){
"use strict";
if (window.__k12) return; window.__k12 = 1;

/* ---------------- estilos ---------------- */
const CSS = `
.bell{position:relative;width:42px;height:42px;flex:0 0 42px;border-radius:13px;background:var(--card);
 border:1.5px solid var(--line);display:flex;align-items:center;justify-content:center;color:var(--ink);box-shadow:var(--shadow)}
.bell:active{transform:scale(.94)}
.bell .bdg{position:absolute;top:-6px;right:-6px;min-width:19px;height:19px;padding:0 5px;border-radius:99px;
 background:var(--red);color:#fff;font-size:10.5px;font-weight:800;display:flex;align-items:center;justify-content:center;
 font-style:normal;border:2px solid var(--vanilla)}
#fxlayer{position:fixed;inset:0;z-index:300;pointer-events:none;overflow:hidden}
.fxcoin{position:absolute;left:50%;top:46%;font-size:30px;font-weight:800;color:#8A5B00;background:#FFEFC2;
 border:2.5px solid #E8A317;padding:12px 24px;border-radius:99px;white-space:nowrap;
 box-shadow:0 18px 40px -14px rgba(60,40,15,.5);animation:fxup 1.4s cubic-bezier(.2,.8,.3,1) forwards}
@keyframes fxup{0%{opacity:0;transform:translate(-50%,-20%) scale(.6)}
 16%{opacity:1;transform:translate(-50%,-50%) scale(1.1)}
 30%{transform:translate(-50%,-50%) scale(1)}
 72%{opacity:1;transform:translate(-50%,-54%) scale(1)}
 100%{opacity:0;transform:translate(-50%,-105%) scale(.92)}}
#xpbar{position:fixed;left:0;right:0;top:0;z-index:310;padding:calc(var(--safe-t) + 8px) 16px 10px;
 background:linear-gradient(180deg,rgba(36,26,18,.96),rgba(36,26,18,.84));color:var(--vanilla);
 transform:translateY(-130%);transition:transform .34s cubic-bezier(.2,.8,.3,1)}
#xpbar.on{transform:none}
#xpbar .l{display:flex;justify-content:space-between;font-size:12.5px;font-weight:750;margin-bottom:6px}
#xpbar .t{height:8px;border-radius:99px;background:rgba(255,244,214,.22);overflow:hidden}
#xpbar .t i{display:block;height:100%;width:0;background:var(--orange);border-radius:99px;transition:width .7s cubic-bezier(.2,.8,.3,1)}
#lvlup{position:fixed;inset:0;z-index:320;display:none;align-items:center;justify-content:center;
 background:rgba(36,26,18,.62);backdrop-filter:blur(5px);padding:24px}
#lvlup.on{display:flex}
#lvlup .bx{background:var(--paper);border-radius:26px;padding:30px 22px;text-align:center;max-width:340px;width:100%;
 animation:k12pop .4s cubic-bezier(.2,.9,.3,1.35);box-shadow:0 30px 70px -20px rgba(36,26,18,.6)}
@keyframes k12pop{from{transform:scale(.78);opacity:0}to{transform:none;opacity:1}}
#k12ban{position:fixed;left:50%;transform:translateX(-50%) translateY(-140%);top:calc(var(--safe-t) + 10px);
 z-index:290;background:var(--card);border:1.5px solid var(--line);border-radius:16px;padding:12px 16px;
 font-size:14px;font-weight:650;max-width:92vw;box-shadow:0 16px 40px -14px rgba(60,40,15,.45);
 transition:transform .35s cubic-bezier(.2,.8,.3,1);display:flex;align-items:center;gap:10px}
#k12ban.on{transform:translateX(-50%) translateY(0)}
.chatw{display:flex;flex-direction:column;gap:8px;padding:4px 0 12px}
.bub{max-width:78%;padding:10px 13px;border-radius:17px;font-size:15px;line-height:1.42;word-break:break-word;white-space:pre-wrap}
.bub.me{align-self:flex-end;background:var(--orange);color:#fff;border-bottom-right-radius:6px}
.bub.you{align-self:flex-start;background:var(--card);border:1.5px solid var(--line);border-bottom-left-radius:6px}
.bub .tm{display:block;font-size:10.5px;opacity:.65;margin-top:3px;text-align:right}
.chatbar{position:fixed;left:0;right:0;bottom:0;z-index:70;padding:9px 12px calc(9px + var(--safe-b));
 background:rgba(255,252,243,.97);border-top:1.5px solid var(--line);display:flex;gap:8px;
 max-width:560px;margin:0 auto}
.chatbar input{flex:1;border-radius:99px}
.chatbar button{flex:0 0 46px;border-radius:99px}
.updrow{display:flex;gap:11px;align-items:flex-start;padding:9px 0;border-bottom:1.5px solid var(--line-2)}
.updrow:last-child{border-bottom:0}
.updrow .e{font-size:20px;flex:0 0 26px}
.updrow b{display:block;font-size:14.5px}
.updrow span{font-size:13px;color:var(--muted);line-height:1.4}
.pod{display:flex;align-items:center;gap:11px;padding:11px;border-radius:14px;background:var(--card);
 border:1.5px solid var(--line);margin-bottom:8px}
.pod.p1{background:linear-gradient(135deg,#FFF3CF,#FFE9B0);border-color:#E8C87A}
.pod.p2{background:#F4F1EA;border-color:#DCD5C6}
.pod.p3{background:#FBEEE3;border-color:#E6CDB4}
.pod .pos{font-size:19px;font-weight:800;width:32px;text-align:center;flex:0 0 32px}
.gal{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px}
.gal .g{position:relative;aspect-ratio:3/4;border-radius:11px;overflow:hidden;background:var(--line-2)}
.gal .g img{width:100%;height:100%;object-fit:cover}
.gal .g button{position:absolute;top:4px;right:4px;width:23px;height:23px;border-radius:50%;
 background:rgba(36,26,18,.72);color:#fff;font-size:13px;line-height:23px}
.countdown{font-variant-numeric:tabular-nums;font-weight:800}
`;
const st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st);

document.body.insertAdjacentHTML('beforeend',
  '<div id="fxlayer"></div>' +
  '<div id="xpbar"><div class="l"><span id="xpl"></span><span id="xpr"></span></div>' +
    '<div class="t"><i id="xpi"></i></div></div>' +
  '<div id="lvlup"><div class="bx" id="lvlbx"></div></div>' +
  '<div id="k12ban"></div>');

/* ---------------- estado propio ---------------- */
const K = { sch: 0, badges: { total: 0, followers: [], msgs: 0 }, privado: 0,
            chatWith: 0, chatTimer: 0, cron: '', exams: [], hw: [], pm: null, other: null };
window.K12 = K;
const DOW7 = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];

function q(s, r){ return (r || document).querySelector(s); }
function d2(n){ return String(n).padStart(2,'0'); }
function hoy(){ return iso(new Date()); }
function diasHasta(day){ return Math.round((parse(day) - parse(hoy())) / 86400000); }
function fecha(day){ const d = parse(day); return DOW7[(d.getDay()+6)%7] + ' ' + d.getDate() + ' ' + MONS[d.getMonth()]; }

/* ---------------- efectos de monedas / XP / nivel ---------------- */
let lx = null, lc = null, ll = null;
function fxMe(me){
  if (!me || typeof me.xp === 'undefined') return;
  if (lx !== null){
    const dc = me.coins - lc, dx = me.xp - lx;
    if (dc > 0) fxCoins(dc);
    if (dx > 0) fxXP(me, dx);
    if (ll !== null && me.level > ll) setTimeout(() => fxLevel(me), 800);
  }
  lx = me.xp; lc = me.coins; ll = me.level;
}
function fxCoins(n){
  const el = document.createElement('div');
  el.className = 'fxcoin';
  el.textContent = '+' + n + ' 🪙';
  q('#fxlayer').appendChild(el);
  setTimeout(() => el.remove(), 1500);
  if (navigator.vibrate && S.prefs.haptics !== false) navigator.vibrate(14);
}
let xpT;
function fxXP(me, d){
  q('#xpl').textContent = 'Nivel ' + me.level;
  q('#xpr').textContent = '+' + d + ' XP  ·  ' + me.into + '/' + me.need;
  const bar = q('#xpbar'); bar.classList.add('on');
  q('#xpi').style.width = '0%';
  setTimeout(() => { q('#xpi').style.width = Math.round(me.into / me.need * 100) + '%'; }, 60);
  clearTimeout(xpT);
  xpT = setTimeout(() => bar.classList.remove('on'), 2600);
}
function fxLevel(me){
  q('#lvlbx').innerHTML =
    '<div style="font-size:52px;line-height:1;margin-bottom:10px">🎉</div>' +
    '<h2 style="font-size:24px;margin-bottom:6px">¡Enhorabuena!</h2>' +
    '<p style="color:var(--ink-2);font-size:15px;margin-bottom:4px">Has subido al</p>' +
    '<div style="font-size:42px;font-weight:800;letter-spacing:-.04em;color:var(--orange-d);margin-bottom:10px">Nivel ' + me.level + '</div>' +
    '<p style="color:var(--muted);font-size:14px;margin-bottom:18px">Te faltan ' + me.need + ' XP para el siguiente. Sigue así.</p>' +
    '<button class="btn block" onclick="K12.cerrarNivel()">Seguir a lo mío</button>';
  q('#lvlup').classList.add('on');
  if (navigator.vibrate && S.prefs.haptics !== false) navigator.vibrate([50,40,50,40,90]);
}
K.cerrarNivel = () => q('#lvlup').classList.remove('on');

/* envolvemos api() para que todo lo que devuelva "me" dispare los efectos */
const _api = window.api;
window.api = async function(a, d){
  const r = await _api(a, d);
  if (r && r.me) fxMe(r.me);
  return r;
};

/* ---------------- campana y avisos ---------------- */
function bellHTML(){
  const n = K.badges.total || 0;
  return '<button class="bell" onclick="K12.abrirAvisos()" aria-label="Avisos">' +
    '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.9" ' +
    'stroke-linecap="round" stroke-linejoin="round"><path d="M18 8.5a6 6 0 1 0-12 0c0 6-2.4 7.5-2.4 7.5h16.8S18 14.5 18 8.5"/>' +
    '<path d="M13.7 20a2 2 0 0 1-3.4 0"/></svg>' +
    (n ? '<i class="bdg num">' + (n > 9 ? '9+' : n) + '</i>' : '') + '</button>';
}
const _shell = window.shell;
window.shell = function(title, sub, body, right){
  _shell(title, sub, body, right);
  if (!S.me) return;
  const row = q('header.top .top-row');
  if (row && !q('.bell')) row.insertAdjacentHTML('beforeend', bellHTML());
};

K.abrirAvisos = async function(){
  const b = K.badges;
  sheet('<h2 style="margin-bottom:4px">Avisos</h2>' +
    '<p class="sub" style="margin-bottom:16px">Lo que ha pasado mientras no estabas.</p>' +
    (b.msgs ? '<button class="tile" style="width:100%;text-align:left;margin-bottom:9px" onclick="closeSheet();go(\'chats\')">' +
      '<span class="av">💬</span><span class="tx"><span class="tt">' + b.msgs +
      (b.msgs === 1 ? ' mensaje sin leer' : ' mensajes sin leer') + '</span>' +
      '<span class="ds">Toca para abrir tus conversaciones</span></span>' +
      '<span style="color:var(--muted)">›</span></button>' : '') +
    (b.followers.length ? '<div class="card flat" style="padding:11px"><b style="font-size:14px">Te han empezado a seguir</b>' +
      '<div class="lst" style="margin-top:9px">' + b.followers.map(u =>
        '<div class="tile" onclick="closeSheet();K12.verPerfil(' + u.id + ')">' +
        '<span class="av">' + (u.avatar ? '<img src="' + esc(u.avatar) + '" alt="">' : '👤') + '</span>' +
        '<span class="tx"><span class="tt">' + esc(u.name) + '</span><span class="ds">@' + esc(u.username) + '</span></span>' +
        '<span style="color:var(--muted)">›</span></div>').join('') + '</div></div>' : '') +
    (!b.total ? '<div class="empty" style="padding:26px"><div class="em">🔔</div><h3>Todo al día</h3>' +
      '<p>No tienes avisos pendientes.</p></div>' : '') +
    '<button class="btn block" style="margin-top:14px" onclick="K12.marcarVistos()">Marcar todo como visto</button>');
};
K.marcarVistos = async function(){
  const r = await api('k12_privacy', { seen_followers: 1 });
  if (r) { K.badges = r.badges; closeSheet(); const b = q('.bell'); if (b) b.outerHTML = bellHTML(); }
};

function banner(txt){
  const el = q('#k12ban');
  el.innerHTML = '<span style="font-size:18px">🔔</span><span>' + txt + '</span>';
  el.classList.add('on');
  el.onclick = () => { el.classList.remove('on'); K.abrirAvisos(); };
  setTimeout(() => el.classList.remove('on'), 5200);
}
JS;
}
function k12_js(): string { return k12_js1() . k12_js2() . k12_js3(); }

function k12_js2(): string { return <<<'JS'

/* ===================================================================
   EXÁMENES
   =================================================================== */
async function viewExams(){
  const r = await api('exams');
  if (!r) return;
  K.exams = r.exams;
  const prox = K.exams.filter(e => diasHasta(e.day) >= 0);
  const pasados = K.exams.filter(e => diasHasta(e.day) < 0).reverse();

  shell('Exámenes', prox.length ? 'Tienes ' + prox.length + (prox.length === 1 ? ' examen por delante' : ' exámenes por delante') : 'Ninguno a la vista',
    (prox.length ? '<div class="lst" style="margin-bottom:12px">' + prox.map(examTile).join('') + '</div>'
      : '<div class="empty"><div class="em">📚</div><h3>Sin exámenes apuntados</h3>' +
        '<p>Apúntalos y Krono te irá avisando las dos semanas de antes.</p></div>') +
    '<button class="btn block" style="margin-bottom:12px" onclick="K12.editExam(0)">+ Apuntar examen</button>' +
    (pasados.length ? '<div class="card"><div class="card-h"><h3>Ya pasaron</h3></div><div class="lst">' +
      pasados.slice(0, 12).map(examTile).join('') + '</div></div>' : ''),
    '<button class="btn sm" onclick="K12.editExam(0)" aria-label="Añadir">+</button>');
}
function examTile(e){
  const d = diasHasta(e.day);
  const txt = d < 0 ? 'Hace ' + (-d) + (d === -1 ? ' día' : ' días')
            : d === 0 ? '¡ES HOY!' : d === 1 ? 'Mañana' : 'En ' + d + ' días';
  const col = d < 0 ? 'var(--muted)' : d <= 3 ? 'var(--red)' : d <= 7 ? '#E8A317' : 'var(--green)';
  return '<div class="tile" style="' + (d >= 0 && d <= 3 ? 'border-color:' + e.color : '') + '" onclick="K12.editExam(' + e.id + ')">' +
    '<span class="av" style="background:' + esc(e.color) + '1F">' + esc(e.emoji) + '</span>' +
    '<span class="tx"><span class="tt">' + esc(e.subject) + '</span>' +
    '<span class="ds">' + fecha(e.day) + (e.title ? ' · ' + esc(e.title) : '') + '</span></span>' +
    '<span class="countdown" style="color:' + col + ';font-size:13px">' + txt + '</span></div>';
}
K.editExam = function(id){
  const e = K.exams.find(x => Number(x.id) === Number(id)) ||
            { id: 0, subject: '', title: '', day: hoy(), notes: '', emoji: '📚', color: '#D8452F' };
  sheet('<h2 style="margin-bottom:14px">' + (id ? 'Editar examen' : 'Nuevo examen') + '</h2>' +
    '<div class="field"><label for="x_s">Asignatura</label><input id="x_s" value="' + esc(e.subject) + '" placeholder="Matemáticas"></div>' +
    '<div class="field"><label for="x_t">Tema o título</label><input id="x_t" value="' + esc(e.title) + '" placeholder="Temas 4 y 5"></div>' +
    '<div class="field"><label for="x_d">Día</label><input id="x_d" type="date" value="' + esc(e.day) + '"></div>' +
    '<div class="field"><label for="x_n">Qué entra</label><textarea id="x_n" style="min-height:90px" placeholder="Apuntes, ejercicios, fórmulas...">' + esc(e.notes) + '</textarea></div>' +
    '<div class="field"><label>Emoji</label>' + emoHTML(e.emoji, 'x_emo') + '</div>' +
    '<div class="field"><label>Color</label>' + palHTML(e.color, 'x_pal') + '</div>' +
    '<button class="btn block" id="x_save">Guardar examen</button>' +
    (id ? '<button class="btn danger block" style="margin-top:9px" onclick="K12.delExam(' + id + ')">Eliminar</button>' : ''));
  wirePickers();
  q('#x_save').onclick = async () => {
    const d = { id, subject: q('#x_s').value.trim(), title: q('#x_t').value.trim(),
                day: q('#x_d').value || hoy(), notes: q('#x_n').value.trim(),
                emoji: pickedEmoji('x_emo', '📚'), color: pickedColor('x_pal') };
    if (!d.subject) return toast('Ponle la asignatura.', 'bad');
    const r = await api('exam_save', d);
    if (!r) return;
    closeSheet(); toast('Examen guardado', 'good'); viewExams();
  };
};
K.delExam = async function(id){
  if (!confirm('¿Eliminar este examen?')) return;
  const r = await api('exam_del', { id });
  if (!r) return;
  closeSheet(); toast('Examen eliminado'); viewExams();
};

/* ===================================================================
   DEBERES
   =================================================================== */
async function viewHW(){
  const r = await api('hw_list');
  if (!r) return;
  K.hw = r.hw;
  const pend = K.hw.filter(h => !Number(h.done));
  const hechos = K.hw.filter(h => Number(h.done));
  const hoyL = pend.filter(h => diasHasta(h.due) <= 0);
  const sem = pend.filter(h => diasHasta(h.due) > 0 && diasHasta(h.due) <= 7);
  const resto = pend.filter(h => diasHasta(h.due) > 7);

  const bloque = (t, l) => l.length ? '<div class="card"><div class="card-h"><h3>' + t + '</h3>' +
    '<span class="chip"><b class="num">' + l.length + '</b></span></div><div class="lst">' + l.map(hwTile).join('') + '</div></div>' : '';

  shell('Deberes', pend.length ? pend.length + (pend.length === 1 ? ' tarea pendiente' : ' tareas pendientes') : 'Nada pendiente',
    (pend.length
      ? bloque('Para hoy o atrasados', hoyL) + bloque('Esta semana', sem) + bloque('Más adelante', resto)
      : '<div class="empty"><div class="em">🎈</div><h3>Sin deberes</h3><p>O te lo has hecho todo, o aún no has apuntado nada.</p></div>') +
    '<button class="btn block" style="margin-bottom:12px" onclick="K12.editHW(0)">+ Apuntar deberes</button>' +
    (hechos.length ? '<div class="card"><div class="card-h"><h3>Hechos</h3>' +
      '<button class="btn ghost sm" onclick="K12.limpiarHW()">Limpiar</button></div><div class="lst">' +
      hechos.slice(0, 15).map(hwTile).join('') + '</div></div>' : ''),
    '<button class="btn sm" onclick="K12.editHW(0)" aria-label="Añadir">+</button>');
}
function hwTile(h){
  const ok = Number(h.done), d = diasHasta(h.due);
  const cuando = ok ? 'Hecho' : d < 0 ? 'Atrasado ' + (-d) + (d === -1 ? ' día' : ' días')
                   : d === 0 ? 'Para hoy' : d === 1 ? 'Para mañana' : 'Para ' + fecha(h.due);
  return '<div class="tile" style="' + (!ok && d < 0 ? 'border-color:var(--red)' : '') + '">' +
    '<span class="av" style="background:' + esc(h.color) + '1F">' + esc(h.emoji) + '</span>' +
    '<span class="tx" onclick="K12.editHW(' + h.id + ')">' +
      '<span class="tt"' + (ok ? ' style="text-decoration:line-through;opacity:.6"' : '') + '>' + esc(h.title) + '</span>' +
      '<span class="ds">' + (h.subject ? esc(h.subject) + ' · ' : '') + cuando + '</span></span>' +
    '<button class="tick' + (ok ? ' on' : '') + '" onclick="K12.tickHW(' + h.id + ',' + (ok ? 0 : 1) + ')">✓</button></div>';
}
K.tickHW = async function(id, v){
  const r = await api('hw_done', { id, done: v });
  if (!r) return;
  if (v) toast('+12 XP · deberes hechos', 'good');
  S.me = r.me; viewHW();
};
K.limpiarHW = async function(){
  if (!confirm('¿Borrar los deberes que ya has hecho?')) return;
  for (const h of K.hw.filter(x => Number(x.done))) await api('hw_del', { id: h.id });
  toast('Limpiado'); viewHW();
};
K.editHW = function(id){
  const h = K.hw.find(x => Number(x.id) === Number(id)) ||
            { id: 0, subject: '', title: '', descr: '', due: hoy(), emoji: '📒', color: '#3B6FD4' };
  sheet('<h2 style="margin-bottom:14px">' + (id ? 'Editar deberes' : 'Nuevos deberes') + '</h2>' +
    '<div class="field"><label for="d_t">¿Qué hay que hacer?</label><input id="d_t" value="' + esc(h.title) + '" placeholder="Ejercicios 3 a 8 de la página 112"></div>' +
    '<div class="field"><label for="d_s">Asignatura</label><input id="d_s" value="' + esc(h.subject) + '" placeholder="Lengua"></div>' +
    '<div class="field"><label for="d_f">Para cuándo</label><input id="d_f" type="date" value="' + esc(h.due) + '"></div>' +
    '<div class="field"><label for="d_d">Detalles</label><textarea id="d_d" style="min-height:70px" placeholder="Lo que dijo el profe...">' + esc(h.descr) + '</textarea></div>' +
    '<div class="field"><label>Emoji</label>' + emoHTML(h.emoji, 'd_emo') + '</div>' +
    '<div class="field"><label>Color</label>' + palHTML(h.color, 'd_pal') + '</div>' +
    '<button class="btn block" id="d_save">Guardar</button>' +
    (id ? '<button class="btn danger block" style="margin-top:9px" onclick="K12.delHW(' + id + ')">Eliminar</button>' : ''));
  wirePickers();
  q('#d_save').onclick = async () => {
    const d = { id, title: q('#d_t').value.trim(), subject: q('#d_s').value.trim(),
                due: q('#d_f').value || hoy(), descr: q('#d_d').value.trim(),
                emoji: pickedEmoji('d_emo', '📒'), color: pickedColor('d_pal') };
    if (!d.title) return toast('Escribe qué hay que hacer.', 'bad');
    const r = await api('hw_save', d);
    if (!r) return;
    closeSheet(); toast('Deberes guardados', 'good'); viewHW();
  };
};
K.delHW = async function(id){
  const r = await api('hw_del', { id });
  if (!r) return;
  closeSheet(); toast('Eliminado'); viewHW();
};

/* ===================================================================
   HORARIO DE TARDE  (+ selector mañana/tarde y compartir)
   =================================================================== */
const _viewSched = window.viewSched;
window.viewSched = async function(){
  if (K.sch === 1) return viewSchedPM();
  await _viewSched();
  inyectaSelector();
};
function selectorHTML(){
  return '<div class="seg" style="margin-bottom:14px">' +
    '<button class="' + (K.sch === 0 ? 'on' : '') + '" onclick="K12.setSch(0)">🏫 Mañana</button>' +
    '<button class="' + (K.sch === 1 ? 'on' : '') + '" onclick="K12.setSch(1)">🌆 Tarde</button>' +
    '</div>';
}
function inyectaSelector(){
  const wrap = q('.screen .wrap');
  if (!wrap || q('#k12sel')) return;
  wrap.insertAdjacentHTML('afterbegin', '<div id="k12sel">' + selectorHTML() + '</div>');
  wrap.insertAdjacentHTML('beforeend',
    '<button class="btn ghost block" style="margin-top:9px" onclick="K12.compartirHorario()">🔗 Compartir mi horario</button>');
}
K.setSch = function(v){ K.sch = v; viewSched(); };

async function viewSchedPM(){
  const r = await api('pm_get');
  if (!r) return;
  K.pm = { periods: r.periods, slots: {} };
  r.slots.forEach(s => { K.pm.slots[s.dow + ':' + s.idx] = s; });
  const hoyD = (new Date().getDay() + 6) % 7, ahora = new Date().getHours()*60 + new Date().getMinutes();

  const grid = '<div class="sched" style="grid-template-columns:44px repeat(7,minmax(0,1fr))">' +
    '<div class="sh"></div>' + DOW7.map((d,i) =>
      '<div class="sh"' + (i === hoyD ? ' style="color:var(--orange-d)"' : '') + '>' + d + '</div>').join('') +
    K.pm.periods.map((p, i) => {
      const live = mins(p.t1) <= ahora && ahora < mins(p.t2);
      let row = '<div class="st"><span>' + p.t1 + '</span><span style="opacity:.55">' + p.t2 + '</span></div>';
      for (let d = 0; d < 7; d++){
        const s = K.pm.slots[d + ':' + i];
        row += '<button class="sc' + (live && d === hoyD ? ' live' : '') + '" style="' +
          (s ? 'background:' + s.color + '18;' : '') + '" onclick="K12.editPM(' + d + ',' + i + ')">' +
          (s ? '<span class="e">' + esc(s.emoji) + '</span><span class="s" style="color:' + esc(s.color) + '">' + esc(s.subject) + '</span>'
             : '<span class="s" style="opacity:.35">+</span>') + '</button>';
      }
      return row;
    }).join('') + '</div>';

  shell('Horario', 'Tu tarde, de 15:30 a 22:00',
    '<div id="k12sel">' + selectorHTML() + '</div>' +
    '<div class="card" style="overflow-x:auto">' + grid + '</div>' +
    '<button class="btn ghost block" style="margin-bottom:9px" onclick="K12.editPMPeriods()">Cambiar los tramos de la tarde</button>' +
    '<button class="btn ghost block" onclick="K12.compartirHorario()">🔗 Compartir mi horario</button>' +
    '<p class="hint" style="margin-top:10px">Aquí va lo tuyo: entrenamientos, academia, estudio, cena... Es un horario aparte del del instituto.</p>');
}
K.editPM = function(dow, idx){
  const s = K.pm.slots[dow + ':' + idx] || { subject: '', room: '', emoji: '🕓', color: '#8A5CD6' };
  const p = K.pm.periods[idx];
  sheet('<h2>' + DOWL[dow].replace(/^./, c => c.toUpperCase()) + '</h2>' +
    '<p class="sub" style="margin-bottom:14px">' + esc(p.label + ' · ' + p.t1 + '–' + p.t2) + '</p>' +
    '<div class="field"><label for="m_s">¿Qué haces?</label><input id="m_s" value="' + esc(s.subject) + '" placeholder="Entrenamiento, estudiar, inglés..."></div>' +
    '<div class="field"><label for="m_r">Sitio (opcional)</label><input id="m_r" value="' + esc(s.room) + '" placeholder="Polideportivo"></div>' +
    '<div class="field"><label>Emoji</label>' + emoHTML(s.emoji, 'm_emo') + '</div>' +
    '<div class="field"><label>Color</label>' + palHTML(s.color, 'm_pal') + '</div>' +
    '<button class="btn block" id="m_save">Guardar</button>' +
    (s.subject ? '<button class="btn danger block" style="margin-top:9px" id="m_del">Vaciar la casilla</button>' : ''));
  wirePickers();
  q('#m_save').onclick = async () => {
    const r = await api('pm_slot', { dow, idx, subject: q('#m_s').value.trim(), room: q('#m_r').value.trim(),
                                     emoji: pickedEmoji('m_emo', '🕓'), color: pickedColor('m_pal') });
    if (!r) return;
    closeSheet(); toast('Horario de tarde actualizado', 'good'); viewSchedPM();
  };
  const dl = q('#m_del');
  if (dl) dl.onclick = async () => { await api('pm_slot', { dow, idx, subject: '' }); closeSheet(); viewSchedPM(); };
};
K.editPMPeriods = function(){
  const fila = p => '<div class="prow card flat" style="padding:11px;margin-bottom:9px">' +
    '<div class="row" style="margin-bottom:8px"><input class="p_l" value="' + esc(p.label) + '" placeholder="Nombre" style="flex:2">' +
    '<button class="btn danger sm" style="flex:0 0 44px" onclick="this.closest(\'.prow\').remove()">✕</button></div>' +
    '<div class="row"><input class="p_1" type="time" value="' + esc(p.t1) + '"><input class="p_2" type="time" value="' + esc(p.t2) + '"></div></div>';
  sheet('<h2 style="margin-bottom:6px">Tramos de la tarde</h2>' +
    '<p class="sub" style="margin-bottom:14px">Ponlos como te venga bien.</p>' +
    '<div id="pm_list">' + K.pm.periods.map(fila).join('') + '</div>' +
    '<button class="btn ghost block" style="margin-bottom:9px" id="pm_add">+ Añadir tramo</button>' +
    '<button class="btn block" id="pm_save">Guardar tramos</button>');
  q('#pm_add').onclick = () => q('#pm_list').insertAdjacentHTML('beforeend', fila({ label: 'Tramo', t1: '22:00', t2: '23:00' }));
  q('#pm_save').onclick = async () => {
    const rows = [...document.querySelectorAll('#pm_list .prow')].map(r => ({
      label: r.querySelector('.p_l').value.trim() || 'Tramo',
      t1: r.querySelector('.p_1').value, t2: r.querySelector('.p_2').value }));
    const r = await api('pm_periods', { periods: rows });
    if (!r) return;
    closeSheet(); toast('Tramos guardados', 'good'); viewSchedPM();
  };
};

K.compartirHorario = function(){
  sheet('<h2 style="margin-bottom:6px">Compartir horario</h2>' +
    '<p class="sub" style="margin-bottom:16px">Tu horario se ve en tu perfil si lo tienes en público. Cualquiera que entre podrá copiárselo de un toque.</p>' +
    '<div class="card flat" style="padding:12px;margin-bottom:12px">' +
      '<b style="font-size:14.5px">Estado de tu perfil</b>' +
      '<p class="sub" style="margin:4px 0 10px">' + (K.privado ? 'Privado: nadie ve tu horario.' : 'Público: tus colegas pueden verlo y copiarlo.') + '</p>' +
      '<button class="btn ' + (K.privado ? '' : 'ghost') + ' block" onclick="K12.togglePriv()">' +
        (K.privado ? 'Poner mi perfil en público' : 'Poner mi perfil en privado') + '</button>' +
    '</div>' +
    '<div class="field"><label>Enlace a tu perfil</label>' +
      '<input id="sh_url" readonly value="' + esc(location.origin + location.pathname + '#u' + S.me.id) + '"></div>' +
    '<button class="btn block" onclick="K12.copiarEnlace()">Copiar enlace</button>');
};
K.copiarEnlace = function(){
  const i = q('#sh_url'); i.select();
  navigator.clipboard ? navigator.clipboard.writeText(i.value).then(() => toast('Enlace copiado', 'good'))
                      : (document.execCommand('copy'), toast('Enlace copiado', 'good'));
};
K.togglePriv = async function(){
  const r = await api('k12_privacy', { privado: K.privado ? 0 : 1 });
  if (!r) return;
  K.privado = r.privado;
  toast(K.privado ? 'Perfil en privado' : 'Perfil en público', 'good');
  closeSheet();
};
JS;
}

function k12_js3(): string { return <<<'JS'

/* ===================================================================
   MENSAJES
   =================================================================== */
async function viewChats(){
  const r = await api('chats');
  if (!r) return;
  const c = r.chats;
  shell('Mensajes', c.length ? 'Puedes escribir a ' + c.length + (c.length === 1 ? ' persona' : ' personas') : 'Sin conversaciones',
    (c.length ? '<div class="lst">' + c.map(u =>
      '<div class="tile" onclick="K12.abrirChat(' + u.id + ')">' +
        '<span class="av">' + (u.avatar ? '<img src="' + esc(u.avatar) + '" alt="">' : '👤') + '</span>' +
        '<span class="tx"><span class="tt">' + esc(u.name) + '</span>' +
        '<span class="ds">' + (u.last ? (Number(u.mine) ? 'Tú: ' : '') + esc(u.last) : 'Aún no os habéis escrito') + '</span></span>' +
        (Number(u.unread) ? '<span class="chip hot num">' + u.unread + '</span>' : '<span style="color:var(--muted)">›</span>') +
      '</div>').join('') + '</div>'
      : '<div class="empty"><div class="em">💬</div><h3>Nadie todavía</h3>' +
        '<p>Solo puedes escribirte con quien te sigue y tú sigues de vuelta. Busca colegas en Más › Colegas.</p></div>') +
    '<p class="hint" style="margin-top:12px">Los mensajes llegan como aviso al móvil aunque tengas Krono cerrada, si tienes activados los avisos en Ajustes.</p>');
}
K.abrirChat = function(id){ K.chatWith = id; go('chat'); };

async function viewChat(){
  const r = await api('chat_get', { id: K.chatWith });
  if (!r) return;
  const who = r.who, me = r.meid;
  shell(who.name, '@' + who.username,
    '<div class="chatw" id="chatw">' + (r.msgs.length ? r.msgs.map(m => bub(m, me)).join('')
      : '<div class="empty" style="padding:30px"><div class="em">👋</div><p>Rompe el hielo.</p></div>') + '</div>' +
    '<div style="height:70px"></div>');
  const nav = q('nav.tabs'); if (nav) nav.style.display = 'none';
  document.body.insertAdjacentHTML('beforeend',
    '<div class="chatbar" id="chatbar"><input id="ch_i" placeholder="Escribe algo..." autocomplete="off">' +
    '<button class="btn" id="ch_s" aria-label="Enviar">➤</button></div>');
  const inp = q('#ch_i');
  q('#ch_s').onclick = enviar;
  inp.addEventListener('keydown', e => { if (e.key === 'Enter') enviar(); });
  scrollFin();
  clearInterval(K.chatTimer);
  K.chatTimer = setInterval(refrescarChat, 6000);

  async function enviar(){
    const b = inp.value.trim(); if (!b) return;
    inp.value = '';
    const s = await api('chat_send', { id: K.chatWith, body: b });
    if (!s) { inp.value = b; return; }
    q('#chatw').insertAdjacentHTML('beforeend', bub({ a: me, body: b, created: new Date().toISOString() }, me));
    scrollFin();
  }
}
function bub(m, me){
  const mine = Number(m.a) === Number(me);
  const t = (m.created || '').slice(11, 16);
  return '<div class="bub ' + (mine ? 'me' : 'you') + '">' + esc(m.body) +
    (t ? '<span class="tm">' + t + '</span>' : '') + '</div>';
}
function scrollFin(){ setTimeout(() => window.scrollTo(0, document.body.scrollHeight), 60); }
async function refrescarChat(){
  if (S.view !== 'chat') { clearInterval(K.chatTimer); return; }
  const r = await api('chat_get', { id: K.chatWith });
  if (!r) return;
  const w = q('#chatw'); if (!w) return;
  const nuevo = r.msgs.map(m => bub(m, r.meid)).join('');
  if (w.innerHTML !== nuevo && r.msgs.length){ w.innerHTML = nuevo; scrollFin(); }
}
function limpiaChat(){
  clearInterval(K.chatTimer);
  const b = q('#chatbar'); if (b) b.remove();
  const nav = q('nav.tabs'); if (nav) nav.style.display = '';
}

/* ===================================================================
   RANKING SEMANAL
   =================================================================== */
async function viewRank(){
  const r = await api('rank_week');
  if (!r) return;
  if (r.prize){
    const p = r.prize;
    setTimeout(() => sheet('<div style="text-align:center;padding:10px 0 4px">' +
      '<div style="font-size:52px">' + (p.pos === 1 ? '🥇' : p.pos === 2 ? '🥈' : p.pos === 3 ? '🥉' : '🎖️') + '</div>' +
      '<h2 style="margin:8px 0 6px">Premio de la semana pasada</h2>' +
      '<p class="sub" style="margin-bottom:14px">Quedaste ' + p.pos + 'º entre tus colegas.</p>' +
      '<div class="chips" style="justify-content:center;margin-bottom:18px">' +
        '<span class="chip">⚡ +<b class="num">' + p.xp + '</b> XP</span>' +
        '<span class="chip">🪙 +<b class="num">' + p.coins + '</b></span></div>' +
      '<button class="btn block" onclick="closeSheet()">¡Toma!</button></div>'), 600);
  }
  const b = r.board;
  shell('Ranking semanal', 'Semana ' + r.week.replace('-W', ', semana '),
    '<div class="card flat" style="background:#FFF9EF">' +
      '<h3 style="margin-bottom:8px">Cómo va esto</h3>' +
      '<p style="font-size:14px;color:var(--ink-2);margin:0">Cada lunes se pone el contador a cero. El domingo se cierra y al entrar cobras tu premio: ' +
      '1º 150 XP y 100 🪙, 2º 100 XP y 60 🪙, 3º 60 XP y 40 🪙, y del cuarto para abajo 20 XP y 10 🪙 por participar.</p>' +
    '</div>' +
    '<div class="card"><div class="card-h"><h3>Esta semana</h3></div>' +
      (b.length ? b.map((u, i) =>
        '<div class="pod' + (i < 3 ? ' p' + (i+1) : '') + '"' +
          (Number(u.id) === r.meid ? ' style="box-shadow:inset 0 0 0 2px var(--orange)"' : ' onclick="K12.verPerfil(' + u.id + ')"') + '>' +
          '<span class="pos">' + (i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : (i+1)) + '</span>' +
          '<span class="av" style="width:36px;height:36px;flex:0 0 36px;border-radius:11px;background:var(--line-2);' +
            'display:flex;align-items:center;justify-content:center;overflow:hidden">' +
            (u.avatar ? '<img src="' + esc(u.avatar) + '" style="width:100%;height:100%;object-fit:cover" alt="">' : '👤') + '</span>' +
          '<span class="tx" style="flex:1;min-width:0"><span class="tt">' + esc(u.name) +
            (Number(u.id) === r.meid ? ' · tú' : '') + '</span>' +
            '<span class="ds">Nivel ' + u.level + ' · 🪙 ' + u.wcoins + ' esta semana</span></span>' +
          '<b class="num" style="font-size:16px">' + u.wxp + '</b></div>').join('')
        : '<div class="empty" style="padding:20px"><p>Sigue a gente para competir con ellos.</p></div>') +
    '</div>' +
    '<p class="hint">Solo compites con la gente a la que sigues. Cuantos más colegas añadas, más movida la cosa.</p>');
}
K.verPerfil = function(id){ S.other = id; K.other = id; go('user'); };

/* ===================================================================
   PERFIL DE OTRA PERSONA (público / privado)
   =================================================================== */
window.viewUser = async function(){
  const r = await api('pub_profile', { id: S.other });
  if (!r) return;
  const u = r.user;
  const f = await api('user', { id: S.other });
  const sigo = f ? Number(f.following) : 0;

  const cab =
    '<div class="card">' +
      '<div class="phead">' +
        '<div class="pav">' + (u.avatar ? '<img src="' + esc(u.avatar) + '" alt="">' : '👤') + '</div>' +
        '<div style="flex:1;min-width:0"><h2>Nivel ' + u.level + '</h2>' +
        '<div class="sub">' + esc(u.bio || 'Sin descripción') + '</div></div>' +
      '</div>' +
      '<div class="chips" style="margin-bottom:14px">' +
        '<span class="chip">👥 <b class="num">' + u.followers + '</b> seguidores</span>' +
        '<span class="chip">➡️ <b class="num">' + u.following + '</b> siguiendo</span>' +
        '<span class="chip">⚡ <b class="num">' + u.xp + '</b> XP</span>' +
        '<span class="chip">🪙 <b class="num">' + u.coins + '</b></span>' +
        (u.privado ? '<span class="chip">🔒 Perfil privado</span>' : '') +
      '</div>' +
      '<div class="row">' +
        '<button class="btn ' + (sigo ? 'ghost' : '') + '" onclick="toggleFollow(' + u.id + ',' + (sigo ? 0 : 1) + ')">' +
          (sigo ? 'Dejar de seguir' : 'Seguir') + '</button>' +
        (Number(u.mutual) ? '<button class="btn ghost" onclick="K12.abrirChat(' + u.id + ')">💬 Mensaje</button>' : '') +
      '</div>' +
    '</div>';

  if (u.privado){
    shell(u.name, '@' + u.username, cab +
      '<div class="empty"><div class="em">🔒</div><h3>Perfil privado</h3>' +
      '<p>Solo se ven sus seguidores, su nivel y sus monedas. Si os seguís mutuamente, pídele que lo ponga en público.</p></div>');
    return;
  }

  const lista = (t, html, vacio) => '<div class="card"><div class="card-h"><h3>' + t + '</h3></div>' +
    (html ? '<div class="lst">' + html + '</div>' : '<p class="hint" style="margin:0">' + vacio + '</p>') + '</div>';

  shell(u.name, '@' + u.username, cab +
    '<div class="card"><div class="card-h"><h3>Sus horarios</h3></div>' +
      '<div class="row" style="margin-bottom:9px">' +
        '<button class="btn ghost sm" onclick="K12.verHorario(' + u.id + ',0)">Ver el de mañana</button>' +
        '<button class="btn ghost sm" onclick="K12.verHorario(' + u.id + ',1)">Ver el de tarde</button>' +
      '</div>' +
      '<div class="row">' +
        '<button class="btn sm" onclick="K12.copiarHorario(' + u.id + ',0)">📋 Copiar mañana</button>' +
        '<button class="btn sm" onclick="K12.copiarHorario(' + u.id + ',1)">📋 Copiar tarde</button>' +
      '</div>' +
      '<p class="hint">Al copiar, tu horario actual se sustituye por el suyo.</p>' +
    '</div>' +
    lista('Sus hábitos', (u.habits || []).map(h =>
      '<div class="tile"><span class="av" style="background:' + esc(h.color) + '1F">' + esc(h.emoji) + '</span>' +
      '<span class="tx"><span class="tt">' + esc(h.title) + '</span>' +
      '<span class="ds">' + (Number(h.target) > 1 ? h.target + ' veces al día' : 'Una vez al día') +
      (h.hour ? ' · ' + esc(h.hour) : '') + '</span></span></div>').join(''), 'Todavía no tiene hábitos.') +
    lista('Sus exámenes', (u.exams || []).map(e =>
      '<div class="tile"><span class="av" style="background:' + esc(e.color) + '1F">' + esc(e.emoji) + '</span>' +
      '<span class="tx"><span class="tt">' + esc(e.subject) + '</span>' +
      '<span class="ds">' + fecha(e.day) + (e.title ? ' · ' + esc(e.title) : '') + '</span></span></div>').join(''),
      'No tiene exámenes apuntados.') +
    lista('Sus deberes', (u.hw || []).map(h =>
      '<div class="tile"><span class="av">📒</span><span class="tx">' +
      '<span class="tt"' + (Number(h.done) ? ' style="text-decoration:line-through;opacity:.6"' : '') + '>' + esc(h.title) + '</span>' +
      '<span class="ds">' + (h.subject ? esc(h.subject) + ' · ' : '') + fecha(h.due) + '</span></span></div>').join(''),
      'No tiene deberes apuntados.'));
};

K.verHorario = async function(id, kind){
  const r = await api('sched_share', { id, kind });
  if (!r) return;
  const sl = {}; r.slots.forEach(s => { sl[s.dow + ':' + s.idx] = s; });
  const cols = kind ? 7 : 5;
  const grid = '<div class="sched" style="grid-template-columns:40px repeat(' + cols + ',minmax(0,1fr))">' +
    '<div class="sh"></div>' + DOW7.slice(0, cols).map(d => '<div class="sh">' + d + '</div>').join('') +
    r.periods.map((p, i) => {
      let row = '<div class="st"><span>' + p.t1 + '</span><span style="opacity:.55">' + p.t2 + '</span></div>';
      for (let d = 0; d < cols; d++){
        const s = sl[d + ':' + i];
        row += '<div class="sc' + (Number(p.is_break) ? ' brk' : '') + '" style="' + (s ? 'background:' + s.color + '18;' : '') + '">' +
          (s ? '<span class="e">' + esc(s.emoji) + '</span><span class="s" style="color:' + esc(s.color) + '">' + esc(s.subject) + '</span>' : '') + '</div>';
      }
      return row;
    }).join('') + '</div>';
  sheet('<h2 style="margin-bottom:12px">Horario de ' + (kind ? 'tarde' : 'mañana') + '</h2>' +
    '<div style="overflow-x:auto;margin-bottom:14px">' + grid + '</div>' +
    '<button class="btn block" onclick="K12.copiarHorario(' + id + ',' + kind + ')">📋 Copiármelo</button>');
};
K.copiarHorario = async function(id, kind){
  if (!confirm('Esto sustituye tu horario de ' + (kind ? 'tarde' : 'mañana') + ' por el suyo. ¿Seguimos?')) return;
  const r = await api('sched_copy', { id, kind });
  if (!r) return;
  closeSheet(); toast('Horario copiado. Ya lo tienes en Horario.', 'good');
};

/* ===================================================================
   PERFIL PROPIO: privacidad
   =================================================================== */
const _viewProfile = window.viewProfile;
window.viewProfile = async function(){
  await _viewProfile();
  const wrap = q('.screen .wrap');
  if (!wrap) return;
  wrap.insertAdjacentHTML('beforeend',
    '<div class="card" style="margin-top:12px"><div class="card-h"><h3>Privacidad</h3></div>' +
      '<div class="lst"><div class="tile">' +
        '<span class="av">' + (K.privado ? '🔒' : '🌍') + '</span>' +
        '<span class="tx"><span class="tt">' + (K.privado ? 'Perfil privado' : 'Perfil público') + '</span>' +
        '<span class="ds">' + (K.privado
          ? 'Solo se ven seguidores, nivel y monedas.'
          : 'Se ven tus horarios, hábitos, exámenes y deberes.') + '</span></span>' +
        '<input type="checkbox" id="k_priv" ' + (K.privado ? '' : 'checked') + ' style="width:22px;height:22px;padding:0;flex:0 0 auto">' +
      '</div></div>' +
      '<p class="hint">El interruptor marcado significa perfil público.</p>' +
    '</div>' +
    '<button class="btn ghost block" style="margin-top:9px" onclick="K12.verPerfil(' + S.me.id + ')">Ver mi perfil como lo ven los demás</button>');
  q('#k_priv').onchange = async e => {
    const r = await api('k12_privacy', { privado: e.target.checked ? 0 : 1 });
    if (!r) return;
    K.privado = r.privado;
    toast(K.privado ? 'Perfil en privado' : 'Perfil en público', 'good');
    viewProfile();
  };
};

/* ===================================================================
   MÁS: entradas nuevas
   =================================================================== */
const _viewMore = window.viewMore;
window.viewMore = function(){
  _viewMore();
  const listas = document.querySelectorAll('.screen .lst');
  const l = listas[listas.length - 1];
  if (!l) return;
  const it = [
    ['exams','📚','Exámenes','Cuenta atrás y avisos'],
    ['hw','📒','Deberes','Las tareas del insti'],
    ['chats','💬','Mensajes','Habla con tus colegas' + (K.badges.msgs ? ' · ' + K.badges.msgs + ' sin leer' : '')],
    ['rank','🏆','Ranking semanal','Compite y llévate premios']
  ];
  l.insertAdjacentHTML('afterbegin', it.map(([v, e, t, d]) =>
    '<button class="tile" style="text-align:left;width:100%" onclick="go(\'' + v + '\')">' +
      '<span class="av">' + e + '</span><span class="tx"><span class="tt">' + t + '</span>' +
      '<span class="ds">' + d + '</span></span><span style="color:var(--muted);font-size:20px">›</span></button>').join(''));
};

/* ===================================================================
   NOTAS CON FOTOS
   =================================================================== */
const _viewNote = window.viewNote;
window.viewNote = function(){
  _viewNote();
  const wrap = q('.screen .wrap');
  if (!wrap) return;
  wrap.insertAdjacentHTML('beforeend',
    '<div class="card" style="margin-top:12px"><div class="card-h"><h3>Fotos de la nota</h3>' +
      '<button class="btn soft sm" onclick="q0(\'#nimg\').click()">+ Añadir</button></div>' +
      '<input id="nimg" type="file" accept="image/*" multiple class="sr">' +
      '<div id="ngal"><p class="hint" style="margin:0">Guarda la nota y añade las fotos que quieras: pizarras, ejercicios, lo que sea.</p></div>' +
    '</div>');
  if (!Array.isArray(S.note.imgs)) S.note.imgs = [];
  q('#nimg').onchange = e => {
    [...e.target.files].forEach(f => {
      const rd = new FileReader();
      rd.onload = () => shrink(rd.result, 1400, img => { (S.note.imgs = S.note.imgs || []).push(img); guardarFotos(); });
      rd.readAsDataURL(f);
    });
  };
  cargarFotos();
};
window.q0 = q;
async function cargarFotos(){
  if (!S.note || !S.note.id) return pintaFotos();
  const r = await api('note_imgs', { id: S.note.id });
  if (!r) return;
  S.note.imgs = r.imgs || [];
  pintaFotos();
}
async function guardarFotos(){
  if (!S.note.id){
    const s = await saveNote(false);
    if (!s) return toast('Escribe un título antes de meter fotos.', 'bad');
  }
  const r = await api('note_imgs', { id: S.note.id, imgs: S.note.imgs || [] });
  if (!r) return;
  S.note.imgs = r.imgs; pintaFotos(); toast('Foto guardada en la nota', 'good');
}
function pintaFotos(){
  const g = q('#ngal'); if (!g) return;
  const im = S.note.imgs || [];
  g.innerHTML = im.length
    ? '<div class="gal">' + im.map((s, i) =>
        '<div class="g"><img src="' + s + '" alt=""><button onclick="K12.quitarFoto(' + i + ')">✕</button></div>').join('') + '</div>' +
      '<button class="btn ghost block" style="margin-top:10px" onclick="K12.fotosANube()">☁️ Subir estas fotos a mi nube</button>'
    : '<p class="hint" style="margin:0">Aún no hay fotos en esta nota.</p>';
}
K.quitarFoto = function(i){ S.note.imgs.splice(i, 1); guardarFotos(); };
K.fotosANube = async function(){
  toast('Subiendo fotos...');
  const r = await api('cloud_photo', { imgs: S.note.imgs || [] });
  if (r) toast(r.subidas + (r.subidas === 1 ? ' foto subida a ' : ' fotos subidas a ') + r.carpeta, 'good');
};

/* ===================================================================
   ESCÁNER: guardar fotos en notas o en la nube
   =================================================================== */
const _viewScan = window.viewScan;
window.viewScan = function(){
  _viewScan();
  const wrap = q('.screen .wrap');
  if (!wrap || !S.scan.shots.length) return;
  wrap.insertAdjacentHTML('beforeend',
    '<div class="card"><div class="card-h"><h3>Guardar las fotos tal cual</h3></div>' +
      '<button class="btn ghost block" style="margin-bottom:9px" onclick="K12.fotosANota()">📝 Guardarlas en una nota</button>' +
      '<button class="btn ghost block" onclick="K12.scanANube()">☁️ Subirlas a mi nube</button>' +
      '<p class="hint">Sin pasarlas a texto: se guardan como imágenes.</p>' +
    '</div>');
};
K.fotosANota = async function(){
  const t = 'Fotos ' + new Date().toLocaleDateString('es-ES');
  const r = await api('note_save', { id: 0, title: t, body: '', tag: 'fotos' });
  if (!r) return;
  const i = await api('note_imgs', { id: r.id, imgs: S.scan.shots });
  if (!i) return;
  toast('Guardadas en Notas', 'good');
  S.scan = { shots: [], text: '' };
  go('notes');
};
K.scanANube = async function(){
  toast('Subiendo fotos...');
  const r = await api('cloud_photo', { imgs: S.scan.shots });
  if (r) toast(r.subidas + (r.subidas === 1 ? ' foto subida a ' : ' fotos subidas a ') + r.carpeta, 'good');
};

/* ===================================================================
   AJUSTES: avisos con la app cerrada + comprobar la nube
   =================================================================== */
const _viewSettings = window.viewSettings;
window.viewSettings = function(){
  _viewSettings();
  const wrap = q('.screen .wrap');
  if (!wrap) return;
  wrap.insertAdjacentHTML('afterbegin',
    '<div class="card"><div class="card-h"><h3>Avisos con la app cerrada</h3></div>' +
      '<p class="sub" style="margin-bottom:12px">Esto es lo que hace que te lleguen avisos aunque no tengas Krono abierta, como en WhatsApp.</p>' +
      '<button class="btn block" style="margin-bottom:9px" onclick="K12.activarPush()">Activar avisos en este dispositivo</button>' +
      '<button class="btn ghost block" onclick="K12.probarPush()">Enviarme un aviso de prueba</button>' +
      '<div class="divider"></div>' +
      '<b style="font-size:14px">Para que funcione de verdad</b>' +
      '<p style="font-size:13.5px;color:var(--ink-2);line-height:1.55;margin:6px 0 10px">' +
        'Tu servidor tiene que llamarse a sí mismo cada pocos minutos. En el panel de tu hosting busca <b>Cron jobs</b> ' +
        'o <b>Tareas programadas</b>, pon que se ejecute <b>cada 5 minutos</b> y pega esta dirección:</p>' +
      '<input class="mono" id="k_cron" readonly value="' + esc(K.cron) + '">' +
      '<button class="btn ghost block" style="margin-top:9px" onclick="K12.copiarCron()">Copiar la dirección del cron</button>' +
      '<p class="hint">En iPhone hace falta además que Krono esté añadida a la pantalla de inicio. Sin eso, Apple no deja que ninguna web mande avisos.</p>' +
    '</div>' +
    '<div class="card"><div class="card-h"><h3>Mi nube</h3></div>' +
      '<button class="btn ghost block" onclick="K12.probarNube()">Comprobar la conexión con la nube</button>' +
      '<p class="hint">Si el Cloud de EducaMadrid no te guarda nada, esto te dice exactamente por qué.</p>' +
    '</div>');
};
K.copiarCron = function(){
  const i = q('#k_cron'); i.select();
  navigator.clipboard ? navigator.clipboard.writeText(i.value).then(() => toast('Copiado', 'good'))
                      : (document.execCommand('copy'), toast('Copiado', 'good'));
};
function b64ToU8(s){
  const pad = '='.repeat((4 - s.length % 4) % 4);
  const b = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from([...b].map(c => c.charCodeAt(0)));
}
K.activarPush = async function(){
  if (!('serviceWorker' in navigator) || !('PushManager' in window))
    return toast('Este navegador no admite avisos en segundo plano.', 'bad');
  if (Notification.permission !== 'granted'){
    const p = await Notification.requestPermission();
    if (p !== 'granted') return toast('No has dado permiso para los avisos.', 'bad');
  }
  try {
    const reg = await navigator.serviceWorker.ready;
    const k = await api('push_pubkey'); if (!k) return;
    let sub = await reg.pushManager.getSubscription();
    if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToU8(k.key) });
    const r = await api('push_subscribe', { sub: sub.toJSON() });
    if (r) toast('Listo. Ya te llegarán avisos con la app cerrada.', 'good');
  } catch (e) { toast('No se ha podido activar: ' + e.message, 'bad'); }
};
K.probarPush = async function(){
  const r = await api('push_test');
  if (r) toast('Aviso enviado. Si no llega, revisa los pasos de arriba.', 'good');
};
K.probarNube = async function(){
  toast('Comprobando...');
  const r = await api('cloud_test');
  if (!r) return;
  sheet('<h2 style="margin-bottom:8px">' + (r.code === 207 || r.code === 200 ? '✅ Todo bien' : '⚠️ Hay un problema') + '</h2>' +
    '<p style="font-size:15px;color:var(--ink-2);line-height:1.55;margin-bottom:14px">' + esc(r.diag) + '</p>' +
    '<p class="hint" style="margin-bottom:16px">Código devuelto por la nube: ' + r.code + '</p>' +
    '<button class="btn block" onclick="closeSheet();go(\'cloud\')">Ir a Mi nube</button>');
};

/* ===================================================================
   ENCAMINADO DE VISTAS
   =================================================================== */
const MIAS = { exams: viewExams, hw: viewHW, chats: viewChats, chat: viewChat, rank: viewRank };
const _render = window.render;
window.render = function(){
  if (S.view !== 'chat') limpiaChat();
  if (S.me && MIAS[S.view]){ MIAS[S.view](); window.scrollTo(0, 0); return; }
  _render();
};
const _back = window.back;
window.back = function(){
  if (MIAS[S.view]) return go('more');
  _back();
};

/* ===================================================================
   ARRANQUE DEL MÓDULO
   =================================================================== */
async function arranca(){
  const r = await api('k12_boot');
  if (!r) return;
  if (!r.auth) return setTimeout(arranca, 1500);   // aún no ha entrado
  K.badges = r.badges; K.privado = r.privado; K.cron = r.cron || '';
  const b = q('.bell');
  if (b) b.outerHTML = bellHTML(); else render();

  if (r.nuevo) setTimeout(() => modalNovedades(r), 700);
  else if (K.badges.total) setTimeout(() => {
    const p = [];
    if (K.badges.followers.length) p.push(K.badges.followers.length + (K.badges.followers.length === 1 ? ' seguidor nuevo' : ' seguidores nuevos'));
    if (K.badges.msgs) p.push(K.badges.msgs + (K.badges.msgs === 1 ? ' mensaje sin leer' : ' mensajes sin leer'));
    banner('Tienes ' + p.join(' y ') + '.');
  }, 900);

  setInterval(async () => {
    const p = await api('k12_ping');
    if (!p) return;
    K.badges = p.badges;
    const bb = q('.bell'); if (bb) bb.outerHTML = bellHTML();
  }, 45000);
}

function modalNovedades(r){
  sheet('<div style="text-align:center;padding:4px 0 12px">' +
      '<div style="font-size:46px">🚀</div>' +
      '<h2 style="margin:8px 0 4px">Nueva actualización</h2>' +
      '<div style="display:inline-block;background:var(--orange);color:#fff;border-radius:99px;' +
        'padding:4px 14px;font-weight:800;font-size:14px;margin-top:6px">Krono ' + esc(r.version) + '</div>' +
    '</div>' +
    '<div class="card flat" style="padding:4px 12px">' +
      r.changelog.map(c => '<div class="updrow"><span class="e">' + c[0] + '</span>' +
        '<span><b>' + esc(c[1]) + '</b><span>' + esc(c[2]) + '</span></span></div>').join('') +
    '</div>' +
    '<button class="btn block" style="margin-top:14px" onclick="K12.verNovedades()">Entendido, a probarlo</button>');
}
K.verNovedades = async function(){
  await api('k12_seen');
  closeSheet();
  toast('¡Bienvenido a Krono 1.2.0!', 'good');
  render();
};

/* arrancamos cuando el resto de la app ya ha cargado */
setTimeout(arranca, 400);
})();
JS;
}

/* =============================================================
   API JSON
   ============================================================= */
if (isset($_GET['api'])) {
    $a = $_GET['api'];
    $in = body();
    $p  = db();

    try {
        switch ($a) {

        /* ---- Cuenta ---- */
        case 'register': {
            $un = strtolower(preg_replace('/[^a-z0-9._]/i', '', s($in, 'username')));
            $pw = (string)($in['pass'] ?? '');
            $nm = s($in, 'name');
            if (mb_strlen($un) < 3 || mb_strlen($un) > 20) fail('El usuario necesita entre 3 y 20 caracteres (letras, números, punto o guion bajo).');
            if (strlen($pw) < 6) fail('La contraseña necesita al menos 6 caracteres.');
            $ex = $p->prepare("SELECT id FROM users WHERE username=?"); $ex->execute([$un]);
            if ($ex->fetch()) fail('Ese usuario ya está cogido. Prueba otro.');
            $p->prepare("INSERT INTO users(username,name,pass,created,last_day) VALUES(?,?,?,?,'')")
              ->execute([$un, $nm !== '' ? $nm : $un, password_hash($pw, PASSWORD_DEFAULT), today()]);
            $u = (int)$p->lastInsertId();
            $_SESSION['uid'] = $u;
            seed_periods($u);
            award($u, 20, 50);
            touch_streak($u);
            j(['ok' => true, 'me' => me_row($u)]);
        }

        case 'login': {
            $un = strtolower(s($in, 'username'));
            $pw = (string)($in['pass'] ?? '');
            $st = $p->prepare("SELECT * FROM users WHERE username=?"); $st->execute([$un]);
            $r = $st->fetch();
            if (!$r || !password_verify($pw, $r['pass'])) fail('Usuario o contraseña incorrectos.');
            $_SESSION['uid'] = (int)$r['id'];
            seed_periods((int)$r['id']);
            touch_streak((int)$r['id']);
            j(['ok' => true, 'me' => me_row((int)$r['id'])]);
        }

        case 'logout': { $_SESSION = []; session_destroy(); j(['ok' => true]); }

        case 'me': {
            $u = uid();
            if (!$u) j(['ok' => true, 'me' => null]);
            touch_streak($u);
            $pr = $p->prepare("SELECT j FROM prefs WHERE uid=?"); $pr->execute([$u]);
            $prefs = json_decode(($pr->fetch()['j'] ?? '{}'), true) ?: [];
            j(['ok' => true, 'me' => me_row($u), 'stats' => stats_of($u), 'prefs' => $prefs]);
        }

        case 'profile_save': {
            $u = need_auth();
            $nm = mb_substr(s($in, 'name'), 0, 40);
            $bio = mb_substr(s($in, 'bio'), 0, 160);
            $av = (string)($in['avatar'] ?? '');
            if ($av !== '' && strlen($av) > 400000) fail('La foto es demasiado grande. Prueba con una más pequeña.');
            $p->prepare("UPDATE users SET name=?,bio=?,avatar=? WHERE id=?")->execute([$nm, $bio, $av, $u]);
            j(['ok' => true, 'me' => me_row($u)]);
        }

        case 'pass_change': {
            $u = need_auth();
            $old = (string)($in['old'] ?? ''); $new = (string)($in['new'] ?? '');
            if (strlen($new) < 6) fail('La contraseña nueva necesita al menos 6 caracteres.');
            $st = $p->prepare("SELECT pass FROM users WHERE id=?"); $st->execute([$u]);
            if (!password_verify($old, $st->fetch()['pass'])) fail('La contraseña actual no coincide.');
            $p->prepare("UPDATE users SET pass=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $u]);
            j(['ok' => true]);
        }

        case 'prefs_save': {
            $u = need_auth();
            $jx = json_encode($in['prefs'] ?? [], JSON_UNESCAPED_UNICODE);
            $p->prepare("INSERT INTO prefs(uid,j) VALUES(?,?) ON CONFLICT(uid) DO UPDATE SET j=excluded.j")
              ->execute([$u, $jx]);
            j(['ok' => true]);
        }

        /* ---- Calendario ---- */
        case 'events': {
            $u = need_auth();
            $from = s($in, 'from', today()); $to = s($in, 'to', today());
            $st = $p->prepare("SELECT * FROM events WHERE uid=? AND day BETWEEN ? AND ? ORDER BY day,allday DESC,t1");
            $st->execute([$u, $from, $to]);
            j(['ok' => true, 'events' => $st->fetchAll()]);
        }

        case 'event_save': {
            $u = need_auth();
            $id = n($in, 'id');
            $title = s($in, 'title') ?: 'Sin título';
            $descr = mb_substr(s($in, 'descr'), 0, 2000);
            $emoji = s($in, 'emoji', '📌');
            $color = s($in, 'color', '#FC6C26');
            $t1 = s($in, 't1', '09:00'); $t2 = s($in, 't2', '10:00');
            $allday = n($in, 'allday'); $remind = n($in, 'remind', 10);
            $extraDays = array_values(array_filter(array_map('strval', (array)($in['days'] ?? []))));

            if ($id) {
                $p->prepare("UPDATE events SET title=?,descr=?,emoji=?,color=?,day=?,t1=?,t2=?,allday=?,remind=? WHERE id=? AND uid=?")
                  ->execute([$title, $descr, $emoji, $color, s($in, 'day', today()), $t1, $t2, $allday, $remind, $id, $u]);
                j(['ok' => true, 'id' => $id]);
            }
            $days = $extraDays ?: [s($in, 'day', today())];
            $grp = count($days) > 1 ? bin2hex(random_bytes(6)) : '';
            $ins = $p->prepare("INSERT INTO events(uid,title,descr,emoji,color,day,t1,t2,allday,remind,grp) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
            $firstId = 0;
            foreach ($days as $d) {
                $ins->execute([$u, $title, $descr, $emoji, $color, $d, $t1, $t2, $allday, $remind, $grp]);
                if (!$firstId) $firstId = (int)$p->lastInsertId();
            }
            award($u, 5, 2);
            j(['ok' => true, 'id' => $firstId, 'created' => count($days)]);
        }

        case 'event_done': {
            $u = need_auth(); $id = n($in, 'id'); $v = n($in, 'done');
            $p->prepare("UPDATE events SET done=? WHERE id=? AND uid=?")->execute([$v, $id, $u]);
            award($u, $v ? 12 : -12, $v ? 4 : -4);
            if ($v) touch_streak($u);
            j(['ok' => true, 'me' => me_row($u)]);
        }

        case 'event_del': {
            $u = need_auth();
            $p->prepare("DELETE FROM events WHERE id=? AND uid=?")->execute([n($in, 'id'), $u]);
            j(['ok' => true]);
        }

        /* ---- Horario ---- */
        case 'schedule': {
            $u = need_auth(); seed_periods($u);
            $pe = $p->prepare("SELECT * FROM periods WHERE uid=? ORDER BY idx"); $pe->execute([$u]);
            $sl = $p->prepare("SELECT * FROM slots WHERE uid=?"); $sl->execute([$u]);
            j(['ok' => true, 'periods' => $pe->fetchAll(), 'slots' => $sl->fetchAll()]);
        }

        case 'slot_save': {
            $u = need_auth();
            $dow = n($in, 'dow'); $idx = n($in, 'idx');
            $sub = mb_substr(s($in, 'subject'), 0, 40);
            if ($sub === '') {
                $p->prepare("DELETE FROM slots WHERE uid=? AND dow=? AND idx=?")->execute([$u, $dow, $idx]);
                j(['ok' => true]);
            }
            $p->prepare("INSERT INTO slots(uid,dow,idx,subject,room,emoji,color) VALUES(?,?,?,?,?,?,?)
                         ON CONFLICT(uid,dow,idx) DO UPDATE SET subject=excluded.subject,room=excluded.room,emoji=excluded.emoji,color=excluded.color")
              ->execute([$u, $dow, $idx, $sub, mb_substr(s($in, 'room'), 0, 20), s($in, 'emoji', '📘'), s($in, 'color', '#FC6C26')]);
            j(['ok' => true]);
        }

        case 'period_save': {
            $u = need_auth();
            $rows = $in['periods'] ?? [];
            if (!is_array($rows) || !$rows) fail('No hay tramos que guardar.');
            $p->prepare("DELETE FROM periods WHERE uid=?")->execute([$u]);
            $st = $p->prepare("INSERT INTO periods(uid,idx,label,t1,t2,is_break) VALUES(?,?,?,?,?,?)");
            foreach (array_values($rows) as $i => $r) {
                $st->execute([$u, $i, mb_substr(s($r, 'label', 'Hora'), 0, 20),
                              s($r, 't1', '08:00'), s($r, 't2', '09:00'), n($r, 'is_break')]);
            }
            j(['ok' => true]);
        }

        /* ---- Hábitos ---- */
        case 'habits': {
            $u = need_auth();
            $day = s($in, 'day', today());
            $dow = (int)date('N', strtotime($day)); // 1=lunes .. 7=domingo
            $st = $p->prepare("SELECT h.*, COALESCE(l.n,0) AS n FROM habits h
                               LEFT JOIN logs l ON l.hid=h.id AND l.day=?
                               WHERE h.uid=? AND h.archived=0 ORDER BY h.pos,h.id");
            $st->execute([$day, $u]);
            $hs = array_values(array_filter($st->fetchAll(), function ($h) use ($dow) {
                $days = array_filter(explode(',', $h['days']));
                return !$days || in_array((string)$dow, $days, true); // sin días = todos los días
            }));
            // Últimos 7 días para la mini-racha
            $hist = [];
            $hq = $p->prepare("SELECT hid,day,n FROM logs WHERE uid=? AND day>=? AND n>0");
            $hq->execute([$u, date('Y-m-d', strtotime($day . ' -30 day'))]);
            foreach ($hq->fetchAll() as $r) $hist[$r['hid']][] = $r['day'];
            j(['ok' => true, 'habits' => $hs, 'hist' => $hist, 'day' => $day]);
        }

        case 'habit_save': {
            $u = need_auth(); $id = n($in, 'id');
            $t = mb_substr(s($in, 'title'), 0, 40);
            if ($t === '') fail('Ponle un nombre al hábito.');
            $days = array_values(array_filter(array_map('strval', (array)($in['days'] ?? []))));
            $f = [$t, s($in, 'emoji', '✅'), s($in, 'color', '#FC6C26'), max(1, n($in, 'target', 1)), s($in, 'hour'), implode(',', $days)];
            if ($id) {
                $f[] = $id; $f[] = $u;
                $p->prepare("UPDATE habits SET title=?,emoji=?,color=?,target=?,hour=?,days=? WHERE id=? AND uid=?")->execute($f);
            } else {
                array_unshift($f, $u);
                $p->prepare("INSERT INTO habits(uid,title,emoji,color,target,hour,days) VALUES(?,?,?,?,?,?,?)")->execute($f);
                $id = (int)$p->lastInsertId();
                award($u, 5, 2);
            }
            j(['ok' => true, 'id' => $id]);
        }

        case 'habit_tick': {
            $u = need_auth();
            $hid = n($in, 'id'); $day = s($in, 'day', today()); $dir = n($in, 'dir', 1);
            $g = $p->prepare("SELECT target FROM habits WHERE id=? AND uid=?"); $g->execute([$hid, $u]);
            $row = $g->fetch(); if (!$row) fail('Ese hábito ya no existe.');
            $cur = $p->prepare("SELECT n FROM logs WHERE hid=? AND day=?"); $cur->execute([$hid, $day]);
            $c = (int)($cur->fetch()['n'] ?? 0);
            $new = max(0, min((int)$row['target'], $c + $dir));
            $p->prepare("INSERT INTO logs(uid,hid,day,n) VALUES(?,?,?,?) ON CONFLICT(hid,day) DO UPDATE SET n=excluded.n")
              ->execute([$u, $hid, $day, $new]);
            if ($new > $c) { award($u, 10, 3); touch_streak($u); }
            elseif ($new < $c) award($u, -10, -3);
            j(['ok' => true, 'n' => $new, 'me' => me_row($u)]);
        }

        case 'habit_del': {
            $u = need_auth(); $id = n($in, 'id');
            $p->prepare("DELETE FROM logs WHERE hid=? AND uid=?")->execute([$id, $u]);
            $p->prepare("DELETE FROM habits WHERE id=? AND uid=?")->execute([$id, $u]);
            j(['ok' => true]);
        }

        /* ---- Notas ---- */
        case 'notes': {
            $u = need_auth();
            $st = $p->prepare("SELECT id,title,tag,cloud,updated,substr(body,1,140) AS preview FROM notes WHERE uid=? ORDER BY updated DESC");
            $st->execute([$u]);
            j(['ok' => true, 'notes' => $st->fetchAll()]);
        }

        case 'note_get': {
            $u = need_auth();
            $st = $p->prepare("SELECT * FROM notes WHERE id=? AND uid=?"); $st->execute([n($in, 'id'), $u]);
            $r = $st->fetch(); if (!$r) fail('Esa nota no existe.', 404);
            j(['ok' => true, 'note' => $r]);
        }

        case 'note_save': {
            $u = need_auth(); $id = n($in, 'id');
            $t = mb_substr(s($in, 'title'), 0, 80) ?: 'Sin título';
            $b = (string)($in['body'] ?? '');
            $tag = mb_substr(s($in, 'tag'), 0, 24);
            $now = date('Y-m-d H:i:s');
            if ($id) {
                $p->prepare("UPDATE notes SET title=?,body=?,tag=?,updated=? WHERE id=? AND uid=?")
                  ->execute([$t, $b, $tag, $now, $id, $u]);
            } else {
                $p->prepare("INSERT INTO notes(uid,title,body,tag,updated) VALUES(?,?,?,?,?)")
                  ->execute([$u, $t, $b, $tag, $now]);
                $id = (int)$p->lastInsertId();
                award($u, 8, 3);
            }
            j(['ok' => true, 'id' => $id, 'updated' => $now]);
        }

        case 'note_del': {
            $u = need_auth();
            $p->prepare("DELETE FROM notes WHERE id=? AND uid=?")->execute([n($in, 'id'), $u]);
            j(['ok' => true]);
        }

        /* ---- Nube personal (WebDAV / Nextcloud / EducaMadrid) ---- */
        case 'cloud_get': {
            $u = need_auth();
            $st = $p->prepare("SELECT url,user,folder,(pass<>'') AS has FROM cloud WHERE uid=?");
            $st->execute([$u]);
            j(['ok' => true, 'cloud' => $st->fetch() ?: ['url' => '', 'user' => '', 'folder' => 'Krono', 'has' => 0]]);
        }

        case 'cloud_save': {
            $u = need_auth();
            $url = rtrim(s($in, 'url'), '/');
            $usr = s($in, 'user');
            $pw  = (string)($in['pass'] ?? '');
            $fo  = trim(s($in, 'folder', 'Krono'), '/');
            if ($url !== '' && !preg_match('~^https://~i', $url)) fail('La dirección de la nube debe empezar por https://');
            $ex = $p->prepare("SELECT pass FROM cloud WHERE uid=?"); $ex->execute([$u]);
            $old = $ex->fetch()['pass'] ?? '';
            if ($pw === '') $pw = $old;
            $p->prepare("INSERT INTO cloud(uid,url,user,pass,folder) VALUES(?,?,?,?,?)
                         ON CONFLICT(uid) DO UPDATE SET url=excluded.url,user=excluded.user,pass=excluded.pass,folder=excluded.folder")
              ->execute([$u, $url, $usr, $pw, $fo ?: 'Krono']);
            j(['ok' => true]);
        }

        case 'cloud_upload': {
            $u = need_auth();
            $st = $p->prepare("SELECT * FROM cloud WHERE uid=?"); $st->execute([$u]);
            $c = $st->fetch();
            if (!$c || $c['url'] === '' || $c['user'] === '') fail('Conecta tu nube en Ajustes antes de subir nada.');
            if (!function_exists('curl_init')) fail('Este servidor no tiene cURL activado, así que no puede subir a la nube.');

            $name = preg_replace('~[\\\\/:*?"<>|]~', '-', s($in, 'name', 'krono.txt'));
            $data = base64_decode((string)($in['b64'] ?? ''), true);
            if ($data === false || $data === '') fail('El archivo llegó vacío.');
            if (strlen($data) > 20 * 1024 * 1024) fail('El archivo pasa de 20 MB.');

            $base = rtrim($c['url'], '/');
            $dir  = rawurlencode($c['folder']);
            $auth = $c['user'] . ':' . $c['pass'];

            // Crear carpeta si no existe (MKCOL devuelve 405 si ya está)
            $ch = curl_init("$base/$dir");
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'MKCOL', CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => $auth, CURLOPT_TIMEOUT => 20,
            ]);
            curl_exec($ch); curl_close($ch);

            $target = "$base/$dir/" . rawurlencode($name);
            $ch = curl_init($target);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => $data, CURLOPT_USERPWD => $auth,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Expect:'],
            ]);
            $res = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($code >= 200 && $code < 300) {
                if (n($in, 'note_id')) {
                    $p->prepare("UPDATE notes SET cloud=? WHERE id=? AND uid=?")
                      ->execute([date('Y-m-d H:i'), n($in, 'note_id'), $u]);
                }
                award($u, 6, 2);
                j(['ok' => true, 'path' => $c['folder'] . '/' . $name]);
            }
            if ($code === 401) fail('La nube rechazó el usuario o la contraseña.');
            if ($code === 409) fail('No existe la carpeta de destino en tu nube.');
            fail('La nube respondió con el código ' . $code . ($err ? " ($err)" : '') . '.');
        }

        /* ---- Colegas ---- */
        case 'search': {
            $u = need_auth();
            $q = s($in, 'q');
            if (mb_strlen($q) < 2) j(['ok' => true, 'users' => []]);
            $st = $p->prepare("SELECT id,username,name,avatar,xp,streak FROM users
                               WHERE (username LIKE ? OR name LIKE ?) AND id<>? LIMIT 20");
            $st->execute(["%$q%", "%$q%", $u]);
            $rows = $st->fetchAll();
            foreach ($rows as &$r) {
                $f = $p->prepare("SELECT 1 FROM follows WHERE a=? AND b=?"); $f->execute([$u, $r['id']]);
                $r['following'] = $f->fetch() ? 1 : 0;
                $r += level_of((int)$r['xp']);
            }
            j(['ok' => true, 'users' => $rows]);
        }

        case 'follow': {
            $u = need_auth(); $b = n($in, 'id'); $on = n($in, 'on');
            if ($b === $u) fail('No puedes seguirte a ti mismo.');
            if ($on) {
                $p->prepare("INSERT OR IGNORE INTO follows(a,b) VALUES(?,?)")->execute([$u, $b]);
                award($u, 2, 1);
            } else {
                $p->prepare("DELETE FROM follows WHERE a=? AND b=?")->execute([$u, $b]);
            }
            j(['ok' => true]);
        }

        case 'user': {
            $u = need_auth(); $id = n($in, 'id');
            $st = $p->prepare("SELECT id,username,name,bio,avatar,xp,coins,streak,best_streak,created FROM users WHERE id=?");
            $st->execute([$id]); $r = $st->fetch();
            if (!$r) fail('Ese perfil no existe.', 404);
            $r += level_of((int)$r['xp']);
            $f = $p->prepare("SELECT 1 FROM follows WHERE a=? AND b=?"); $f->execute([$u, $id]);
            j(['ok' => true, 'user' => $r, 'stats' => stats_of($id), 'following' => $f->fetch() ? 1 : 0]);
        }

        case 'friends': {
            $u = need_auth();
            $st = $p->prepare("SELECT u.id,u.username,u.name,u.avatar,u.xp,u.streak FROM follows f
                               JOIN users u ON u.id=f.b WHERE f.a=? ORDER BY u.xp DESC");
            $st->execute([$u]);
            $rows = $st->fetchAll();
            foreach ($rows as &$r) $r += level_of((int)$r['xp']);
            // Ranking: yo + a quien sigo
            $me = me_row($u); $me['username'] = $me['username'];
            $board = $rows; $board[] = $me;
            usort($board, fn($x, $y) => (int)$y['xp'] <=> (int)$x['xp']);
            j(['ok' => true, 'friends' => $rows, 'board' => array_slice($board, 0, 25), 'meid' => $u]);
        }

        /* ---- Modo enfoque ---- */
        case 'focus_list': {
            $u = need_auth();
            $st = $p->prepare("SELECT * FROM focus WHERE uid=? ORDER BY t1"); $st->execute([$u]);
            j(['ok' => true, 'focus' => $st->fetchAll()]);
        }

        case 'focus_save': {
            $u = need_auth(); $id = n($in, 'id');
            $f = [mb_substr(s($in, 'title', 'Enfoque'), 0, 30), s($in, 't1', '16:00'), s($in, 't2', '18:00'),
                  s($in, 'days', '1,2,3,4,5'), n($in, 'active', 1)];
            if ($id) { $f[] = $id; $f[] = $u;
                $p->prepare("UPDATE focus SET title=?,t1=?,t2=?,days=?,active=? WHERE id=? AND uid=?")->execute($f);
            } else { array_unshift($f, $u);
                $p->prepare("INSERT INTO focus(uid,title,t1,t2,days,active) VALUES(?,?,?,?,?,?)")->execute($f);
                $id = (int)$p->lastInsertId();
            }
            j(['ok' => true, 'id' => $id]);
        }

        case 'focus_del': {
            $u = need_auth();
            $p->prepare("DELETE FROM focus WHERE id=? AND uid=?")->execute([n($in, 'id'), $u]);
            j(['ok' => true]);
        }

        case 'focus_reward': {
            $u = need_auth();
            $mins = max(1, min(240, n($in, 'mins')));
            award($u, $mins, (int)floor($mins / 5));
            touch_streak($u);
            j(['ok' => true, 'me' => me_row($u)]);
        }

        /* ---- Exportar todo ---- */
        case 'export': {
            $u = need_auth();
            $out = ['exported' => date('c'), 'user' => me_row($u)];
            foreach (['events', 'slots', 'periods', 'habits', 'logs', 'notes', 'focus'] as $t) {
                $st = $p->prepare("SELECT * FROM $t WHERE uid=?"); $st->execute([$u]);
                $out[$t] = $st->fetchAll();
            }
            j(['ok' => true, 'data' => $out]);
        }

        /* ---- Exámenes ---- */
        case 'exams': {
            $u = need_auth();
            $st = $p->prepare("SELECT * FROM exams WHERE uid=? ORDER BY day"); $st->execute([$u]);
            j(['ok' => true, 'exams' => $st->fetchAll()]);
        }
        case 'exam_save': {
            $u = need_auth(); $id = n($in, 'id');
            $sub = mb_substr(s($in, 'subject'), 0, 40);
            if ($sub === '') fail('Ponle la asignatura al examen.');
            $f = [$sub, mb_substr(s($in, 'title'), 0, 60), s($in, 'day', today()),
                  mb_substr(s($in, 'notes'), 0, 1000), s($in, 'emoji', '📚'), s($in, 'color', '#D8452F')];
            if ($id) { $f[] = $id; $f[] = $u;
                $p->prepare("UPDATE exams SET subject=?,title=?,day=?,notes=?,emoji=?,color=? WHERE id=? AND uid=?")->execute($f);
            } else { array_unshift($f, $u);
                $p->prepare("INSERT INTO exams(uid,subject,title,day,notes,emoji,color) VALUES(?,?,?,?,?,?,?)")->execute($f);
                $id = (int)$p->lastInsertId(); award($u, 5, 2);
            }
            j(['ok' => true, 'id' => $id]);
        }
        case 'exam_del': {
            $u = need_auth();
            $p->prepare("DELETE FROM exams WHERE id=? AND uid=?")->execute([n($in, 'id'), $u]);
            j(['ok' => true]);
        }

        /* ---- Rutina de tarde (16:00–22:00, seis tramos por hora) ---- */
        case 'routine': {
            $u = need_auth();
            $st = $p->prepare("SELECT * FROM routine WHERE uid=?"); $st->execute([$u]);
            j(['ok' => true, 'routine' => $st->fetchAll()]);
        }
        case 'routine_save': {
            $u = need_auth();
            $dow = n($in, 'dow'); $idx = n($in, 'idx');
            $t = mb_substr(s($in, 'title'), 0, 30);
            if ($t === '') {
                $p->prepare("DELETE FROM routine WHERE uid=? AND dow=? AND idx=?")->execute([$u, $dow, $idx]);
                j(['ok' => true]);
            }
            $p->prepare("INSERT INTO routine(uid,dow,idx,title,emoji,color) VALUES(?,?,?,?,?,?)
                         ON CONFLICT(uid,dow,idx) DO UPDATE SET title=excluded.title,emoji=excluded.emoji,color=excluded.color")
              ->execute([$u, $dow, $idx, $t, s($in, 'emoji', '🕓'), s($in, 'color', '#8A5CD6')]);
            j(['ok' => true]);
        }
        case 'routine_copy': {
            $u = need_auth(); $from = n($in, 'from');
            $st = $p->prepare("SELECT * FROM routine WHERE uid=? AND dow=?"); $st->execute([$u, $from]);
            $rows = $st->fetchAll();
            $ins = $p->prepare("INSERT INTO routine(uid,dow,idx,title,emoji,color) VALUES(?,?,?,?,?,?)
                                 ON CONFLICT(uid,dow,idx) DO UPDATE SET title=excluded.title,emoji=excluded.emoji,color=excluded.color");
            for ($d = 0; $d < 5; $d++) {
                if ($d === $from) continue;
                foreach ($rows as $r) $ins->execute([$u, $d, $r['idx'], $r['title'], $r['emoji'], $r['color']]);
            }
            j(['ok' => true]);
        }

        /* ---- Logros: comprobar y avisar de los nuevos ---- */
        case 'achievements_check': {
            $u = need_auth();
            $me = me_row($u); $st2 = stats_of($u);
            $have = []; $q = $p->prepare("SELECT badge FROM unlocked WHERE uid=?"); $q->execute([$u]);
            foreach ($q->fetchAll() as $r) $have[$r['badge']] = 1;
            $newly = [];
            foreach (ACHIEVEMENTS as $key => $a) {
                if (isset($have[$key])) continue;
                if (achievement_ok($key, $me, $st2)) {
                    $p->prepare("INSERT OR IGNORE INTO unlocked(uid,badge,created) VALUES(?,?,?)")->execute([$u, $key, date('c')]);
                    $newly[] = $key;
                    push_to_user($u, '🏅 ¡Logro conseguido!', $a[1], 'badge-' . $key);
                }
            }
            j(['ok' => true, 'newly' => $newly]);
        }

        /* ---- Notificaciones push (VAPID) ---- */
        case 'push_pubkey': {
            $vk = vapid_keys();
            if (!$vk['ok']) fail('Este servidor no puede generar claves push (falta la extensión OpenSSL).');
            j(['ok' => true, 'key' => $vk['pub']]);
        }
        case 'push_subscribe': {
            $u = need_auth();
            $sub = $in['sub'] ?? null;
            if (!$sub || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth']))
                fail('Suscripción incompleta.');
            $p->prepare("INSERT INTO push_subs(uid,endpoint,p256dh,auth,created) VALUES(?,?,?,?,?)
                         ON CONFLICT(endpoint) DO UPDATE SET uid=excluded.uid,p256dh=excluded.p256dh,auth=excluded.auth")
              ->execute([$u, $sub['endpoint'], $sub['keys']['p256dh'], $sub['keys']['auth'], date('c')]);
            j(['ok' => true]);
        }
        case 'push_unsubscribe': {
            $u = need_auth();
            $p->prepare("DELETE FROM push_subs WHERE uid=? AND endpoint=?")->execute([$u, s($in, 'endpoint')]);
            j(['ok' => true]);
        }
        case 'push_test': {
            $u = need_auth();
            push_to_user($u, '🔔 Prueba de Krono', 'Si ves esto, las notificaciones funcionan incluso con la app cerrada.');
            j(['ok' => true]);
        }

        /* ---- Iniciar sesión con Google ---- */
        case 'google_login': {
            if (GOOGLE_CLIENT_ID === '') fail('El inicio de sesión con Google no está configurado todavía en el servidor.');
            $payload = google_verify(s($in, 'credential'));
            if (!$payload) fail('No se pudo verificar la cuenta de Google.');
            $sub = $payload['sub']; $email = $payload['email'] ?? ''; $name = $payload['name'] ?? 'Usuario';
            $st = $p->prepare("SELECT id FROM users WHERE google_sub=?"); $st->execute([$sub]);
            $row = $st->fetch();
            if ($row) { $uid = (int)$row['id']; }
            else {
                $base = strtolower(preg_replace('/[^a-z0-9]/i', '', explode('@', $email)[0] ?: 'usuario'));
                $un = $base; $i = 1;
                while (true) {
                    $c = $p->prepare("SELECT id FROM users WHERE username=?"); $c->execute([$un]);
                    if (!$c->fetch()) break;
                    $un = $base . $i; $i++;
                }
                $p->prepare("INSERT INTO users(username,name,pass,email,google_sub,created,last_day) VALUES(?,?,?,?,?,?,'')")
                  ->execute([$un, $name, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $email, $sub, today()]);
                $uid = (int)$p->lastInsertId();
                seed_periods($uid); award($uid, 20, 50);
            }
            $_SESSION['uid'] = $uid; touch_streak($uid);
            j(['ok' => true, 'me' => me_row($uid)]);
        }

        /* ---- Clave de IA personal + lectura de apuntes con IA ---- */
        case 'ai_key_save': {
            $u = need_auth();
            $p->prepare("UPDATE users SET ai_key=? WHERE id=?")->execute([(string)($in['key'] ?? ''), $u]);
            j(['ok' => true]);
        }
        case 'ai_key_status': {
            $u = need_auth();
            $st = $p->prepare("SELECT ai_key FROM users WHERE id=?"); $st->execute([$u]);
            j(['ok' => true, 'has' => (($st->fetch()['ai_key'] ?? '') !== '')]);
        }
        case 'ai_ocr': {
            $u = need_auth();
            $st = $p->prepare("SELECT ai_key FROM users WHERE id=?"); $st->execute([$u]);
            $key = $st->fetch()['ai_key'] ?? '';
            if ($key === '') fail('Pon tu clave de la API de Anthropic en Ajustes para usar la lectura con IA.');
            $images = $in['images'] ?? [];
            if (!$images) fail('No hay fotos que leer.');
            $content = [['type' => 'text', 'text' =>
                'Transcribe TODO el texto manuscrito o impreso de estas fotos de apuntes, tal cual está escrito, ' .
                'respetando párrafos y saltos de línea. No resumas, no comentes, no añadas nada tuyo. ' .
                'Si hay varias páginas, sepáralas con una línea "— página N —".']];
            foreach ($images as $img) {
                if (!preg_match('~^data:image/(\w+);base64,(.+)$~', (string)$img, $mm)) continue;
                $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/' . $mm[1], 'data' => $mm[2]]];
            }
            if (count($content) < 2) fail('Las imágenes no llegaron en un formato válido.');
            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => 'claude-sonnet-4-6', 'max_tokens' => 4000,
                    'messages' => [['role' => 'user', 'content' => $content]],
                ]),
            ]);
            $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            $data = json_decode((string)$res, true);
            if ($code !== 200) fail('La IA respondió con un error (' . $code . '). Revisa que tu clave sea correcta y tenga saldo.');
            $text = '';
            foreach (($data['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
            award($u, 6, 2);
            j(['ok' => true, 'text' => trim($text)]);
        }

        /* ---- Tarea programada (cron externo) ---- */
        case 'cron_tick': {
            if (!hash_equals(CRON_SECRET, s($in, 'secret', (string)($_GET['secret'] ?? '')))) fail('Token incorrecto.', 403);
            $sent = run_cron_tick();
            j(['ok' => true, 'sent' => $sent]);
        }

        default: fail('Acción desconocida: ' . htmlspecialchars($a), 404);
        }
    } catch (Throwable $e) {
        fail('Error del servidor: ' . $e->getMessage(), 500);
    }
}

/* ---- Arranque de la interfaz ---- */
db();
$U = uid();
$ME = $U ? me_row($U) : null;
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Krono</title>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
<meta name="theme-color" content="#FC6C26">
<meta name="description" content="Krono: tu tiempo, organizado.">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Krono">
<link rel="manifest" href="?r=manifest">
<link rel="apple-touch-icon" href="?r=icon&s=192">
<link rel="icon" href="?r=icon&s=192">
<style>
/* ============ TOKENS ============ */
:root{
  --orange:#FC6C26; --orange-d:#D9540F; --orange-soft:#FFE3D2;
  --vanilla:#FFF4D6; --paper:#FFFCF3; --card:#FFFFFF;
  --ink:#241A12; --ink-2:#4A3B2C; --muted:#8C7C63;
  --line:#EFE1C2; --line-2:#F7EDD6;
  --green:#1F9A6D; --red:#D8452F; --blue:#3B6FD4;
  --r-s:10px; --r-m:16px; --r-l:22px;
  --tab-h:64px;
  --safe-b:env(safe-area-inset-bottom,0px);
  --safe-t:env(safe-area-inset-top,0px);
  --shadow:0 1px 2px rgba(60,40,15,.06), 0 8px 24px -12px rgba(60,40,15,.18);
}
*,*::before,*::after{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{
  margin:0;padding:0;width:100%;max-width:100%;
  overflow-x:hidden;overscroll-behavior-x:none;
}
body{
  background:var(--vanilla);color:var(--ink);
  font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","Segoe UI",Roboto,system-ui,sans-serif;
  font-size:16px;line-height:1.45;
  -webkit-font-smoothing:antialiased;
  touch-action:pan-y;
  -webkit-text-size-adjust:100%;
}
img{max-width:100%;display:block}
button,input,textarea,select{font:inherit;color:inherit}
button{border:0;background:none;cursor:pointer;padding:0}
input,textarea,select{
  width:100%;background:var(--card);border:1.5px solid var(--line);
  border-radius:var(--r-s);padding:12px 13px;outline:none;
  transition:border-color .15s, box-shadow .15s;
}
input:focus,textarea:focus,select:focus{border-color:var(--orange);box-shadow:0 0 0 3px var(--orange-soft)}
textarea{resize:vertical;min-height:110px;line-height:1.55}
:focus-visible{outline:2.5px solid var(--orange-d);outline-offset:2px}
.num{font-variant-numeric:tabular-nums;font-feature-settings:"tnum"}
h1,h2,h3,h4{margin:0;letter-spacing:-.022em;line-height:1.2;font-weight:700}
h1{font-size:26px}h2{font-size:20px}h3{font-size:17px}
p{margin:0 0 10px}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}

/* ============ ESTRUCTURA ============ */
#app{width:100%;max-width:100%;min-height:100vh;min-height:100dvh}
.wrap{width:100%;max-width:560px;margin:0 auto;padding:0 16px}
.screen{display:none;padding-bottom:calc(var(--tab-h) + var(--safe-b) + 28px)}
.screen.on{display:block;animation:in .22s ease}
@keyframes in{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}

/* Cabecera */
header.top{
  position:sticky;top:0;z-index:50;
  padding-top:calc(var(--safe-t) + 10px);padding-bottom:10px;
  background:linear-gradient(var(--vanilla) 72%, rgba(255,244,214,0));
  backdrop-filter:saturate(1.2) blur(6px);
}
.top-row{display:flex;align-items:center;gap:10px;min-width:0}
.top-row h1{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sub{color:var(--muted);font-size:13px;margin-top:2px}

/* Chips de estado */
.chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}
.chip{
  display:inline-flex;align-items:center;gap:5px;
  background:var(--card);border:1.5px solid var(--line);
  border-radius:999px;padding:5px 11px;font-size:13px;font-weight:650;
  white-space:nowrap;
}
.chip b{font-variant-numeric:tabular-nums}
.chip.hot{background:var(--orange);border-color:var(--orange);color:#fff}

/* Tarjetas */
.card{
  background:var(--card);border:1.5px solid var(--line);border-radius:var(--r-m);
  padding:15px;margin-bottom:12px;box-shadow:var(--shadow);
}
.card.flat{box-shadow:none}
.card-h{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
.card-h h3{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* Botones */
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:7px;
  background:var(--orange);color:#fff;border-radius:12px;
  padding:13px 18px;font-weight:700;font-size:15px;letter-spacing:-.01em;
  transition:transform .12s, filter .12s;
}
.btn:active{transform:scale(.97);filter:brightness(.94)}
.btn.block{width:100%}
.btn.ghost{background:var(--card);color:var(--ink);border:1.5px solid var(--line)}
.btn.soft{background:var(--orange-soft);color:var(--orange-d)}
.btn.danger{background:var(--card);color:var(--red);border:1.5px solid #F3D3CD}
.btn.sm{padding:9px 13px;font-size:14px;border-radius:10px}
.btn[disabled]{opacity:.5;pointer-events:none}
.row{display:flex;gap:9px}
.row>*{flex:1;min-width:0}

/* Segmentado */
.seg{display:flex;background:var(--line-2);border-radius:12px;padding:3px;gap:3px}
.seg button{
  flex:1;min-width:0;padding:9px 4px;border-radius:9px;font-size:13.5px;font-weight:650;
  color:var(--ink-2);transition:background .15s,color .15s;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.seg button.on{background:var(--card);color:var(--ink);box-shadow:0 1px 3px rgba(60,40,15,.14)}

/* Barra inferior */
nav.tabs{
  position:fixed;left:0;right:0;bottom:0;z-index:60;
  height:calc(var(--tab-h) + var(--safe-b));
  padding-bottom:var(--safe-b);
  background:rgba(255,252,243,.94);
  backdrop-filter:saturate(1.6) blur(14px);
  border-top:1.5px solid var(--line);
  display:flex;
}
nav.tabs button{
  flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:3px;color:var(--muted);font-size:10.5px;font-weight:650;padding-top:6px;
}
nav.tabs button .ic{font-size:21px;line-height:1;transition:transform .16s}
nav.tabs button.on{color:var(--orange-d)}
nav.tabs button.on .ic{transform:translateY(-1px) scale(1.08)}
nav.tabs button span:last-child{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* ============ HOY: cinta del día ============ */
.ribbon{position:relative;padding-left:56px;min-height:60px}
.ribbon::before{
  content:"";position:absolute;left:52px;top:6px;bottom:6px;width:2px;
  background:var(--line);border-radius:2px;
}
.slotline{position:relative;margin-bottom:10px}
.slotline .hr{
  position:absolute;left:-56px;top:0;width:46px;text-align:right;
  font-size:12.5px;font-weight:700;color:var(--muted);
  font-variant-numeric:tabular-nums;padding-top:11px;
}
.slotline .dot{
  position:absolute;left:-8px;top:15px;width:11px;height:11px;border-radius:50%;
  background:var(--card);border:2.5px solid var(--line);
}
.item{
  background:var(--card);border:1.5px solid var(--line);border-left-width:4px;
  border-radius:12px;padding:11px 13px;display:flex;gap:10px;align-items:flex-start;
}
.item .em{font-size:19px;line-height:1.25;flex:0 0 auto}
.item .tx{flex:1;min-width:0;display:block}
.item .tt{display:block;font-weight:700;font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.item .ds{font-size:13px;color:var(--muted);margin-top:1px;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.item.past{opacity:.5}
.item.now{border-color:var(--orange);box-shadow:0 0 0 3px var(--orange-soft)}
.nowline{position:relative;height:0;margin:2px 0 12px}
.nowline::before{
  content:"";position:absolute;left:-56px;right:0;top:0;height:2px;background:var(--orange);
}
.nowline::after{
  content:"";position:absolute;left:-60px;top:-3.5px;width:9px;height:9px;
  border-radius:50%;background:var(--orange);
}
.nowlabel{
  position:absolute;left:-56px;top:-20px;font-size:11px;font-weight:800;
  color:var(--orange-d);letter-spacing:.01em;
}

/* Anillo de progreso */
.ring-wrap{display:flex;align-items:center;gap:16px}
.ring{position:relative;width:78px;height:78px;flex:0 0 78px}
.ring svg{transform:rotate(-90deg)}
.ring .pct{
  position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  font-size:19px;font-weight:800;font-variant-numeric:tabular-nums;letter-spacing:-.03em;
}
.bar{height:9px;background:var(--line-2);border-radius:99px;overflow:hidden}
.bar i{display:block;height:100%;background:var(--orange);border-radius:99px;transition:width .35s ease}

/* ============ CALENDARIO ============ */
.cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:3px}
.cal-dow{
  text-align:center;font-size:11px;font-weight:750;color:var(--muted);
  padding:4px 0;letter-spacing:.01em;
}
.cell{
  position:relative;aspect-ratio:1;border-radius:10px;background:var(--line-2);
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;
  font-size:14px;font-weight:650;font-variant-numeric:tabular-nums;min-width:0;
}
.cell.out{background:transparent;color:#C9BBA3}
.cell.sel{background:var(--orange);color:#fff}
.cell.today:not(.sel){box-shadow:inset 0 0 0 2px var(--orange);color:var(--orange-d)}
.cell .pips{display:flex;gap:2px;height:4px}
.cell .pips i{width:4px;height:4px;border-radius:50%;background:var(--orange)}
.cell.sel .pips i{background:#fff}

.week-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px}
.wd{
  border-radius:12px;background:var(--line-2);padding:7px 3px;text-align:center;min-width:0;
}
.wd.sel{background:var(--orange);color:#fff}
.wd .d1{font-size:10px;font-weight:700;opacity:.7}
.wd .d2{font-size:16px;font-weight:800;font-variant-numeric:tabular-nums}

.year-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}
.ymo{background:var(--card);border:1.5px solid var(--line);border-radius:12px;padding:9px 6px;text-align:center}
.ymo .nm{font-size:12.5px;font-weight:750;margin-bottom:5px}
.ymo .mini{display:grid;grid-template-columns:repeat(7,1fr);gap:1.5px}
.ymo .mini i{height:4px;border-radius:1px;background:var(--line-2);display:block}
.ymo .mini i.has{background:var(--orange)}
.ymo.cur{border-color:var(--orange)}

/* ============ HORARIO ============ */
.sched{display:grid;grid-template-columns:42px repeat(5,minmax(0,1fr));gap:3px}
.sh{font-size:10.5px;font-weight:750;color:var(--muted);text-align:center;padding:4px 0}
.st{
  font-size:9.5px;font-weight:700;color:var(--muted);text-align:right;padding-right:4px;
  display:flex;flex-direction:column;justify-content:center;font-variant-numeric:tabular-nums;
  line-height:1.15;
}
.sc{
  min-height:52px;border-radius:9px;background:var(--line-2);border:1.5px solid transparent;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  padding:3px 2px;gap:1px;min-width:0;overflow:hidden;
}
.sc .e{font-size:14px;line-height:1}
.sc .s{font-size:9.5px;font-weight:700;text-align:center;line-height:1.1;
  width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sc.brk{background:repeating-linear-gradient(45deg,var(--line-2),var(--line-2) 5px,var(--paper) 5px,var(--paper) 10px);min-height:26px}
.sc.live{border-color:var(--orange);box-shadow:0 0 0 2px var(--orange-soft)}

/* ============ LISTAS ============ */
.lst{display:flex;flex-direction:column;gap:9px}
.tile{
  display:flex;align-items:center;gap:11px;background:var(--card);
  border:1.5px solid var(--line);border-radius:14px;padding:12px;min-width:0;
}
.tile .av{
  width:42px;height:42px;border-radius:13px;flex:0 0 42px;
  display:flex;align-items:center;justify-content:center;font-size:20px;
  background:var(--line-2);overflow:hidden;
}
.tile .av img{width:100%;height:100%;object-fit:cover}
.tile .tx{flex:1;min-width:0;display:block;text-align:left}
.tile .tt{display:block;font-weight:700;font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tile .ds{display:block;font-size:12.5px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tile>span:last-child{flex:0 0 auto}
.tick{
  width:36px;height:36px;flex:0 0 36px;border-radius:50%;
  border:2.5px solid var(--line);display:flex;align-items:center;justify-content:center;
  font-size:16px;color:transparent;transition:all .18s;
}
.tick.on{background:var(--orange);border-color:var(--orange);color:#fff}
.dots{display:flex;gap:3px;margin-top:5px}
.dots i{width:7px;height:7px;border-radius:2px;background:var(--line-2)}
.dots i.on{background:var(--orange)}

/* Vacío */
.empty{text-align:center;padding:34px 20px;color:var(--muted)}
.empty .em{font-size:38px;margin-bottom:10px}
.empty h3{color:var(--ink);margin-bottom:5px}
.empty p{font-size:14px;margin:0}

/* ============ MODAL ============ */
.sheet-bg{
  position:fixed;inset:0;z-index:100;background:rgba(36,26,18,.42);
  display:none;align-items:flex-end;justify-content:center;
}
.sheet-bg.on{display:flex}
.sheet{
  width:100%;max-width:560px;max-height:92vh;max-height:92dvh;overflow-y:auto;overflow-x:hidden;
  background:var(--paper);border-radius:22px 22px 0 0;
  padding:8px 16px calc(24px + var(--safe-b));
  animation:up .26s cubic-bezier(.2,.8,.3,1);
  -webkit-overflow-scrolling:touch;touch-action:pan-y;overscroll-behavior:contain;
}
@keyframes up{from{transform:translateY(100%)}to{transform:none}}
.grab{width:38px;height:4px;border-radius:99px;background:var(--line);margin:6px auto 14px;touch-action:none}
.grab-zone{padding-top:4px;margin:-4px -16px 0;padding:4px 16px 10px;touch-action:none}
.field{margin-bottom:13px}
.field label{display:block;font-size:13px;font-weight:700;color:var(--ink-2);margin-bottom:5px}
.hint{font-size:12.5px;color:var(--muted);margin-top:5px;line-height:1.4}

.picker{display:flex;gap:7px;flex-wrap:wrap}
.sw{width:34px;height:34px;border-radius:11px;border:2.5px solid transparent;transition:transform .12s}
.sw.on{border-color:var(--ink);transform:scale(1.08)}
.emo{
  width:40px;height:40px;border-radius:12px;background:var(--line-2);
  font-size:20px;display:flex;align-items:center;justify-content:center;
}
.emo.on{background:var(--orange-soft);box-shadow:inset 0 0 0 2.5px var(--orange)}

/* Toast */
#toast{
  position:fixed;left:50%;transform:translateX(-50%) translateY(20px);
  bottom:calc(var(--tab-h) + var(--safe-b) + 16px);z-index:200;
  background:var(--ink);color:var(--vanilla);padding:12px 18px;border-radius:13px;
  font-size:14px;font-weight:650;max-width:88vw;text-align:center;
  opacity:0;pointer-events:none;transition:opacity .22s, transform .22s;
  box-shadow:0 10px 30px -8px rgba(36,26,18,.5);
}
#toast.on{opacity:1;transform:translateX(-50%) translateY(0)}
#toast.bad{background:var(--red)}
#toast.good{background:var(--green)}

/* Login */
.auth{
  min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;
  justify-content:center;padding:32px 22px calc(32px + var(--safe-b));
}
.logo{text-align:center;margin-bottom:28px}
.logo .mk{
  width:74px;height:74px;margin:0 auto 14px;border-radius:24px;background:var(--orange);
  display:flex;align-items:center;justify-content:center;font-size:36px;
  box-shadow:0 14px 34px -12px rgba(252,108,38,.8);
}
.logo h1{font-size:36px;letter-spacing:-.045em}
.logo p{color:var(--muted);font-size:15px;margin-top:6px}

/* Perfil */
.phead{display:flex;align-items:center;gap:14px;margin-bottom:14px}
.pav{
  width:70px;height:70px;border-radius:24px;flex:0 0 70px;background:var(--orange-soft);
  display:flex;align-items:center;justify-content:center;font-size:30px;overflow:hidden;
  border:2.5px solid var(--card);box-shadow:var(--shadow);
}
.pav img{width:100%;height:100%;object-fit:cover}
.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.stat{background:var(--card);border:1.5px solid var(--line);border-radius:13px;padding:11px 6px;text-align:center}
.stat b{display:block;font-size:20px;font-weight:800;font-variant-numeric:tabular-nums;letter-spacing:-.03em}
.stat span{font-size:11px;color:var(--muted);font-weight:650}

/* Logros */
.badges{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
.badge{text-align:center;padding:10px 3px;border-radius:13px;background:var(--line-2);opacity:.42}
.badge.got{opacity:1;background:var(--orange-soft)}
.badge .e{font-size:24px}
.badge .n{font-size:9.5px;font-weight:700;margin-top:3px;line-height:1.15}

/* Escáner */
.shots{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.shot{position:relative;aspect-ratio:3/4;border-radius:11px;overflow:hidden;background:var(--line-2)}
.shot img{width:100%;height:100%;object-fit:cover}
.shot button{
  position:absolute;top:4px;right:4px;width:23px;height:23px;border-radius:50%;
  background:rgba(36,26,18,.72);color:#fff;font-size:13px;line-height:23px;
}
.divider{height:1.5px;background:var(--line);margin:16px 0}
.mono{font-family:ui-monospace,"SF Mono",Menlo,monospace;font-size:13px}
.note-ed{min-height:46vh;border:0;background:transparent;padding:0;font-size:16px;line-height:1.6}
.note-ed:focus{box-shadow:none}
</style>
</head>
<body>
<div id="app"></div>
<div id="toast" role="status" aria-live="polite"></div>
<div class="sheet-bg" id="sheetbg"><div class="sheet" id="sheet"></div></div>
<script>
"use strict";
/* ===================================================================
   KRONO — lógica de la aplicación
   =================================================================== */
const $  = (s, r) => (r || document).querySelector(s);
const APP = $('#app');
const SELF = location.pathname.split('/').pop() || 'krono.php';

const S = {
  me: null, prefs: {}, stats: {},
  view: 'hoy', prev: 'hoy',
  cal: { mode: 'mes', date: iso(new Date()) },
  events: {}, habits: [], hist: {}, periods: [], slots: {},
  notes: [], note: null, scan: { shots: [], text: '' },
  friends: [], board: [], other: null, focusList: [], focusRun: null,
  fired: {}
};

const PAL = ['#FC6C26','#D8452F','#E8A317','#1F9A6D','#3B6FD4','#8A5CD6','#C2185B','#5D4037','#455A64','#0F9B8E'];
const EMO = ['📌','📘','📗','📕','🧪','🧮','🌍','🖥️','🎨','🎼','🏃','🗣️','📝','🧠','💪','💧','📖','🛏️','🧹','🍎','☀️','🌙','🎯','⚡','🔥','❤️','⚽','🎸','💻','🧘'];
const DOW = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
const DOWL = ['lunes','martes','miércoles','jueves','viernes','sábado','domingo'];
const MON = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
const MONS = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

/* ---------- utilidades ---------- */
function esc(v){ return String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function iso(d){ const x = new Date(d); return x.getFullYear() + '-' + String(x.getMonth()+1).padStart(2,'0') + '-' + String(x.getDate()).padStart(2,'0'); }
function parse(s){ const [y,m,d] = String(s).split('-').map(Number); return new Date(y, m-1, d); }
function addDays(s, n){ const d = parse(s); d.setDate(d.getDate()+n); return iso(d); }
function dowIdx(s){ return (parse(s).getDay() + 6) % 7; }            // 0 = lunes
function weekStart(s){ return addDays(s, -dowIdx(s)); }
function mins(t){ const [h,m] = String(t||'0:0').split(':').map(Number); return (h||0)*60 + (m||0); }
function nowMins(){ const d = new Date(); return d.getHours()*60 + d.getMinutes(); }
function hhmm(m){ return String(Math.floor(m/60)).padStart(2,'0') + ':' + String(m%60).padStart(2,'0'); }
function longDate(s){ const d = parse(s); return DOWL[dowIdx(s)] + ', ' + d.getDate() + ' de ' + MON[d.getMonth()].toLowerCase(); }
function isToday(s){ return s === iso(new Date()); }

let toastT;
function toast(msg, kind){
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'on' + (kind ? ' ' + kind : '');
  clearTimeout(toastT);
  toastT = setTimeout(() => { t.className = ''; }, 2600);
}

async function api(action, data){
  try{
    const r = await fetch('?api=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data || {})
    });
    const j = await r.json();
    if (!j.ok) { toast(j.error || 'Algo ha fallado.', 'bad'); return null; }
    return j;
  }catch(e){
    toast('Sin conexión con el servidor.', 'bad');
    return null;
  }
}

function buzz(ms){ if (navigator.vibrate && S.prefs.haptics !== false) navigator.vibrate(ms || 12); }

/* ---------- hoja modal ---------- */
function sheet(html){
  const el = $('#sheet');
  el.style.transform = ''; el.style.transition = '';
  el.innerHTML = '<div class="grab-zone" id="grabzone"><div class="grab"></div></div>' + html;
  $('#sheetbg').classList.add('on');
  document.body.style.overflow = 'hidden';
  wireSheetDrag();
}
function closeSheet(){
  $('#sheetbg').classList.remove('on');
  document.body.style.overflow = '';
  const el = $('#sheet'); el.style.transform = ''; el.style.transition = '';
}
$('#sheetbg').addEventListener('click', e => { if (e.target.id === 'sheetbg') closeSheet(); });

/* Arrastrar el tirador hacia abajo para cerrar la hoja, como en iOS */
function wireSheetDrag(){
  const zone = $('#grabzone'), el = $('#sheet');
  if (!zone || !el) return;
  let startY = 0, dy = 0, dragging = false;
  const onStart = e => {
    dragging = true; startY = (e.touches ? e.touches[0].clientY : e.clientY);
    el.style.transition = 'none';
  };
  const onMove = e => {
    if (!dragging) return;
    const y = (e.touches ? e.touches[0].clientY : e.clientY);
    dy = Math.max(0, y - startY);
    el.style.transform = 'translateY(' + dy + 'px)';
  };
  const onEnd = () => {
    if (!dragging) return;
    dragging = false;
    el.style.transition = 'transform .22s ease';
    if (dy > 90) { el.style.transform = 'translateY(100%)'; setTimeout(closeSheet, 180); }
    else el.style.transform = 'translateY(0)';
    dy = 0;
  };
  zone.addEventListener('touchstart', onStart, { passive: true });
  zone.addEventListener('touchmove', onMove, { passive: true });
  zone.addEventListener('touchend', onEnd);
  zone.addEventListener('mousedown', onStart);
  window.addEventListener('mousemove', onMove);
  window.addEventListener('mouseup', onEnd);
}

/* ---------- selectores de color y emoji ---------- */
function palHTML(sel, id){
  return '<div class="picker" id="' + id + '">' + PAL.map(c =>
    '<button type="button" class="sw' + (c === sel ? ' on' : '') + '" data-c="' + c + '" style="background:' + c + '" aria-label="Color ' + c + '"></button>'
  ).join('') + '</div>';
}
function emoHTML(sel, id){
  return '<div class="picker" id="' + id + '" style="max-height:112px;overflow-y:auto">' + EMO.map(e =>
    '<button type="button" class="emo' + (e === sel ? ' on' : '') + '" data-e="' + e + '">' + e + '</button>'
  ).join('') + '</div>';
}
function wirePickers(){
  document.querySelectorAll('.picker').forEach(p => {
    p.addEventListener('click', e => {
      const b = e.target.closest('.sw,.emo'); if (!b) return;
      p.querySelectorAll('.sw,.emo').forEach(x => x.classList.remove('on'));
      b.classList.add('on'); buzz(8);
    });
  });
}
function pickedColor(id){ const b = $('#' + id + ' .sw.on'); return b ? b.dataset.c : PAL[0]; }
function pickedEmoji(id, def){ const b = $('#' + id + ' .emo.on'); return b ? b.dataset.e : (def || '📌'); }

/* ===================================================================
   ARRANQUE
   =================================================================== */
async function boot(){
  const r = await api('me');
  if (r && r.me) { S.me = r.me; S.stats = r.stats || {}; S.prefs = r.prefs || {}; }
  try { S.fired = JSON.parse(localStorage.getItem('krono_fired') || '{}'); } catch(_) { S.fired = {}; }
  render();
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('?r=sw').catch(() => {});
  }
  setInterval(tickClock, 30000);
}

function render(){
  if (!S.me) return renderAuth();
  const views = {
    hoy: viewHoy, cal: viewCal, sched: viewSched, habits: viewHabits,
    more: viewMore, notes: viewNotes, note: viewNote, scan: viewScan,
    friends: viewFriends, profile: viewProfile, settings: viewSettings,
    focus: viewFocus, badges: viewBadges, user: viewUser, cloud: viewCloud
  };
  (views[S.view] || viewHoy)();
  window.scrollTo(0, 0);
}

function go(v){
  if (v === S.view) return;
  if (S.view === 'note') { clearTimeout(S.noteTimer); saveNote(false); }
  S.prev = S.view; S.view = v; render(); buzz(8);
}
function back(){ go(['notes','note','scan','friends','profile','settings','focus','badges','user','cloud'].includes(S.view) ? 'more' : 'hoy'); }

/* ---------- estructura común ---------- */
function shell(title, sub, body, right){
  const main = ['hoy','cal','sched','habits','more'];
  const tab = main.includes(S.view) ? S.view : 'more';
  APP.innerHTML =
    '<header class="top"><div class="wrap"><div class="top-row">' +
      (main.includes(S.view) ? '' : '<button class="btn ghost sm" onclick="back()" aria-label="Volver">‹</button>') +
      '<div style="flex:1;min-width:0"><h1>' + esc(title) + '</h1>' +
        (sub ? '<div class="sub">' + esc(sub) + '</div>' : '') +
      '</div>' + (right || '') +
    '</div></div></header>' +
    '<div class="screen on"><div class="wrap">' + body + '</div></div>' +
    '<nav class="tabs">' + [
      ['hoy','◷','Hoy'], ['cal','▦','Agenda'], ['sched','⊞','Horario'],
      ['habits','◎','Hábitos'], ['more','⋯','Más']
    ].map(([k,i,l]) =>
      '<button class="' + (tab === k ? 'on' : '') + '" onclick="go(\'' + k + '\')">' +
      '<span class="ic">' + i + '</span><span>' + l + '</span></button>'
    ).join('') + '</nav>';
}

function statChips(){
  const lv = S.me;
  return '<div class="chips">' +
    '<span class="chip' + (lv.streak > 0 ? ' hot' : '') + '">🔥 <b class="num">' + lv.streak + '</b> ' + (lv.streak === 1 ? 'día' : 'días') + '</span>' +
    '<span class="chip">⭐ Nivel <b class="num">' + lv.level + '</b></span>' +
    '<span class="chip">🪙 <b class="num">' + lv.coins + '</b></span>' +
  '</div>';
}

/* ===================================================================
   ACCESO
   =================================================================== */
let authMode = 'login';
function renderAuth(){
  const reg = authMode === 'reg';
  APP.innerHTML =
  '<div class="auth"><div class="wrap">' +
    '<div class="logo"><div class="mk">◷</div><h1>Krono</h1>' +
      '<p>Tu tiempo, organizado.</p></div>' +
    '<div class="seg" style="margin-bottom:18px">' +
      '<button class="' + (!reg ? 'on' : '') + '" onclick="authMode=\'login\';renderAuth()">Entrar</button>' +
      '<button class="' + (reg ? 'on' : '') + '" onclick="authMode=\'reg\';renderAuth()">Crear cuenta</button>' +
    '</div>' +
    (reg ? '<div class="field"><label for="a_name">Tu nombre</label><input id="a_name" placeholder="Cómo te llamas" autocomplete="name"></div>' : '') +
    '<div class="field"><label for="a_user">Nombre de usuario</label>' +
      '<input id="a_user" placeholder="sin espacios" autocapitalize="none" autocomplete="username" spellcheck="false"></div>' +
    '<div class="field"><label for="a_pass">Contraseña</label>' +
      '<input id="a_pass" type="password" placeholder="mínimo 6 caracteres" autocomplete="' + (reg ? 'new-password' : 'current-password') + '"></div>' +
    '<label class="chip" style="margin:4px 0 16px"><input type="checkbox" id="a_keep" checked style="width:auto;padding:0;margin-right:7px"> Mantener la sesión abierta</label>' +
    '<button class="btn block" id="a_go">' + (reg ? 'Crear mi cuenta' : 'Entrar') + '</button>' +
    '<p class="hint" style="text-align:center;margin-top:16px">Tu nombre de usuario es único y es como te encontrarán tus colegas.</p>' +
  '</div></div>';

  $('#a_go').onclick = doAuth;
  $('#a_pass').addEventListener('keydown', e => { if (e.key === 'Enter') doAuth(); });
  const saved = localStorage.getItem('krono_user');
  if (saved && !reg) $('#a_user').value = saved;
}

async function doAuth(){
  const reg = authMode === 'reg';
  const username = $('#a_user').value.trim();
  const pass = $('#a_pass').value;
  if (!username || !pass) return toast('Rellena usuario y contraseña.', 'bad');
  $('#a_go').disabled = true;
  const r = await api(reg ? 'register' : 'login', {
    username, pass, name: reg ? ($('#a_name').value.trim() || username) : ''
  });
  $('#a_go').disabled = false;
  if (!r) return;
  if ($('#a_keep').checked) localStorage.setItem('krono_user', username);
  else localStorage.removeItem('krono_user');
  S.me = r.me;
  const m = await api('me');
  if (m) { S.stats = m.stats || {}; S.prefs = m.prefs || {}; }
  toast(reg ? '¡Bienvenido a Krono, ' + r.me.name + '!' : 'Hola de nuevo, ' + r.me.name, 'good');
  S.view = 'hoy'; render();
}

/* ===================================================================
   HOY — la cinta del día
   =================================================================== */
async function viewHoy(){
  const d = iso(new Date());
  shell('Hoy', longDate(d), '<div class="card"><div style="height:90px"></div></div>');

  const [ev, hb, sc] = await Promise.all([
    api('events', { from: d, to: d }),
    api('habits', { day: d }),
    api('schedule')
  ]);
  if (!ev || !hb || !sc) return;

  S.events[d] = ev.events;
  S.habits = hb.habits; S.hist = hb.hist || {};
  S.periods = sc.periods; S.slots = keyed(sc.slots);

  const done = S.habits.filter(h => h.n >= h.target).length;
  const pct = S.habits.length ? Math.round(done / S.habits.length * 100) : 0;
  const dow = dowIdx(d);

  // Construir la cinta: clases de hoy + eventos
  const line = [];
  if (dow < 5) {
    const hasClasses = S.periods.some((p, i) => !Number(p.is_break) && !!S.slots[dow + ':' + i]);
    S.periods.forEach((p, i) => {
      const brk = Number(p.is_break);
      const s = S.slots[dow + ':' + i];
      if (brk) { if (!hasClasses) return; }   // sin clases ese día: no mostrar el recreo
      else if (!s) return;                    // hora sin asignatura puesta: no mostrar nada
      line.push({
        m1: mins(p.t1), m2: mins(p.t2),
        emoji: brk ? '☕' : s.emoji,
        title: brk ? p.label : s.subject,
        sub: brk ? 'Descanso' : (s.room ? 'Aula ' + s.room : p.label),
        color: brk ? '#C9BBA3' : s.color,
        kind: 'clase'
      });
    });
  }
  ev.events.forEach(e => line.push({
    m1: Number(e.allday) ? -1 : mins(e.t1), m2: Number(e.allday) ? -1 : mins(e.t2),
    emoji: e.emoji, title: e.title, sub: e.descr || (Number(e.allday) ? 'Todo el día' : e.t1 + '–' + e.t2),
    color: e.color, kind: 'evento', id: e.id, done: Number(e.done)
  }));
  line.sort((a, b) => a.m1 - b.m1);

  const now = nowMins();
  let placed = false;
  const ribbon = line.length ? line.map(it => {
    let pre = '';
    if (!placed && it.m1 > now && it.m1 >= 0) {
      placed = true;
      pre = '<div class="nowline"><span class="nowlabel num">' + hhmm(now) + '</span></div>';
    }
    const live = it.m1 <= now && now < it.m2;
    const past = it.m2 <= now && it.m2 > 0;
    return pre +
    '<div class="slotline">' +
      '<span class="hr num">' + (it.m1 < 0 ? '—' : hhmm(it.m1)) + '</span>' +
      '<span class="dot" style="' + (live ? 'border-color:' + it.color : '') + '"></span>' +
      '<div class="item' + (live ? ' now' : '') + (past && !live ? ' past' : '') + '" style="border-left-color:' + it.color + '"' +
        (it.kind === 'evento' ? ' onclick="openEvent(' + it.id + ')"' : '') + '>' +
        '<span class="em">' + it.emoji + '</span>' +
        '<span class="tx"><span class="tt"' + (it.done ? ' style="text-decoration:line-through;opacity:.55"' : '') + '>' + esc(it.title) + '</span>' +
        '<span class="ds">' + esc(it.sub) + '</span></span>' +
      '</div>' +
    '</div>';
  }).join('') + (placed ? '' : '<div class="nowline"><span class="nowlabel num">' + hhmm(now) + '</span></div>')
  : '<div class="empty"><div class="em">🌤️</div><h3>El día está en blanco</h3><p>Añade algo a la agenda o rellena tu horario.</p></div>';

  const nextH = S.habits.filter(h => h.n < h.target).slice(0, 3);

  shell('Hoy', longDate(d),
    statChips() +
    '<div class="card" style="margin-top:14px">' +
      '<div class="ring-wrap">' + ring(pct) +
        '<div style="flex:1;min-width:0">' +
          '<h3>' + (pct === 100 ? '¡Día redondo!' : pct >= 50 ? 'Vas bien' : 'A por ello') + '</h3>' +
          '<p class="sub" style="margin:2px 0 9px">' + done + ' de ' + S.habits.length + ' hábitos hechos</p>' +
          '<button class="btn soft sm" onclick="go(\'habits\')">Ver hábitos</button>' +
        '</div>' +
      '</div>' +
    '</div>' +
    (nextH.length ? '<div class="card"><div class="card-h"><h3>Pendiente de hoy</h3></div><div class="lst">' +
      nextH.map(h => habitTile(h, d)).join('') + '</div></div>' : '') +
    '<div class="card"><div class="card-h"><h3>Tu día</h3>' +
      '<button class="btn soft sm" onclick="openEvent(0)">+ Añadir</button></div>' +
      '<div class="ribbon">' + ribbon + '</div>' +
    '</div>' +
    '<div class="row" style="margin-bottom:12px">' +
      '<button class="btn ghost" onclick="go(\'focus\')">🎯 Enfoque</button>' +
      '<button class="btn ghost" onclick="go(\'scan\')">📷 Escanear</button>' +
    '</div>'
  );
  scheduleNotifications();
}

function ring(pct){
  const R = 33, C = 2 * Math.PI * R;
  return '<div class="ring"><svg width="78" height="78" viewBox="0 0 78 78">' +
    '<circle cx="39" cy="39" r="' + R + '" fill="none" stroke="var(--line-2)" stroke-width="9"/>' +
    '<circle cx="39" cy="39" r="' + R + '" fill="none" stroke="var(--orange)" stroke-width="9" stroke-linecap="round" ' +
    'stroke-dasharray="' + C + '" stroke-dashoffset="' + (C * (1 - pct / 100)) + '"/>' +
    '</svg><span class="pct num">' + pct + '%</span></div>';
}

function keyed(slots){
  const o = {};
  (slots || []).forEach(s => { o[s.dow + ':' + s.idx] = s; });
  return o;
}

function tickClock(){
  if (S.view === 'hoy') viewHoy();
  scheduleNotifications();
}

/* ===================================================================
   AGENDA (calendario)
   =================================================================== */
async function viewCal(){
  const m = S.cal.mode, d = S.cal.date;
  let from, to;
  if (m === 'día')      { from = d; to = d; }
  else if (m === 'semana'){ from = weekStart(d); to = addDays(from, 6); }
  else if (m === 'mes')  { const x = parse(d); from = iso(new Date(x.getFullYear(), x.getMonth(), 1)); to = iso(new Date(x.getFullYear(), x.getMonth()+1, 0)); }
  else                   { const y = parse(d).getFullYear(); from = y + '-01-01'; to = y + '-12-31'; }

  const r = await api('events', { from, to });
  if (!r) return;
  const byDay = {};
  r.events.forEach(e => { (byDay[e.day] = byDay[e.day] || []).push(e); });

  const seg = '<div class="seg" style="margin-bottom:14px">' +
    ['día','semana','mes','año'].map(k =>
      '<button class="' + (m === k ? 'on' : '') + '" onclick="S.cal.mode=\'' + k + '\';viewCal()">' +
      k.charAt(0).toUpperCase() + k.slice(1) + '</button>').join('') + '</div>';

  let body = seg, sub = '';
  if (m === 'día')      { body += calDay(d, byDay);    sub = longDate(d); }
  else if (m === 'semana'){ body += calWeek(d, byDay);  sub = 'Semana del ' + parse(weekStart(d)).getDate() + ' de ' + MON[parse(weekStart(d)).getMonth()].toLowerCase(); }
  else if (m === 'mes')  { body += calMonth(d, byDay);  sub = MON[parse(d).getMonth()] + ' ' + parse(d).getFullYear(); }
  else                   { body += calYear(d, byDay);   sub = String(parse(d).getFullYear()); }

  shell('Agenda', sub, body,
    '<button class="btn sm" onclick="openEvent(0)" aria-label="Añadir">+</button>');
}

function navBar(prevFn, nextFn, label){
  return '<div class="card flat" style="display:flex;align-items:center;gap:8px;padding:9px 11px">' +
    '<button class="btn ghost sm" onclick="' + prevFn + '">‹</button>' +
    '<div style="flex:1;min-width:0;text-align:center;font-weight:750;font-size:15px">' + esc(label) + '</div>' +
    '<button class="btn ghost sm" onclick="' + nextFn + '">›</button>' +
    '</div>';
}
function shift(n, unit){
  const d = parse(S.cal.date);
  if (unit === 'd') d.setDate(d.getDate() + n);
  if (unit === 'w') d.setDate(d.getDate() + n * 7);
  if (unit === 'm') d.setMonth(d.getMonth() + n);
  if (unit === 'y') d.setFullYear(d.getFullYear() + n);
  S.cal.date = iso(d); viewCal();
}
function pickDay(day){ S.cal.date = day; S.cal.mode = 'día'; viewCal(); }

function eventRow(e){
  return '<div class="item" style="border-left-color:' + esc(e.color) + '" onclick="openEvent(' + e.id + ')">' +
    '<span class="em">' + esc(e.emoji) + '</span>' +
    '<span class="tx"><span class="tt"' + (Number(e.done) ? ' style="text-decoration:line-through;opacity:.55"' : '') + '>' + esc(e.title) + '</span>' +
    '<span class="ds">' + (Number(e.allday) ? 'Todo el día' : esc(e.t1 + ' – ' + e.t2)) + (e.descr ? ' · ' + esc(e.descr) : '') + '</span></span>' +
    '<button class="tick' + (Number(e.done) ? ' on' : '') + '" onclick="event.stopPropagation();toggleEvent(' + e.id + ',' + (Number(e.done) ? 0 : 1) + ')">✓</button>' +
  '</div>';
}

function calDay(d, byDay){
  const list = byDay[d] || [];
  const dow = dowIdx(d);
  const classes = (dow < 5 && S.periods.length) ? S.periods.map((p, i) => {
    const s = S.slots[dow + ':' + i];
    if (!s) return '';
    return '<div class="item" style="border-left-color:' + esc(s.color) + '">' +
      '<span class="em">' + esc(s.emoji) + '</span><span class="tx">' +
      '<span class="tt">' + esc(s.subject) + '</span>' +
      '<span class="ds">' + esc(p.t1 + ' – ' + p.t2) + (s.room ? ' · Aula ' + esc(s.room) : '') + '</span></span></div>';
  }).join('') : '';

  const lbl = DOW[dowIdx(d)] + ' ' + parse(d).getDate() + ' ' + MONS[parse(d).getMonth()] + (isToday(d) ? ' · hoy' : '');
  return navBar("shift(-1,'d')", "shift(1,'d')", lbl) +
    '<div class="card"><div class="card-h"><h3>Agenda</h3>' +
      '<button class="btn soft sm" onclick="openEvent(0,\'' + d + '\')">+ Añadir</button></div>' +
      (list.length ? '<div class="lst">' + list.map(eventRow).join('') + '</div>'
        : '<div class="empty" style="padding:22px"><div class="em">📭</div><p>Nada apuntado este día.</p></div>') +
    '</div>' +
    (classes ? '<div class="card"><div class="card-h"><h3>Clases</h3></div><div class="lst">' + classes + '</div></div>' : '');
}

function calWeek(d, byDay){
  const ws = weekStart(d);
  const days = Array.from({length:7}, (_,i) => addDays(ws, i));
  const head = '<div class="week-grid" style="margin-bottom:14px">' + days.map(x =>
    '<button class="wd' + (x === d ? ' sel' : '') + '" onclick="S.cal.date=\'' + x + '\';viewCal()">' +
      '<div class="d1">' + DOW[dowIdx(x)] + '</div>' +
      '<div class="d2 num">' + parse(x).getDate() + '</div>' +
      '<div class="dots" style="justify-content:center;margin-top:3px">' +
        Array.from({length: Math.min(3, (byDay[x]||[]).length)}).map(() => '<i class="on"></i>').join('') +
      '</div>' +
    '</button>').join('') + '</div>';

  const body = days.map(x => {
    const l = byDay[x] || [];
    if (!l.length) return '';
    return '<div class="card"><div class="card-h"><h3>' + DOWL[dowIdx(x)].replace(/^./, c => c.toUpperCase()) +
      ' ' + parse(x).getDate() + '</h3></div><div class="lst">' + l.map(eventRow).join('') + '</div></div>';
  }).join('');

  return navBar("shift(-1,'w')", "shift(1,'w')", 'Semana ' + parse(ws).getDate() + '–' + parse(addDays(ws,6)).getDate() + ' ' + MONS[parse(ws).getMonth()]) +
    head + (body || '<div class="empty"><div class="em">🗓️</div><h3>Semana libre</h3><p>Ni un evento apuntado.</p></div>');
}

function calMonth(d, byDay){
  const x = parse(d), y = x.getFullYear(), mo = x.getMonth();
  const first = new Date(y, mo, 1), last = new Date(y, mo+1, 0);
  const lead = (first.getDay() + 6) % 7;
  const cells = [];
  for (let i = lead; i > 0; i--) cells.push({ d: iso(new Date(y, mo, 1 - i)), out: 1 });
  for (let i = 1; i <= last.getDate(); i++) cells.push({ d: iso(new Date(y, mo, i)), out: 0 });
  while (cells.length % 7) cells.push({ d: iso(new Date(y, mo+1, cells.length - lead - last.getDate() + 1)), out: 1 });

  const grid = '<div class="cal-grid">' + DOW.map(w => '<div class="cal-dow">' + w + '</div>').join('') +
    cells.map(c => {
      const n = (byDay[c.d] || []).length;
      return '<button class="cell' + (c.out ? ' out' : '') + (c.d === d ? ' sel' : '') + (isToday(c.d) ? ' today' : '') +
        '" onclick="S.cal.date=\'' + c.d + '\';viewCal()">' +
        '<span class="num">' + parse(c.d).getDate() + '</span>' +
        '<span class="pips">' + Array.from({length: Math.min(3, n)}).map(() => '<i></i>').join('') + '</span>' +
      '</button>';
    }).join('') + '</div>';

  const l = byDay[d] || [];
  return navBar("shift(-1,'m')", "shift(1,'m')", MON[mo] + ' ' + y) +
    '<div class="card">' + grid + '</div>' +
    '<div class="card"><div class="card-h"><h3>' + longDate(d).replace(/^./, c => c.toUpperCase()) + '</h3>' +
      '<button class="btn soft sm" onclick="openEvent(0,\'' + d + '\')">+</button></div>' +
      (l.length ? '<div class="lst">' + l.map(eventRow).join('') + '</div>'
        : '<div class="empty" style="padding:20px"><p>Día libre. Toca + para apuntar algo.</p></div>') +
    '</div>';
}

function calYear(d, byDay){
  const y = parse(d).getFullYear(), cm = new Date().getMonth(), cy = new Date().getFullYear();
  const grid = '<div class="year-grid">' + MON.map((nm, i) => {
    const last = new Date(y, i+1, 0).getDate();
    const cells = Array.from({length: last}, (_, k) => {
      const day = y + '-' + String(i+1).padStart(2,'0') + '-' + String(k+1).padStart(2,'0');
      return '<i class="' + ((byDay[day]||[]).length ? 'has' : '') + '"></i>';
    }).join('');
    return '<button class="ymo' + (y === cy && i === cm ? ' cur' : '') + '" onclick="S.cal.date=\'' +
      y + '-' + String(i+1).padStart(2,'0') + '-01\';S.cal.mode=\'mes\';viewCal()">' +
      '<div class="nm">' + MONS[i] + '</div><div class="mini">' + cells + '</div></button>';
  }).join('') + '</div>';
  const total = Object.values(byDay).reduce((a, b) => a + b.length, 0);
  return navBar("shift(-1,'y')", "shift(1,'y')", String(y)) + '<div class="card">' + grid + '</div>' +
    '<div class="card flat" style="text-align:center"><b class="num" style="font-size:24px">' + total + '</b>' +
    '<div class="sub">eventos apuntados en ' + y + '</div></div>';
}

/* ---------- editar evento ---------- */
async function openEvent(id, day){
  let e = { id: 0, title: '', descr: '', emoji: '📌', color: PAL[0], day: day || S.cal.date || iso(new Date()), t1: '09:00', t2: '10:00', allday: 0, remind: 10 };
  if (id) {
    const all = Object.values(S.events).flat();
    const found = all.find(x => Number(x.id) === Number(id));
    if (found) e = found;
    else { const r = await api('events', { from: '2000-01-01', to: '2100-01-01' }); if (r) e = r.events.find(x => Number(x.id) === Number(id)) || e; }
  }
  sheet(
    '<h2 style="margin-bottom:14px">' + (id ? 'Editar evento' : 'Nuevo evento') + '</h2>' +
    '<div class="field"><label for="e_t">Título</label><input id="e_t" value="' + esc(e.title) + '" placeholder="Examen de mates, entrenamiento..."></div>' +
    '<div class="field"><label for="e_d">Descripción</label><textarea id="e_d" style="min-height:70px" placeholder="Detalles, qué llevar, qué estudiar...">' + esc(e.descr) + '</textarea></div>' +
    '<div class="field"><label for="e_day">Día</label><input id="e_day" type="date" value="' + esc(e.day) + '"></div>' +
    (id ? '' :
    '<div class="field"><label class="chip" style="width:auto"><input type="checkbox" id="e_multi" style="width:auto;padding:0;margin-right:7px"> Repetir en más días</label>' +
      '<div id="e_multi_box" style="display:none;margin-top:9px">' +
        '<div class="row" style="margin-bottom:7px"><input id="e_multi_add" type="date"><button type="button" class="btn ghost sm" id="e_multi_go">Añadir</button></div>' +
        '<div class="dots" id="e_multi_list" style="flex-wrap:wrap;gap:6px"></div>' +
      '</div></div>') +
    '<div class="field"><label class="chip" style="width:auto"><input type="checkbox" id="e_all" ' + (Number(e.allday) ? 'checked' : '') + ' style="width:auto;padding:0;margin-right:7px"> Dura todo el día</label></div>' +
    '<div class="row" id="e_times" style="margin-bottom:13px' + (Number(e.allday) ? ';display:none' : '') + '">' +
      '<div><label style="font-size:13px;font-weight:700">Empieza</label><input id="e_t1" type="time" value="' + esc(e.t1) + '"></div>' +
      '<div><label style="font-size:13px;font-weight:700">Acaba</label><input id="e_t2" type="time" value="' + esc(e.t2) + '"></div>' +
    '</div>' +
    '<div class="field"><label for="e_rem">Avisarme</label><select id="e_rem">' +
      [[0,'No avisar'],[5,'5 minutos antes'],[10,'10 minutos antes'],[30,'30 minutos antes'],[60,'1 hora antes'],[1440,'El día antes']]
        .map(([v,l]) => '<option value="' + v + '"' + (Number(e.remind) === v ? ' selected' : '') + '>' + l + '</option>').join('') +
    '</select></div>' +
    '<div class="field"><label>Emoji</label>' + emoHTML(e.emoji, 'e_emo') + '</div>' +
    '<div class="field"><label>Color</label>' + palHTML(e.color, 'e_pal') + '</div>' +
    '<button class="btn block" id="e_save">Guardar evento</button>' +
    (id ? '<button class="btn danger block" style="margin-top:9px" onclick="delEvent(' + id + ')">Eliminar</button>' : '')
  );
  wirePickers();
  S.multiDays = [];
  $('#e_all').onchange = ev => { $('#e_times').style.display = ev.target.checked ? 'none' : 'flex'; };
  const mbox = $('#e_multi_box');
  if ($('#e_multi')) {
    $('#e_multi').onchange = ev => { mbox.style.display = ev.target.checked ? 'block' : 'none'; };
    $('#e_multi_go').onclick = () => {
      const v = $('#e_multi_add').value;
      if (!v || S.multiDays.includes(v)) return;
      S.multiDays.push(v); renderMultiDays();
    };
  }
  function renderMultiDays(){
    $('#e_multi_list').innerHTML = S.multiDays.map(d =>
      '<span class="chip">' + parse(d).getDate() + ' ' + MONS[parse(d).getMonth()] +
      ' <button type="button" style="margin-left:5px;font-weight:800" onclick="removeMultiDay(\'' + d + '\')">✕</button></span>'
    ).join('') || '<span class="hint">Añade fechas con el selector de arriba.</span>';
  }
  window.removeMultiDay = (d) => { S.multiDays = S.multiDays.filter(x => x !== d); renderMultiDays(); };
  $('#e_save').onclick = async () => {
    const d = {
      id: id, title: $('#e_t').value.trim(), descr: $('#e_d').value.trim(),
      day: $('#e_day').value, allday: $('#e_all').checked ? 1 : 0,
      t1: $('#e_t1').value || '09:00', t2: $('#e_t2').value || '10:00',
      remind: Number($('#e_rem').value),
      emoji: pickedEmoji('e_emo', '📌'), color: pickedColor('e_pal')
    };
    if (!id && $('#e_multi') && $('#e_multi').checked && S.multiDays.length) {
      d.days = [d.day, ...S.multiDays];
    }
    if (!d.title) return toast('Ponle un título al evento.', 'bad');
    if (!d.allday && mins(d.t2) <= mins(d.t1)) return toast('La hora de fin va antes que la de inicio.', 'bad');
    const r = await api('event_save', d);
    if (!r) return;
    closeSheet();
    toast(r.created > 1 ? 'Evento guardado en ' + r.created + ' días' : 'Evento guardado', 'good');
    S.cal.date = d.day; render();
  };
}

async function delEvent(id){
  if (!confirm('¿Eliminar este evento? No se puede deshacer.')) return;
  const r = await api('event_del', { id });
  if (!r) return;
  closeSheet(); toast('Evento eliminado'); render();
}

async function toggleEvent(id, v){
  buzz(v ? 18 : 8);
  const r = await api('event_done', { id, done: v });
  if (!r) return;
  S.me = r.me;
  if (v) toast('+12 XP · ¡hecho!', 'good');
  render();
}

/* ===================================================================
   HORARIO DE CLASE
   =================================================================== */
async function viewSched(){
  const r = await api('schedule');
  if (!r) return;
  S.periods = r.periods; S.slots = keyed(r.slots);
  const today = dowIdx(iso(new Date())), now = nowMins();

  const grid = '<div class="sched">' +
    '<div class="sh"></div>' + DOW.slice(0,5).map((d,i) =>
      '<div class="sh"' + (i === today ? ' style="color:var(--orange-d)"' : '') + '>' + d + '</div>').join('') +
    S.periods.map((p, i) => {
      const brk = Number(p.is_break);
      const live = mins(p.t1) <= now && now < mins(p.t2);
      let row = '<div class="st"><span>' + p.t1 + '</span><span style="opacity:.55">' + p.t2 + '</span></div>';
      for (let d = 0; d < 5; d++) {
        const s = S.slots[d + ':' + i];
        const on = live && d === today;
        row += '<button class="sc' + (brk ? ' brk' : '') + (on ? ' live' : '') + '" ' +
          'style="' + (s && !brk ? 'background:' + s.color + '18;' : '') + '" ' +
          'onclick="editSlot(' + d + ',' + i + ')">' +
          (brk ? '<span class="s" style="opacity:.6">' + esc(p.label) + '</span>'
               : (s ? '<span class="e">' + esc(s.emoji) + '</span><span class="s" style="color:' + esc(s.color) + '">' + esc(s.subject) + '</span>'
                    : '<span class="s" style="opacity:.35">+</span>')) +
        '</button>';
      }
      return row;
    }).join('') + '</div>';

  const todayList = today < 5 ? S.periods.map((p, i) => {
    const s = S.slots[today + ':' + i];
    if (!s) return '';
    const live = mins(p.t1) <= now && now < mins(p.t2);
    const past = mins(p.t2) <= now;
    return '<div class="item' + (live ? ' now' : '') + (past ? ' past' : '') + '" style="border-left-color:' + esc(s.color) + '">' +
      '<span class="em">' + esc(s.emoji) + '</span><span class="tx">' +
      '<span class="tt">' + esc(s.subject) + '</span>' +
      '<span class="ds">' + esc(p.t1 + ' – ' + p.t2) + (s.room ? ' · Aula ' + esc(s.room) : '') + '</span></span></div>';
  }).join('') : '';

  shell('Horario', 'Toca una casilla para poner tu asignatura',
    '<div class="card">' + grid + '</div>' +
    (todayList ? '<div class="card"><div class="card-h"><h3>Clases de hoy</h3></div><div class="lst">' + todayList + '</div></div>' : '') +
    '<button class="btn ghost block" onclick="editPeriods()">Cambiar los tramos horarios</button>' +
    '<p class="hint" style="margin-top:10px">Los tramos vienen puestos de 8:15 a 15:05 con recreo de 11:00 a 11:25. Si en tu instituto son otros, cámbialos ahí.</p>'
  );
}

function editSlot(dow, idx){
  const s = S.slots[dow + ':' + idx] || { subject: '', room: '', emoji: '📘', color: PAL[0] };
  const p = S.periods[idx];
  sheet(
    '<h2>' + DOWL[dow].replace(/^./, c => c.toUpperCase()) + '</h2>' +
    '<p class="sub" style="margin-bottom:14px">' + esc(p.label + ' · ' + p.t1 + '–' + p.t2) + '</p>' +
    '<div class="field"><label for="s_sub">Asignatura</label><input id="s_sub" value="' + esc(s.subject) + '" placeholder="Matemáticas, Historia..."></div>' +
    '<div class="field"><label for="s_room">Aula (opcional)</label><input id="s_room" value="' + esc(s.room) + '" placeholder="2.14"></div>' +
    '<div class="field"><label>Emoji</label>' + emoHTML(s.emoji, 's_emo') + '</div>' +
    '<div class="field"><label>Color</label>' + palHTML(s.color, 's_pal') + '</div>' +
    '<button class="btn block" id="s_save">Guardar</button>' +
    (s.subject ? '<button class="btn danger block" style="margin-top:9px" id="s_del">Vaciar la casilla</button>' : '')
  );
  wirePickers();
  $('#s_save').onclick = async () => {
    const r = await api('slot_save', {
      dow, idx, subject: $('#s_sub').value.trim(), room: $('#s_room').value.trim(),
      emoji: pickedEmoji('s_emo', '📘'), color: pickedColor('s_pal')
    });
    if (!r) return;
    closeSheet(); toast('Horario actualizado', 'good'); viewSched();
  };
  const del = $('#s_del');
  if (del) del.onclick = async () => {
    await api('slot_save', { dow, idx, subject: '' });
    closeSheet(); toast('Casilla vacía'); viewSched();
  };
}

function editPeriods(){
  sheet(
    '<h2 style="margin-bottom:6px">Tramos horarios</h2>' +
    '<p class="sub" style="margin-bottom:14px">Ajusta las horas a las de tu instituto.</p>' +
    '<div id="p_list">' + S.periods.map((p, i) => periodRow(p, i)).join('') + '</div>' +
    '<button class="btn ghost block" style="margin-bottom:9px" onclick="addPeriod()">+ Añadir tramo</button>' +
    '<button class="btn block" id="p_save">Guardar tramos</button>'
  );
  $('#p_save').onclick = async () => {
    const rows = [...document.querySelectorAll('#p_list .prow')].map(r => ({
      label: r.querySelector('.p_l').value.trim() || 'Hora',
      t1: r.querySelector('.p_1').value, t2: r.querySelector('.p_2').value,
      is_break: r.querySelector('.p_b').checked ? 1 : 0
    }));
    const r = await api('period_save', { periods: rows });
    if (!r) return;
    closeSheet(); toast('Tramos guardados', 'good'); viewSched();
  };
}

function periodRow(p, i){
  return '<div class="prow card flat" style="padding:11px;margin-bottom:9px">' +
    '<div class="row" style="margin-bottom:8px">' +
      '<input class="p_l" value="' + esc(p.label) + '" placeholder="Nombre" style="flex:2">' +
      '<button class="btn danger sm" style="flex:0 0 44px" onclick="this.closest(\'.prow\').remove()">✕</button>' +
    '</div>' +
    '<div class="row" style="margin-bottom:8px">' +
      '<input class="p_1" type="time" value="' + esc(p.t1) + '">' +
      '<input class="p_2" type="time" value="' + esc(p.t2) + '">' +
    '</div>' +
    '<label class="chip" style="width:auto"><input class="p_b" type="checkbox" ' + (Number(p.is_break) ? 'checked' : '') +
      ' style="width:auto;padding:0;margin-right:7px"> Es un recreo</label>' +
  '</div>';
}
function addPeriod(){
  $('#p_list').insertAdjacentHTML('beforeend', periodRow({ label: 'Hora', t1: '15:05', t2: '16:00', is_break: 0 }, 99));
}

/* ===================================================================
   HÁBITOS
   =================================================================== */
async function viewHabits(){
  const day = S.cal.habitDay || iso(new Date());
  const r = await api('habits', { day });
  if (!r) return;
  S.habits = r.habits; S.hist = r.hist || {};

  const done = S.habits.filter(h => h.n >= h.target).length;
  const pct = S.habits.length ? Math.round(done / S.habits.length * 100) : 0;
  const ws = weekStart(day);
  const strip = '<div class="week-grid" style="margin-bottom:14px">' + Array.from({length:7}, (_,i) => {
    const x = addDays(ws, i);
    return '<button class="wd' + (x === day ? ' sel' : '') + '" onclick="S.cal.habitDay=\'' + x + '\';viewHabits()">' +
      '<div class="d1">' + DOW[i] + '</div><div class="d2 num">' + parse(x).getDate() + '</div></button>';
  }).join('') + '</div>';

  shell('Hábitos', isToday(day) ? 'Hoy' : longDate(day),
    strip +
    '<div class="card">' +
      '<div class="card-h"><h3>Progreso del día</h3><b class="num" style="font-size:20px">' + pct + '%</b></div>' +
      '<div class="bar"><i style="width:' + pct + '%"></i></div>' +
      '<p class="sub" style="margin:9px 0 0">' + done + ' de ' + S.habits.length + ' completados' +
        (pct === 100 && S.habits.length ? ' · día perfecto 🔥' : '') + '</p>' +
    '</div>' +
    (S.habits.length
      ? '<div class="lst" style="margin-bottom:12px">' + S.habits.map(h => habitTile(h, day, true)).join('') + '</div>'
      : '<div class="empty"><div class="em">◎</div><h3>Aún no hay hábitos</h3><p>Empieza por uno pequeño que puedas repetir todos los días.</p></div>') +
    '<button class="btn block" onclick="editHabit(0)">+ Nuevo hábito</button>',
    '<button class="btn sm" onclick="editHabit(0)" aria-label="Añadir">+</button>'
  );
}

function habitTile(h, day, full){
  const n = Number(h.n), t = Number(h.target), ok = n >= t;
  const days = (S.hist[h.id] || []);
  const strip = full ? '<div class="dots">' + Array.from({length:7}, (_,i) => {
    const d = addDays(day, i - 6);
    return '<i class="' + (days.includes(d) ? 'on' : '') + '"></i>';
  }).join('') + '</div>' : '';
  return '<div class="tile" style="' + (ok ? 'border-color:' + h.color : '') + '">' +
    '<span class="av" style="background:' + esc(h.color) + '1F">' + esc(h.emoji) + '</span>' +
    '<span class="tx"' + (full ? ' onclick="editHabit(' + h.id + ')"' : '') + '>' +
      '<span class="tt"' + (ok ? ' style="text-decoration:line-through;opacity:.6"' : '') + '>' + esc(h.title) + '</span>' +
      '<span class="ds">' + (t > 1 ? n + ' de ' + t + ' veces' : (ok ? 'Hecho' : 'Pendiente')) +
        (h.hour ? ' · ' + esc(h.hour) : '') +
        (h.days ? ' · ' + String(h.days).split(',').filter(Boolean).map(x => DOW[x-1]).join(' ') : '') + '</span>' + strip +
    '</span>' +
    '<button class="tick' + (ok ? ' on' : '') + '" style="' + (ok ? 'background:' + h.color + ';border-color:' + h.color : '') +
      '" onclick="tickHabit(' + h.id + ',\'' + day + '\',' + (ok ? -1 : 1) + ')" aria-label="Marcar">✓</button>' +
  '</div>';
}

async function tickHabit(id, day, dir){
  buzz(dir > 0 ? 18 : 8);
  const r = await api('habit_tick', { id, day, dir });
  if (!r) return;
  S.me = r.me;
  if (dir > 0) toast('+10 XP', 'good');
  S.view === 'hoy' ? viewHoy() : viewHabits();
}

function editHabit(id){
  const h = S.habits.find(x => Number(x.id) === Number(id)) ||
            { id: 0, title: '', emoji: '💪', color: PAL[0], target: 1, hour: '', days: '' };
  const selDays = String(h.days || '').split(',').filter(Boolean).map(Number);
  sheet(
    '<h2 style="margin-bottom:14px">' + (id ? 'Editar hábito' : 'Nuevo hábito') + '</h2>' +
    '<div class="field"><label for="h_t">Nombre</label><input id="h_t" value="' + esc(h.title) + '" placeholder="Leer 20 minutos, beber agua..."></div>' +
    '<div class="row" style="margin-bottom:13px">' +
      '<div><label style="font-size:13px;font-weight:700">Veces al día</label>' +
        '<input id="h_n" type="number" min="1" max="20" value="' + Number(h.target) + '"></div>' +
      '<div><label style="font-size:13px;font-weight:700">Recordar a las</label>' +
        '<input id="h_h" type="time" value="' + esc(h.hour) + '"></div>' +
    '</div>' +
    '<div class="field"><label>¿Qué días? <span class="hint" style="display:inline">(ninguno marcado = todos los días)</span></label>' +
      '<div class="picker" id="h_days">' + DOW.map((dn, i) =>
        '<button type="button" class="emo' + (selDays.includes(i+1) ? ' on' : '') + '" data-d="' + (i+1) +
        '" style="font-size:12px;font-weight:750" onclick="this.classList.toggle(\'on\')">' + dn + '</button>').join('') +
      '</div></div>' +
    '<div class="field"><label>Emoji</label>' + emoHTML(h.emoji, 'h_emo') + '</div>' +
    '<div class="field"><label>Color</label>' + palHTML(h.color, 'h_pal') + '</div>' +
    '<button class="btn block" id="h_save">Guardar hábito</button>' +
    (id ? '<button class="btn danger block" style="margin-top:9px" onclick="delHabit(' + id + ')">Eliminar</button>' : '')
  );
  wirePickers();
  $('#h_save').onclick = async () => {
    const days = [...document.querySelectorAll('#h_days .emo.on')].map(b => b.dataset.d);
    const d = {
      id, title: $('#h_t').value.trim(), target: Number($('#h_n').value) || 1,
      hour: $('#h_h').value, emoji: pickedEmoji('h_emo', '💪'), color: pickedColor('h_pal'), days
    };
    if (!d.title) return toast('Ponle un nombre al hábito.', 'bad');
    const r = await api('habit_save', d);
    if (!r) return;
    closeSheet(); toast('Hábito guardado', 'good'); viewHabits();
  };
}

async function delHabit(id){
  if (!confirm('¿Eliminar el hábito y todo su historial?')) return;
  const r = await api('habit_del', { id });
  if (!r) return;
  closeSheet(); toast('Hábito eliminado'); viewHabits();
}

/* ===================================================================
   MÁS
   =================================================================== */
function viewMore(){
  const m = S.me, lv = m;
  const items = [
    ['notes','📝','Notas','Escribe y guárdalas en tu nube'],
    ['scan','📷','Escanear apuntes','De foto a texto y a PDF'],
    ['friends','👥','Colegas','Búscalos y compara rachas'],
    ['focus','🎯','Modo enfoque','Silencia el ruido y concéntrate'],
    ['badges','🏅','Logros','Lo que llevas conseguido'],
    ['profile','👤','Mi perfil','Nombre, foto y estadísticas'],
    ['cloud','☁️','Mi nube','Conecta el Cloud de EducaMadrid'],
    ['settings','⚙️','Ajustes','Notificaciones y cuenta']
  ];
  shell('Más', '@' + m.username,
    '<div class="card">' +
      '<div class="phead">' +
        '<div class="pav">' + (m.avatar ? '<img src="' + esc(m.avatar) + '" alt="">' : '👤') + '</div>' +
        '<div style="flex:1;min-width:0">' +
          '<h2 style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(m.name) + '</h2>' +
          '<div class="sub">Nivel ' + lv.level + ' · ' + lv.into + '/' + lv.need + ' XP</div>' +
          '<div class="bar" style="margin-top:7px"><i style="width:' + Math.round(lv.into / lv.need * 100) + '%"></i></div>' +
        '</div>' +
      '</div>' + statChips() +
    '</div>' +
    '<div class="lst">' + items.map(([v, e, t, d]) =>
      '<button class="tile" style="text-align:left;width:100%" onclick="go(\'' + v + '\')">' +
        '<span class="av">' + e + '</span>' +
        '<span class="tx"><span class="tt">' + t + '</span><span class="ds">' + d + '</span></span>' +
        '<span style="color:var(--muted);font-size:20px">›</span>' +
      '</button>').join('') + '</div>'
  );
}

/* ===================================================================
   NOTAS
   =================================================================== */
async function viewNotes(){
  const r = await api('notes');
  if (!r) return;
  S.notes = r.notes;
  shell('Notas', S.notes.length + (S.notes.length === 1 ? ' nota' : ' notas'),
    (S.notes.length
      ? '<div class="lst" style="margin-bottom:12px">' + S.notes.map(n =>
          '<button class="tile" style="text-align:left;width:100%;align-items:flex-start" onclick="openNote(' + n.id + ')">' +
            '<span class="av">📄</span>' +
            '<span class="tx"><span class="tt">' + esc(n.title) + '</span>' +
            '<span class="ds">' + esc((n.preview || '').replace(/\s+/g, ' ').slice(0, 60) || 'Vacía') + '</span>' +
            '<span class="ds" style="font-size:11px">' + esc((n.updated || '').slice(0, 16)) +
              (n.cloud ? ' · ☁️ en la nube' : '') + '</span></span>' +
          '</button>').join('') + '</div>'
      : '<div class="empty"><div class="em">📝</div><h3>Ninguna nota todavía</h3><p>Escribe apuntes, resúmenes o lo que se te ocurra.</p></div>') +
    '<button class="btn block" onclick="openNote(0)">+ Nota nueva</button>',
    '<button class="btn sm" onclick="openNote(0)" aria-label="Nueva">+</button>'
  );
}

async function openNote(id){
  if (id) {
    const r = await api('note_get', { id });
    if (!r) return;
    S.note = r.note;
  } else {
    S.note = { id: 0, title: '', body: '', tag: '', cloud: '' };
  }
  go('note');
}

function viewNote(){
  const n = S.note;
  shell(n.id ? 'Editar nota' : 'Nota nueva', n.cloud ? 'Subida a la nube el ' + n.cloud : 'Sin subir a la nube',
    '<div class="card">' +
      '<input id="n_t" value="' + esc(n.title) + '" placeholder="Título de la nota" ' +
        'style="border:0;padding:0 0 9px;font-size:20px;font-weight:750;background:transparent">' +
      '<div class="divider" style="margin:0 0 12px"></div>' +
      '<textarea id="n_b" class="note-ed" placeholder="Escribe aquí...">' + esc(n.body) + '</textarea>' +
    '</div>' +
    '<div class="row" style="margin-bottom:9px">' +
      '<button class="btn" id="n_save">Guardar</button>' +
      '<button class="btn ghost" onclick="notePDF()">Exportar PDF</button>' +
    '</div>' +
    '<button class="btn ghost block" style="margin-bottom:9px" onclick="noteToCloud()">☁️ Subir a mi nube</button>' +
    (n.id ? '<button class="btn danger block" onclick="delNote(' + n.id + ')">Eliminar nota</button>' : '') +
    '<p class="hint">Para subirla a la nube antes tienes que conectarla en Más › Mi nube.</p>'
  );
  $('#n_save').onclick = () => saveNote(true);
  $('#n_b').addEventListener('input', () => {
    clearTimeout(S.noteTimer);
    S.noteTimer = setTimeout(() => saveNote(false), 2200);
  });
}

async function saveNote(loud){
  const ti = $('#n_t'), bo = $('#n_b');
  if (!ti || !bo || !S.note) return null;          // ya no estamos en el editor
  const d = { id: S.note.id, title: ti.value.trim(), body: bo.value, tag: S.note.tag };
  if (!d.title && !d.body.trim()) { if (loud) toast('La nota está vacía.', 'bad'); return null; }
  const r = await api('note_save', d);
  if (!r) return null;
  const era = S.note.id;
  S.note.id = r.id; S.note.title = d.title; S.note.body = d.body;
  if (loud) {
    toast('Nota guardada', 'good');
    if (!era && S.view === 'note') render();   // pasa de "Nota nueva" a "Editar nota"
  }
  return r;
}

async function delNote(id){
  if (!confirm('¿Eliminar esta nota?')) return;
  const r = await api('note_del', { id });
  if (!r) return;
  toast('Nota eliminada'); go('notes');
}

/* ---------- PDF ---------- */
let jspdfReady = null;
function loadJsPDF(){
  if (jspdfReady) return jspdfReady;
  jspdfReady = new Promise((ok, no) => {
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
    s.onload = ok; s.onerror = () => no(new Error('cdn'));
    document.head.appendChild(s);
  });
  return jspdfReady;
}

async function buildPDF(title, text, images){
  await loadJsPDF();
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ unit: 'mm', format: 'a4' });
  const W = 210, M = 18, maxW = W - M * 2;
  let y = M + 4;

  doc.setFont('helvetica', 'bold'); doc.setFontSize(18);
  doc.text(doc.splitTextToSize(title || 'Krono', maxW), M, y);
  y += 9;
  doc.setDrawColor(252, 108, 38); doc.setLineWidth(1.1);
  doc.line(M, y, M + 34, y); y += 10;

  doc.setFont('helvetica', 'normal'); doc.setFontSize(11.5);
  doc.setTextColor(30, 25, 20);
  const lines = doc.splitTextToSize(text || '', maxW);
  lines.forEach(l => {
    if (y > 275) { doc.addPage(); y = M; }
    doc.text(l, M, y); y += 6.1;
  });

  (images || []).forEach(src => {
    doc.addPage();
    try {
      const props = doc.getImageProperties(src);
      const w = maxW, h = Math.min(250, props.height * w / props.width);
      doc.addImage(src, 'JPEG', M, M, w, h);
    } catch (_) {}
  });

  doc.setFontSize(8.5); doc.setTextColor(150, 135, 110);
  const total = doc.getNumberOfPages();
  for (let i = 1; i <= total; i++) {
    doc.setPage(i);
    doc.text('Krono · ' + new Date().toLocaleDateString('es-ES') + ' · ' + i + '/' + total, M, 288);
  }
  return doc;
}

async function notePDF(){
  const t = $('#n_t').value.trim() || 'Nota';
  try {
    const doc = await buildPDF(t, $('#n_b').value, []);
    doc.save(safeName(t) + '.pdf');
    toast('PDF descargado', 'good');
  } catch (e) { toast('No se pudo cargar el generador de PDF. ¿Tienes conexión?', 'bad'); }
}

function safeName(s){ return (s || 'krono').replace(/[\\/:*?"<>|]/g, '-').slice(0, 60); }

async function noteToCloud(){
  const saved = await saveNote(false);
  if (!saved) return toast('Escribe algo antes de subirla.', 'bad');
  const t = $('#n_t').value.trim() || 'Nota';
  sheet('<h2 style="margin-bottom:6px">Subir a la nube</h2>' +
    '<p class="sub" style="margin-bottom:16px">Elige en qué formato quieres guardarla.</p>' +
    '<button class="btn block" style="margin-bottom:9px" onclick="doUpload(\'pdf\')">Como PDF</button>' +
    '<button class="btn ghost block" onclick="doUpload(\'txt\')">Como texto (.txt)</button>');
  window.__noteTitle = t;
}

async function doUpload(kind){
  const t = window.__noteTitle || 'Nota';
  closeSheet();
  toast('Subiendo...');
  try {
    let b64, name;
    if (kind === 'pdf') {
      const doc = await buildPDF(t, S.note.body, []);
      b64 = doc.output('datauristring').split(',')[1];
      name = safeName(t) + '.pdf';
    } else {
      b64 = btoa(unescape(encodeURIComponent(S.note.title + '\n\n' + S.note.body)));
      name = safeName(t) + '.txt';
    }
    const r = await api('cloud_upload', { name, b64, note_id: S.note.id });
    if (!r) return;
    toast('Guardado en ' + r.path, 'good');
    S.note.cloud = new Date().toISOString().slice(0, 16).replace('T', ' ');
    render();
  } catch (e) { toast('No se pudo generar el archivo.', 'bad'); }
}

/* ===================================================================
   ESCÁNER (foto → texto → PDF)
   =================================================================== */
function viewScan(){
  const sc = S.scan;
  shell('Escanear apuntes', 'Haz fotos y conviértelas en texto',
    '<div class="card">' +
      '<div class="row" style="margin-bottom:12px">' +
        '<button class="btn" onclick="$(\'#cam\').click()">📷 Cámara</button>' +
        '<button class="btn ghost" onclick="$(\'#gal\').click()">🖼️ Galería</button>' +
      '</div>' +
      '<input id="cam" type="file" accept="image/*" capture="environment" multiple class="sr">' +
      '<input id="gal" type="file" accept="image/*" multiple class="sr">' +
      (sc.shots.length
        ? '<div class="shots">' + sc.shots.map((s, i) =>
            '<div class="shot"><img src="' + s + '" alt=""><button onclick="dropShot(' + i + ')">✕</button></div>').join('') + '</div>'
        : '<div class="empty" style="padding:22px"><div class="em">📄</div><p>Aún no hay fotos. Fotografía tus apuntes página a página.</p></div>') +
    '</div>' +
    (sc.shots.length ? '<button class="btn block" style="margin-bottom:12px" id="ocr_go">Leer el texto de ' +
      sc.shots.length + (sc.shots.length === 1 ? ' foto' : ' fotos') + '</button>' : '') +
    '<div id="ocr_out">' + (sc.text ?
      '<div class="card"><div class="card-h"><h3>Texto reconocido</h3></div>' +
      '<textarea id="sc_tx" style="min-height:190px">' + esc(sc.text) + '</textarea>' +
      '<div class="row" style="margin-top:11px">' +
        '<button class="btn" onclick="scanToNote()">Guardar como nota</button>' +
        '<button class="btn ghost" onclick="scanToPDF()">Crear PDF</button>' +
      '</div>' +
      '<button class="btn ghost block" style="margin-top:9px" onclick="scanToCloud()">☁️ Subir PDF a mi nube</button>' +
      '</div>' : '') + '</div>' +
    '<p class="hint">El reconocimiento funciona muy bien con texto impreso o fotocopias. Con letra a mano acierta menos, así que repasa el resultado antes de guardarlo.</p>'
  );
  $('#cam').onchange = e => addShots(e.target.files);
  $('#gal').onchange = e => addShots(e.target.files);
  const g = $('#ocr_go'); if (g) g.onclick = runOCR;
}

function addShots(files){
  [...files].forEach(f => {
    const rd = new FileReader();
    rd.onload = () => {
      shrink(rd.result, 1400, img => { S.scan.shots.push(img); viewScan(); });
    };
    rd.readAsDataURL(f);
  });
}
function dropShot(i){ S.scan.shots.splice(i, 1); viewScan(); }

function shrink(dataUrl, max, cb){
  const im = new Image();
  im.onload = () => {
    const sc = Math.min(1, max / Math.max(im.width, im.height));
    const c = document.createElement('canvas');
    c.width = Math.round(im.width * sc); c.height = Math.round(im.height * sc);
    c.getContext('2d').drawImage(im, 0, 0, c.width, c.height);
    cb(c.toDataURL('image/jpeg', 0.82));
  };
  im.onerror = () => cb(dataUrl);
  im.src = dataUrl;
}

let tessReady = null;
function loadTesseract(){
  if (tessReady) return tessReady;
  tessReady = new Promise((ok, no) => {
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.0/dist/tesseract.min.js';
    s.onload = ok; s.onerror = () => no(new Error('cdn'));
    document.head.appendChild(s);
  });
  return tessReady;
}

async function runOCR(){
  const btn = $('#ocr_go');
  btn.disabled = true; btn.textContent = 'Preparando el lector...';
  try {
    await loadTesseract();
    const worker = await Tesseract.createWorker('spa', 1, {
      logger: m => {
        if (m.status === 'recognizing text')
          btn.textContent = 'Leyendo... ' + Math.round(m.progress * 100) + '%';
      }
    });
    let out = '';
    for (let i = 0; i < S.scan.shots.length; i++) {
      btn.textContent = 'Leyendo página ' + (i + 1) + ' de ' + S.scan.shots.length;
      const { data } = await worker.recognize(S.scan.shots[i]);
      out += (i ? '\n\n— — —\n\n' : '') + (data.text || '').trim();
    }
    await worker.terminate();
    S.scan.text = out.trim() || 'No se ha reconocido texto. Prueba con más luz o la foto más recta.';
    viewScan();
    toast('Texto reconocido', 'good');
  } catch (e) {
    btn.disabled = false;
    btn.textContent = 'Leer el texto';
    toast('No se pudo cargar el lector. Necesita conexión la primera vez.', 'bad');
  }
}

async function scanToNote(){
  const txt = $('#sc_tx').value;
  const r = await api('note_save', { id: 0, title: 'Apuntes ' + new Date().toLocaleDateString('es-ES'), body: txt, tag: 'escáner' });
  if (!r) return;
  toast('Guardado en Notas', 'good');
  S.scan = { shots: [], text: '' };
  go('notes');
}

async function scanToPDF(){
  try {
    const t = 'Apuntes ' + new Date().toLocaleDateString('es-ES');
    const doc = await buildPDF(t, $('#sc_tx').value, S.scan.shots);
    doc.save(safeName(t) + '.pdf');
    toast('PDF descargado', 'good');
  } catch (e) { toast('No se pudo generar el PDF.', 'bad'); }
}

async function scanToCloud(){
  try {
    const t = 'Apuntes ' + new Date().toLocaleDateString('es-ES');
    const doc = await buildPDF(t, $('#sc_tx').value, S.scan.shots);
    toast('Subiendo...');
    const r = await api('cloud_upload', { name: safeName(t) + '.pdf', b64: doc.output('datauristring').split(',')[1] });
    if (r) toast('Guardado en ' + r.path, 'good');
  } catch (e) { toast('No se pudo generar el PDF.', 'bad'); }
}

/* ===================================================================
   COLEGAS
   =================================================================== */
async function viewFriends(){
  const r = await api('friends');
  if (!r) return;
  S.friends = r.friends; S.board = r.board;
  const meid = r.meid;

  shell('Colegas', S.friends.length + (S.friends.length === 1 ? ' colega' : ' colegas'),
    '<div class="card">' +
      '<div class="field" style="margin:0"><label for="f_q">Buscar por nombre de usuario</label>' +
      '<input id="f_q" placeholder="ejemplo: martina.g" autocapitalize="none" spellcheck="false"></div>' +
      '<div id="f_res" style="margin-top:11px"></div>' +
    '</div>' +
    '<div class="card"><div class="card-h"><h3>Ranking de XP</h3></div>' +
      (S.board.length ? '<div class="lst">' + S.board.map((u, i) =>
        '<div class="tile" style="' + (Number(u.id) === meid ? 'border-color:var(--orange);background:#FFF9EF' : '') + '" ' +
          (Number(u.id) === meid ? '' : 'onclick="openUser(' + u.id + ')"') + '>' +
          '<span class="av" style="font-size:15px;font-weight:800">' + (i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : (i+1)) + '</span>' +
          '<span class="tx"><span class="tt">' + esc(u.name) + (Number(u.id) === meid ? ' · tú' : '') + '</span>' +
          '<span class="ds">Nivel ' + u.level + ' · 🔥 ' + u.streak + (u.streak == 1 ? ' día' : ' días') + '</span></span>' +
          '<b class="num" style="font-size:15px">' + u.xp + '</b>' +
        '</div>').join('') + '</div>'
        : '<div class="empty" style="padding:20px"><p>Añade colegas para competir por el primer puesto.</p></div>') +
    '</div>' +
    (S.friends.length ? '<div class="card"><div class="card-h"><h3>A quién sigues</h3></div><div class="lst">' +
      S.friends.map(u => userTile(u)).join('') + '</div></div>' : '')
  );

  let t;
  $('#f_q').addEventListener('input', e => {
    clearTimeout(t);
    const q = e.target.value.trim();
    if (q.length < 2) { $('#f_res').innerHTML = ''; return; }
    t = setTimeout(async () => {
      const s = await api('search', { q });
      if (!s) return;
      $('#f_res').innerHTML = s.users.length
        ? '<div class="lst">' + s.users.map(u => userTile(u, true)).join('') + '</div>'
        : '<p class="hint">Nadie con ese nombre. Comprueba que lo escribes igual.</p>';
    }, 320);
  });
}

function userTile(u, withBtn){
  return '<div class="tile" onclick="openUser(' + u.id + ')">' +
    '<span class="av">' + (u.avatar ? '<img src="' + esc(u.avatar) + '" alt="">' : '👤') + '</span>' +
    '<span class="tx"><span class="tt">' + esc(u.name) + '</span>' +
    '<span class="ds">@' + esc(u.username) + ' · Nivel ' + u.level + ' · 🔥 ' + u.streak + '</span></span>' +
    (withBtn ? '<button class="btn ' + (Number(u.following) ? 'ghost' : '') + ' sm" ' +
      'onclick="event.stopPropagation();toggleFollow(' + u.id + ',' + (Number(u.following) ? 0 : 1) + ')">' +
      (Number(u.following) ? 'Siguiendo' : 'Seguir') + '</button>' : '<span style="color:var(--muted)">›</span>') +
  '</div>';
}

async function toggleFollow(id, on){
  const r = await api('follow', { id, on });
  if (!r) return;
  toast(on ? 'Ahora le sigues' : 'Has dejado de seguirle');
  if (S.view === 'user') viewUser(); else viewFriends();
}

async function openUser(id){ S.other = id; go('user'); }

async function viewUser(){
  const r = await api('user', { id: S.other });
  if (!r) return;
  const u = r.user, st = r.stats;
  shell(u.name, '@' + u.username,
    '<div class="card">' +
      '<div class="phead">' +
        '<div class="pav">' + (u.avatar ? '<img src="' + esc(u.avatar) + '" alt="">' : '👤') + '</div>' +
        '<div style="flex:1;min-width:0">' +
          '<h2>Nivel ' + u.level + '</h2>' +
          '<div class="sub">' + esc(u.bio || 'Sin descripción') + '</div>' +
        '</div>' +
      '</div>' +
      '<div class="chips" style="margin-bottom:14px">' +
        '<span class="chip">🔥 <b class="num">' + u.streak + '</b> ' + (u.streak == 1 ? 'día' : 'días') + '</span>' +
        '<span class="chip">🏆 récord <b class="num">' + u.best_streak + '</b></span>' +
        '<span class="chip">⚡ <b class="num">' + u.xp + '</b> XP</span>' +
      '</div>' +
      '<button class="btn ' + (r.following ? 'ghost' : '') + ' block" onclick="toggleFollow(' + u.id + ',' + (r.following ? 0 : 1) + ')">' +
        (r.following ? 'Dejar de seguir' : 'Seguir') + '</button>' +
    '</div>' +
    '<div class="stats" style="margin-bottom:12px">' +
      '<div class="stat"><b class="num">' + st.followers + '</b><span>Seguidores</span></div>' +
      '<div class="stat"><b class="num">' + st.following + '</b><span>Siguiendo</span></div>' +
      '<div class="stat"><b class="num">' + st.completed + '</b><span>Completados</span></div>' +
    '</div>' +
    '<div class="stats">' +
      '<div class="stat"><b class="num">' + st.habits + '</b><span>Hábitos</span></div>' +
      '<div class="stat"><b class="num">' + st.events + '</b><span>Eventos</span></div>' +
      '<div class="stat"><b class="num">' + st.notes + '</b><span>Notas</span></div>' +
    '</div>'
  );
}

/* ===================================================================
   PERFIL
   =================================================================== */
async function viewProfile(){
  const r = await api('me');
  if (r) { S.me = r.me; S.stats = r.stats; }
  const m = S.me, st = S.stats;
  shell('Mi perfil', '@' + m.username,
    '<div class="card">' +
      '<div class="phead">' +
        '<button class="pav" onclick="$(\'#p_file\').click()" aria-label="Cambiar foto">' +
          (m.avatar ? '<img id="p_prev" src="' + esc(m.avatar) + '" alt="">' : '<span id="p_prev">📷</span>') + '</button>' +
        '<div style="flex:1;min-width:0">' +
          '<h2>Nivel ' + m.level + '</h2>' +
          '<div class="sub">' + m.into + ' / ' + m.need + ' XP para el siguiente</div>' +
          '<div class="bar" style="margin-top:7px"><i style="width:' + Math.round(m.into / m.need * 100) + '%"></i></div>' +
        '</div>' +
      '</div>' +
      '<input id="p_file" type="file" accept="image/*" class="sr">' +
      '<div class="field"><label for="p_name">Nombre</label><input id="p_name" value="' + esc(m.name) + '"></div>' +
      '<div class="field"><label for="p_bio">Descripción</label><input id="p_bio" value="' + esc(m.bio) + '" placeholder="2º de Bachillerato · me gusta el baloncesto"></div>' +
      '<button class="btn block" id="p_save">Guardar cambios</button>' +
    '</div>' +
    '<div class="stats" style="margin-bottom:8px">' +
      '<div class="stat"><b class="num">' + m.streak + '</b><span>Racha</span></div>' +
      '<div class="stat"><b class="num">' + m.best_streak + '</b><span>Récord</span></div>' +
      '<div class="stat"><b class="num">' + m.coins + '</b><span>Monedas</span></div>' +
    '</div>' +
    '<div class="stats" style="margin-bottom:12px">' +
      '<div class="stat"><b class="num">' + st.followers + '</b><span>Seguidores</span></div>' +
      '<div class="stat"><b class="num">' + st.following + '</b><span>Siguiendo</span></div>' +
      '<div class="stat"><b class="num">' + st.completed + '</b><span>Completados</span></div>' +
    '</div>' +
    '<button class="btn ghost block" onclick="go(\'badges\')">Ver mis logros</button>'
  );

  $('#p_file').onchange = e => {
    const f = e.target.files[0]; if (!f) return;
    const rd = new FileReader();
    rd.onload = () => shrink(rd.result, 320, img => {
      S.me.avatar = img;
      const p = $('#p_prev');
      p.outerHTML = '<img id="p_prev" src="' + img + '" alt="">';
    });
    rd.readAsDataURL(f);
  };
  $('#p_save').onclick = async () => {
    const r = await api('profile_save', { name: $('#p_name').value.trim(), bio: $('#p_bio').value.trim(), avatar: S.me.avatar || '' });
    if (!r) return;
    S.me = r.me; toast('Perfil actualizado', 'good'); viewProfile();
  };
}

/* ===================================================================
   LOGROS
   =================================================================== */
const BADGES = [
  { e:'🌱', n:'Primer paso',  ok: s => s.stats.completed >= 1 },
  { e:'🔥', n:'3 días',       ok: s => s.me.streak >= 3 },
  { e:'⚡', n:'Semana',       ok: s => s.me.streak >= 7 },
  { e:'💎', n:'Mes entero',   ok: s => s.me.best_streak >= 30 },
  { e:'📝', n:'Escritor',     ok: s => s.stats.notes >= 5 },
  { e:'🗓️', n:'Organizado',   ok: s => s.stats.events >= 10 },
  { e:'◎',  n:'5 hábitos',    ok: s => s.stats.habits >= 5 },
  { e:'💯', n:'100 checks',   ok: s => s.stats.completed >= 100 },
  { e:'👥', n:'Sociable',     ok: s => s.stats.following >= 3 },
  { e:'⭐', n:'Nivel 5',      ok: s => s.me.level >= 5 },
  { e:'👑', n:'Nivel 10',     ok: s => s.me.level >= 10 },
  { e:'🪙', n:'500 monedas',  ok: s => s.me.coins >= 500 }
];

async function viewBadges(){
  const r = await api('me');
  if (r) { S.me = r.me; S.stats = r.stats; }
  const got = BADGES.filter(b => b.ok(S));
  shell('Logros', got.length + ' de ' + BADGES.length + ' conseguidos',
    '<div class="card">' +
      '<div class="bar" style="margin-bottom:14px"><i style="width:' + Math.round(got.length / BADGES.length * 100) + '%"></i></div>' +
      '<div class="badges">' + BADGES.map(b =>
        '<div class="badge' + (b.ok(S) ? ' got' : '') + '"><div class="e">' + b.e + '</div><div class="n">' + b.n + '</div></div>'
      ).join('') + '</div>' +
    '</div>' +
    '<div class="card"><div class="card-h"><h3>Cómo se gana XP</h3></div>' +
      '<div class="lst">' + [
        ['✅','Completar un hábito','+10 XP · +3 🪙'],
        ['🗓️','Marcar un evento como hecho','+12 XP · +4 🪙'],
        ['🎯','Cada minuto de enfoque','+1 XP'],
        ['📝','Crear una nota','+8 XP · +3 🪙'],
        ['🔥','Mantener la racha un día más','hasta +25 🪙']
      ].map(([e,t,d]) =>
        '<div class="tile"><span class="av">' + e + '</span><span class="tx">' +
        '<span class="tt">' + t + '</span><span class="ds">' + d + '</span></span></div>').join('') +
      '</div>' +
    '</div>'
  );
}

/* ===================================================================
   MODO ENFOQUE
   =================================================================== */
async function viewFocus(){
  if (S.focusRun) return renderFocusRun();
  const r = await api('focus_list');
  if (!r) return;
  S.focusList = r.focus;

  shell('Modo enfoque', 'Bloquea distracciones y gana XP',
    '<div class="card">' +
      '<div class="card-h"><h3>Sesión rápida</h3></div>' +
      '<div class="row" style="margin-bottom:9px">' +
        [15, 25, 45].map(m => '<button class="btn ghost" onclick="startFocus(' + m + ')">' + m + ' min</button>').join('') +
      '</div>' +
      '<button class="btn block" onclick="startFocus(60)">1 hora de concentración</button>' +
    '</div>' +
    '<div class="card"><div class="card-h"><h3>Franjas programadas</h3>' +
      '<button class="btn soft sm" onclick="editFocus(0)">+</button></div>' +
      (S.focusList.length ? '<div class="lst">' + S.focusList.map(f =>
        '<div class="tile" onclick="editFocus(' + f.id + ')">' +
          '<span class="av">' + (Number(f.active) ? '🔕' : '💤') + '</span>' +
          '<span class="tx"><span class="tt">' + esc(f.title) + '</span>' +
          '<span class="ds">' + esc(f.t1 + ' – ' + f.t2) + ' · ' +
            f.days.split(',').filter(Boolean).map(d => DOW[Number(d)-1] || '').join(' ') + '</span></span>' +
          '<span style="color:var(--muted)">›</span></div>').join('') + '</div>'
        : '<div class="empty" style="padding:20px"><p>Programa franjas fijas, por ejemplo mientras estudias por la tarde.</p></div>') +
    '</div>' +
    '<div class="card flat" style="background:#FFF9EF">' +
      '<h3 style="margin-bottom:8px">Bloquear apps del iPhone</h3>' +
      '<p style="font-size:14px;color:var(--ink-2);margin-bottom:10px">Krono no puede cerrar otras apps: iOS no deja que ninguna página web ni app toque a las demás. Lo que sí funciona es enlazarlo con lo que trae Apple:</p>' +
      '<ol style="font-size:14px;color:var(--ink-2);padding-left:20px;margin:0;line-height:1.7">' +
        '<li>Abre Ajustes › Tiempo de uso › Tiempo de inactividad.</li>' +
        '<li>Marca las mismas franjas que tienes aquí.</li>' +
        '<li>En Límites de apps, elige las que te distraen.</li>' +
        '<li>Activa Bloquear al terminar el límite.</li>' +
      '</ol>' +
    '</div>'
  );
}

function startFocus(mins){
  S.focusRun = { total: mins * 60, left: mins * 60, mins };
  renderFocusRun();
  S.focusTimer = setInterval(() => {
    if (!S.focusRun) return clearInterval(S.focusTimer);
    S.focusRun.left--;
    if (S.focusRun.left <= 0) return endFocus(true);
    const el = $('#fc_time'); if (el) el.textContent = fmtClock(S.focusRun.left);
    const rg = $('#fc_ring'); if (rg) rg.style.strokeDashoffset = (2*Math.PI*66) * (S.focusRun.left / S.focusRun.total);
  }, 1000);
}
function fmtClock(s){ return String(Math.floor(s/60)).padStart(2,'0') + ':' + String(s%60).padStart(2,'0'); }

function renderFocusRun(){
  const f = S.focusRun, C = 2*Math.PI*66;
  APP.innerHTML =
    '<div class="auth" style="background:var(--ink);color:var(--vanilla);min-height:100dvh"><div class="wrap" style="text-align:center">' +
      '<p style="opacity:.6;font-size:14px;margin-bottom:22px">Modo enfoque · ' + f.mins + ' minutos</p>' +
      '<div style="position:relative;width:180px;height:180px;margin:0 auto 26px">' +
        '<svg width="180" height="180" viewBox="0 0 180 180" style="transform:rotate(-90deg)">' +
          '<circle cx="90" cy="90" r="66" fill="none" stroke="rgba(255,244,214,.16)" stroke-width="10"/>' +
          '<circle id="fc_ring" cx="90" cy="90" r="66" fill="none" stroke="var(--orange)" stroke-width="10" ' +
            'stroke-linecap="round" stroke-dasharray="' + C + '" stroke-dashoffset="' + (C * (f.left/f.total)) + '"/>' +
        '</svg>' +
        '<div id="fc_time" class="num" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:38px;font-weight:800;letter-spacing:-.04em">' +
          fmtClock(f.left) + '</div>' +
      '</div>' +
      '<p style="opacity:.75;font-size:15px;margin-bottom:28px">Deja el móvil boca abajo. Al acabar te llevas ' + f.mins + ' XP.</p>' +
      '<button class="btn block" style="margin-bottom:9px" onclick="endFocus(false)">Terminar antes</button>' +
    '</div></div>';
}

async function endFocus(complete){
  clearInterval(S.focusTimer);
  const done = complete ? S.focusRun.mins : Math.round((S.focusRun.total - S.focusRun.left) / 60);
  S.focusRun = null;
  if (done > 0) {
    const r = await api('focus_reward', { mins: done });
    if (r) S.me = r.me;
  }
  if (complete) { buzz([60,40,60]); notify('Sesión terminada', 'Has aguantado los ' + done + ' minutos. +' + done + ' XP.'); }
  toast(done > 0 ? '+' + done + ' XP de enfoque' : 'Sesión cancelada', done > 0 ? 'good' : '');
  S.view = 'focus'; render();
}

function editFocus(id){
  const f = S.focusList.find(x => Number(x.id) === Number(id)) ||
            { id: 0, title: 'Estudio', t1: '16:00', t2: '18:00', days: '1,2,3,4,5', active: 1 };
  const days = String(f.days).split(',').filter(Boolean).map(Number);
  sheet(
    '<h2 style="margin-bottom:14px">' + (id ? 'Editar franja' : 'Nueva franja') + '</h2>' +
    '<div class="field"><label for="fo_t">Nombre</label><input id="fo_t" value="' + esc(f.title) + '"></div>' +
    '<div class="row" style="margin-bottom:13px">' +
      '<div><label style="font-size:13px;font-weight:700">Desde</label><input id="fo_1" type="time" value="' + esc(f.t1) + '"></div>' +
      '<div><label style="font-size:13px;font-weight:700">Hasta</label><input id="fo_2" type="time" value="' + esc(f.t2) + '"></div>' +
    '</div>' +
    '<div class="field"><label>Días</label><div class="picker" id="fo_d">' +
      DOW.map((d, i) => '<button type="button" class="emo' + (days.includes(i+1) ? ' on' : '') + '" data-d="' + (i+1) +
        '" style="font-size:12px;font-weight:750" onclick="this.classList.toggle(\'on\')">' + d + '</button>').join('') +
    '</div></div>' +
    '<label class="chip" style="margin-bottom:16px"><input type="checkbox" id="fo_a" ' + (Number(f.active) ? 'checked' : '') +
      ' style="width:auto;padding:0;margin-right:7px"> Franja activa</label>' +
    '<button class="btn block" id="fo_s">Guardar franja</button>' +
    (id ? '<button class="btn danger block" style="margin-top:9px" onclick="delFocus(' + id + ')">Eliminar</button>' : '')
  );
  $('#fo_s').onclick = async () => {
    const ds = [...document.querySelectorAll('#fo_d .emo.on')].map(b => b.dataset.d).join(',');
    const r = await api('focus_save', {
      id, title: $('#fo_t').value.trim() || 'Enfoque', t1: $('#fo_1').value, t2: $('#fo_2').value,
      days: ds || '1,2,3,4,5', active: $('#fo_a').checked ? 1 : 0
    });
    if (!r) return;
    closeSheet(); toast('Franja guardada', 'good'); viewFocus();
  };
}
async function delFocus(id){
  const r = await api('focus_del', { id });
  if (!r) return;
  closeSheet(); toast('Franja eliminada'); viewFocus();
}

/* ===================================================================
   MI NUBE (WebDAV / Nextcloud / EducaMadrid)
   =================================================================== */
async function viewCloud(){
  const r = await api('cloud_get');
  if (!r) return;
  const c = r.cloud;
  shell('Mi nube', Number(c.has) ? 'Conectada' : 'Sin conectar',
    '<div class="card">' +
      '<div class="field"><label for="c_url">Dirección WebDAV</label>' +
        '<input id="c_url" value="' + esc(c.url) + '" placeholder="https://cloud.educa.madrid.org/remote.php/dav/files/usuario" autocapitalize="none" spellcheck="false"></div>' +
      '<div class="field"><label for="c_user">Usuario</label>' +
        '<input id="c_user" value="' + esc(c.user) + '" autocapitalize="none" spellcheck="false"></div>' +
      '<div class="field"><label for="c_pass">Contraseña</label>' +
        '<input id="c_pass" type="password" placeholder="' + (Number(c.has) ? 'Guardada · escribe para cambiarla' : 'Tu contraseña de la nube') + '"></div>' +
      '<div class="field"><label for="c_fold">Carpeta de destino</label>' +
        '<input id="c_fold" value="' + esc(c.folder) + '" placeholder="Krono"></div>' +
      '<button class="btn block" id="c_save">Guardar conexión</button>' +
    '</div>' +
    '<div class="card flat" style="background:#FFF9EF">' +
      '<h3 style="margin-bottom:9px">Cómo sacar tu dirección</h3>' +
      '<p style="font-size:14px;color:var(--ink-2);margin-bottom:10px">El Cloud de EducaMadrid funciona con Nextcloud, así que admite WebDAV:</p>' +
      '<ol style="font-size:14px;color:var(--ink-2);padding-left:20px;margin:0 0 12px;line-height:1.7">' +
        '<li>Entra en tu Cloud desde el ordenador.</li>' +
        '<li>Baja del todo en Archivos y pulsa Ajustes.</li>' +
        '<li>Copia la dirección WebDAV que aparece ahí.</li>' +
        '<li>Pégala arriba con tu usuario y contraseña.</li>' +
      '</ol>' +
      '<p class="hint" style="margin:0">Si tu centro tiene activada la verificación en dos pasos, genera una contraseña de aplicación en Ajustes › Seguridad y usa esa aquí.</p>' +
    '</div>' +
    '<p class="hint">La contraseña se guarda en tu servidor, no se la mandamos a nadie. Aun así, usa siempre una contraseña de aplicación si tu nube te deja crearlas.</p>'
  );
  $('#c_save').onclick = async () => {
    const r2 = await api('cloud_save', {
      url: $('#c_url').value.trim(), user: $('#c_user').value.trim(),
      pass: $('#c_pass').value, folder: $('#c_fold').value.trim() || 'Krono'
    });
    if (!r2) return;
    toast('Nube conectada', 'good'); viewCloud();
  };
}

/* ===================================================================
   AJUSTES
   =================================================================== */
const NOTIF_KINDS = [
  ['habits','◎','Hábitos','Te avisa a la hora que pusiste en cada uno'],
  ['events','🗓️','Agenda','Antes de cada evento, según lo que elijas'],
  ['sched','⊞','Clases','Cinco minutos antes de cada clase'],
  ['focus','🎯','Enfoque','Cuando empieza y acaba una franja'],
  ['streak','🔥','Racha','Por la noche si te falta algo por marcar']
];

function viewSettings(){
  const p = S.prefs;
  const perm = ('Notification' in window) ? Notification.permission : 'unsupported';
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;

  shell('Ajustes', '@' + S.me.username,
    '<div class="card">' +
      '<div class="card-h"><h3>Notificaciones</h3></div>' +
      (perm === 'granted'
        ? '<p class="sub" style="margin-bottom:12px">Permiso concedido. Los avisos llegan mientras Krono esté abierta o en segundo plano.</p>'
        : '<button class="btn block" style="margin-bottom:12px" onclick="askNotif()">Activar las notificaciones</button>') +
      (!standalone ? '<div class="card flat" style="background:#FFF9EF;margin-bottom:12px">' +
        '<p style="font-size:14px;margin:0;color:var(--ink-2)">En iPhone las notificaciones solo funcionan si añades Krono a la pantalla de inicio: pulsa Compartir y luego <b>Añadir a pantalla de inicio</b>.</p></div>' : '') +
      '<div class="lst">' + NOTIF_KINDS.map(([k, e, t, d]) =>
        '<div class="tile"><span class="av">' + e + '</span>' +
        '<span class="tx"><span class="tt">' + t + '</span><span class="ds">' + d + '</span></span>' +
        '<label style="flex:0 0 auto"><input type="checkbox" class="nk" data-k="' + k + '" ' +
          (p['n_' + k] !== false ? 'checked' : '') + ' style="width:22px;height:22px;padding:0"></label></div>').join('') +
      '</div>' +
    '</div>' +
    '<div class="card"><div class="card-h"><h3>Cómo avisan</h3></div>' +
      '<div class="lst">' +
        '<div class="tile"><span class="av">🔊</span><span class="tx"><span class="tt">Sonido</span>' +
          '<span class="ds">Con el tono del sistema</span></span>' +
          '<input type="checkbox" id="s_sound" ' + (p.sound !== false ? 'checked' : '') + ' style="width:22px;height:22px;padding:0;flex:0 0 auto"></div>' +
        '<div class="tile"><span class="av">📳</span><span class="tx"><span class="tt">Vibración</span>' +
          '<span class="ds">Al marcar cosas y al avisar</span></span>' +
          '<input type="checkbox" id="s_haptics" ' + (p.haptics !== false ? 'checked' : '') + ' style="width:22px;height:22px;padding:0;flex:0 0 auto"></div>' +
      '</div>' +
      '<div class="field" style="margin:13px 0 0"><label for="s_night">Aviso nocturno de la racha</label>' +
        '<input id="s_night" type="time" value="' + esc(p.night || '21:30') + '"></div>' +
    '</div>' +
    '<button class="btn block" style="margin-bottom:12px" id="s_save">Guardar ajustes</button>' +
    '<div class="card"><div class="card-h"><h3>Cuenta</h3></div>' +
      '<button class="btn ghost block" style="margin-bottom:9px" onclick="changePass()">Cambiar contraseña</button>' +
      '<button class="btn ghost block" style="margin-bottom:9px" onclick="exportAll()">Descargar todos mis datos</button>' +
      '<button class="btn danger block" onclick="logout()">Cerrar sesión</button>' +
    '</div>' +
    '<p class="hint" style="text-align:center">Krono · hecho para organizarte sin agobios.</p>'
  );

  $('#s_save').onclick = async () => {
    const p2 = Object.assign({}, S.prefs);
    document.querySelectorAll('.nk').forEach(c => { p2['n_' + c.dataset.k] = c.checked; });
    p2.sound = $('#s_sound').checked;
    p2.haptics = $('#s_haptics').checked;
    p2.night = $('#s_night').value || '21:30';
    const r = await api('prefs_save', { prefs: p2 });
    if (!r) return;
    S.prefs = p2; toast('Ajustes guardados', 'good');
  };
}

function changePass(){
  sheet('<h2 style="margin-bottom:14px">Cambiar contraseña</h2>' +
    '<div class="field"><label for="cp_o">Contraseña actual</label><input id="cp_o" type="password"></div>' +
    '<div class="field"><label for="cp_n">Contraseña nueva</label><input id="cp_n" type="password" placeholder="mínimo 6 caracteres"></div>' +
    '<button class="btn block" id="cp_g">Cambiar</button>');
  $('#cp_g').onclick = async () => {
    const r = await api('pass_change', { old: $('#cp_o').value, new: $('#cp_n').value });
    if (!r) return;
    closeSheet(); toast('Contraseña cambiada', 'good');
  };
}

async function exportAll(){
  const r = await api('export');
  if (!r) return;
  const blob = new Blob([JSON.stringify(r.data, null, 2)], { type: 'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'krono-' + iso(new Date()) + '.json';
  a.click();
  toast('Datos descargados', 'good');
}

async function logout(){
  if (!confirm('¿Cerrar la sesión?')) return;
  await api('logout');
  S.me = null; authMode = 'login'; render();
}

/* ===================================================================
   NOTIFICACIONES
   =================================================================== */
async function askNotif(){
  if (!('Notification' in window)) return toast('Este navegador no admite notificaciones.', 'bad');
  const p = await Notification.requestPermission();
  if (p === 'granted') { toast('Notificaciones activadas', 'good'); notify('Krono listo', 'Te avisaremos de lo que importa.'); }
  else toast('No has dado permiso. Puedes cambiarlo en los ajustes del iPhone.', 'bad');
  viewSettings();
}

function notify(title, body, tag){
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  const opts = {
    body, tag: tag || 'krono', icon: '?r=icon&s=192', badge: '?r=icon&s=192',
    silent: S.prefs.sound === false, url: location.href
  };
  if (navigator.serviceWorker && navigator.serviceWorker.controller) {
    navigator.serviceWorker.controller.postMessage(Object.assign({ type: 'notify', title }, opts));
  } else {
    try { new Notification(title, opts); } catch (_) {}
  }
}

function markFired(key){
  S.fired[key] = 1;
  const keep = {}, t = iso(new Date());
  Object.keys(S.fired).forEach(k => { if (k.indexOf(t) === 0) keep[k] = 1; });
  S.fired = keep;
  localStorage.setItem('krono_fired', JSON.stringify(keep));
}

function scheduleNotifications(){
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  const p = S.prefs, now = nowMins(), t = iso(new Date()), dow = dowIdx(t);

  // Hábitos con hora
  if (p.n_habits !== false) {
    S.habits.forEach(h => {
      if (!h.hour || Number(h.n) >= Number(h.target)) return;
      const m = mins(h.hour);
      const k = t + ':h' + h.id;
      if (now >= m && now < m + 3 && !S.fired[k]) {
        markFired(k); notify(h.emoji + ' ' + h.title, 'Toca hacerlo. Marca el tick cuando lo tengas.', 'h' + h.id);
      }
    });
  }

  // Eventos de hoy
  if (p.n_events !== false) {
    (S.events[t] || []).forEach(e => {
      if (Number(e.done) || Number(e.allday) || !Number(e.remind)) return;
      const m = mins(e.t1) - Number(e.remind);
      const k = t + ':e' + e.id;
      if (now >= m && now < m + 3 && !S.fired[k]) {
        markFired(k); notify(e.emoji + ' ' + e.title, 'Empieza a las ' + e.t1 + '.', 'e' + e.id);
      }
    });
  }

  // Clases
  if (p.n_sched !== false && dow < 5) {
    S.periods.forEach((per, i) => {
      const s = S.slots[dow + ':' + i];
      if (!s) return;
      const m = mins(per.t1) - 5;
      const k = t + ':c' + dow + i;
      if (now >= m && now < m + 3 && !S.fired[k]) {
        markFired(k); notify(s.emoji + ' ' + s.subject, 'En 5 minutos' + (s.room ? ', aula ' + s.room : '') + '.', 'c' + i);
      }
    });
  }

  // Aviso nocturno de racha
  if (p.n_streak !== false) {
    const m = mins(p.night || '21:30');
    const k = t + ':night';
    const falta = S.habits.filter(h => Number(h.n) < Number(h.target)).length;
    if (now >= m && now < m + 3 && !S.fired[k] && falta > 0) {
      markFired(k);
      notify('🔥 Tu racha de ' + S.me.streak + (S.me.streak === 1 ? ' día' : ' días'), 'Te queda' + (falta === 1 ? '' : 'n') + ' ' + falta +
        (falta === 1 ? ' hábito' : ' hábitos') + ' por marcar hoy.', 'night');
    }
  }
}

/* ---------- arrancar ---------- */
window.go = go; window.back = back;
boot();
</script>
<script src="?r=addon12"></script>
</body>
</html>