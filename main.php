<?php
// bot.php — Multi-Bot Master Controller
declare(strict_types=1);

@ini_set('memory_limit', '-1');
@ini_set('max_execution_time', '0');
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Asia/Dhaka');
set_time_limit(0);
ignore_user_abort(true);

// Load সব টোকেন
$BOT_TOKENS = require __DIR__ . "/tokens.php";

// ⚙️ Master-এর টোকেন (polling-এর জন্য #১)
const MASTER_TOKEN = "8730292350:AAGqEEbDqE9yYs2fGU7GTouc4ct70TvrZX0";
const MASTER_API   = "https://api.telegram.org/bot" . MASTER_TOKEN . "/";

const DEV_ID    = "@MirsaraiBd";
const IMG_URL   = "https://t.me/vzbfegu/385";
const BRAND     = "ᴀsᴛ • 𝐒ᴍᴀʀᴛ • 𝐄ᴀsʏ";
const STATE_DIR = __DIR__ . "/state";
const STOP_DIR  = __DIR__ . "/stop";
const PID_DIR   = __DIR__ . "/pids";
const LOG_DIR   = __DIR__ . "/logs";
const QUEUE_DIR = __DIR__ . "/queue";
const RUNNING_DIR = __DIR__ . "/running";

const FORCE_CHANNELS = [
    ["id" => "@ChannnelAi", "name" => "𝗟𝗢𝗚𝗦_𝗖𝗛𝗔𝗡𝗡𝗘𝗟👾", "url" => "https://t.me/ChannnelAi"],
    ["id" => "@ChannnelAi",    "name" => "ɢx ᴡᴇʙ ᴛᴏ ᴢɪᴘ",   "url" => "https://t.me/ChannnelAi"],
];

foreach ([STATE_DIR, STOP_DIR, PID_DIR, LOG_DIR, QUEUE_DIR, RUNNING_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0777, true);
}

$ULTIMATE_APIS = require __DIR__ . "/apis.php";
$api_count = count($ULTIMATE_APIS);

require_once __DIR__ . "/multibot.php";

// ─── Master Telegram API (শুধু polling-এর জন্য) ───
function tgMaster($method, $params = []) {
    static $ch = null;
    if ($ch === null) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TCP_FASTOPEN => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
        ]);
    }
    curl_setopt($ch, CURLOPT_URL, MASTER_API . $method);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $r = curl_exec($ch);
    return $r ? json_decode($r, true) : null;
}

// ─── Multi-bot helpers ───
function sendMsg($cid, $txt, $kb = null, $photo = null) {
    return sendMsgMulti($cid, $txt, $kb, $photo);
}

function editMsg($cid, $mid, $txt, $kb = null) {
    return editMsgMulti($cid, $mid, $txt, $kb);
}

function answerCb($cb_id, $txt = "") {
    return answerCbMulti($cb_id, $txt);
}

// ─── Force Join ───
function checkJoin($uid) {
    return checkJoinMulti($uid);
}

function forceJoinKeyboard() {
    $buttons = [];
    foreach (FORCE_CHANNELS as $ch) {
        $buttons[] = [["text" => "📢 " . $ch["name"], "url" => $ch["url"]]];
    }
    $buttons[] = [["text" => "✅ ᴠᴇʀɪғʏ ᴊᴏɪɴ", "callback_data" => "verify_join"]];
    return ["inline_keyboard" => $buttons];
}

function sendForceJoinMsg($cid) {
    $txt = "🔒 <b>ꜰᴏʀᴄᴇ ᴊᴏɪɴ ʀᴇǫᴜɪʀᴇᴅ</b>\n"
         . "─────────────────────────\n\n"
         . "ᴛᴏ ᴜsᴇ ᴛʜɪs ʙᴏᴛ ʏᴏᴜ ᴍᴜsᴛ ᴊᴏɪɴ:\n\n";
    foreach (FORCE_CHANNELS as $i => $ch) {
        $txt .= ($i+1) . ". " . $ch["name"] . "\n";
    }
    $txt .= "\n⚠️ <b>ᴊᴏɪɴ ɴᴀ ᴋᴏʀʟᴇ ʙᴏᴛ ᴜsᴇ ᴋᴏʀᴀ ʏᴀʙᴇ ɴᴀ</b>";
    sendMsg($cid, $txt, forceJoinKeyboard());
}

// ─── Keyboards ───
function mainKb() {
    return ["keyboard"=>[
        [["text"=>"🚀 𝐒ᴛᴀʀᴛ 𝐈ɴғɪɴɪᴛᴇ"]],
        [["text"=>"📊 𝙲𝚑𝚎𝚌𝚔 𝚂𝚝𝚊𝚝𝚜"],["text"=>"ℹ️ ʜᴇʟᴘ"]],
        [["text"=>"👨‍💻 ᴅᴇᴠᴇʟᴏᴘᴇʀ"],["text"=>"🛑 𝐒ᴛᴏᴘ 𝐀ʟʟ"]]
    ],"resize_keyboard"=>true];
}

// ─── Helpers ───
function tk($uid, $phone) { return md5($uid . "_" . $phone); }

function enqueueJob($uid, $phone, $cid, $mid) {
    $tk = tk($uid, $phone);
    foreach (glob(QUEUE_DIR . "/job_*_{$tk}.json") as $f) return $f;
    foreach (glob(RUNNING_DIR . "/run_*_{$tk}.json") as $f) return $f;

    $job = ["uid"=>$uid,"phone"=>$phone,"cid"=>$cid,"mid"=>$mid,
            "ts"=>microtime(true),"tk"=>$tk];
    $f = QUEUE_DIR . "/job_" . sprintf('%017.0f', microtime(true)*1000000) . "_{$tk}.json";
    @file_put_contents($f, json_encode($job), LOCK_EX);
    return $f;
}

function queueCount() { return count(glob(QUEUE_DIR . "/job_*.json")); }

function stopTarget($uid, $phone) {
    @file_put_contents(STOP_DIR . "/t_" . tk($uid,$phone) . ".flag", "1");
    @unlink(RUNNING_DIR . "/run_" . tk($uid,$phone) . ".json");
}

function stopAllTargets($uid) {
    foreach (glob(STATE_DIR . "/t_*.json") as $sf) {
        $d = @json_decode(@file_get_contents($sf), true);
        if (isset($d['uid']) && (int)$d['uid'] === $uid) {
            stopTarget($uid, $d['phone']);
        }
    }
}

function getUserTargets($uid) {
    $targets = [];
    foreach (glob(STATE_DIR . "/t_*.json") as $sf) {
        $d = @json_decode(@file_get_contents($sf), true);
        if (isset($d['uid']) && (int)$d['uid'] === $uid) $targets[] = $d;
    }
    return $targets;
}

// ═══════════════════════════════════════════════════════════════
//  MAIN POLLING
// ═══════════════════════════════════════════════════════════════
$last_update_id = 0;

echo "╔══════════════════════════════════════════════════╗\n";
echo "║  🚀 $BRAND — ʍᴜʟᴛɪ-ʙᴏᴛ ᴠ15                   ║\n";
echo "║  " . count($BOT_TOKENS) . " Bots Loaded                                  ║\n";
echo "╚══════════════════════════════════════════════════╝\n";
echo "[✓] APIs    : $api_count\n";
echo "[✓] Bots    : " . count($BOT_TOKENS) . "\n";
echo "[✓] Master  : " . substr(MASTER_TOKEN, 0, 15) . "...\n\n";

tgMaster("getUpdates", ["offset" => -1, "timeout" => 0]);

while (true) {
    try {
        $updates = tgMaster("getUpdates", [
            "offset" => $last_update_id + 1,
            "timeout" => 25,
            "allowed_updates" => json_encode(["message","callback_query"])
        ]);

        if (!is_array($updates) || !isset($updates['result'])) {
            usleep(20000);
            continue;
        }

        foreach ($updates['result'] as $u) {
            $last_update_id = $u['update_id'];

            // ═══ MESSAGE ═══
            if (isset($u['message'])) {
                $msg = $u['message'];
                $cid = (int) $msg['chat']['id'];
                $uid = (int) ($msg['from']['id'] ?? $cid);
                $txt = trim($msg['text'] ?? '');

                if (!checkJoin($uid)) {
                    sendForceJoinMsg($cid);
                    continue;
                }

                if (strpos($txt, '/start') === 0) {
                    $w = "▓▒░ <b>" . BRAND . "</b> ░▒▓\n"
                       . "───── ʍᴜʟᴛɪ-ʙᴏᴛ ᴠ15 ─────\n\n"
                       . "┌─── <b>[ ⚙️ 𝚂𝚢𝚜𝚝𝚎𝚖 ]</b>\n"
                       . "├ 👤 ᴅᴇᴠ : <code>" . DEV_ID . "</code>\n"
                       . "├ ⚡ ᴀᴘɪs : <code>$api_count</code>\n"
                       . "├ 🤖 ʙᴏᴛs : <code>" . count($BOT_TOKENS) . "</code>\n"
                       . "├ 📥 ǫᴜᴇᴜᴇ : <code>" . queueCount() . "</code>\n"
                       . "└ 🚫 sʟᴇᴇᴘ : <code>ɴᴏɴᴇ</code>\n\n"
                       . "📌 sᴇɴᴅ ɴᴜᴍʙᴇʀ ᴛᴏ sᴛᴀʀᴛ";
                    sendMsg($cid, $w, mainKb(), IMG_URL);
                }

                elseif ($txt === "ℹ️ ʜᴇʟᴘ") {
                    sendMsg($cid, "ℹ️ <b>ʜᴇʟᴘ</b>\n\n📱 sᴇɴᴅ ɴᴜᴍʙᴇʀ\n🛑 /stopall\n📊 /stats", mainKb());
                }

                elseif ($txt === "👨‍💻 ᴅᴇᴠᴇʟᴏᴘᴇʀ") {
                    sendMsg($cid, "👨‍💻 <b>$BRAND</b>\n📦 v15\n🤖 ᴍᴜʟᴛɪ-ʙᴏᴛ", mainKb());
                }

                elseif ($txt === "🚀 𝐒ᴛᴀʀᴛ 𝐈ɴғɪɴɪᴛᴇ") {
                    sendMsg($cid, "📱 sᴇɴᴅ ɴᴜᴍʙᴇʀ\nᴇx: <code>019XXXXXXXX</code>", mainKb());
                }

                elseif ($txt === "🛑 𝐒ᴛᴏᴘ 𝐀ʟʟ") {
                    stopAllTargets($uid);
                    sendMsg($cid, "🛑 ᴀʟʟ sᴛᴏᴘᴘᴇᴅ.", mainKb());
                }

                elseif ($txt === "📊 𝙲𝚑𝚎𝚌𝚔 𝚂𝚝𝚊𝚝𝚜") {
                    $targets = getUserTargets($uid);
                    if (empty($targets)) {
                        sendMsg($cid, "📊 ɴᴏ ᴀᴄᴛɪᴠᴇ ᴛᴀʀɢᴇᴛs.", mainKb());
                    } else {
                        $m = "📊 <b>ʏᴏᴜʀ ᴛᴀʀɢᴇᴛs</b>\n\n";
                        $tot = 0;
                        foreach ($targets as $i => $t) {
                            $tot += $t['hits'] ?? 0;
                            $m .= "[" . ($i+1) . "] <code>{$t['phone']}</code>\n"
                                . "  ʜɪᴛs: <b>" . ($t['hits'] ?? 0) . "</b>\n\n";
                        }
                        $m .= "🔥 ᴛᴏᴛᴀʟ : <b>$tot</b>";
                        sendMsg($cid, $m, mainKb());
                    }
                }

                elseif (strpos($txt, '/stopall') === 0) {
                    stopAllTargets($uid);
                    sendMsg($cid, "🛑 ᴀʟʟ sᴛᴏᴘᴘᴇᴅ.", mainKb());
                }
                elseif (preg_match('/^\/stop\s+(\d{11})$/', $txt, $mm)) {
                    stopTarget($uid, $mm[1]);
                    sendMsg($cid, "🛑 sᴛᴏᴘᴘᴇᴅ <code>{$mm[1]}</code>", mainKb());
                }
                elseif (strpos($txt, '/stats') === 0) {
                    $targets = getUserTargets($uid);
                    $m = "📊 ᴛᴀʀɢᴇᴛs : " . count($targets) . "\n";
                    foreach ($targets as $t) {
                        $m .= "• <code>{$t['phone']}</code> — " . ($t['hits'] ?? 0) . "\n";
                    }
                    sendMsg($cid, $m, mainKb());
                }

                elseif (preg_match_all('/01[3-9]\d{8}/', $txt, $matches)) {
                    $phones = array_unique($matches[0]);
                    $valid = [];
                    foreach ($phones as $p) {
                        if (preg_match('/^01[3-9]\d{8}$/', $p)) $valid[] = $p;
                    }
                    if (empty($valid)) {
                        sendMsg($cid, "❌ ɴᴏ ᴠᴀʟɪᴅ ɴᴜᴍʙᴇʀs.", mainKb());
                        continue;
                    }

                    $count = count($valid);
                    $summary = "▓▒░ <b>ʟᴀᴜɴᴄʜɪɴɢ $count ᴛᴀʀɢᴇᴛ(s)</b> ░▒▓\n"
                             . "─────────────────────────────\n\n";

                    foreach ($valid as $i => $phone) {
                        if (file_exists(RUNNING_DIR . "/run_" . tk($uid,$phone) . ".json")) {
                            stopTarget($uid, $phone);
                            usleep(200000);
                        }

                        $init = sendMsg($cid,
                            "▓▒░ <b>ᴛᴀʀɢᴇᴛ #" . ($i+1) . "</b> ░▒▓\n"
                          . "├ 📱 ᴘʜᴏɴᴇ : <code>$phone</code>\n"
                          . "├ ⚡ ᴍᴏᴅᴇ : <code>ᴜɴʟɪᴍɪᴛᴇᴅ</code>\n"
                          . "└ 🚀 sᴛᴀᴛᴜs : <code>ǫᴜᴇᴜᴇᴅ</code>"
                        );

                        if ($init && isset($init['result']['message_id'])) {
                            $mid = (int) $init['result']['message_id'];
                            @file_put_contents(STATE_DIR . "/t_" . tk($uid,$phone) . ".json", json_encode([
                                "uid"=>$uid,"phone"=>$phone,"cid"=>$cid,"mid"=>$mid,
                                "hits"=>0,"cycle"=>0,
                                "last_update"=>date('H:i:s'),
                                "started"=>time()
                            ]), LOCK_EX);

                            enqueueJob($uid, $phone, $cid, $mid);
                            $summary .= "✅ <code>$phone</code>\n";
                        }
                        usleep(80000);
                    }

                    $summary .= "\n─────────────────────────────\n"
                              . "📥 ǫᴜᴇᴜᴇ : <b>" . queueCount() . "</b>\n"
                              . "🤖 ᴡᴏʀᴋᴇʀs ᴘʀᴏᴄᴇssɪɴɢ...";
                    sendMsg($cid, $summary, mainKb());
                }
            }

            // ═══ CALLBACK ═══
            if (isset($u['callback_query'])) {
                $cb = $u['callback_query'];
                $data = $cb['data'] ?? '';
                $cbUid = $cb['from']['id'] ?? 0;
                $cbCid = $cb['message']['chat']['id'] ?? 0;
                $cbMid = $cb['message']['message_id'] ?? 0;

                if ($data === 'verify_join') {
                    if (checkJoin($cbUid)) {
                        answerCb($cb['id'], "✅ ᴠᴇʀɪғɪᴇᴅ!");
                        editMsg($cbCid, $cbMid, "✅ <b>ᴊᴏɪɴ ᴠᴇʀɪғɪᴇᴅ!</b>\n\nɴᴏᴡ sᴇɴᴅ /start.");
                    } else {
                        answerCb($cb['id'], "❌ ᴊᴏɪɴ ᴀʟʟ ᴄʜᴀɴɴᴇʟs ғɪʀsᴛ!");
                    }
                }
                elseif (strpos($data, 'stop_target_') === 0) {
                    $phone = substr($data, 12);
                    stopTarget($cbUid, $phone);
                    answerCb($cb['id'], "🛑 sᴛᴏᴘᴘɪɴɢ...");
                }
                else {
                    answerCb($cb['id'], "ᴏᴋ");
                }
            }
        }
    } catch (Throwable $e) {
        @file_put_contents(LOG_DIR . "/error.log",
            "[" . date('H:i:s') . "] " . $e->getMessage() . "\n", FILE_APPEND);
        usleep(50000);
    }

    usleep(10000);
}